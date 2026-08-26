<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\Account\AccountRegistry;
use Affilicard\Queue\ActionStoreInterface;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\QueueMaintenance;
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
	 * 1 リクエストで処理する failed action の上限（全 account 横断の合計）。
	 *
	 * deleteFailed/retryFailed は failed が数千件に膨らむと 1 リクエストで全件を同期処理して
	 * PHP のタイムアウト/メモリ枯渇を招く。この上限で 1 リクエストあたりの処理件数を有界にし、
	 * 未処理の failed は次リクエストに残す（レスポンスの remaining で残存を通知）。
	 */
	private const FAILED_BATCH_LIMIT = 100;

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
					// paused を boolean スキーマで受ける。sanitize_callback=rest_sanitize_boolean で
					// 文字列 "false"/"0"/0 も正しく false へ正規化される（素の (bool) キャストだと
					// "false" が truthy になる）。
					'args'                => array(
						'paused' => array(
							'type'              => 'boolean',
							'required'          => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
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
				'summary'                 => $summary,
				'depth'                   => $this->queueStats->depth(),
				'paused'                  => GeneralSettings::isQueuePaused(),
				// cron 健全性の可視化（Task 12）: 最後に掃引が完走した時刻（ISO8601 UTC）。
				// 分割実行の途中（QueueMaintenance::sweep() が false を返す間）は書き換わらない。
				// 一度も完走していなければ空文字列。
				'last_sweep_completed_at' => (string) get_option( QueueMaintenance::OPTION_LAST_COMPLETED, '' ),
			),
			200
		);
	}

	public function pause( WP_REST_Request $request ): WP_REST_Response {
		// register_rest_route の sanitize_callback で正規化済みだが、直接呼び出し経路でも
		// "false"/"0" が truthy 化しないよう rest_sanitize_boolean を明示的に適用して防御する。
		$paused   = (bool) rest_sanitize_boolean( $request->get_param( 'paused' ) );
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
			// 全 account 横断で最大 FAILED_BATCH_LIMIT 件だけ処理して打ち切る（残りは次リクエスト）。
			if ( $deleted >= self::FAILED_BATCH_LIMIT ) {
				break;
			}
			$ids = $this->failedActionIds( $this->group( $account ), self::FAILED_BATCH_LIMIT );
			foreach ( $ids as $id ) {
				if ( $deleted >= self::FAILED_BATCH_LIMIT ) {
					break;
				}
				$this->actionStore->deleteAction( (int) $id );
				++$deleted;
			}
		}

		return new WP_REST_Response(
			array(
				'ok'        => true,
				'deleted'   => $deleted,
				// 上限に達した＝未処理の failed が残っている可能性を示す（再度実行で続きを処理）。
				'remaining' => $deleted >= self::FAILED_BATCH_LIMIT,
			),
			200
		);
	}

	/**
	 * failed action を hook/args そのままで再度 pending として積み直し、元の failed action は
	 * ActionStoreInterface 経由で削除する（キューの表示件数が failed→pending へ正しく移る）。
	 */
	public function retryFailed( WP_REST_Request $request ): WP_REST_Response {
		$retried   = 0;
		$processed = 0;
		foreach ( $this->accountCodes as $account ) {
			// 全 account 横断で最大 FAILED_BATCH_LIMIT 件だけ処理して打ち切る（残りは次リクエスト）。
			if ( $processed >= self::FAILED_BATCH_LIMIT ) {
				break;
			}
			$group   = $this->group( $account );
			$actions = as_get_scheduled_actions(
				array(
					'group'    => $group,
					'status'   => 'failed',
					'per_page' => self::FAILED_BATCH_LIMIT,
				)
			);
			if ( ! is_array( $actions ) ) {
				continue;
			}

			foreach ( $actions as $id => $action ) {
				if ( $processed >= self::FAILED_BATCH_LIMIT ) {
					break;
				}
				++$processed;
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
				'ok'        => true,
				'retried'   => $retried,
				// 上限に達した＝未処理の failed が残っている可能性を示す（再度実行で続きを処理）。
				'remaining' => $processed >= self::FAILED_BATCH_LIMIT,
			),
			200
		);
	}

	private function group( string $account ): string {
		return 'affilicard-' . $account;
	}

	/**
	 * @param int $limit 取得件数の上限（as_get_scheduled_actions の per_page）。バッチ処理で
	 *                   1 リクエストの負荷を有界にするため既定は無制限（-1）ではなく呼び出し側が指定。
	 * @return list<int|string>
	 */
	private function failedActionIds( string $group, int $limit = -1 ): array {
		$ids = as_get_scheduled_actions(
			array(
				'group'    => $group,
				'status'   => 'failed',
				'per_page' => $limit,
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
