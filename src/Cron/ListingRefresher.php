<?php
declare(strict_types=1);

namespace Affilicard\Cron;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Pricing\FetchStatus;
use Affilicard\Pricing\LegacyOffer;
use Affilicard\Pricing\ListingEligibility;
use Affilicard\Pricing\OfferIdentity;
use Affilicard\Pricing\OfferSelector;
use Affilicard\Provider\BatchFetchingProvider;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\RateLimiter;
use Affilicard\Queue\WorkOutcome;
use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Settings\GeneralSettings;
use Affilicard\Upgrade\PluginUpgrade;
use Affilicard\Util\ScalarField;

/**
 * 商品 listing を Provider 経由で再取得し価格等を更新する。
 *
 * v2.4.0（Action Scheduler キュー化）以降、公開中商品を横断する同期スイープは
 * QueueMaintenance::sweep()（enqueue）+ RefreshHandler（AS ワーカー実行時に
 * refreshOne() を呼ぶ）に置き換わった。このクラスの公開 API は単一 listing を
 * 対象にする refreshOne() のみで、複数商品を走査する run()/refreshProduct() 系
 * （Phase 1 の同期スイープ実装）は死コードとして削除済み。
 *
 * v4.0.0（listing の複数購入リンク化）以降、実際に fetch→反映するのは listing
 * 自身ではなく OfferSelector が選んだ購入リンク（offer）。v4.6.0 からはバージョン
 * ごとに 1 件ずつ（OfferSelector::selectForRefresh()。カードに並ぶものと同じ）。
 * どの購入リンクを使うかの判定は OfferSelector に一元化されており、ここでは
 * その結果を信頼して選ばれた offer だけを更新する。**保存に渡すのも購入リンク 1 件ずつ**
 * ——listing 全体を渡すと、fetch のあいだに入った管理画面の編集を fetch 前の写しで
 * 巻き戻す（{@see ProductRepositoryInterface::updateListingOffer()}）。
 */
class ListingRefresher {

	/** 2 件目以降の呼び出しの枠を待つ回数の上限（取れなければ一時失敗にして、あとで取り直す）。 */
	private const MAX_ACQUIRE_ATTEMPTS = 5;

	/**
	 * @param \Closure(int): void|null $sleepMs 枠を待つときの待ち（ミリ秒）。テストで差し替える。既定は usleep()。
	 * @param RateLimiter|null         $limiter 1 回の更新で 2 件目以降の外部 API を叩くとき、1 回ごとに枠を取る。
	 *                                          無ければ有効間隔だけ待つ（テスト・単体利用向け）。
	 */
	public function __construct(
		private ProviderRegistry $registry,
		private ProductRepositoryInterface $repository,
		private ?\Closure $sleepMs = null,
		private ?RateLimiter $limiter = null
	) {}

