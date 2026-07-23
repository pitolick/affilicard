<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * Action Scheduler のジョブ件数を provider（`affilicard-{provider}` group）別に
 * pending / in-progress / failed 集計する。管理画面のキューパネル（Task 15/16）が
 * 表示状態の取得に使う。
 *
 * $providers は自動更新対象 provider コードの配列（呼び出し側 = Plugin が
 * ProviderRegistry::isAutomatic() でフィルタして注入する想定）。QueueStats 自身は
 * provider コードをハードコードしない。
 */
final class QueueStats {

	/**
	 * @param string[] $providers provider コード配列（例: ['rakuten-kobo', 'dmm-ebook']）。
	 */
	public function __construct( private array $providers ) {}

	/**
	 * @return array{pending: int, in_progress: int, failed: int}
	 */
	public function forProvider( string $provider ): array {
		$group = 'affilicard-' . $provider;

		return array(
			'pending'     => $this->countByStatus( $group, 'pending' ),
			'in_progress' => $this->countByStatus( $group, 'in-progress' ),
			'failed'      => $this->countByStatus( $group, 'failed' ),
		);
	}

	/**
	 * @return array<string, array{pending: int, in_progress: int, failed: int}>
	 */
	public function summary(): array {
		$out = array();
		foreach ( $this->providers as $provider ) {
			$out[ $provider ] = $this->forProvider( $provider );
		}
		return $out;
	}

	public function depth(): int {
		$total = 0;
		foreach ( $this->providers as $provider ) {
			$total += $this->forProvider( $provider )['pending'];
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
