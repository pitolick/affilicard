<?php
declare(strict_types=1);

namespace Affilicard;

use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductListColumns;
use Affilicard\PostType\ProductMetaBox;
use Affilicard\PostType\ProductPostType;
use Affilicard\Provider\Dmm\DmmProvider;
use Affilicard\Provider\ManualProvider;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Repository\ProductRepository;
use Affilicard\Rest\CredentialsController;
use Affilicard\Rest\PlatformsController;
use Affilicard\Rest\ProductsController;
use Affilicard\Rest\RestController;
use Affilicard\Rest\SettingsController;
use Affilicard\Settings\DashboardWidget;
use Affilicard\Types\EbookType;
use Affilicard\Types\GenericType;
use Affilicard\Types\ProductTypeRegistry;

/**
 * プラグインのブートストラップ。
 *
 * CPT 登録、管理画面メタボックス/一覧カラム/ダッシュボードウィジェット、
 * REST API、Provider/ProductType レジストリ、有効化フックを配線する。
 */
final class Plugin {

	public const SEEDED_AT_OPTION = 'affilicard_seeded_at';

	public static function boot(): void {
		$instance = new self();
		$instance->bootInstance();
	}

	private function bootInstance(): void {
		// CPT 登録
		add_action( 'init', array( ProductPostType::class, 'register' ) );

		// 管理画面
		if ( is_admin() ) {
			ProductMetaBox::register();
			ProductListColumns::register();
			$dashboard = new DashboardWidget( new ProductRepository() );
			$dashboard->register();

			add_action( 'admin_menu', array( self::class, 'registerSettingsPage' ) );
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueueSettingsAssets' ) );
		}

		// Provider / ProductType レジストリ (ProductTypeRegistry は将来 Block/Render で参照予定)
		$providers = self::buildProviderRegistry();
		self::buildProductTypeRegistry();

		// REST API
		$rest = new RestController(
			new ProductsController( new ProductRepository() ),
			new SettingsController(),
			new PlatformsController(),
			new CredentialsController( $providers )
		);
		$rest->register();

		// 有効化フック: デフォルト platform を idempotent に seed
		register_activation_hook( AFFILICARD_PLUGIN_FILE, array( self::class, 'onActivate' ) );
	}

	public static function buildProviderRegistry(): ProviderRegistry {
		$registry = new ProviderRegistry();
		$registry->register( new ManualProvider() );
		$registry->register( new DmmProvider() );
		return $registry;
	}

	public static function buildProductTypeRegistry(): ProductTypeRegistry {
		$registry = new ProductTypeRegistry();
		$registry->register( new GenericType() );
		$registry->register( new EbookType() );
		return $registry;
	}

	public static function onActivate(): void {
		if ( false !== get_option( self::SEEDED_AT_OPTION, false ) ) {
			return;
		}
		PlatformConfig::save( PlatformConfig::defaults() );
		update_option( self::SEEDED_AT_OPTION, gmdate( 'c' ), false );
	}

	public static function registerSettingsPage(): void {
		add_submenu_page(
			'edit.php?post_type=' . ProductPostType::POST_TYPE,
			__( 'Affilicard 設定', 'affilicard' ),
			__( '設定', 'affilicard' ),
			'manage_options',
			'affilicard-settings',
			array( self::class, 'renderSettingsPage' )
		);
	}

	public static function renderSettingsPage(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Affilicard 設定', 'affilicard' ) . '</h1>';
		echo '<div id="affilicard-settings-root"></div></div>';
	}

	public static function enqueueSettingsAssets( string $hook ): void {
		if ( false === strpos( $hook, 'affilicard-settings' ) ) {
			return;
		}

		$asset_file = AFFILICARD_PLUGIN_DIR . 'build/settings.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => AFFILICARD_VERSION,
			);

		wp_enqueue_script(
			'affilicard-settings',
			AFFILICARD_PLUGIN_URL . 'build/settings.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( 'affilicard-settings', 'affilicard' );
	}
}
