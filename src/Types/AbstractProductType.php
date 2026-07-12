<?php
declare(strict_types=1);

namespace Affilicard\Types;

/**
 * extras の Hybrid validate ロジックを集約する基底クラス。
 *
 * 各 ProductType はこのクラスを継承し code/label/extrasSchema/extractExtrasFromProvider のみ実装する。
 */
abstract class AbstractProductType implements ProductTypeInterface {

	/**
	 * Hybrid 形式: list of `[{label, value, key?}]`。
	 * key は extrasSchema() の key と一致するときだけ保持する。
	 * label または value が空の行は除外する。
	 *
	 * @param mixed $extras
	 * @return list<array{key?: string, label: string, value: string}>
	 */
	public function validateExtras( $extras ): array {
		if ( ! is_array( $extras ) ) {
			return array();
		}

		$schema_keys = array_column( $this->extrasSchema(), 'key' );
		$clean       = array();

		foreach ( $extras as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$label = trim( (string) ( $row['label'] ?? '' ) );
			$value = trim( (string) ( $row['value'] ?? '' ) );
			$key   = isset( $row['key'] ) ? trim( (string) $row['key'] ) : '';

			if ( '' === $label || '' === $value ) {
				continue;
			}

			$entry = array(
				'label' => $label,
				'value' => $value,
			);
			if ( '' !== $key && in_array( $key, $schema_keys, true ) ) {
				$entry['key'] = $key;
			}

			$clean[] = $entry;
		}

		return $clean;
	}

	/**
	 * @return list<string>
	 */
	public function cardHeaderKeys(): array {
		return $this->cardKeysByDisplay( 'header' );
	}

	/**
	 * @return list<string>
	 */
	public function cardHiddenKeys(): array {
		return $this->cardKeysByDisplay( 'hidden' );
	}

	public function cardMediaLabel(): string {
		return __( '商品画像', 'affilicard' );
	}

	public function cardMediaAspectRatio(): string {
		return '1 / 1';
	}

	/**
	 * extrasSchema の card 区分に一致するキー一覧を返す（既定 'detail'）。
	 *
	 * @return list<string>
	 */
	private function cardKeysByDisplay( string $display ): array {
		$keys = array();
		foreach ( $this->extrasSchema() as $field ) {
			if ( ! isset( $field['key'] ) ) {
				continue;
			}
			$card = isset( $field['card'] ) ? (string) $field['card'] : 'detail';
			if ( $card === $display ) {
				$keys[] = (string) $field['key'];
			}
		}
		return $keys;
	}
}
