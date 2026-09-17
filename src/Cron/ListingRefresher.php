<?php
declare(strict_types=1);

namespace Affilicard\Cron;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Pricing\FetchStatus;
use Affilicard\Pricing\LegacyOffer;
use Affilicard\Pricing\ListingEligibility;
use Affilicard\Pricing\OfferIdentity;
use Affilicard\Pricing\OfferSelector;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\WorkOutcome;
use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Settings\GeneralSettings;
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
 * 自身ではなく OfferSelector::select() が選んだ購入リンク（offer）1件のみ。
 * どの購入リンクを使うかの判定は OfferSelector に一元化されており、ここでは
 * その結果を信頼して選ばれた offer だけを更新する。**保存に渡すのもその 1 件だけ**
 * ——listing 全体を渡すと、fetch のあいだに入った管理画面の編集を fetch 前の写しで
 * 巻き戻す（{@see ProductRepositoryInterface::updateListingOffer()}）。
 */
class ListingRefresher {

	public function __construct(
		private ProviderRegistry $registry,
		private ProductRepositoryInterface $repository
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
	public function refreshOne( int $postId, string $platform ): WorkOutcome {
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
			list( $patch, $outcome, $targetIdentity ) = $this->refreshListing( $listing, (string) $product['title'] );
			if ( null === $patch ) {
				// 更新すべき購入リンクが無い＝保存するものも無い。ここで listing を書き戻すと、
				// fetch もしていないのに fetch 前の写しで管理画面の編集を巻き戻す。
				return $outcome;
			}
			// updateListingOffer() の戻り値を必ず反映する。find() から再読込までの間（外部
			// API fetch 中）に対象 platform の listing、または対象の購入リンクそのものが
			// 削除されると false（未保存）が返る。ここで false を握り潰すと、取得済みの
			// 新しい価格が保存されないまま成功扱いになり、ハンドラが再試行もしない＝サイレントな
			// データロスになる。保存失敗はリトライで解決し得るため TRANSIENT_FAILURE を返す。
			// 削除された購入リンクを取りこぼしても、リトライでは OfferSelector が「そのとき
			// 現存する」購入リンクを選び直すため自然に解消する（無限には回らない——
			// ThrottledActionHandler::backoff() が MAX_ATTEMPTS で打ち切る）。
			$saved = $this->repository->updateListingOffer( $postId, $platform, $patch, $targetIdentity );
			if ( ! $saved ) {
				return WorkOutcome::TRANSIENT_FAILURE;
			}
			return $outcome;
		}
		// platform 該当 listing なし＝対象なし（no-op）＝SUCCESS。
		return WorkOutcome::SUCCESS;
	}

