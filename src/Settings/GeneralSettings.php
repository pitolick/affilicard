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
		'cache_ttl_seconds'      => 86400,
		'default_product_type'   => 'generic',
		// 既定 ON: 自動更新は本プラグインの中核（価格を規約準拠の 24h 以内に保つ）。新規 install で
		// 自動取得プロバイダ未設定なら掃引は何も積まず無害（空回りの WP-Cron イベントのみ）。既に
		// cron_enabled を保存済みのサイトはこの既定変更の影響を受けない（保存値が優先）。
		'cron_enabled'           => true,
		'refresh_interval_hours' => 3,
		'schema_version'         => 2,
		'queue_paused'           => false,
		'queue_depth_cap'        => 500,
		'throttle_overrides'     => array(),
		'retention_done_hours'   => 24,
		'retention_failed_days'  => 7,
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

	public static function isCronEnabled(): bool {
		$settings = self::get();
		return ! empty( $settings['cron_enabled'] );
	}

	public static function refreshIntervalHours(): int {
		$settings = self::get();
		return (int) $settings['refresh_interval_hours'];
	}

	public static function isQueuePaused(): bool {
		return ! empty( self::get()['queue_paused'] );
	}

	public static function queueDepthCap(): int {
		return (int) self::get()['queue_depth_cap'];
	}

	/**
	 * v2.4.0: throttle_overrides は account コード（例: 'rakuten', 'dmm'）で引く
	 * （レート制限は provider ではなく共有 API＝account 単位でかかるため）。
	 */
	public static function throttleOverrideMs( string $account ): int {
		$ov = self::get()['throttle_overrides'];
		return is_array( $ov ) && isset( $ov[ $account ] ) ? max( 0, (int) $ov[ $account ] ) : 0;
	}

	public static function retentionDoneHours(): int {
		return (int) self::get()['retention_done_hours'];
	}

	public static function retentionFailedDays(): int {
		return (int) self::get()['retention_failed_days'];
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

		$refresh_interval_hours = isset( $values['refresh_interval_hours'] ) ? (int) $values['refresh_interval_hours'] : (int) self::DEFAULTS['refresh_interval_hours'];
		if ( $refresh_interval_hours < 1 ) {
			$refresh_interval_hours = (int) self::DEFAULTS['refresh_interval_hours'];
		}

		$schema_version = isset( $values['schema_version'] ) ? (int) $values['schema_version'] : (int) self::DEFAULTS['schema_version'];

		$queue_paused    = ! empty( $values['queue_paused'] );
		$queue_depth_cap = isset( $values['queue_depth_cap'] ) ? max( 1, (int) $values['queue_depth_cap'] ) : self::DEFAULTS['queue_depth_cap'];

		$overrides_raw      = isset( $values['throttle_overrides'] ) && is_array( $values['throttle_overrides'] ) ? $values['throttle_overrides'] : array();
		$throttle_overrides = array();
		foreach ( $overrides_raw as $account => $ms ) {
			$throttle_overrides[ (string) $account ] = max( 0, (int) $ms );
		}

		$retention_done_hours  = isset( $values['retention_done_hours'] ) ? max( 1, (int) $values['retention_done_hours'] ) : self::DEFAULTS['retention_done_hours'];
		$retention_failed_days = isset( $values['retention_failed_days'] ) ? max( 1, (int) $values['retention_failed_days'] ) : self::DEFAULTS['retention_failed_days'];

		return array(
			'cache_ttl_seconds'      => $ttl,
			'default_product_type'   => $type,
			'cron_enabled'           => $cron_enabled,
			'refresh_interval_hours' => $refresh_interval_hours,
			'schema_version'         => $schema_version,
			'queue_paused'           => $queue_paused,
			'queue_depth_cap'        => $queue_depth_cap,
			'throttle_overrides'     => $throttle_overrides,
			'retention_done_hours'   => $retention_done_hours,
			'retention_failed_days'  => $retention_failed_days,
		);
	}
}
