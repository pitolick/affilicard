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
	 * @return list<array{key: string, label: string, card?: string}>
	 */
	public function extrasSchema(): array;

	/**
	 * 書誌ヘッダに昇格する extras キー（extrasSchema の card='header'）。
	 *
	 * @return list<string>
	 */
	public function cardHeaderKeys(): array;

	/**
	 * カード非表示にする extras キー（extrasSchema の card='hidden'）。
	 *
	 * @return list<string>
	 */
	public function cardHiddenKeys(): array;

	/**
	 * 画像が無い場合のサムネイルプレースホルダ文言。
	 */
	public function cardMediaLabel(): string;

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
