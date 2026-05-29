<?php
/**
 * Affilicard プラグインの uninstall ハンドラ。
 *
 * 管理画面から「削除」を実行された際に WordPress が直接 require する。
 * `Plugin::boot()` は走らないため、autoload を自前で読み込む必要がある。
 *
 * @package Affilicard
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$affilicard_uninstall_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $affilicard_uninstall_autoload ) ) {
	require_once $affilicard_uninstall_autoload;
} else {
	// vendor/ 不在環境用の最小フォールバック。
	require_once __DIR__ . '/src/Uninstall.php';
}

\Affilicard\Uninstall::run();
