<?php
declare(strict_types=1);

namespace Affilicard\Cron;

use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductPostType;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Repository\ProductRepositoryInterface;

/**
 * 公開中の商品 listing を Provider 経由で再取得し価格等を更新する。
 *
 * 対象 listing は update_mode='auto' && auto_update=true && enabled
 * （force=true のときは auto_update を無視）。post_status が publish 以外はスキップ。
 */
class ListingRefresher {

	public function __construct(
		private ProviderRegistry $registry,
		private ProductRepositoryInterface $repository
	) {}

	public function run( bool $force = false ): void {
		$this->forEachPublished( null, $force );
	}

	public function runForPlatform( string $platformCode, bool $force = false ): void {
		if ( '' === $platformCode ) {
			return;
		}
		$this->forEachPublished( $platformCode, $force );
	}

	private function forEachPublished( ?string $onlyPlatform, bool $force ): void {
		$ids = get_posts(
			array(
				'post_type'      => ProductPostType::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		if ( ! is_array( $ids ) ) {
			return;
		}
		foreach ( $ids as $id ) {
			$this->refreshProduct( (int) $id, $onlyPlatform, $force );
		}
	}

	public function refreshProduct( int $postId, ?string $onlyPlatform = null, bool $force = false ): void {
		$product = $this->repository->find( $postId );
		if ( null === $product || ! is_array( $product['listings'] ?? null ) ) {
			return;
		}

		$changed  = false;
		$listings = $product['listings'];
		foreach ( $listings as $index => $listing ) {
			if ( ! is_array( $listing ) || ! $this->isListingEligible( $listing, $force ) ) {
				continue;
			}
			if ( null !== $onlyPlatform && ( $listing['platform'] ?? '' ) !== $onlyPlatform ) {
				continue;
			}
			$listings[ $index ] = $this->refreshListing( $listing, (string) $product['title'] );
			$changed            = true;
		}

		if ( $changed ) {
			$this->saveProduct( $postId, $product, $listings );
		}
	}

	/**
	 * 指定 platform の listing を1件 fetch→反映し保存する。
	 *
	 * 既存 refreshListing() を再利用（force 相当・throttle はハンドラ側で担保済みの前提）。
	 * 商品または該当 platform の listing が見つからない場合は false。
	 */
	public function refreshOne( int $postId, string $platform ): bool {
		$product = $this->repository->find( $postId );
		if ( null === $product || ! is_array( $product['listings'] ?? null ) ) {
			return false;
		}
		$listings = $product['listings'];
		foreach ( $listings as $index => $listing ) {
			if ( ! is_array( $listing ) || ( $listing['platform'] ?? '' ) !== $platform ) {
				continue;
			}
			$refreshed          = $this->refreshListing( $listing, (string) $product['title'] );
			$listings[ $index ] = $refreshed;
			$this->saveProduct( $postId, $product, $listings );
			return '' === (string) ( $refreshed['fetch_error'] ?? '' );
		}
		return false;
	}

	/**
	 * 商品と更新後 listings を Repository 形に組んで保存する（refreshProduct/refreshOne 共用）。
	 *
	 * @param array<string, mixed>       $product  Repository::find() の戻り
	 * @param list<array<string, mixed>> $listings 更新後 listing 群
	 */
	private function saveProduct( int $postId, array $product, array $listings ): void {
		$this->repository->save(
			array(
				'id'           => $postId,
				'title'        => (string) $product['title'],
				'content'      => (string) $product['content'],
				'status'       => (string) $product['status'],
				'product_type' => (string) $product['product_type'],
				'stock_status' => (string) $product['stock_status'],
				'extras'       => $product['extras'],
				'listings'     => array_values( $listings ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $listing
	 */
	private function isListingEligible( array $listing, bool $force = false ): bool {
		$mode    = isset( $listing['update_mode'] ) ? (string) $listing['update_mode'] : 'auto';
		$auto    = ! isset( $listing['auto_update'] ) || (bool) $listing['auto_update'];
		$enabled = ! isset( $listing['enabled'] ) || (bool) $listing['enabled'];
		if ( 'auto' !== $mode || ! $enabled ) {
			return false;
		}
		return $force ? true : $auto;
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
