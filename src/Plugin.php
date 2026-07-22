<?php
declare(strict_types=1);

namespace Affilicard;

use Affilicard\Account\AccountRegistry;
use Affilicard\Account\AccountUiList;
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
use Affilicard\Provider\ProviderUiList;
use Affilicard\Provider\Rakuten\RakutenProvider;
use Affilicard\Queue\ActionSchedulerLoader;
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
		// bundle した Action Scheduler をロード（plugins_loaded 優先度 0）
		ActionSchedulerLoader::register();

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
			add_action( 'admin_init', array( self::class, 'purgeLegacyProviderCredentials' ) );
			add_action( 'admin_init', array( self::class, 'backfillEligibleProviders' ) );
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

		// 価格更新 Cron: 全体単一イベントのハンドラ登録 + 設定との差分調整
		RefreshScheduler::register(
			static function (): void {
				( new ListingRefresher( self::buildProviderRegistry(), new ProductRepository() ) )->run();
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

	/**
	 * `affilicard_provider_<code>_credentials` 形式の旧 provider 単位 credentials を一度きり purge する。
	 *
	 * account 単位 credentials（AccountRegistry/CredentialsController）への移行に伴い不要となった
	 * 旧オプションを admin_init で削除する。`affilicard_legacy_creds_purged` フラグで一度だけ実行する。
	 */
	public static function purgeLegacyProviderCredentials(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( 'affilicard_legacy_creds_purged' ) ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$like = $wpdb->esc_like( 'affilicard_provider_' ) . '%' . $wpdb->esc_like( '_credentials' );
		$keys = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);
		foreach ( (array) $keys as $key ) {
			delete_option( (string) $key );
		}
		update_option( 'affilicard_legacy_creds_purged', 1, false );
	}

	/**
	 * `eligibleProvider` 追加前に保存された既存 platform へ、provider を一度きり補完する。
	 *
	 * v2.3.0 の手動/自動トグルは eligibleProvider が空だと「自動取得」を選べない一方、
	 * ListingRefresher の自動判定（`$provider->isAutomatic()`）は `provider !== 'manual'` で行うため、
	 * eligibleProvider が空のまま provider != 'manual' な platform は UI 上「手動固定」に見えつつ
	 * cron では自動 refresh される、という不整合が起き得る。
	 * seed（PlatformConfig::defaults()）は新規 install にしか効かないため、既存 install を
	 * `affilicard_eligible_provider_backfilled` フラグで一度だけ補完する。
	 *
	 * 優先順位: まず既知の code→provider マップを適用し、マップに無い code でも
	 * `provider !== 'manual'` かつ eligibleProvider が空なら `eligibleProvider = provider` を補完する
	 * 一般則を適用する。既に値がある platform は上書きしない。
	 */
	public static function backfillEligibleProviders(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( 'affilicard_eligible_provider_backfilled' ) ) {
			return;
		}

		$map = array(
			'rakuten-kobo' => 'rakuten-kobo',
			'dmm-books'    => 'dmm-ebook',
		);

		$changed = false;
		$out     = array();
		foreach ( PlatformConfig::all() as $definition ) {
			$arr = $definition->toArray();
			if ( '' === $definition->eligibleProvider ) {
				if ( isset( $map[ $definition->code ] ) ) {
					$arr['eligibleProvider'] = $map[ $definition->code ];
					$changed                 = true;
				} elseif ( 'manual' !== $definition->provider ) {
					// マップ未収載でも provider が自動系なら、UI/cron の判定を一致させるため provider を補完する。
					$arr['eligibleProvider'] = $definition->provider;
					$changed                 = true;
				}
			}
			$out[] = $arr;
		}

		if ( $changed ) {
			PlatformConfig::save( $out );
		}

		update_option( 'affilicard_eligible_provider_backfilled', 1, false );
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
		wp_add_inline_script(
			'affilicard-settings',
			'window.affilicardAccounts=' . wp_json_encode( AccountUiList::build( self::buildAccountRegistry() ) ) . ';'
			. 'window.affilicardProviders=' . wp_json_encode( ProviderUiList::build( self::buildProviderRegistry() ) ) . ';',
			'before'
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
