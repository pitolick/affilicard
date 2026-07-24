<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * Action Scheduler のジョブ件数を account（`affilicard-{account}` group）別に
 * pending / in-progress / failed / complete 集計する。管理画面のキューパネル（Task 15/16）が
 * 表示状態の取得に使う。complete はオペレータが完了・チャーン量（保持期間で bound される）を
 * 把握するための可視化で、pending だけ見ていると「実は 200+ 完了している」動きが見えない
 * ギャップ（症状4）を埋める。
 *
 * v2.4.0: レート制限・キュー監視は provider 単位ではなく共有 API＝account 単位
 * （認証画面の楽天/DMM と一致）で行う。$accounts は自動更新対象 account コードの配列
 * （呼び出し側 = Plugin が ProviderRegistry から isAutomatic() な provider の
 * accountCode() を重複排除して注入する想定）。QueueStats 自身は account コードを
 * ハードコードしない。
 */
final class QueueStats {

	/**
	 * @param string[] $accounts account コード配列（例: ['rakuten', 'dmm']）。
	 */
	public function __construct( private array $accounts ) {}

	/**
	 * @return array{pending: int, in_progress: int, failed: int, complete: int}
	 */
	public function forAccount( string $account ): array {
		$group = 'affilicard-' . $account;

		return array(
			'pending'     => $this->countByStatus( $group, 'pending' ),
			'in_progress' => $this->countByStatus( $group, 'in-progress' ),
			'failed'      => $this->countByStatus( $group, 'failed' ),
			'complete'    => $this->countByStatus( $group, 'complete' ),
		);
	}

	/**
	 * @return array<string, array{pending: int, in_progress: int, failed: int, complete: int}>
	 */
	public function summary(): array {
		$out = array();
		foreach ( $this->accounts as $account ) {
			$out[ $account ] = $this->forAccount( $account );
		}
		return $out;
	}

	public function depth(): int {
		$total = 0;
		foreach ( $this->accounts as $account ) {
			$total += $this->forAccount( $account )['pending'];
		}
		return $total;
	}

	private function countByStatus( string $group, string $status ): int {
		$ids = as_get_scheduled_actions(
			array(
				'group'    => $group,
				'status'   => $status,
				'per_page' => -1,
			),
			'ids'
		);
		return is_array( $ids ) ? count( $ids ) : 0;
	}
}