	/**
	 * 指定 platform の listing を1件 fetch→反映し保存する。
	 *
	 * 既存 refreshListing() を再利用（force 相当・throttle はハンドラ側で担保済みの前提）。
	 * 商品または該当 platform の listing が見つからない場合は false。
	 *
	 * v2.4.0: enqueue から worker 実行までの間に listing が DISABLED / manual へ切り替わる
	 * TOCTOU（Time-Of-Check-Time-Of-Use）を防ぐため、実行時に update_mode/enabled を
	 * 再チェックする（ListingEligibility::isEnabledAuto()）。auto_update はここでは見ない
	 * ――force enqueue（管理画面「強制更新」）は auto_update=false の listing も対象に
	 * 含める契約のため、実行時に auto_update だけを理由に取りこぼすと force 機能が壊れる。
	 *
	 * 保存は find→save（全 listings 上書き）ではなく Repository::updateListingOffer()
	 * （対象 platform の、身元が一致する購入リンク 1 件のみ原子的に差し替え）で行う。
	 * RateLimiter は account 単位で直列化するため、同一商品の別 platform listing は別
	 * group で並行実行され得る。全 listings 上書きだと後着の save が先着の別 platform
	 * 更新を消す（lost update）。さらに listing 単位で渡しても、fetch（数百 ms〜数秒）の
	 * あいだに管理画面が同じ listing の購入リンクを追加・削除・並べ替えていれば、その編集を
	 * fetch 前の写しで巻き戻してしまう。渡す単位を「今回 fetch した offer 1 件」まで
	 * 絞り込むことで、古い値そのものを保存経路へ持ち込まない。
	 */
	/**
	 * @param bool $firstSlotUsed 呼び出し元が取った 1 件目の呼び出しの枠を、既に使っている（または
	 *                            枠を取っていない）か。true なら、この更新の最初の外部 API 呼び出しから
	 *                            枠を取ってから叩く。BatchRefreshHandler が、まとめ取得の先取りに枠を
	 *                            使ったとき・先取りの結果だけで処理するとき（枠を取らない）に渡す。
	 *                            対象 ID から推し量らないのは、先取りと再読込の間に対象が変わりうるため。
	 */
	public function refreshOne( int $postId, string $platform, bool $firstSlotUsed = false ): WorkOutcome {
		$product = $this->repository->find( $postId );
		if ( null === $product || ! is_array( $product['listings'] ?? null ) ) {
			// 削除済み商品・listing 無し＝対象なし（no-op）。deleted 商品で failed 化させない。
			return WorkOutcome::SUCCESS;
		}
		foreach ( $product['listings'] as $listing ) {
			if ( ! is_array( $listing ) || ( $listing['platform'] ?? '' ) !== $platform ) {
				continue;
			}
			if ( ! ListingEligibility::isEnabledAuto( $listing ) ) {
				// 実行時に無効化・手動化された listing は対象外（no-op）＝SUCCESS。failed 化させない。
				return WorkOutcome::SUCCESS;
			}
			if ( self::isHeldForMigration( $listing ) ) {
				// 移行がまだ到達していない listing には書き込まない（理由は
				// {@see self::isHeldForMigration()}）。削除済み商品・無効化された listing と
				// 同じ「対象なし（no-op）＝SUCCESS」で返す——一時失敗にすると backoff の
				// 試行回数を焼き、移行が長引くインストールで failed が積み上がる。
				return WorkOutcome::SUCCESS;
			}
			$results = $this->refreshListing( $listing, (string) $product['title'], $firstSlotUsed );
			if ( array() === $results ) {
				// 更新すべき購入リンクが無い＝保存するものも無い。ここで listing を書き戻すと、
				// fetch もしていないのに fetch 前の写しで管理画面の編集を巻き戻す。リトライで
				// 解決し得るため give-up はせず transient 扱いにする。
				return WorkOutcome::TRANSIENT_FAILURE;
			}
			// バージョン違い（OfferSelector::selectForRefresh() が返す複数件）は、取得した
			// 購入リンクごとに保存する。保存は 1 件ずつ listing を読み直して差し込むため、
			// 前の保存を巻き戻さない。
			//
			// updateListingOffer() の戻り値を必ず反映する。find() から再読込までの間（外部
			// API fetch 中）に対象 platform の listing、または対象の購入リンクそのものが
			// 削除されると false（未保存）が返る。ここで false を握り潰すと、取得済みの
			// 新しい価格が保存されないまま成功扱いになり、ハンドラが再試行もしない＝サイレントな
			// データロスになる。保存失敗はリトライで解決し得るため一時失敗に数える。
			// 削除された購入リンクを取りこぼしても、リトライでは OfferSelector が「そのとき
			// 現存する」購入リンクを選び直すため自然に解消する（無限には回らない——
			// ThrottledActionHandler::backoff() が MAX_ATTEMPTS で打ち切る）。
			$outcomes = array();
			foreach ( $results as list( $patch, $outcome, $targetIdentity, $fetched ) ) {
				$saved = $this->repository->updateListingOffer( $postId, $platform, $patch, $targetIdentity );
				if ( ! $saved ) {
					$outcome = WorkOutcome::TRANSIENT_FAILURE;
					$fetched = true;
				}
				$outcomes[] = array( $outcome, $fetched );
			}
			return self::combineOutcomes( $outcomes );
		}
		// platform 該当 listing なし＝対象なし（no-op）＝SUCCESS。
		return WorkOutcome::SUCCESS;
	}

