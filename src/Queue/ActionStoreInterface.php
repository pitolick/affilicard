<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * Action Scheduler の Store API のうち、failed action の削除操作のみを抽象化する契約。
 *
 * as_unschedule_action / as_unschedule_all_actions などの公開関数群は内部的に
 * status=pending でしかクエリしないため、failed action を取り消す・削除するには
 * ActionScheduler::store()->delete_action() のような Store API への直接アクセスが必要になる。
 * これは procedural な `as_*` 関数と異なり WP_Mock の userFunction() では差し替えられない
 * （実クラス呼び出しのため）。QueueController がテスト可能であるよう、この境界を interface として
 * 切り出し、実装（ActionSchedulerStore）は Plugin 配線でのみ生成する。
 */
interface ActionStoreInterface {

	/**
	 * 指定した Action Scheduler の action を完全に削除する（status を問わない）。
	 */
	public function deleteAction( int $actionId ): void;
}
