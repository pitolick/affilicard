<?php
declare(strict_types=1);

namespace Affilicard\Settings;

/**
 * `affilicard_general` オプションを読み書きする。
 *
 * オプションが未設定の場合は DEFAULTS を返す。update() は部分更新（merge）として動作する。
 */
final class GeneralSettings {

	public const OPTION_KEY = 'affilicard_general';

	public const DEFAULTS = array(
		'cache_ttl_seconds'    => 86400,
		'default_product_type' => 'generic',
		'cron_enabled'         => false,
		'schema_version'       => 1,
	);

	private const MIN_TTL = 60;
	private const MAX_TTL = 2592000;

	/**
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::merge( $stored );
	}

	/**
	 * 部分更新する。値ごとに sanitize した上で current と merge し、update_option で保存する。
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	public static function update( array $values ): array {
		$current = self::get();

		$sanitized = self::sanitize( array_merge( $current, $values ) );

		update_option( self::OPTION_KEY, $sanitized, false );

		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $stored
	 * @return array<string, mixed>
	 */
	private static function merge( array $stored ): array {
		$merged = self::DEFAULTS;
		foreach ( self::DEFAULTS as $key => $_default ) {
			if ( array_key_exists( $key, $stored ) ) {
				$merged[ $key ] = $stored[ $key ];
			}
		}
		return self::sanitize( $merged );
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private static function sanitize( array $values ): array {
		$ttl = isset( $values['cache_ttl_seconds'] ) ? (int) $values['cache_ttl_seconds'] : self::DEFAULTS['cache_ttl_seconds'];
		if ( $ttl < self::MIN_TTL ) {
			$ttl = self::MIN_TTL;
		}
		if ( $ttl > self::MAX_TTL ) {
			$ttl = self::MAX_TTL;
		}

		$type = isset( $values['default_product_type'] ) ? (string) $values['default_product_type'] : self::DEFAULTS['default_product_type'];
		if ( ! in_array( $type, array( 'generic', 'ebook' ), true ) ) {
			$type = self::DEFAULTS['default_product_type'];
		}

		$cron_enabled = isset( $values['cron_enabled'] ) ? (bool) $values['cron_enabled'] : (bool) self::DEFAULTS['cron_enabled'];

		$schema_version = isset( $values['schema_version'] ) ? (int) $values['schema_version'] : (int) self::DEFAULTS['schema_version'];

		return array(
			'cache_ttl_seconds'    => $ttl,
			'default_product_type' => $type,
			'cron_enabled'         => $cron_enabled,
			'schema_version'       => $schema_version,
		);
	}
}