	/**
	 * 指定 listing に対して refreshOne() が **叩き得る** fetch の件数
	 * （OfferSelector::select() の選択結果件数）。fetch を伴わず件数だけを求める——
	 * ThrottledActionHandler::run() が performWork()（＝refreshOne()）を呼ぶ**前**に、
	 * レート制限の枠をこの件数に比例させて確保するために使う
	 * （RefreshHandler::refreshTargetCount() から呼ばれる）。
	 *
	 * 該当 listing・platform が無ければ 0（refreshOne() 自身も fetch を行わない）。
	 *
	 * **数えるのは「必ず叩く件数」ではなく「叩き得る件数」である。** 枠取りと本処理は
	 * 別々に条件を判定するため、その隙間で条件が変わった場合に枠を取り損ねてはならない
	 * （枠なしの fetch＝レート制限のすり抜け）。したがって 0 を返してよいのは
	 * 「refreshOne() がこの実行で fetch することが**あり得ない**」ケースに限る:
	 *
	 * - 商品・該当 platform の listing が無い（削除済み）
	 * - {@see ListingEligibility::isEnabledAuto()} が false（無効化・手動化）——
	 *   refreshOne() も同じゲートで no-op を返す。移行の完走とも無関係に効き続ける
	 * - 選ばれた購入リンクが {@see self::willFetch()} で false（自動 Provider 未対応・
	 *   external_id 無し）
	 *
	 * これを飛ばして一律に枠を取ると、実際には fetch しないジョブが account の最終
	 * リクエスト時刻だけを進め、本当に fetch したい後続のジョブを無駄に待たせる
	 * （CodeRabbit Minor #3）。
	 *
	 * **移行の見送り（{@see self::isHeldForMigration()}）はここでは 0 にしない。**
	 * 見送りは上の 3 つと違い「今この瞬間は fetch しない」だけで、移行バッチは別
	 * リクエストで走るため、この判定と refreshOne() の再判定の隙間で完走し得る。
	 * そのとき refreshOne() 側では見送りが既に解けており、枠を 1 つも確保しないまま
	 * 外部 API を叩く。見送りが続いた場合のコストは枠 1 つの空振り（account の次の
	 * 要求が 1 間隔ぶん遅れる）で、しかも移行が完走するまでの一時的なものに収まる——
	 * すり抜けた 1 リクエストのほうが高くつくため、取りに倒す。
	 */
	public function targetCount( int $postId, string $platform ): int {
		return count( $this->fetchTargetExternalIds( $postId, $platform ) );
	}

