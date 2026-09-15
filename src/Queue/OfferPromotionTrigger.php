<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductPostType;
use Affilicard\Pricing\LegacyOffer;
use Affilicard\Pricing\ListingEligibility;
use Affilicard\Pricing\OfferSelector;
use Affilicard\Pricing\PriceFreshness;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Settings\GeneralSettings;
use Affilicard\Util\JsonField;

/**
 * listings meta の保存を契機に、今使う購入リンクが古ければ即時取得を 1 件積む。
 *
 * 繰り上がりの経路は「本プラグイン自身が恒久エラーを検知した」「外部ツールが
 * 購入リンクを削除した」「管理画面で並べ替えた」の 3 つあるが、検知するのは
 * 「切り替わった」というイベントではなく「今使う購入リンクの価格が古い」という
 * 状態である。3 経路すべてが update_post_meta( META_LISTINGS, ... ) を通るため、
 * 1 つのフック（Plugin.php の updated_post_meta/added_post_meta）で拾える。
 * 経路ごとにフックを置くと、新しい経路が増えたときに漏れる。
 *
 * 判定は QueueMaintenance::sweep() と同じゲート（ListingEligibility::isAutoEligible →
 * OfferSelector::select → PriceFreshness::needsRefetch）を使う。needsRefetch() を
 * 再利用することが要点で、失敗し続ける購入リンクを毎回積み直さないクールダウンを
 * そのまま引き継げる。
 *
 * **このクラス自身は post meta を書かないが、それだけではループが閉じる理由には
 * ならない。** enqueueManual() が積んだジョブは別リクエストで非同期に実行され、
 * その経路（Enqueuer::enqueueManual → Action Scheduler ワーカー →
 * RefreshHandler::handle → ListingRefresher::refreshOne →
 * ProductRepository::updateListing → update_post_meta( META_LISTINGS, ... )）が
 * 結局このフックを再び起動する。折り返してきたその新しいリクエストでは、
 * 同一リクエスト内の再入ガード（$inFlight）は当然空であり、このクロスリクエストな
 * 往復をそもそも止める役割は持たない。
 *
 * ループが実際に閉じるのは、`ListingRefresher` が成功・恒久失敗・一時失敗・
 * unsupported のどの結果でも `last_fetched_at` を無条件に刻むためである
 * （`ListingRefresherTest` でその刻印をピン留め済み）。折り返してきたこのフックが
 * `PriceFreshness::needsRefetch()` を再評価する時点では、その刻印によって
 * 「もう古くない」と判定され false を返すため、再投入が起きない。**したがって
 * このファイルの安全性はここだけで完結しておらず、`ListingRefresher` が
 * すべての結果で `last_fetched_at` を刻み続けるという振る舞いに依存している。**
 * その前提が崩れる変更（例: 一部の失敗系統だけ刻印をスキップする）は、この
 * フックを無限ループさせる。
 *
 * **$inFlight が守るのは「同一実行内の同期的な再帰」だけであり、「同一リクエスト内の
 * 独立した複数回の保存」まで塞いではならない。** 以前は $inFlight を解放せず
 * リクエスト終了まで立てたままにしていたため、例えば platform A の listing 保存で
 * 本フックが発火し全 listing（A・B）を評価した直後、同じリクエストの中で platform B の
 * listing を保存しても、$inFlight が残っていて 2 回目の呼び出しが丸ごと無視され、
 * B の新しい状態が一度も評価されない事故があった（CodeRabbit Major #2）。
 * onListingsSaved() は $inFlight を `finally` で必ず解放し、実行が完全に終わった後の
 * 独立した呼び出しは毎回きちんと評価する。
 *
 * 2 層目は **時間窓ではなく「同じジョブが既に pending か」** で判定する。設計当初は
 * 商品単位の 60 秒 transient だったが、時間窓は「窓の中で起きた最後の変更が誰にも
 * 拾われないまま次の掃引まで待つ」事故を作る。pending の有無で抑えれば、抑止しても
 * そのジョブが実行時に最新の listing を読むので取りこぼしが無い。
 *
 * 抑止する理由は churn の削減である。`Enqueuer::enqueueManual()` は「手動更新を
 * 先頭へ繰り上げる」ため毎回 unschedule → schedule し直す。同一リクエストで複数の
 * listing が書かれるとそのたびに Action Scheduler の行を作り直すことになる
 * （unique=true により pending は 1 件へ収束するので壊れはしない）。
 */
