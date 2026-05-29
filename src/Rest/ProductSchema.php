<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\Stock\StockStatus;

/**
 * `/products` 系エンドポイントの input schema を返す。
 *
 * register_rest_route の args として渡すことを想定。
 */
final class ProductSchema {

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function args(): array {
		return array(
			'title'        => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'content'      => array(
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'wp_kses_post',
			),
			'status'       => array(
				'type'    => 'string',
				'enum'    => array( 'publish', 'draft', 'pending', 'future' ),
				'default' => 'publish',
			),
			'product_type' => array(
				'type'              => 'string',
				'default'           => 'generic',
				'sanitize_callback' => 'sanitize_key',
			),
			'stock_status' => array(
				'type'    => 'string',
				'enum'    => StockStatus::all(),
				'default' => StockStatus::AVAILABLE,
			),
			'extras'       => array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( self::class, 'sanitizeExtras' ),
			),
			'listings'     => array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( self::class, 'sanitizeListings' ),
			),
		);
	}

	/**
	 * Hybrid extras を sanitize する。
	 *
	 * - 各エントリは ['label' => string, 'value' => string, 'key' => string?] を期待
	 * - label / value が両方空のエントリは除外する
	 *
	 * @param mixed $extras
	 * @return list<array<string, string>>
	 */
	public static function sanitizeExtras( $extras ): array {
		if ( ! is_array( $extras ) ) {
			return array();
		}

		$result = array();
		foreach ( $extras as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$label = isset( $entry['label'] ) ? (string) sanitize_text_field( (string) $entry['label'] ) : '';
			$value = isset( $entry['value'] ) ? (string) sanitize_text_field( (string) $entry['value'] ) : '';

			if ( '' === $label && '' === $value ) {
				continue;
			}

			$row = array(
				'label' => $label,
				'value' => $value,
			);

			if ( isset( $entry['key'] ) && '' !== (string) $entry['key'] ) {
				$row['key'] = (string) sanitize_key( (string) $entry['key'] );
			}

			$result[] = $row;
		}

		return $result;
	}

	/**
	 * listings を sanitize する。
	 *
	 * - 各エントリの platform は文字列必須（空文字なら除外）
	 * - enabled / auto_update は bool キャスト
	 * - URL 系は esc_url_raw、文字列フィールドは sanitize_text_field
	 * - 欠損フィールドはデフォルトで補完する
	 *
	 * @param mixed $listings
	 * @return list<array<string, mixed>>
	 */
	public static function sanitizeListings( $listings ): array {
		if ( ! is_array( $listings ) ) {
			return array();
		}

		$result = array();
		foreach ( $listings as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$platform = isset( $entry['platform'] ) ? (string) sanitize_key( (string) $entry['platform'] ) : '';
			if ( '' === $platform ) {
				continue;
			}

			$platform_extras = array();
			if ( isset( $entry['platform_extras'] ) && is_array( $entry['platform_extras'] ) ) {
				foreach ( $entry['platform_extras'] as $k => $v ) {
					if ( ! is_string( $k ) ) {
						continue;
					}
					$platform_extras[ $k ] = is_scalar( $v ) ? (string) $v : '';
				}
			}

			$row = array(
				'platform'              => $platform,
				'enabled'               => isset( $entry['enabled'] ) ? (bool) $entry['enabled'] : true,
				'update_mode'           => isset( $entry['update_mode'] ) ? (string) sanitize_key( (string) $entry['update_mode'] ) : 'auto',
				'auto_update'           => isset( $entry['auto_update'] ) ? (bool) $entry['auto_update'] : true,
				'external_id'           => isset( $entry['external_id'] ) ? (string) sanitize_text_field( (string) $entry['external_id'] ) : '',
				'regular_url'           => isset( $entry['regular_url'] ) ? (string) esc_url_raw( (string) $entry['regular_url'] ) : '',
				'affiliate_url'         => isset( $entry['affiliate_url'] ) ? (string) esc_url_raw( (string) $entry['affiliate_url'] ) : '',
				'price'                 => isset( $entry['price'] ) ? (string) sanitize_text_field( (string) $entry['price'] ) : '',
				'list_price'            => isset( $entry['list_price'] ) ? (string) sanitize_text_field( (string) $entry['list_price'] ) : '',
				'badge'                 => isset( $entry['badge'] ) ? (string) sanitize_text_field( (string) $entry['badge'] ) : '',
				'image_url'             => isset( $entry['image_url'] ) ? (string) esc_url_raw( (string) $entry['image_url'] ) : '',
				'button_label_override' => isset( $entry['button_label_override'] ) ? (string) sanitize_text_field( (string) $entry['button_label_override'] ) : '',
				'last_fetched_at'       => isset( $entry['last_fetched_at'] ) ? (string) sanitize_text_field( (string) $entry['last_fetched_at'] ) : '',
				'fetch_error'           => isset( $entry['fetch_error'] ) ? (string) sanitize_text_field( (string) $entry['fetch_error'] ) : '',
				'platform_extras'       => $platform_extras,
			);

			$result[] = $row;
		}

		return $result;
	}
}
