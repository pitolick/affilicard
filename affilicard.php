<?php
/**
 * Plugin Name: Affilicard
 * Plugin URI:  https://github.com/pitolick/affilicard
 * Description: 汎用アフィリエイト商品カード WordPress プラグイン
 * Version:     0.1.0
 * Author:      pitolick
 * Author URI:  https://github.com/pitolick
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
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

define( 'AFFILICARD_VERSION', '0.1.0' );
define( 'AFFILICARD_PLUGIN_FILE', __FILE__ );
define( 'AFFILICARD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AFFILICARD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$affilicard_autoload = AFFILICARD_PLUGIN_DIR . 'vendor/autoload.php';
if ( ! file_exists( $affilicard_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Affilicard: composer install を実行してください', 'affilicard' );
			echo '</p></div>';
		}
	);
	return;
}
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

\Affilicard\Plugin::boot();
