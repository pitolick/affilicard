<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductPostType;
use Affilicard\Pricing\ListingEligibility;
use Affilicard\Pricing\OfferSelector;
use Affilicard\Pricing\PriceFreshness;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Settings\GeneralSettings;

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
 * 同一リクエスト内の再入ガード（$inFlight）は空であり、60秒の短期クールダウンも
 * とうに期限切れなので、1・2層目はこのクロスリクエストな往復を止められない。
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
 */
final class OfferPromotionTrigger {

	/**
	 * 短期クールダウン（秒）。外部ツールが複数の購入リンクを立て続けに削除する等、
	 * 同じ商品に対してこのフックがリクエストを跨いで連打されるのを吸収する保険。
	 * needsRefetch() が持つ長いクールダウン（TTL 起点）とは別物。
	 */
	private const COOLDOWN_SECONDS = 60;

	/**
	 * 同一リクエスト内で処理済みの商品 ID（1層目: 再入ガード）。
	 *
	 * 1 回の保存が listings meta を複数回書く場合や、投入処理から本フックへ再帰
	 * （enqueueManual の先で他のトリガーが同じ post を触る等）した場合に、同じ
	 * リクエスト内で二重に処理しないための静的な既処理集合。
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

		// 1層目: 再入ガード（同一リクエスト内）。
		if ( isset( self::$inFlight[ $postId ] ) ) {
			return;
		}
		self::$inFlight[ $postId ] = true;

		// 2層目: 短期クールダウン（リクエスト跨ぎの連打を吸収する）。
		$key = 'affilicard_offer_promote_' . $postId;
		if ( false !== get_transient( $key ) ) {
			return;
		}

		// 公開商品のみ対象にする（QueueMaintenance::sweep() の post_status => 'publish'
		// クエリと同じガード）。sweep はクエリの時点で非公開商品を取得しないが、
		// このフックは listings meta の書き込みそのものを契機にするため、
		// draft/pending/trash への書き込みでも素通りしないよう明示的に確認する。
		if ( 'publish' !== get_post_status( $postId ) ) {
			return;
		}

		$listings = get_post_meta( $postId, ProductPostType::META_LISTINGS, true );
		if ( is_array( $listings ) ) {
			$now = time();
			foreach ( $listings as $listing ) {
				$this->maybeEnqueue( $postId, $listing, $now );
			}
		}

		set_transient( $key, 1, self::COOLDOWN_SECONDS );
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

		$offers  = isset( $listing['offers'] ) && is_array( $listing['offers'] ) ? $listing['offers'] : array();
		$targets = OfferSelector::select( $offers, GeneralSettings::fallbackOnTerminal() );
		if ( array() === $targets ) {
			return;
		}

		$platform = (string) ( $listing['platform'] ?? '' );

		// give-up 中（RefreshHandler が恒久失敗を検知して立てた cooldown）の listing は
		// QueueMaintenance::sweep() と同じく期間中スキップする。ここを抜けると、
		// 外部ツールが listings meta を書き換えるたびに、廃盤/無効 ID への
		// リトライ連鎖を give-up の TTL 内で何度も焼くことになる。
		if ( get_transient( RefreshHandler::giveUpTransientKey( $postId, $platform ) ) ) {
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

		// 3層目: Enqueuer::enqueueManual() 自体が unique=true のため、1・2層目を
		// すり抜けても投入は 1 件に収束する。
		$this->enqueuer->enqueueManual( $postId, $platform, $account );
	}

	/** 一括書き込みによる抑止（0層目）が有効か。 */
	public static function isSuppressed(): bool {
		return self::$suppressed;
	}

	/** テスト用に再入ガード（1層目）と抑止フラグ（0層目）を解除する。 */
	public static function resetForTests(): void {
		self::$inFlight   = array();
		self::$suppressed = false;
	}
}
