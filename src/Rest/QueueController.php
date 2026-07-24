<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\Account\AccountRegistry;
use Affilicard\Queue\ActionStoreInterface;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\QueueStats;
use Affilicard\Settings\GeneralSettings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/affilicard/v1/refresh-queue` — キュー管理パネル（Task 16）向け REST（manage_options）。
 *
 * v2.4.0: provider コード単位から account コード単位へ統一（レート制限は共有 API＝account
 * 単位でかかり、認証画面（楽天/DMM）と一致させるため）。$accountCodes は自動更新対象
 * account コード（'rakuten','dmm' 等）。Plugin 側で ProviderRegistry::all() を
 * isAutomatic() でフィルタし、accountCode() を重複排除して注入する（ハードコード禁止）。
 *
 * pending の取消（clearAll/cancelPending）は AS の公開関数 `as_unschedule_all_actions('', [], $group)`
 * のみで完結する（内部的に status=pending のみが対象）。failed action の削除・再投入
 * （deleteFailed/retryFailed）は AS の公開関数に「failed を対象にする」ものが存在しないため、
 * `ActionStoreInterface`（Store API への薄い境界）経由で個別に delete する。
 */
final class QueueController {

	/**
	 * @param list<string> $accountCodes 自動更新対象 account コード（例: ['rakuten', 'dmm']）。
	 */
	public function __construct(
		private QueueStats $queueStats,
		private array $accountCodes,
		private ActionStoreInterface $actionStore,
		private AccountRegistry $accountRegistry
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

	/**
	 * summary は account コード => { code, label, pending, in_progress, failed } を返す。
	 * code/label を REST 側で埋め込むことで、JS が account コード→表示ラベルの対応表を
	 * ハードコードせずに済む（QueuePanel.jsx はこの label をそのまま描画する）。
	 */
	public function stats( WP_REST_Request $request ): WP_REST_Response {
		$summary = array();
		foreach ( $this->queueStats->summary() as $account => $counts ) {
			$accountDefinition   = $this->accountRegistry->get( $account );
			$summary[ $account ] = array_merge(
				array(
					'code'  => $account,
					'label' => null !== $accountDefinition ? $accountDefinition->label() : $account,
				),
				$counts
			);
		}

		return new WP_REST_Response(
			array(
				'summary' => $summary,
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
	 * 全 account group の pending action を取り消す（「キューを空にする」操作）。
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
	 * 全 account group の pending action を取り消す（clearAll と同じ操作をパネルの
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
		foreach ( $this->accountCodes as $account ) {
			$ids = $this->failedActionIds( $this->group( $account ) );
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
		foreach ( $this->accountCodes as $account ) {
			$group   = $this->group( $account );
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

				// $unique=true のため、同一 hook/args の pending が既に存在すると 0（no-op）が返る。
				// その場合は再試行が実質行われていないので、元の failed action は削除せず残す
				// （次回の retry 対象・表示のどちらからも失われないようにする）。
				$scheduledId = as_schedule_single_action( time(), $hook, $args, $group, true, Enqueuer::PRIORITY_MANUAL );
				if ( empty( $scheduledId ) ) {
					continue;
				}

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

	private function group( string $account ): string {
		return 'affilicard-' . $account;
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
		foreach ( $this->accountCodes as $account ) {
			$group  = $this->group( $account );
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
