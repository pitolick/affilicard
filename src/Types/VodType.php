<?php
declare(strict_types=1);

namespace Affilicard\Types;

/**
 * VOD（動画配信）タイプ。推奨フィールドは監督・出演・配給の 3 つ。
 *
 * 当面は手動入力のみで外部 API provider を持たないため、
 * extractExtrasFromProvider() は常に空配列を返す。
 */
final class VodType extends AbstractProductType {

	public function code(): string {
		return 'vod';
	}

	public function label(): string {
		return __( '動画配信', 'affilicard' );
	}

	/**
	 * @return list<array{key: string, label: string, card?: string}>
	 */
	public function extrasSchema(): array {
		return array(
			array(
				'key'   => 'director',
				'label' => __( '監督', 'affilicard' ),
				'card'  => 'header',
			),
			array(
				'key'   => 'cast',
				'label' => __( '出演', 'affilicard' ),
				'card'  => 'header',
			),
			array(
				'key'   => 'distributor',
				'label' => __( '配給', 'affilicard' ),
				'card'  => 'detail',
			),
		);
	}

	public function cardMediaLabel(): string {
		return __( 'キービジュアル', 'affilicard' );
	}

	/**
	 * VOD は現状 manual のみで provider 連携が無いため常に空配列。
	 *
	 * @param array<string, mixed> $providerRaw
	 * @return list<array{key?: string, label: string, value: string}>
	 */
	public function extractExtrasFromProvider( string $providerCode, array $providerRaw ): array {
		return array();
	}
}
