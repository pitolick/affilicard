<?php
declare(strict_types=1);

namespace Affilicard\Types;

/**
 * 商品タイプ（generic / ebook / vod 等）が実装するインターフェース。
 */
interface ProductTypeInterface {

	/**
	 * 内部コード（例: 'generic', 'ebook', 'vod'）。
	 */
	public function code(): string;

	/**
	 * UI 表示用の日本語ラベル。
	 */
	public function label(): string;

	/**
	 * extras（追加情報）の推奨フィールドのスキーマ。
	 *
	 * @return list<array{key: string, label: string}>
	 */
	public function extrasSchema(): array;

	/**
	 * provider から取得した raw を extras 行（Hybrid 形式）に変換する。
	 *
	 * @param array<string, mixed> $providerRaw
	 * @return list<array{key?: string, label: string, value: string}>
	 */
	public function extractExtrasFromProvider( string $providerCode, array $providerRaw ): array;

	/**
	 * extras を Hybrid 形式に validate / sanitize する。
	 *
	 * @param mixed $extras 任意の入力（list of rows を想定）
	 * @return list<array{key?: string, label: string, value: string}>
	 */
	public function validateExtras( $extras ): array;
}
