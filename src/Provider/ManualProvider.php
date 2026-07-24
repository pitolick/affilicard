<?php
declare(strict_types=1);

namespace Affilicard\Provider;

/**
 * 手動入力 Provider。管理画面のフォームに直接価格・URL を入力する運用に使う。
 */
final class ManualProvider implements ProviderInterface {

	public function code(): string {
		return 'manual';
	}

	public function label(): string {
		return __( '手動入力', 'affilicard' );
	}

	public function isAutomatic(): bool {
		return false;
	}

	public function accountCode(): ?string {
		return null;
	}

	/**
	 * 手動入力 Provider は自動取得経路（isAutomatic=false のため通常呼ばれない）に乗らない。
	 * 万一到達しても give-up させないよう、安全側で一時失敗（error/transient）を返す
	 * （miss を返すと terminal 扱いで give-up マーカーが立ってしまうため使わない）。
	 *
	 * @param array<string, mixed> $platformConfig
	 */
	public function fetch( string $externalId, array $platformConfig ): FetchResult {
		return FetchResult::error();
	}

	/**
	 * @param array<string, string> $credentials
	 * @return array{ok: bool, message: string}
	 */
	public function testConnection( array $credentials ): array {
		return array(
			'ok'      => true,
			'message' => __( '手動入力 Provider です（疎通確認不要）', 'affilicard' ),
		);
	}

	public function minRequestIntervalMs(): int {
		return 0;
	}
}
