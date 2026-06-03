<?php
declare(strict_types=1);

namespace Affilicard\Cron;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Settings\GeneralSettings;

/**
 * platform 単位の affilicard_refresh_platform WP-Cron イベントを登録/解除する。
 *
 * グローバル cron_enabled（master）と各 platform の autoRefresh / refreshFrequency を
 * 実スケジュールと突き合わせて差分調整する（reconcile）。hook 引数で platform を識別。
 */
final class RefreshScheduler {

	public const HOOK = 'affilicard_refresh_platform';

	/**
	 * フック配線。$handler は platform code を 1 引数で受ける callable。
	 */
	public static function register( callable $handler ): void {
		add_action( self::HOOK, $handler, 10, 1 );
	}

	public static function reconcile(): void {
		$master = GeneralSettings::isCronEnabled();

		foreach ( PlatformConfig::all() as $definition ) {
			$args    = array( $definition->code );
			$desired = ( $master && $definition->autoRefresh ) ? $definition->refreshFrequency : null;
			$current = wp_get_schedule( self::HOOK, $args );

			if ( null === $desired ) {
				if ( false !== $current ) {
					wp_clear_scheduled_hook( self::HOOK, $args );
				}
				continue;
			}
			if ( false === $current ) {
				wp_schedule_event( time(), $desired, self::HOOK, $args );
				continue;
			}
			if ( $current !== $desired ) {
				wp_clear_scheduled_hook( self::HOOK, $args );
				wp_schedule_event( time(), $desired, self::HOOK, $args );
			}
		}
	}

	public static function clear(): void {
		wp_unschedule_hook( self::HOOK );
	}
}