final class OfferPromotionTrigger {

	/**
	 * 実行中の商品 ID（1層目: 再入ガード）。
	 *
	 * onListingsSaved() の実行中だけ立て、`finally` で必ず解放する（session-scoped では
	 * ない）。1 回の保存が listings meta を複数回書く場合や、投入処理から本フックへ
	 * 同期的に再帰（enqueueManual の先で他のトリガーが同じ post を触る等）した場合に、
	 * その**実行中の**多重処理だけを防ぐための静的集合。同一リクエスト内で実行が
	 * 完全に終わった後の独立した 2 回目の呼び出しはこれに引っかからず、通常どおり
	 * 評価される（クラス docblock 参照）。
	 *
	 * @var array<int, true>
	 */
	private static array $inFlight = array();

	/**
	 * 一括書き込み中の抑止フラグ（{@see self::withSuppression()}）。
	 *
	 * アップグレード移行は全商品の listings を書き直す。移行した offer は元の
	 * （しばしば古い）last_fetched_at をそのまま引き継ぐため、needsRefetch() は
	 * ほぼ全件で true を返す。抑止しないと、更新した瞬間にカタログ全件ぶんの
	 * 即時取得ジョブが積まれて API のレート制限を焼き切る。移行は形状を変える
	 * だけで購入リンクの中身は変えていないため、繰り上がりの契機ではない。
	 *
	 * @var bool
	 */
	private static bool $suppressed = false;

	/**
	 * $callback の実行中だけ本トリガーを止める（移行など、購入リンクの内容を
	 * 変えない一括書き込み向け）。
	 *
	 * 通常の掃引（QueueMaintenance::sweep()）は止めないので、抑止した商品も
	 * 次回の掃引で通常どおり拾われる——取得が永久に落ちることはない。
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public static function withSuppression( callable $callback ) {
		$previous         = self::$suppressed;
		self::$suppressed = true;
		try {
			return $callback();
		} finally {
			self::$suppressed = $previous;
		}
	}

	public function __construct(
		private Enqueuer $enqueuer,
		private ProviderRegistry $providerRegistry = new ProviderRegistry()
	) {}

	/**
	 * `updated_post_meta`/`added_post_meta` フック（listings meta 限定）から呼ばれる。
	 */
	public function onListingsSaved( int $postId ): void {
		// 0層目: 一括書き込みによる抑止（移行など。self::$suppressed の docblock 参照）。
		if ( self::$suppressed ) {
			return;
		}

		// 1層目: 再入ガード（同一実行内の同期的な再帰のみを防ぐ）。
		if ( isset( self::$inFlight[ $postId ] ) ) {
			return;
		}
		self::$inFlight[ $postId ] = true;

		try {
			// 公開商品のみ対象にする（QueueMaintenance::sweep() の post_status => 'publish'
			// クエリと同じガード）。sweep はクエリの時点で非公開商品を取得しないが、
			// このフックは listings meta の書き込みそのものを契機にするため、
			// draft/pending/trash への書き込みでも素通りしないよう明示的に確認する。
			if ( 'publish' !== get_post_status( $postId ) ) {
				return;
			}

			// listings meta は配列とは限らず **JSON 文字列でも入りうる**。他の読み手
			// （PluginUpgrade::migrateOneProduct() / ProductRepository::listingSummary() /
			// ProductListColumns::renderFallbackColumn()）はいずれも JsonField::decode() で
			// 文字列形も復号しており、ここだけ is_array() で弾くと、外部ツールが JSON で
			// 書いた商品は maybeEnqueue() に一度も到達しない——繰り上がった購入リンクが
			// 古いまま即時取得されず、次の掃引まで待たされる。
			$raw      = get_post_meta( $postId, ProductPostType::META_LISTINGS, true );
			$listings = is_array( $raw ) ? $raw : ( is_string( $raw ) ? JsonField::decode( $raw, array() ) : array() );

			$now = time();
			foreach ( $listings as $listing ) {
				$this->maybeEnqueue( $postId, $listing, $now );
			}
		} finally {
			// 実行が正常終了・例外いずれでも、次の（同一リクエスト内かどうかを問わない）
			// 独立した呼び出しを塞がないよう必ず解放する。
			unset( self::$inFlight[ $postId ] );
		}
	}

