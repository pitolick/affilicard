<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * bundle した Action Scheduler をロードする。
 *
 * AS はプラグインファイル require 時点（Plugin::bootInstance() から）で同期的に require する
 * （plugins_loaded へ延期しない）。AS 本体は action-scheduler.php 内で自身の初期化コールバックを
 * plugins_loaded（優先度 0/1）に add_action するが、これは「まだ発火していない plugins_loaded に
 * 新しいコールバックを積む」操作であり、plugins_loaded が発火する前に完了させる必要がある。
 * もし require 自体を plugins_loaded@0 のコールバックとして遅延させると、AS の require はその
 * plugins_loaded@0 バケットのイテレーション“最中”に実行されることになり、AS がそこで新たに
 * add_action する plugins_loaded@0 コールバックは同じイテレーション中のバケットに追加されるため
 * PHP/WP の do_action には拾われず、AS が一切初期化されない（本クラスの旧 register() 実装の不具合）。
 * 複数プラグイン同梱時の最新版自動選択は AS 側の ActionScheduler_Versions が担うため、
 * こちら側は「まだ他プラグインが require していなければ require する」だけでよい。
 */
final class ActionSchedulerLoader {

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
