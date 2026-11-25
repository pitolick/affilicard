<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\PostType\ProductPostType;
use Affilicard\Queue\BatchSizer;
use Affilicard\Queue\Enqueuer;
use Affilicard\Repository\ProductRepositoryInterface;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/affilicard/v1/refresh` — 価格更新の手動トリガー（manage_options）。
 *
 * v2.4.0: 同期 ListingRefresher::run()/runForPlatform() から
 * Enqueuer::enqueueProductListings() 経由の enqueue（manual 経路・priority 10）へ変更した
 * （spec §2 要件1「手動ボタンは即返し」）。実際の fetch/保存は Action Scheduler 側の
 * RefreshHandler（Enqueuer::HOOK_REFRESH）が担う。ここでは enqueue 件数を即座に返すのみ。
 *
 * platform 未指定なら全公開商品、指定なら該当 platform の listing のみ enqueue する。
 * enqueue 対象は ELIGIBLE listing（update_mode=auto && enabled && ( force || auto_update )）
 * のみ（Enqueuer::enqueueProductListings と同一の判定）。`force` request param は旧
 * ListingRefresher::run($force) 由来の挙動（管理画面「強制更新」ボタン）で、
 * auto_update=false（手動上書き中）の listing も対象に含める。
 *
 * まとめ取得できる Provider（BatchFetchingProvider。Amazon 等）の account は、1 件ずつの
 * ジョブではなく掃引と同じバッチジョブ（Enqueuer::HOOK_REFRESH_BATCH・手動の優先度）に積む。
 * 1 件ずつのジョブは別々のリクエストで走るため、まとめ取得が効かず API 呼び出しが件数ぶん
 * 発生する（Amazon Creators API は 1 日の上限が売上に比例するため、10 倍の差が効く）。
 */
final class RefreshController {

	public function __construct(
		private ProductRepositoryInterface $repository,
		private Enqueuer $enqueuer,
		/** null なら従来どおり全件を 1 件ずつのジョブに積む。 */
		private ?BatchSizer $batchSizer = null
	) {}

	public function registerRoutes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/refresh',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
			)
		);
	}

	public function canManageOptions(): bool {
		return (bool) current_user_can( 'manage_options' );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$platform = (string) $request->get_param( 'platform' );
		$scope    = '' === $platform ? 'all' : $platform;
		$force    = (bool) $request->get_param( 'force' );

		$queued = $this->enqueueEligibleListings( '' !== $platform ? $platform : null, $force );

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'scope'  => $scope,
				'queued' => $queued,
				'force'  => $force,
			),
			200
		);
	}

	/**
	 * 公開中の全商品を走査し、ELIGIBLE な auto listing を manual enqueue する。
	 * $onlyPlatform が指定されていれば、その platform の listing のみを対象にする。
	 * $force=true の場合は auto_update=false の listing も対象に含める
	 * （管理画面「強制更新」ボタン用。旧 ListingRefresher::run($force) の挙動を踏襲）。
	 */
	private function enqueueEligibleListings( ?string $onlyPlatform, bool $force ): int {
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
			return 0;
		}

		$queued = 0;
		/** @var array<string, list<array{post_id: int, platform: string}>> $batchItems account => まとめて積む listing */
		$batchItems = array();
		foreach ( $ids as $id ) {
			$product = $this->repository->find( (int) $id );
			if ( null === $product ) {
				continue;
			}

			if ( null !== $onlyPlatform ) {
				$listings            = is_array( $product['listings'] ?? null ) ? $product['listings'] : array();
				$product['listings'] = array_values(
					array_filter(
						$listings,
						static fn( $listing ) => is_array( $listing ) && ( $listing['platform'] ?? '' ) === $onlyPlatform
					)
				);
			}

			foreach ( $this->enqueuer->eligibleTargets( $product, $force ) as $target ) {
				++$queued;
				if ( null !== $this->batchSizer && $this->batchSizer->isBatchFetching( $target['account'] ) ) {
					$batchItems[ $target['account'] ][] = array(
						'post_id'  => (int) $id,
						'platform' => $target['platform'],
					);
					continue;
				}
				$this->enqueuer->enqueueManual( (int) $id, $target['platform'], $target['account'] );
			}
		}

		foreach ( $batchItems as $account => $items ) {
			$size = max( 1, $this->batchSizer->sizeFor( (string) $account ) );
			foreach ( array_chunk( $items, $size ) as $chunk ) {
				$actionId = $this->enqueuer->enqueueBatch( (string) $account, $chunk, 0, true, Enqueuer::PRIORITY_MANUAL );
				if ( 0 === $actionId ) {
					// 同じ items が既に積まれている（unique）か、登録に失敗した。後者は AS が例外を
					// 握り潰して 0 を返すだけなので、ここで記録しないと気づけない。
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'affilicard: 手動更新のバッチを積めませんでした（account=%s, items=%d件）。同じ内容が既に積まれているか、登録に失敗しています。',
							(string) $account,
							count( $chunk )
						)
					);
				}
			}
		}

		return $queued;
	}
}
