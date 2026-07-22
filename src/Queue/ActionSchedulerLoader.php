<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * bundle した Action Scheduler をロードする。AS は複数プラグイン同梱を想定し
 * 最新版を自動選択するため、plugins_loaded（優先度 0）で functions.php を require するだけでよい。
 */
final class ActionSchedulerLoader {

	public static function register(): void {
		add_action( 'plugins_loaded', array( self::class, 'boot' ), 0 );
	}

	public static function boot(): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$path = self::path();
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}

	public static function path(): string {
		return AFFILICARD_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
	}
}