	/**
	 * 指定 listing に対して refreshOne() が実際に fetch する件数（OfferSelector::select() の
	 * 選択結果件数）。fetch を伴わず件数だけを求める——ThrottledActionHandler::run() が
	 * performWork()（＝refreshOne()）を呼ぶ**前**に、レート制限の枠をこの件数に比例させて
	 * 確保するために使う（RefreshHandler::refreshTargetCount() から呼ばれる）。
	 *
	 * 該当 listing・platform が無ければ 0（refreshOne() 自身も fetch を行わない）。
	 *
	 * refreshOne() と同じ ListingEligibility::isEnabledAuto() ゲートを先に掛ける。
	 * これを飛ばすと、実行時に無効化・手動化された listing（refreshOne() が SUCCESS_noop で
	 * 即 return し fetch しない）にも OfferSelector::select() の件数ぶんレート制限の枠を
	 * 予約してしまい、実際には使われない枠を無駄に確保することになる（CodeRabbit Minor #3）。
	 */
	public function targetCount( int $postId, string $platform ): int {
		$product = $this->repository->find( $postId );
		if ( null === $product || ! is_array( $product['listings'] ?? null ) ) {
			return 0;
		}
		foreach ( $product['listings'] as $listing ) {
			if ( ! is_array( $listing ) || ( $listing['platform'] ?? '' ) !== $platform ) {
				continue;
			}
			if ( ! ListingEligibility::isEnabledAuto( $listing ) ) {
				return 0;
			}
			// refreshListing() と同じフォールバックを通す。ここだけ offers を直接読むと、
			// 移行前の flat な listing で「refreshOne は fetch するのに枠は 0 件ぶんしか
			// 確保しない」というズレが生まれる。
			$offers   = LegacyOffer::offersWithFallback( $listing );
			$selected = OfferSelector::select( $offers, GeneralSettings::fallbackOnTerminal() );

			// 選ばれても実際に外部 API を叩かない購入リンク（自動 Provider 未対応・
			// external_id 無し）はレート制限の枠を使わない。判定は refreshListing() と
			// 同じ willFetch() に委ねる——条件をここへ写すと、片方だけ変えたときに
			// 「枠は取るのに叩かない／叩くのに枠が無い」というズレが生まれる。
			$count = 0;
			foreach ( $selected as $offer ) {
				if ( is_array( $offer ) && $this->willFetch( $listing, $offer ) ) {
					++$count;
				}
			}
			return $count;
		}
		return 0;
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
	 * listing の中から OfferSelector が選んだ購入リンク（offer）1件を fetch→反映し、
	 * 更新後の offer と WorkOutcome のタプルを返す。
	 *
	 * 返すのは選ばれた offer 1 件だけで、listing は返さない――保存経路（Repository::
	 * updateListingOffer()）が listing を読み直して差し込むため、ここで listing を組み立てて
	 * 持ち回ると、その写しが古くなった状態で保存へ渡ってしまう。
	 *
	 * 更新すべき購入リンクが無いときは offer に null を返す（保存を行わせない）。
	 *
	 * @param array<string, mixed> $listing
	 * @return array{0: array<string, mixed>|null, 1: WorkOutcome, 2: string} 取得が変えた
	 *   フィールドだけの差分、outcome、取得前に確定させたマージ先 identity のタプル
	 */
	private function refreshListing( array $listing, string $productTitle ): array {
		// 移行前の flat な listing（offers を持たず取得結果フィールドが listing 直下に並ぶ
		// v3 以前の形）も取得対象にする。offers を直接読むと、移行バッチが当該商品へ到達
		// する前に管理画面の「強制更新」が走ったとき、一度も fetch せず TRANSIENT_FAILURE
		// を返してリトライ枠だけを焼く。読み取り側（CardRenderer 等）と同じ写像を通す。
		//
		// このフォールバックで得た offer を保存経路（Repository::updateListingOffer()）へ
		// 渡すと、保存側も同じ写像で listing を offers[] へ揃えて書き戻す。その形は移行
		// バッチにとって「変換済み」と同じで、PluginUpgrade::migrateListingToOffers() は
		// offers を持つ listing をそのまま返す（冪等）ため二重に offer を作ることはない。
		$offers  = LegacyOffer::offersWithFallback( $listing );
		$targets = OfferSelector::select( $offers, GeneralSettings::fallbackOnTerminal() );
		if ( array() === $targets ) {
			// 更新すべき購入リンクが無い（offers が空）＝何もしない。リトライで解決し得るため
			// give-up はせず transient 扱いにする。
			return array( null, WorkOutcome::TRANSIENT_FAILURE, '' );
		}
		$offer = $targets[0];
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
			return array( $patch, WorkOutcome::TRANSIENT_FAILURE, $targetIdentity );
		}

		// **trim() は判定にだけ使い、渡すのは元の値。** 前後の空白を含む検索語を
		// 運用者が意図して入れている場合があるため、空かどうかだけを trim で見る。
		$searchKey = ScalarField::string( $offer, 'search_key' );
		$context   = array(
			'search_key'  => '' !== trim( $searchKey ) ? $searchKey : $productTitle,
			'regular_url' => ScalarField::string( $offer, 'regular_url' ),
			'external_id' => $externalId,
		);

		$result = $provider->fetch( $externalId, $context );
		if ( $result->isTerminalMiss() ) {
			// 恒久失敗（該当なし・無効 ID）。last_verified_at は更新せず（表示鮮度据え置き）、
			// TERMINAL_FAILURE を返してハンドラに give-up させる。
			$patch['fetch_status'] = FetchStatus::TERMINAL;
			return array( $patch, WorkOutcome::TERMINAL_FAILURE, $targetIdentity );
		}
		if ( ! $result->isHit() ) {
			// 一時失敗（API 到達不可・エラー・認証未設定等）。リトライで解決し得るため give-up しない。
			$patch['fetch_status'] = FetchStatus::TRANSIENT;
			return array( $patch, WorkOutcome::TRANSIENT_FAILURE, $targetIdentity );
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
		foreach ( array( 'price', 'list_price', 'badge', 'image_url' ) as $field ) {
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

		return array( $patch, WorkOutcome::SUCCESS, $targetIdentity );
	}
}
