<?php
declare(strict_types=1);

namespace Affilicard\Cron;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Settings\GeneralSettings;

/**
 * platform 単位の affilicard_refresh_platform WP-Cron イベントを登録/解除する。
 *
 * グローバル cron_enabled（master）と各 platform の autoRefresh / refreshIntervalHours を
 * 実スケジュールと突き合わせて差分調整する（reconcile）。hook 引数で platform を識別。
 * `cron_schedules` フィルタで interval ごとの動的スケジュール（affilicard_ivl_{hours}h）を登録する。
 */
final class RefreshScheduler {

	public const HOOK = 'affilicard_refresh_platform';

	/**
	 * フック配線。$handler は platform code を 1 引数で受ける callable。
	 */
	public static function register( callable $handler ): void {
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- interval は addSchedules() 内で管理者設定（refreshIntervalHours、1h 未満は 1h にクランプ）から動的に算出するため静的検知不可。
		add_filter( 'cron_schedules', array( self::class, 'addSchedules' ) );
		add_action( self::HOOK, $handler, 10, 1 );
	}

	/**
	 * refreshIntervalHours から WP-Cron のカスタムスケジュール名を作る。
	 *
	 * 1 未満は 1 にクランプする（0 以下の入力は無限ループ的スケジュールになり得るため）。
	 */
	public static function scheduleName( int $hours ): string {
		return 'affilicard_ivl_' . max( 1, $hours ) . 'h';
	}

	/**
	 * `cron_schedules` フィルタ。auto-refresh 対象 platform が使用する間隔をすべて登録する。
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules
	 * @return array<string, array{interval:int, display:string}>
	 */
	public static function addSchedules( array $schedules ): array {
		$hour_in_seconds = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;

		foreach ( PlatformConfig::all() as $definition ) {
			if ( ! $definition->autoRefresh ) {
				continue;
			}
			$hours = max( 1, $definition->refreshIntervalHours );
			$name  = self::scheduleName( $hours );
			if ( ! isset( $schedules[ $name ] ) ) {
				$schedules[ $name ] = array(
					'interval' => $hours * $hour_in_seconds,
					/* translators: %d: hours */
					'display'  => sprintf( __( '%d時間毎（affilicard）', 'affilicard' ), $hours ),
				);
			}
		}
		return $schedules;
	}

	public static function reconcile(): void {
		$master = GeneralSettings::isCronEnabled();

		foreach ( PlatformConfig::all() as $definition ) {
			$args    = array( $definition->code );
			$desired = ( $master && $definition->autoRefresh )
				? self::scheduleName( $definition->refreshIntervalHours )
				: null;
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
