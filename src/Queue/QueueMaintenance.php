<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductPostType;
use Affilicard\Repository\ProductRepositoryInterface;

/**
 * 掃引（sweep）: 公開中の全商品を走査し、自動更新対象 listing を Enqueuer 経由で
 * Action Scheduler へ積む。affilicard_refresh_all（全体単一 WP-Cron イベント）から
 * 呼ばれる想定。
 *
 * ここで行うのは enqueue 時点の対象フィルタ（update_mode=auto && enabled && auto_update
 * && platform 定義が既知）のみ。鮮度スキップ（fresh は積まない）・depth cap・jitter は
 * Enqueuer::enqueueSweep() 側の責務。
 *
 * 注意: ListingRefresher::refreshOne()（ハンドラが実行時に呼ぶ）はこの対象フィルタを
 * 再チェックしないため、enqueue 時点でのフィルタリングがここで担保する唯一のゲートになる。
 */
final class QueueMaintenance {

	public function __construct(
		private ProductRepositoryInterface $repository,
		private Enqueuer $enqueuer
	) {}

	public function sweep(): void {
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

		$now = time();
		foreach ( $ids as $id ) {
			$product = $this->repository->find( (int) $id );
			if ( null === $product || ! is_array( $product['listings'] ?? null ) ) {
				continue;
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

				// auto listing のみ（update_mode=auto・enabled・auto_update）。
				$mode    = (string) ( $listing['update_mode'] ?? 'auto' );
				$enabled = ! isset( $listing['enabled'] ) || (bool) $listing['enabled'];
				$auto    = ! isset( $listing['auto_update'] ) || (bool) $listing['auto_update'];
				if ( 'auto' !== $mode || ! $enabled || ! $auto ) {
					continue;
				}

				$this->enqueuer->enqueueSweep( (int) $id, $platform, $def->provider, $def, $listing, $now );
			}
		}
	}
}
