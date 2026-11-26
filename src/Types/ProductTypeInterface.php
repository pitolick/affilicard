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
	 * placeholder は商品編集画面の入力欄の例文（任意）。管理画面はこのスキーマを
	 * そのまま受け取って入力欄を作る（{@see ProductTypeUiList}）。
	 *
	 * @return list<array{key: string, label: string, card?: string, placeholder?: string}>
	 */
	public function extrasSchema(): array;

	/**
	 * このタイプの購入リンク（offer）だけが持つ項目のキー。
	 *
	 * 例: 動画配信の `plan_label`（見放題・レンタルなどの提供形態）。ここに無い項目は、
	 * 保存はされていても商品編集画面に入力欄を出さず、カードにも出さない
	 * （タイプを切り替えても値は消さない）。どのタイプにも共通の項目
	 * （価格・URL・バージョン名の variant_label など）はここに書かない。
	 *
	 * @return list<string>
	 */
	public function offerFields(): array;

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
	 * カードのメディア枠アスペクト比（CSS aspect-ratio 値。例 "2 / 3" / "1 / 1"）。
	 */
	public function cardMediaAspectRatio(): string;

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
