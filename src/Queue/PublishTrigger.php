<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Pricing\ListingEligibility;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Repository\ProductRepositoryInterface;

/**
 * 記事（投稿）の公開/更新をトリガーに、本文中の `affilicard/product-card` ブロックが
 * 参照する商品の auto listing を Enqueuer 経由で force 投入する。
 *
 * 対象は「draft/future → publish」の昇格（transition_post_status）と、
 * 「publish のまま再保存」（post_updated）の 2 経路。ここで解決するのはブロック属性
 * から既存商品を特定するところまでで、AutoCreate は行わない（未登録商品は無視する）。
 *
 * enqueue 対象フィルタ（update_mode=auto && enabled && auto_update && platform 定義が
 * 既知）は QueueMaintenance::sweep() と同じ判定をここでも独立して行う
 * （ListingRefresher::refreshOne() は再チェックしないため、enqueue 側が唯一のゲート）。
 */
final class PublishTrigger {

	public function __construct(
		private ProductRepositoryInterface $repository,
		private Enqueuer $enqueuer,
		private ProviderRegistry $providerRegistry
	) {}

	/**
	 * `transition_post_status` フック: publish 昇格時のみ syncPost する。
	 */
	public function onTransition( string $newStatus, string $oldStatus, \WP_Post $post ): void {
		if ( 'publish' !== $newStatus ) {
			return;
		}
		$this->syncPost( $post );
	}

	/**
	 * `post_updated` フック: publish → publish（公開済みの再保存）のみ syncPost する。
	 * draft → publish はここでは扱わない（transition_post_status/onTransition の責務）。
	 */
	public function onUpdated( int $postId, \WP_Post $after, \WP_Post $before ): void {
		if ( 'publish' !== $after->post_status || 'publish' !== $before->post_status ) {
			return;
		}
		$this->syncPost( $after );
	}

	private function syncPost( \WP_Post $post ): void {
		$postId = (int) $post->ID;
		if ( wp_is_post_autosave( $postId ) || wp_is_post_revision( $postId ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		foreach ( $this->resolveProductIds( (string) $post->post_content ) as $productId ) {
			$this->forceEnqueueEligibleListings( $productId );
		}
	}

	private function forceEnqueueEligibleListings( int $productId ): void {
		$product = $this->repository->find( $productId );
		if ( null === $product || ! is_array( $product['listings'] ?? null ) ) {
			return;
		}

		foreach ( $product['listings'] as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$platform = (string) ( $listing['platform'] ?? '' );
			$def      = PlatformConfig::find( $platform );
			if ( null === $def ) {
				continue;
			}

			// auto listing のみ force 投入する（update_mode=auto・enabled・auto_update）。
			// QueueMaintenance::sweep() の対象フィルタと同一の判定。
			if ( ! ListingEligibility::isAutoEligible( $listing ) ) {
				continue;
			}

			// v2.4.0: enqueueForced の group も account コード単位。account が解決できない
			// （provider 未登録、または手動系で accountCode() が null）listing は積まない。
			$account = $this->providerRegistry->get( $def->provider )?->accountCode();
			if ( null === $account ) {
				continue;
			}

			$this->enqueuer->enqueueForced( $productId, $platform, $account );
		}
	}

	/**
	 * 本文を parse_blocks し、`affilicard/product-card` ブロックが参照する既存商品の
	 * post_id 一覧を解決する（テスト容易化のため public 抽出）。AutoCreate は行わない
	 * ため、未登録の商品を参照するブロックは結果から除外される。
	 *
	 * @return list<int>
	 */
	public function resolveProductIds( string $content ): array {
		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) ) {
			return array();
		}

		$ids = array();
		foreach ( $this->collectProductCardBlocks( $blocks ) as $block ) {
			$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$product = $this->resolveProduct( $attrs );
			if ( null !== $product && isset( $product['id'] ) ) {
				$ids[] = (int) $product['id'];
			}
		}
		return $ids;
	}

	/**
	 * `affilicard/product-card` ブロックを innerBlocks も含めて再帰的に集める。
	 *
	 * @param array<int, mixed> $blocks
	 * @return list<array<string, mixed>>
	 */
	private function collectProductCardBlocks( array $blocks ): array {
		$found = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( 'affilicard/product-card' === ( $block['blockName'] ?? null ) ) {
				$found[] = $block;
			}

			$inner = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
			if ( array() !== $inner ) {
				$found = array_merge( $found, $this->collectProductCardBlocks( $inner ) );
			}
		}
		return $found;
	}

	/**
	 * ブロック属性から既存商品を解決する。優先順位は Block::resolveProduct() と同じ
	 * （productId → slug → externalId+platform）だが、AutoCreate は行わない。
	 *
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>|null
	 */
	private function resolveProduct( array $attrs ): ?array {
		$productId = isset( $attrs['productId'] ) ? (int) $attrs['productId'] : 0;
		if ( $productId > 0 ) {
			return $this->repository->find( $productId );
		}

		$slug = isset( $attrs['slug'] ) ? trim( (string) $attrs['slug'] ) : '';
		if ( '' !== $slug ) {
			return $this->repository->findBySlug( $slug );
		}

		$externalId = isset( $attrs['externalId'] ) ? trim( (string) $attrs['externalId'] ) : '';
		$platform   = isset( $attrs['platform'] ) ? trim( (string) $attrs['platform'] ) : '';
		if ( '' !== $externalId && '' !== $platform ) {
			return $this->repository->findByExternalId( $platform, $externalId );
		}

		return null;
	}
}