	/**
	 * refreshOne() が **叩き得る** 購入リンクの外部 ID（{@see self::targetCount()} が数える集合）。
	 *
	 * まとめ取得できる Provider（{@see \Affilicard\Provider\BatchFetchingProvider}）へ
	 * 先に渡す外部 ID を求めるために使う（BatchRefreshHandler）。件数の判定と同じ選択ロジックを
	 * 共有する——別々に書くと「数えた集合」と「先に取った集合」がずれる。
	 *
	 * @return list<string>
	 */
	public function fetchTargetExternalIds( int $postId, string $platform ): array {
		$product = $this->repository->find( $postId );
		if ( null === $product || ! is_array( $product['listings'] ?? null ) ) {
			return array();
		}
		foreach ( $product['listings'] as $listing ) {
			if ( ! is_array( $listing ) || ( $listing['platform'] ?? '' ) !== $platform ) {
				continue;
			}
			if ( ! ListingEligibility::isEnabledAuto( $listing ) ) {
				return array();
			}
			// refreshListing() と同じフォールバックを通す。ここだけ offers を直接読むと、
			// 移行前の flat な listing で「refreshOne は fetch するのに枠は 0 件ぶんしか
			// 確保しない」というズレが生まれる（移行の見送り中も同じ理由で数える——
			// 見送りが解けたあとに叩く件数は、この flat な listing を変換した 1 件）。
			$offers   = LegacyOffer::offersWithFallback( $listing );
			$selected = OfferSelector::selectForRefresh( $offers, GeneralSettings::fallbackOnTerminal() );

			// 選ばれても実際に外部 API を叩かない購入リンク（自動 Provider 未対応・
			// external_id 無し）はレート制限の枠を使わない。判定は refreshListing() と
			// 同じ willFetch() に委ねる——条件をここへ写すと、片方だけ変えたときに
			// 「枠は取るのに叩かない／叩くのに枠が無い」というズレが生まれる。
			$ids = array();
			foreach ( $selected as $offer ) {
				if ( is_array( $offer ) && $this->willFetch( $listing, $offer ) ) {
					$ids[] = ScalarField::string( $offer, 'external_id' );
				}
			}
			return $ids;
		}
		return array();
	}

	/**
	 * この listing は「移行がまだ到達していない」ため、今は書き込まずに見送るか。
	 *
	 * **なぜ見送るのか。** 価格更新の保存
	 * （{@see \Affilicard\Repository\ProductRepository::updateListingOffer()}）は
	 * {@see LegacyOffer::offersWithFallback()} で listing を `offers[]` へ揃えてから
	 * 書き戻す。つまり **v3 の flat な listing へ届いた価格更新は、そのついでに
	 * flat → `offers[]` の変換も済ませてしまう**。その変換は
	 * `update_post_meta()` → `sanitize_meta()` →
	 * {@see \Affilicard\Rest\ProductSchema::sanitizeOffers()} を通り、身元
	 * （`regular_url` / `external_id`）を 1 つも持たない購入リンクをそこで落とす。
	 *
	 * 移行だけが {@see \Affilicard\Rest\ProductSchema::withLegacyOfferPreservation()}
	 * でその規則を外して購入リンクを温存し、件数と post ID を控えて管理画面に
	 * 名指しで通知する（運用者が通常 URL を足すための猶予を作る）。価格更新が先に
	 * 届くと、**データが消えるうえに温存カウンタも増えず通知も出ない**。しかも移行が
	 * あとから到達しても、その listing は既に `offers[]` を持つため
	 * {@see \Affilicard\Upgrade\PluginUpgrade::migrateListingToOffers()} は冪等に
	 * 素通りし、数える機会そのものが失われる。
	 *
	 * **これは予防的な措置である。** 本番カタログ（商品 1,500 件）を実測した時点では
	 * `affiliate_url` だけを持つ listing は 0 件で、運用者もそのようなデータを手入力
	 * した覚えはないとのことだった。つまり今この窓を通っても失われるデータは無い。
	 * それでも塞ぐのは、失われたときに**気づく手段が無い**（通知も件数も出ない）
	 * 種類の損失だからである。
	 *
	 * **見送った更新は失われない。** ここで書き込みを行わない＝`last_fetched_at` が
	 * 据え置かれるため、{@see \Affilicard\Pricing\PriceFreshness::needsRefetch()} は
	 * 引き続き true を返し、次の掃引（{@see \Affilicard\Queue\QueueMaintenance::sweep()}）
	 * が同じ listing をまた積む。移行が完走すれば listing は `offers[]` を持つので
	 * この見送りは効かなくなり、そのまま通常どおり取得される。掃引が flat な listing を
	 * 拾えること自体は sweep() 側のフォールバックが保証している（同関数のコメント参照）。
	 *
	 * **窓は「移行が未完」かつ「この listing が未変換」の積集合に限る。** 移行中でも
	 * 既に `offers[]` を持つ listing は普通に更新する——そちらは価格更新が変換を
	 * 兼ねることが無く、止める理由が無い。判定の順序も安いほうを先に置き、
	 * 移行済みの listing では option を読まない。
	 *
	 * **未変換の判定は移行自身と同じものを使う**（{@see LegacyOffer::isUnmigrated()}）。
	 * 「移行がこれから変換するつもりの listing には書かない」が守りたい規則なので、
	 * 判定が 2 つに割れてはならない。
	 *
	 * @param array<string, mixed> $listing
	 */
	private static function isHeldForMigration( array $listing ): bool {
		return LegacyOffer::isUnmigrated( $listing ) && PluginUpgrade::isOffersMigrationPending();
	}

