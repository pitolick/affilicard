<?php
declare(strict_types=1);

namespace Affilicard\Types;

/**
 * 管理画面(React)へ渡す商品タイプの一覧。
 *
 * 商品タイプの定義は PHP（{@see ProductTypeRegistry}）だけが持つ。JS はこの一覧を
 * `window.affilicardProductTypes` で受け取り、タイプの選択肢・追加情報の入力欄・
 * タイプ固有の購入リンクの項目（{@see ProductTypeInterface::offerFields()}）を作る。
 * JS 側にタイプを直書きすると、PHP にタイプを足したときに管理画面だけ取り残される
 * （4.4.0 までは動画配信が選べなかった）。
 */
final class ProductTypeUiList {

	/**
	 * @return list<array{code: string, label: string, extrasSchema: list<array{key: string, label: string, placeholder: string}>, offerFields: list<string>}>
	 */
	public static function build( ProductTypeRegistry $registry ): array {
		$list = array();
		foreach ( $registry->all() as $type ) {
			$schema = array();
			foreach ( $type->extrasSchema() as $field ) {
				if ( ! isset( $field['key'], $field['label'] ) ) {
					continue;
				}
				$schema[] = array(
					'key'         => (string) $field['key'],
					'label'       => (string) $field['label'],
					'placeholder' => isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '',
				);
			}
			$list[] = array(
				'code'         => $type->code(),
				'label'        => $type->label(),
				'extrasSchema' => $schema,
				'offerFields'  => array_values( array_map( 'strval', $type->offerFields() ) ),
			);
		}
		return $list;
	}
}
