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
}