	/**
	 * この購入リンクが実際に外部 API を叩くか。
	 *
	 * refreshListing() が UNSUPPORTED で早期に返す条件と同じものを、枠取り
	 * （{@see self::targetCount()}）からも参照できるよう 1 箇所に置く。
	 *
	 * **値は {@see ScalarField::string()} で読む。** listing/offer は postmeta 由来で、
	 * ネストした配列やオブジェクトを含みうる。`(string)` で直に畳むと配列は
	 * 「Array to string conversion」の警告のうえ `'Array'` になり、その外部 ID で
	 * 「叩く」と判定して実際に Provider の fetch() を呼んでしまう——誰の価格とも
	 * 分からない結果を持ち帰るか、該当なし（恒久失敗）で give-up させる。非スカラーは
	 * 「値なし」に倒し、自動取得の対象外（UNSUPPORTED）として扱う。
	 *
	 * @param array<string, mixed> $listing
	 * @param array<string, mixed> $offer
	 */
	private function willFetch( array $listing, array $offer ): bool {
		$externalId = ScalarField::string( $offer, 'external_id' );
		if ( '' === $externalId ) {
			return false;
		}
		$definition = PlatformConfig::find( ScalarField::string( $listing, 'platform' ) );
		if ( null === $definition ) {
			return false;
		}
		$provider = $this->registry->get( $definition->provider );
		return null !== $provider && $provider->isAutomatic();
	}

	/**
	 * listing の中から価格更新の対象（OfferSelector::selectForRefresh()。バージョンごとに 1 件）を
	 * 選び、それぞれを fetch→反映した結果を返す。
	 *
	 * 返すのは購入リンクごとの「取得が変えたフィールドだけ」のパッチで、listing は返さない――
	 * 保存経路（Repository::updateListingOffer()）が listing を読み直して差し込むため、ここで
	 * listing を組み立てて持ち回ると、その写しが古くなった状態で保存へ渡ってしまう。
	 *
	 * 更新すべき購入リンクが無いときは空配列を返す（保存を行わせない）。
	 *
	 * @param array<string, mixed> $listing
	 * @return list<array{0: array<string, mixed>, 1: WorkOutcome, 2: string, 3: bool}> 購入リンクごとの
	 *   パッチ、outcome、取得前に確定させたマージ先 identity、外部 API を試みたか
	 */
	private function refreshListing( array $listing, string $productTitle, bool $firstSlotUsed = false ): array {
		// flat な listing（offers を持たず取得結果フィールドが listing 直下に並ぶ v3 以前の
		// 形）も取得対象にする。offers を直接読むと、そういう listing は一度も fetch されず
		// TRANSIENT_FAILURE を返してリトライ枠だけを焼く。読み取り側（CardRenderer 等）と
		// 同じ写像を通す。
		//
		// **ここへ flat な listing が届くのは「移行がもう来ない」ときだけである。** 移行が
		// 未完のあいだは refreshOne() が isHeldForMigration() で先に見送るため、この関数は
		// 呼ばれない。残るのは移行が完走したあとも flat のままの listing——
		// PluginUpgrade::MIGRATION_MAX_ATTEMPTS ぶん保存に失敗して移行が諦めた商品
		// （PluginUpgrade::giveUpOnProduct()。旧形式のまま残り、読み取り側の
		// フォールバックが描く契約）である。**このフォールバックはそれらのための
		// ものなので外せない**——外すと、諦められた商品は自動更新からも恒久的に外れる。
		//
		// このフォールバックで得た offer を保存経路（Repository::updateListingOffer()）へ
		// 渡すと、保存側も同じ写像で listing を offers[] へ揃えて書き戻す。その形は移行
		// バッチにとって「変換済み」と同じで、PluginUpgrade::migrateListingToOffers() は
		// offers を持つ listing をそのまま返す（冪等）ため二重に offer を作ることはない。
		// **その「保存が変換を兼ねる」性質こそ、移行中に見送る理由そのものである**
		// （isHeldForMigration() の PHPDoc 参照）。移行が諦めた商品では、温存も通知も
		// もう起こらないことが確定しているため、見送る意味が無い。
		$offers  = LegacyOffer::offersWithFallback( $listing );
		$targets = OfferSelector::selectForRefresh( $offers, GeneralSettings::fallbackOnTerminal() );

		$results = array();
		// 1 件目の呼び出しの枠はハンドラが取っている。ハンドラがその枠を既に使った（まとめ取得の先取り）
		// か取っていないと伝えてきたら、最初の外部 API 呼び出しから枠を取ってから叩く。
		$calledApi = $firstSlotUsed;
		foreach ( $targets as $offer ) {
			$results[] = $this->refreshOffer( $listing, $offer, $productTitle, $calledApi );
		}
		return $results;
	}

