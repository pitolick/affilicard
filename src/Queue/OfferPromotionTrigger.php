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
 * **このクラスは post meta を書かない。** ループしない根拠がこの一点に依存するため、
 * OfferPromotionTriggerTest::test_フックはpost_metaを書かない で固定している。
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

	public function __construct(
		private Enqueuer $enqueuer,
		private ProviderRegistry $providerRegistry = new ProviderRegistry()
	) {}

	/**
	 * `updated_post_meta`/`added_post_meta` フック（listings meta 限定）から呼ばれる。
	 */
	public function onListingsSaved( int $postId ): void {
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
		$def      = PlatformConfig::find( $platform );
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

	/** テスト用に再入ガード（1層目）を解除する。 */
	public static function resetForTests(): void {
		self::$inFlight = array();
	}
}
