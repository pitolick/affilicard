<?php
declare(strict_types=1);

namespace Affilicard\Cron;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Pricing\ListingEligibility;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Repository\ProductRepositoryInterface;

/**
 * 商品 listing を Provider 経由で再取得し価格等を更新する。
 *
 * v2.4.0（Action Scheduler キュー化）以降、公開中商品を横断する同期スイープは
 * QueueMaintenance::sweep()（enqueue）+ RefreshHandler（AS ワーカー実行時に
 * refreshOne() を呼ぶ）に置き換わった。このクラスの公開 API は単一 listing を
 * 対象にする refreshOne() のみで、複数商品を走査する run()/refreshProduct() 系
 * （Phase 1 の同期スイープ実装）は死コードとして削除済み。
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
	 * 保存は find→save（全 listings 上書き）ではなく Repository::updateListing()（対象
	 * platform のみ原子的に差し替え）で行う。RateLimiter は account 単位で直列化するため、
	 * 同一商品の別 platform listing は別 group で並行実行され得る。全 listings 上書きだと
	 * 後着の save が先着の別 platform 更新を消す（lost update）ため、単一 listing の原子的
	 * 更新に委譲する。
	 */
	public function refreshOne( int $postId, string $platform ): bool {
		$product = $this->repository->find( $postId );
		if ( null === $product || ! is_array( $product['listings'] ?? null ) ) {
			return false;
		}
		foreach ( $product['listings'] as $listing ) {
			if ( ! is_array( $listing ) || ( $listing['platform'] ?? '' ) !== $platform ) {
				continue;
			}
			if ( ! ListingEligibility::isEnabledAuto( $listing ) ) {
				return false;
			}
			$refreshed = $this->refreshListing( $listing, (string) $product['title'] );
			// updateListing() の戻り値を必ず反映する。find() から updateListing() の再読込
			// までの間（外部 API fetch 中）に対象 platform の listing が削除・変更されると
			// updateListing() は false（未保存）を返す。ここで false を握り潰すと、取得済みの
			// 新しい価格が保存されないまま refreshOne() が true（成功）を返し、ハンドラが成功と
			// 判断して再試行もされない＝サイレントなデータロスになる。$saved を戻り値に含める。
			$saved = $this->repository->updateListing( $postId, $platform, $refreshed );
			return $saved && '' === (string) ( $refreshed['fetch_error'] ?? '' );
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $listing
	 * @return array<string, mixed>
	 */
	private function refreshListing( array $listing, string $productTitle ): array {
		$platformCode = isset( $listing['platform'] ) ? (string) $listing['platform'] : '';
		$externalId   = isset( $listing['external_id'] ) ? (string) $listing['external_id'] : '';
		// last_fetched_at は PriceFreshness::needsRefetch() が time()（実 UTC epoch）と比較して
		// 掃引の再取得クールダウンを判定する。current_time('c') はサイトのローカル時刻に '+00:00'
		// を付与するだけで実 UTC ではない（UTC 以外の TZ だとクールダウンがずれる）ため、
		// last_verified_at と同様に gmdate('c')（実 UTC）で記録する。
		$now = gmdate( 'c' );

		$definition                 = PlatformConfig::find( $platformCode );
		$provider                   = null !== $definition ? $this->registry->get( $definition->provider ) : null;
		$listing['last_fetched_at'] = $now;

		if ( null === $provider || ! $provider->isAutomatic() || '' === $externalId ) {
			$listing['fetch_error'] = (string) __( '対応する自動 Provider がありません', 'affilicard' );
			return $listing;
		}

		$context = array(
			'search_key'  => isset( $listing['search_key'] ) && '' !== trim( (string) $listing['search_key'] )
				? (string) $listing['search_key']
				: $productTitle,
			'regular_url' => isset( $listing['regular_url'] ) ? (string) $listing['regular_url'] : '',
			'external_id' => $externalId,
		);

		$fetched = $provider->fetch( $externalId, $context );
		if ( null === $fetched ) {
			$listing['fetch_error'] = (string) __( '価格情報の取得に失敗しました', 'affilicard' );
			return $listing;
		}

		$listing['fetch_error'] = '';
		// PriceFreshness::isPriceDisplayable() は time()（実 UTC epoch）と比較するため、
		// last_verified_at も実 UTC で記録する必要がある。current_time('c') はサイトのローカル
		// 時刻に '+00:00' を付与するだけで実 UTC ではない（wp-env 等 UTC 以外のタイムゾーンだと
		// ずれる）ため、last_fetched_at とは別に gmdate('c') で書く。
		$listing['last_verified_at'] = gmdate( 'c' );
		$listing['price']            = isset( $fetched['price'] ) ? (string) $fetched['price'] : ( $listing['price'] ?? '' );
		$listing['list_price']       = isset( $fetched['list_price'] ) ? (string) $fetched['list_price'] : ( $listing['list_price'] ?? '' );
		$listing['badge']            = isset( $fetched['badge'] ) ? (string) $fetched['badge'] : ( $listing['badge'] ?? '' );
		$listing['image_url']        = isset( $fetched['image_url'] ) ? (string) $fetched['image_url'] : ( $listing['image_url'] ?? '' );

		// regular_url / affiliate_url は isset() だけで判定すると、Provider が空文字を
		// 返した場合に既存の保存値を空で上書きしてしまう（isset('') === true のため）。
		// 空文字の fetch 結果では既存値を保持し、非空の場合のみ更新する。
		$fetched_regular        = isset( $fetched['regular_url'] ) ? (string) $fetched['regular_url'] : '';
		$listing['regular_url'] = '' !== $fetched_regular ? $fetched_regular : ( $listing['regular_url'] ?? '' );

		$fetched_affiliate        = isset( $fetched['affiliate_url'] ) ? (string) $fetched['affiliate_url'] : '';
		$listing['affiliate_url'] = '' !== $fetched_affiliate ? $fetched_affiliate : ( $listing['affiliate_url'] ?? '' );
		return $listing;
	}
}