	/**
	 * 結果をまとめて、ハンドラへ返す 1 つの outcome にする。
	 *
	 * - 取得した購入リンクが 1 件も無い（全件が自動取得の対象外）＝一時失敗（従来どおり）
	 * - 1 件でも一時失敗・保存失敗 ＝一時失敗（再試行は全件を取り直す。まとめ取得なら 1 リクエスト）
	 * - 全件が恒久失敗 ＝恒久失敗（give-up マーカーが立つ）
	 * - それ以外（成功と恒久失敗が混ざる）＝成功。恒久失敗したバージョンは offer の
	 *   fetch_status=terminal で価格を出さなくなり（PriceFreshness。行と購入ボタンは残る）、
	 *   代わりのリンクがあれば繰り上がる。生きているバージョンの更新は止めない
	 *
	 * @param list<array{0: WorkOutcome, 1: bool}> $outcomes outcome と「外部 API を試みたか」の組
	 */
	private static function combineOutcomes( array $outcomes ): WorkOutcome {
		$tried = array();
		foreach ( $outcomes as list( $outcome, $fetched ) ) {
			if ( $fetched ) {
				$tried[] = $outcome;
			}
		}
		if ( array() === $tried || in_array( WorkOutcome::TRANSIENT_FAILURE, $tried, true ) ) {
			return WorkOutcome::TRANSIENT_FAILURE;
		}
		foreach ( $tried as $outcome ) {
			if ( WorkOutcome::TERMINAL_FAILURE !== $outcome ) {
				return WorkOutcome::SUCCESS;
			}
		}
		return WorkOutcome::TERMINAL_FAILURE;
	}

