<?php
declare(strict_types=1);

namespace Affilicard\Types;

/**
 * VOD（動画配信）タイプ。推奨フィールドは監督・出演・公開年・配給の 4 つ。
 *
 * 公開年は利用側が作品を「タイトル + 公開年」で同定するため、カードにも出せるようにしている。
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
	 * @return list<array{key: string, label: string, card?: string, placeholder?: string}>
	 */
	public function extrasSchema(): array {
		return array(
			array(
				'key'         => 'director',
				'label'       => __( '監督', 'affilicard' ),
				'card'        => 'header',
				'placeholder' => __( '監督名', 'affilicard' ),
			),
			array(
				'key'         => 'cast',
				'label'       => __( '出演', 'affilicard' ),
				'card'        => 'header',
				'placeholder' => __( '例: 俳優A、俳優B', 'affilicard' ),
			),
			array(
				'key'         => 'release_year',
				'label'       => __( '公開年', 'affilicard' ),
				'card'        => 'header',
				'placeholder' => __( '例: 2022', 'affilicard' ),
			),
			array(
				'key'         => 'distributor',
				'label'       => __( '配給', 'affilicard' ),
				'card'        => 'detail',
				'placeholder' => __( '配給会社名', 'affilicard' ),
			),
		);
	}

	public function cardMediaLabel(): string {
		return __( 'キービジュアル', 'affilicard' );
	}

	/**
	 * 提供形態（見放題・レンタル・購入など）のラベルは動画配信だけが持つ。
	 *
	 * @return list<string>
	 */
	public function offerFields(): array {
		return array( 'plan_label' );
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
