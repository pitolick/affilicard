<?php
/**
 * Plugin Name: Affilicard
 * Plugin URI:  https://github.com/pitolick/affilicard
 * Description: 汎用アフィリエイト商品カード WordPress プラグイン
 * Version:     3.2.1
 * Author:      pitolick
 * Author URI:  https://github.com/pitolick
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: affilicard
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.8
 *
 * @package Affilicard
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AFFILICARD_VERSION', '3.2.1' );
define( 'AFFILICARD_PLUGIN_FILE', __FILE__ );
define( 'AFFILICARD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AFFILICARD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$affilicard_autoload = AFFILICARD_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $affilicard_autoload ) ) {
	require_once $affilicard_autoload;

	// plugin-update-checker による GitHub Releases 経由の自動更新
	if ( class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
		$affilicard_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/pitolick/affilicard/',
			AFFILICARD_PLUGIN_FILE,
			'affilicard'
		);
		$affilicard_updater->setBranch( 'main' );
		$affilicard_updater->getVcsApi()->enableReleaseAssets();
		$affilicard_updater->allowAutoupdateField();
	}
} else {
	// vendor/ 不在環境（WP Playground 等の git:directory 展開）向けフォールバック。
	// 簡易 PSR-4 autoloader を登録し、CPT 等の基本機能だけは有効化する。自動更新は無効。
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'Affilicard\\';
			if ( strpos( $class_name, $prefix ) !== 0 ) {
				return;
			}
			$relative = substr( $class_name, strlen( $prefix ) );
			$file     = AFFILICARD_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);

	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'Affilicard: vendor/ が見つからないため自動更新機能 (plugin-update-checker) は無効化されています。本番環境では GitHub Release zip をご利用ください。', 'affilicard' );
			echo '</p></div>';
		}
	);
}

\Affilicard\Plugin::boot();
