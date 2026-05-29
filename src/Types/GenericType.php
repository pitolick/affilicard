<?php
declare(strict_types=1);

namespace Affilicard\Types;

/**
 * 汎用商品タイプ。推奨フィールド（schema）は持たず、ユーザー任意の extras 行のみを受け入れる。
 */
final class GenericType extends AbstractProductType {

	public function code(): string {
		return 'generic';
	}

	public function label(): string {
		return __( '汎用', 'affilicard' );
	}

	/**
	 * @return list<array{key: string, label: string}>
	 */
	public function extrasSchema(): array {
		return array();
	}

	/**
	 * 汎用タイプは provider raw から extras を自動抽出しない。
	 *
	 * @param array<string, mixed> $providerRaw
	 * @return list<array{key?: string, label: string, value: string}>
	 */
	public function extractExtrasFromProvider( string $providerCode, array $providerRaw ): array {
		return array();
	}
}
