<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * ActionStoreInterface の実装。bundle した Action Scheduler の `ActionScheduler::store()`
 * （グローバル名前空間・procedural function ではなくクラス API）に委譲する薄いアダプタ。
 *
 * `ActionScheduler_wpPostStore::delete_action()` は内部で get_post/wp_delete_post を
 * 呼ぶため、実 WordPress 環境（wp-env 等）でのみ動作を確認できる。ユニットテストでは
 * ActionStoreInterface を Mockery でモックして QueueController 側のみを検証する
 * （このクラス自体は薄いアダプタとして未テスト — task-15-report.md に明記）。
 *
 * @codeCoverageIgnore
 */
final class ActionSchedulerStore implements ActionStoreInterface {

	public function deleteAction( int $actionId ): void {
		\ActionScheduler::store()->delete_action( (string) $actionId );
	}
}
