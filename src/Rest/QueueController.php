<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\Queue\ActionStoreInterface;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\QueueStats;
use Affilicard\Settings\GeneralSettings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/affilicard/v1/refresh-queue` — キュー管理パネル（Task 16）向け REST（manage_options）。
 *
 * $providerCodes は自動更新対象 provider コード（'rakuten-kobo','dmm-ebook' 等・'manual' を除く）。
 * Plugin 側で ProviderRegistry::all() を isAutomatic() でフィルタして注入する（ハードコード禁止）。
 *
 * pending の取消（clearAll/cancelPending）は AS の公開関数 `as_unschedule_all_actions('', [], $group)`
 * のみで完結する（内部的に status=pending のみが対象）。failed action の削除・再投入
 * （deleteFailed/retryFailed）は AS の公開関数に「failed を対象にする」ものが存在しないため、
 * `ActionStoreInterface`（Store API への薄い境界）経由で個別に delete する。
 */
final class QueueController {

	/**
	 * @param list<string> $providerCodes 自動更新対象 provider コード（例: ['rakuten-kobo', 'dmm-ebook']）。
	 */
	public function __construct(
		private QueueStats $queueStats,
		private array $providerCodes,
		private ActionStoreInterface $actionStore
	) {}

	public function registerRoutes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/refresh-queue',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'stats' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'clearAll' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/refresh-queue/pause',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'pause' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/refresh-queue/failed',
			array(
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'deleteFailed' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/refresh-queue/retry-failed',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'retryFailed' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/refresh-queue/cancel-pending',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'cancelPending' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
			)
		);
	}

	public function canManageOptions(): bool {
		return (bool) current_user_can( 'manage_options' );
	}

	public function stats( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'summary' => $this->queueStats->summary(),
				'depth'   => $this->queueStats->depth(),
				'paused'  => GeneralSettings::isQueuePaused(),
			),
			200
		);
	}

	public function pause( WP_REST_Request $request ): WP_REST_Response {
		$paused   = (bool) $request->get_param( 'paused' );
		$settings = GeneralSettings::update( array( 'queue_paused' => $paused ) );

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'paused' => (bool) $settings['queue_paused'],
			),
			200
		);
	}

	/**
	 * 全 provider group の pending action を取り消す（「キューを空にする」操作）。
	 */
	public function clearAll( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'      => true,
				'cleared' => $this->cancelPendingActionsForAllGroups(),
			),
			200
		);
	}

	/**
	 * 全 provider group の pending action を取り消す（clearAll と同じ操作をパネルの
	 * 「pending をキャンセル」ボタン向けに別エンドポイントとして公開する）。
	 */
	public function cancelPending( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'        => true,
				'cancelled' => $this->cancelPendingActionsForAllGroups(),
			),
			200
		);
	}

	public function deleteFailed( WP_REST_Request $request ): WP_REST_Response {
		$deleted = 0;
		foreach ( $this->providerCodes as $provider ) {
			$ids = $this->failedActionIds( $this->group( $provider ) );
			foreach ( $ids as $id ) {
				$this->actionStore->deleteAction( (int) $id );
				++$deleted;
			}
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'deleted' => $deleted,
			),
			200
		);
	}

	/**
	 * failed action を hook/args そのままで再度 pending として積み直し、元の failed action は
	 * ActionStoreInterface 経由で削除する（キューの表示件数が failed→pending へ正しく移る）。
	 */
	public function retryFailed( WP_REST_Request $request ): WP_REST_Response {
		$retried = 0;
		foreach ( $this->providerCodes as $provider ) {
			$group   = $this->group( $provider );
			$actions = as_get_scheduled_actions(
				array(
					'group'    => $group,
					'status'   => 'failed',
					'per_page' => -1,
				)
			);
			if ( ! is_array( $actions ) ) {
				continue;
			}

			foreach ( $actions as $id => $action ) {
				if ( ! is_object( $action ) || ! method_exists( $action, 'get_hook' ) || ! method_exists( $action, 'get_args' ) ) {
					continue;
				}

				$hook = (string) $action->get_hook();
				$args = $action->get_args();
				$args = is_array( $args ) ? $args : array();

				as_schedule_single_action( time(), $hook, $args, $group, true, Enqueuer::PRIORITY_MANUAL );
				$this->actionStore->deleteAction( (int) $id );
				++$retried;
			}
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'retried' => $retried,
			),
			200
		);
	}

	private function group( string $provider ): string {
		return 'affilicard-' . $provider;
	}

	/**
	 * @return list<int|string>
	 */
	private function failedActionIds( string $group ): array {
		$ids = as_get_scheduled_actions(
			array(
				'group'    => $group,
				'status'   => 'failed',
				'per_page' => -1,
			),
			'ids'
		);
		return is_array( $ids ) ? $ids : array();
	}

	private function cancelPendingActionsForAllGroups(): int {
		$count = 0;
		foreach ( $this->providerCodes as $provider ) {
			$group  = $this->group( $provider );
			$ids    = as_get_scheduled_actions(
				array(
					'group'    => $group,
					'status'   => 'pending',
					'per_page' => -1,
				),
				'ids'
			);
			$count += is_array( $ids ) ? count( $ids ) : 0;

			as_unschedule_all_actions( '', array(), $group );
		}
		return $count;
	}
}