	/**
	 * @param mixed $listing
	 */
	private function maybeEnqueue( int $postId, $listing, int $now ): void {
		if ( ! is_array( $listing ) ) {
			return;
		}

		// 自動更新対象外の listing は、他の判定を一切読まずに弾く
		// （QueueMaintenance::sweep() と同一のフィルタ）。
		if ( ! ListingEligibility::isAutoEligible( $listing ) ) {
			return;
		}

		// 移行前の flat な listing も対象にする。ListingRefresher / QueueMaintenance は
		// 既に LegacyOffer::offersWithFallback() を通しており、ここだけ offers を直読み
		// すると「掃引と価格更新は扱えるのに繰り上がりだけ拾わない」不整合になる。
		$targets = OfferSelector::select(
			LegacyOffer::offersWithFallback( $listing ),
			GeneralSettings::fallbackOnTerminal()
		);
		if ( array() === $targets ) {
			return;
		}

		$platform = (string) ( $listing['platform'] ?? '' );

		// give-up 中（RefreshHandler が恒久失敗を検知して立てた cooldown）の listing は
		// QueueMaintenance::sweep() と同じく期間中スキップする。ここを抜けると、
		// 外部ツールが listings meta を書き換えるたびに、廃盤/無効 ID への
		// リトライ連鎖を give-up の TTL 内で何度も焼くことになる。
		//
		// ただしマーカーは (post_id, platform) 単位でしか立たないため、判定は
		// 「今使う購入リンク自身が terminal か」まで含めて RefreshHandler::isGivenUp()
		// に委ねる。そうしないと、恒久失敗した購入リンクのマーカーが、繰り上げた
		// 別の購入リンク（一度も失敗していない）まで TTL のあいだ止めてしまう。
		if ( RefreshHandler::isGivenUp( $postId, $platform, $targets[0] ) ) {
			return;
		}

		$def = PlatformConfig::find( $platform );
		if ( null === $def ) {
			return;
		}

		// needsRefetch() のクールダウン（last_fetched_at 起点）をそのまま使う。ここが、
		// 失敗し続ける購入リンクを本フックが毎回再投入しない理由そのもの。
		if ( ! PriceFreshness::needsRefetch( $targets[0], $def, $now ) ) {
			return;
		}

		$account = $this->providerRegistry->get( $def->provider )?->accountCode();
		if ( null === $account ) {
			return;
		}

		// 2層目: 同じジョブが既に pending なら投入しない。
		//
		// enqueueManual() は「手動更新を先頭へ繰り上げる」ため毎回 unschedule →
		// schedule し直す。同一リクエストで複数の listing が書かれると、そのたびに
		// Action Scheduler の行を作り直すことになる（pending は 1 件に収束するので
		// 壊れはしないが、無駄なキューの churn が出る）。
		//
		// 時間窓（transient）ではなく「pending の有無」で抑えるのは、更新を取りこぼさない
		// ためである。pending なジョブは後で実行されるとき最新の listing を読むので、
		// 抑止しても内容は反映される。時間窓だと、窓の中で起きた最後の変更が誰にも
		// 拾われないまま次の掃引まで待つことになる。
		$pending_args = array(
			'post_id'  => $postId,
			'platform' => $platform,
		);
		if ( as_has_scheduled_action( Enqueuer::HOOK_REFRESH, $pending_args, $this->enqueuer->group( $account ) ) ) {
			return;
		}

		// 3層目: enqueueManual() 自体が unique=true のため、1・2 層をすり抜けても
		// pending は 1 件に収束する。
		$this->enqueuer->enqueueManual( $postId, $platform, $account );
	}

	/** 一括書き込みによる抑止（0層目）が有効か。 */
	public static function isSuppressed(): bool {
		return self::$suppressed;
	}

	/**
	 * テスト用に再入ガード（1層目）と抑止フラグ（0層目）を解除する。
	 *
	 * 通常運用では $inFlight は onListingsSaved() の `finally` で必ず解放されるため
	 * 常に空のはずだが、テストが例外的な状態（モックの例外送出テスト等）を挟んだ後の
	 * 後始末として維持する。
	 */
	public static function resetForTests(): void {
		self::$inFlight   = array();
		self::$suppressed = false;
	}
}