	/**
	 * 購入リンク 1 件を fetch し、取得が変えたフィールドだけのパッチを作る。
	 *
	 * 1 回の更新で 2 件目以降の外部 API を叩くとき（まとめ取得の結果が手元に無いとき）は、
	 * **呼び出しごとにレート制限の枠を取る**（{@see self::acquireNextCall()}）。ハンドラが取るのは
	 * 1 件目の枠だけで、枠は取った時刻しか記録しないため、ハンドラ側で件数ぶん見込んでも
	 * 次のジョブとの間隔は守れない。
	 *
	 * @param array<string, mixed> $listing
	 * @param array<string, mixed> $offer
	 * @param bool                 $calledApi この更新の中で既に外部 API を叩いたか（書き換える）
	 * @return array{0: array<string, mixed>, 1: WorkOutcome, 2: string, 3: bool} パッチ、outcome、
	 *   取得前に確定させたマージ先 identity、外部 API を試みたか
	 */
	private function refreshOffer( array $listing, array $offer, string $productTitle, bool &$calledApi ): array {
		// **マージ先の identity は fetch の前に確定させる。** 取得結果は regular_url を
		// 上書きしうるので、external_id を持たない購入リンクでは取得後に identity が
		// 変わる。取得後の値で探すと保存側が相手を見失い、価格が永久に入らなくなる。
		$targetIdentity = OfferIdentity::of( $offer );

		// 値は willFetch() と同じ {@see ScalarField::string()} で読む（非スカラーは
		// 「値なし」）。ここだけ `(string)` に戻すと、叩くかどうかの判定と実際に
		// Provider へ渡す値が食い違う。
		$platformCode = ScalarField::string( $listing, 'platform' );
		$externalId   = ScalarField::string( $offer, 'external_id' );
		// last_fetched_at は PriceFreshness::needsRefetch() が time()（実 UTC epoch）と比較して
		// 掃引の再取得クールダウンを判定する。current_time('c') はサイトのローカル時刻に '+00:00'
		// を付与するだけで実 UTC ではない（UTC 以外の TZ だとクールダウンがずれる）ため、
		// last_verified_at と同様に gmdate('c')（実 UTC）で記録する。
		$now = gmdate( 'c' );

		$definition = PlatformConfig::find( $platformCode );
		$provider   = null !== $definition ? $this->registry->get( $definition->provider ) : null;

		// **返すのは offer 全体ではなく「取得が変えたフィールドだけ」のパッチである。**
		// offer 全体を返すと、保存側が fetch 前のスナップショットで最新の offer を置き換え、
		// fetch 中に管理者が行った並べ替え（display_order）や search_key の編集が巻き戻る。
		// display_order / search_key / external_id は管理者のもので、取得は触らない。
		$patch = array( 'last_fetched_at' => $now );

		if ( ! $this->willFetch( $listing, $offer ) ) {
			// 自動 Provider 未対応・external_id 無し＝この購入リンクは自動取得の対象外。
			// 状態としては恒久的だが、リトライ分類（give-up するかどうか）はここでは変えない
			// ――give-up するのは「該当なし・無効 ID」（TERMINAL）のときだけ。
			$patch['fetch_status'] = FetchStatus::UNSUPPORTED;
			return array( $patch, WorkOutcome::TRANSIENT_FAILURE, $targetIdentity, false );
		}

		// **trim() は判定にだけ使い、渡すのは元の値。** 前後の空白を含む検索語を
		// 運用者が意図して入れている場合があるため、空かどうかだけを trim で見る。
		$searchKey = ScalarField::string( $offer, 'search_key' );
		$context   = array(
			'search_key'  => '' !== trim( $searchKey ) ? $searchKey : $productTitle,
			'regular_url' => ScalarField::string( $offer, 'regular_url' ),
			'external_id' => $externalId,
		);

		$needsHttp = ! ( $provider instanceof BatchFetchingProvider && $provider->hasPrefetched( $externalId ) );
		if ( $needsHttp && $calledApi && ! $this->acquireNextCall( $provider ) ) {
			// 枠が取れない（同じ account を別のジョブが使い続けている）。叩かずに一時失敗にし、
			// ハンドラの再試行に任せる（last_verified_at は据え置き）。
			$patch['fetch_status'] = FetchStatus::TRANSIENT;
			return array( $patch, WorkOutcome::TRANSIENT_FAILURE, $targetIdentity, true );
		}
		if ( $needsHttp ) {
			$calledApi = true;
		}

		$result = $provider->fetch( $externalId, $context );
		if ( $result->isTerminalMiss() ) {
			// 恒久失敗（該当なし・無効 ID）。last_verified_at は更新せず（表示鮮度据え置き）、
			// TERMINAL_FAILURE を返してハンドラに give-up させる。
			$patch['fetch_status'] = FetchStatus::TERMINAL;
			return array( $patch, WorkOutcome::TERMINAL_FAILURE, $targetIdentity, true );
		}
		if ( ! $result->isHit() ) {
			// 一時失敗（API 到達不可・エラー・認証未設定等）。リトライで解決し得るため give-up しない。
			$patch['fetch_status'] = FetchStatus::TRANSIENT;
			return array( $patch, WorkOutcome::TRANSIENT_FAILURE, $targetIdentity, true );
		}

		$fetched               = $result->data;
		$patch['fetch_status'] = FetchStatus::NONE;
		// PriceFreshness::isPriceDisplayable() は time()（実 UTC epoch）と比較するため、
		// last_verified_at も実 UTC で記録する必要がある。current_time('c') はサイトのローカル
		// 時刻に '+00:00' を付与するだけで実 UTC ではない（wp-env 等 UTC 以外のタイムゾーンだと
		// ずれる）ため、last_fetched_at とは別に gmdate('c') で書く。
		$patch['last_verified_at'] = gmdate( 'c' );

		// **取得が返さなかった項目はパッチに入れない。** 以前は取得前の値を書き戻していたが、
		// それでは fetch 中に管理者が編集した値を古い写しで上書きしてしまう。キーを落とせば
		// 保存側のマージで最新の値がそのまま残る。
		// price_from・plan_label は返す Provider（Amazon の動画）だけが上書きする。返さない Provider
		// （書籍など）では手入力の提供形態がそのまま残る。
		foreach ( array( 'price', 'price_from', 'list_price', 'list_price_label', 'badge', 'points', 'adult', 'image_url', 'plan_label' ) as $field ) {
			if ( isset( $fetched[ $field ] ) ) {
				$patch[ $field ] = (string) $fetched[ $field ];
			}
		}

		// regular_url / affiliate_url は isset() だけで判定すると、Provider が空文字を
		// 返した場合に既存の保存値を空で上書きしてしまう（isset('') === true のため）。
		// 空文字の fetch 結果ではキー自体を落とし、非空の場合のみパッチへ載せる。
		foreach ( array( 'regular_url', 'affiliate_url' ) as $field ) {
			$value = isset( $fetched[ $field ] ) ? (string) $fetched[ $field ] : '';
			if ( '' !== $value ) {
				$patch[ $field ] = $value;
			}
		}

		return array( $patch, WorkOutcome::SUCCESS, $targetIdentity, true );
	}

