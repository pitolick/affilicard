<?php
declare(strict_types=1);

namespace Affilicard\Upgrade;

/**
 * プラグインのバージョン移行ルーチン。
 *
 * `register_activation_hook` は WordPress の自動更新・管理画面からの更新では
 * 実行されない。有効化したまま更新したサイトでも初期化処理が確実に走るよう、
 * `plugins_loaded` で保存済みバージョンと現在バージョンを比較して差分処理を行う。
 */
final class PluginUpgrade {

	public const OPTION_VERSION = 'affilicard_plugin_version';

	/** 棚卸し基準日。最終掲載日を持たない既存商品の判定基準になる（spec §5-3）。 */
	public const OPTION_STOCKTAKE_BASELINE = 'affilicard_stocktake_baseline';

	public static function maybeUpgrade( string $currentVersion ): void {
		$stored = (string) get_option( self::OPTION_VERSION, '' );
		if ( $stored === $currentVersion ) {
			return;
		}

		// 棚卸し基準日は「無ければ作る」。既にあるサイトでは絶対に書き換えない
		// （更新のたびにリセットされると棚卸しが永久に発動しない）。
		add_option( self::OPTION_STOCKTAKE_BASELINE, gmdate( 'c' ), '', false );

		update_option( self::OPTION_VERSION, $currentVersion, false );
	}
}
