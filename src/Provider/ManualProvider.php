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

	/**
	 * @param array<string, mixed> $platformConfig
	 * @return array<string, mixed>|null
	 */
	public function fetch( string $externalId, array $platformConfig ): ?array {
		return null;
	}

	/**
	 * @return list<array{key: string, label: string, type: 'text'|'password', required: bool}>
	 */
	public function credentialsSchema(): array {
		return array();
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
}
