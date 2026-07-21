<?php
declare(strict_types=1);

namespace Affilicard\Cron;

use Affilicard\Settings\GeneralSettings;

/**
 * 全 platform 共通の affilicard_refresh_all WP-Cron イベントを登録/解除する。
 *
 * グローバル cron_enabled（master）と GeneralSettings::refreshIntervalHours() を
 * 実スケジュールと突き合わせて差分調整する（reconcile）。旧 per-platform hook
 * （affilicard_refresh_platform）は廃止のため master 状態に関わらず常に unschedule する。
 * `cron_schedules` フィルタで間隔ごとの動的スケジュール（affilicard_ivl_{hours}h）を登録する。
 */
final class RefreshScheduler {

	public const HOOK_ALL = 'affilicard_refresh_all';

	/**
	 * 旧 per-platform hook。platform 単位 cron から全体 cron への移行のため
	 * reconcile()/clear() で unschedule するためだけに残す。
	 */
	private const LEGACY_HOOK = 'affilicard_refresh_platform';

	/**
	 * フック配線。$handler は引数を取らない callable（全商品を一括 refresh）。
	 */
	public static function register( callable $handler ): void {
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- interval は addSchedules() 内で管理者設定（refreshIntervalHours、1h 未満は 1h にクランプ）から動的に算出するため静的検知不可。
		add_filter( 'cron_schedules', array( self::class, 'addSchedules' ) );
		add_action( self::HOOK_ALL, $handler );
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
	 * `cron_schedules` フィルタ。GeneralSettings のグローバル間隔を単一登録する。
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules
	 * @return array<string, array{interval:int, display:string}>
	 */
	public static function addSchedules( array $schedules ): array {
		$hour_in_seconds = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;

		$hours = max( 1, GeneralSettings::refreshIntervalHours() );
		$name  = self::scheduleName( $hours );
		if ( ! isset( $schedules[ $name ] ) ) {
			$schedules[ $name ] = array(
				'interval' => $hours * $hour_in_seconds,
				/* translators: %d: hours */
				'display'  => sprintf( __( '%d時間毎（affilicard）', 'affilicard' ), $hours ),
			);
		}
		return $schedules;
	}

	public static function reconcile(): void {
		// 旧 per-platform 方式からの移行: master 状態に関わらず常に旧 hook を解除する。
		wp_unschedule_hook( self::LEGACY_HOOK );

		$master  = GeneralSettings::isCronEnabled();
		$desired = $master ? self::scheduleName( GeneralSettings::refreshIntervalHours() ) : null;
		$current = wp_get_schedule( self::HOOK_ALL, array() );

		if ( null === $desired ) {
			if ( false !== $current ) {
				wp_clear_scheduled_hook( self::HOOK_ALL, array() );
			}
			return;
		}
		if ( false === $current ) {
			wp_schedule_event( time(), $desired, self::HOOK_ALL, array() );
			return;
		}
		if ( $current !== $desired ) {
			wp_clear_scheduled_hook( self::HOOK_ALL, array() );
			wp_schedule_event( time(), $desired, self::HOOK_ALL, array() );
		}
	}

	public static function clear(): void {
		wp_unschedule_hook( self::HOOK_ALL );
		wp_unschedule_hook( self::LEGACY_HOOK );
	}
}
