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
		$now          = (string) current_time( 'c' );

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

		$listing['fetch_error']      = '';
		$listing['last_verified_at'] = $now;
		$listing['price']            = isset( $fetched['price'] ) ? (string) $fetched['price'] : ( $listing['price'] ?? '' );
		$listing['list_price']       = isset( $fetched['list_price'] ) ? (string) $fetched['list_price'] : ( $listing['list_price'] ?? '' );
		$listing['badge']            = isset( $fetched['badge'] ) ? (string) $fetched['badge'] : ( $listing['badge'] ?? '' );
		$listing['image_url']        = isset( $fetched['image_url'] ) ? (string) $fetched['image_url'] : ( $listing['image_url'] ?? '' );
		$listing['regular_url']      = isset( $fetched['regular_url'] ) ? (string) $fetched['regular_url'] : ( $listing['regular_url'] ?? '' );
		$listing['affiliate_url']    = isset( $fetched['affiliate_url'] ) ? (string) $fetched['affiliate_url'] : ( $listing['affiliate_url'] ?? '' );
		return $listing;
	}
}