	/**
	 * 1 回の更新の中で、2 件目以降の外部 API 呼び出しの枠を取る。
	 *
	 * 間隔はハンドラが枠を取るのと同じ有効間隔（Provider の下限と管理画面の上書きの大きいほう）。
	 * リミッタが無いときはその間隔だけ待つ。
	 */
	private function acquireNextCall( \Affilicard\Provider\ProviderInterface $provider ): bool {
		$account  = (string) $provider->accountCode();
		$override = '' !== $account ? GeneralSettings::throttleOverrideMs( $account ) : 0;
		$interval = $override > 0 ? max( $provider->minRequestIntervalMs(), $override ) : $provider->minRequestIntervalMs();
		if ( null === $this->limiter || '' === $account ) {
			$this->sleepMs( $interval );
			return true;
		}
		for ( $attempt = 0; $attempt < self::MAX_ACQUIRE_ATTEMPTS; $attempt++ ) {
			$nowMs   = (int) round( microtime( true ) * 1000 );
			$acquire = $this->limiter->tryAcquire( $account, $interval, $nowMs );
			if ( $acquire['ok'] ) {
				return true;
			}
			$this->sleepMs( max( 1, min( (int) $acquire['next_ms'] - $nowMs, $interval ) ) );
		}
		return false;
	}

	private function sleepMs( int $ms ): void {
		if ( $ms <= 0 ) {
			return;
		}
		if ( null !== $this->sleepMs ) {
			( $this->sleepMs )( $ms );
			return;
		}
		usleep( $ms * 1000 );
	}
}
