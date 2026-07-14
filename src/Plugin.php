<?php
declare(strict_types=1);

namespace Affilicard;

use Affilicard\Account\AccountRegistry;
use Affilicard\Account\DmmAccount;
use Affilicard\Account\RakutenAccount;
use Affilicard\Block\Block;
use Affilicard\Cron\ListingRefresher;
use Affilicard\Cron\RefreshScheduler;
use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductListColumns;
use Affilicard\PostType\ProductMetaBox;
use Affilicard\PostType\ProductPostType;
use Affilicard\Provider\Dmm\DmmProvider;
use Affilicard\Provider\ManualProvider;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Provider\Rakuten\RakutenProvider;
use Affilicard\Repository\ProductRepository;
use Affilicard\Rest\CardPreviewController;
use Affilicard\Rest\CredentialsController;
use Affilicard\Rest\PlatformsController;
use Affilicard\Rest\ProductsController;
use Affilicard\Rest\RefreshController;
use Affilicard\Rest\RestController;
use Affilicard\Rest\SettingsController;
use Affilicard\Settings\DashboardWidget;
use Affilicard\Types\EbookType;
use Affilicard\Types\GenericType;
use Affilicard\Types\ProductTypeRegistry;
use Affilicard\Types\VodType;

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
		add_action( 'init', array( \Affilicard\PostType\ProductMeta::class, 'register' ) );

		// Gutenberg Block 登録（フロント/エディタ両方で init 時に必要）
		Block::register_hook();

		// 管理画面
		if ( is_admin() ) {
			ProductMetaBox::register();
			ProductListColumns::register();
			$dashboard = new DashboardWidget( new ProductRepository() );
			$dashboard->register();

			add_action( 'admin_menu', array( self::class, 'registerSettingsPage' ) );
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueueSettingsAssets' ) );
		}

		// ProductType レジストリ（Block の type 解決でも buildProductTypeRegistry() を参照）
		$providers = self::buildProviderRegistry();
		self::buildProductTypeRegistry();

		// REST API
		$repository = new ProductRepository();
		$rest       = new RestController(
			new ProductsController( $repository ),
			new SettingsController(),
			new PlatformsController(),
			new CredentialsController( $providers, self::buildAccountRegistry() ),
			new RefreshController( new ListingRefresher( $providers, new ProductRepository() ) ),
			new CardPreviewController( $repository )
		);
		$rest->register();

		add_action(
			'rest_after_insert_' . ProductPostType::POST_TYPE,
			static function ( $post ) {
				if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
					return;
				}
				$post_id = (int) $post->ID;
				// autosave/revision では派生 meta を再構築しない。
				if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
					return;
				}
				( new \Affilicard\Repository\ProductRepository() )->syncDerivedMeta( $post_id );
			},
			10,
			1
		);

		// 価格更新 Cron: platform 単位イベントのハンドラ登録 + 設定との差分調整
		RefreshScheduler::register(
			static function ( $platformCode ): void {
				( new ListingRefresher( self::buildProviderRegistry(), new ProductRepository() ) )->runForPlatform( (string) $platformCode );
			}
		);
		add_action( 'init', array( RefreshScheduler::class, 'reconcile' ) );

		// 予約投稿（future）→ publish 昇格時に最新価格へ refresh
		add_action( 'transition_post_status', array( self::class, 'onTransitionPostStatus' ), 10, 3 );

		// 有効化フック: デフォルト platform を idempotent に seed
		register_activation_hook( AFFILICARD_PLUGIN_FILE, array( self::class, 'onActivate' ) );
		// 無効化フック: WP-Cron スケジュールをすべて解除
		register_deactivation_hook( AFFILICARD_PLUGIN_FILE, array( RefreshScheduler::class, 'clear' ) );
	}

	public static function buildProviderRegistry(): ProviderRegistry {
		$registry = new ProviderRegistry();
		$registry->register( new ManualProvider() );
		$registry->register( new DmmProvider() );
		$registry->register( new RakutenProvider() );
		return $registry;
	}

	public static function buildAccountRegistry(): AccountRegistry {
		$registry = new AccountRegistry();
		$registry->register( new RakutenAccount() );
		$registry->register( new DmmAccount() );
		return $registry;
	}

	public static function buildProductTypeRegistry(): ProductTypeRegistry {
		$registry = new ProductTypeRegistry();
		$registry->register( new GenericType() );
		$registry->register( new EbookType() );
		$registry->register( new VodType() );
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

	/**
	 * 予約投稿（future）が publish に昇格した瞬間に listing を最新価格へ refresh する。
	 *
	 * @param object $post
	 */
	public static function onTransitionPostStatus( string $newStatus, string $oldStatus, $post ): void {
		if ( 'publish' !== $newStatus || 'future' !== $oldStatus ) {
			return;
		}
		if ( ! is_object( $post ) || ! isset( $post->post_type ) || ProductPostType::POST_TYPE !== $post->post_type ) {
			return;
		}
		( new ListingRefresher( self::buildProviderRegistry(), new ProductRepository() ) )->refreshProduct( (int) $post->ID );
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
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style(
			'affilicard-admin-settings',
			AFFILICARD_PLUGIN_URL . 'assets/admin-settings.css',
			array( 'wp-components' ),
			AFFILICARD_VERSION
		);
	}
}
