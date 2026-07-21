<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Plugin;
use Affilicard\Provider\Dmm\DmmProvider;
use Affilicard\Provider\ManualProvider;
use Affilicard\Types\EbookType;
use Affilicard\Types\GenericType;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class PluginTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_buildProviderRegistry_includes_manual_and_dmm_ebook(): void {
		$registry = Plugin::buildProviderRegistry();

		$codes = $registry->codes();
		$this->assertContains( ( new ManualProvider() )->code(), $codes );
		$this->assertContains( ( new DmmProvider() )->code(), $codes );
		$this->assertContains( 'manual', $codes );
		$this->assertContains( 'dmm-ebook', $codes );
	}

	public function test_buildProductTypeRegistry_includes_generic_and_ebook(): void {
		$registry = Plugin::buildProductTypeRegistry();

		$codes = $registry->codes();
		$this->assertContains( ( new GenericType() )->code(), $codes );
		$this->assertContains( ( new EbookType() )->code(), $codes );
		$this->assertContains( 'generic', $codes );
		$this->assertContains( 'ebook', $codes );
	}

	public function test_onActivate_is_idempotent_when_seeded_at_already_set(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( Plugin::SEEDED_AT_OPTION, false )
			->andReturn( '2026-01-01T00:00:00+00:00' );

		// seeded 済みなら PlatformConfig::save の前提となる update_option / get_option (defaults 読み出し) は呼ばない。
		WP_Mock::userFunction( 'update_option' )
			->never();

		Plugin::onActivate();

		$this->assertConditionsMet();
	}

	public function test_onActivate_seeds_defaults_and_records_timestamp_on_fresh_install(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( Plugin::SEEDED_AT_OPTION, false )
			->andReturn( false );

		// PlatformConfig::save 内部で defaults 個数分の update_option が走る。
		// ここでは「affilicard_seeded_at が確実に書かれる」ことと
		// 「PlatformConfig::OPTION_KEY (affilicard_platforms) が書かれる」ことを検査する。
		$seeded_recorded   = false;
		$platforms_written = false;
		WP_Mock::userFunction( 'update_option' )
			->andReturnUsing(
				function ( $key, $value, $autoload = false ) use ( &$seeded_recorded, &$platforms_written ) {
					if ( PlatformConfig::OPTION_KEY === $key ) {
						$platforms_written = true;
						$this->assertIsArray( $value );
						$this->assertNotEmpty( $value );
					}
					if ( Plugin::SEEDED_AT_OPTION === $key ) {
						$seeded_recorded = true;
						$this->assertIsString( $value );
						$this->assertFalse( $autoload );
					}
					return true;
				}
			);

		Plugin::onActivate();

		$this->assertTrue( $platforms_written, 'PlatformConfig::save がデフォルト platform を書き出すべき' );
		$this->assertTrue( $seeded_recorded, 'affilicard_seeded_at オプションが記録されるべき' );
	}

	public function test_registerSettingsPage_calls_add_submenu_page_under_cpt(): void {
		WP_Mock::userFunction( 'add_submenu_page' )
			->once()
			->andReturnUsing(
				function ( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback ) {
					$this->assertSame( 'edit.php?post_type=affilicard_product', $parent_slug );
					$this->assertSame( 'manage_options', $capability );
					$this->assertSame( 'affilicard-settings', $menu_slug );
					$this->assertSame( array( Plugin::class, 'renderSettingsPage' ), $callback );
					return 'affilicard_product_page_affilicard-settings';
				}
			);

		Plugin::registerSettingsPage();

		$this->assertConditionsMet();
	}

	public function test_boot_registers_block_init_hook(): void {
		WP_Mock::userFunction( 'is_admin', array( 'return' => false ) );
		WP_Mock::userFunction( 'register_activation_hook', array( 'return' => true ) );
		WP_Mock::userFunction( 'register_deactivation_hook', array( 'return' => true ) );

		// WP_Mock::add_action は userFunction でオーバーライドできないため、
		// WP_Mock ネイティブの expectActionAdded + AnyInstance マッチャーを使用する。
		WP_Mock::expectActionAdded(
			'init',
			array( new \WP_Mock\Matcher\AnyInstance( \Affilicard\Block\Block::class ), 'register' )
		);

		// Cron: 全体単一イベントのハンドラ登録
		WP_Mock::expectActionAdded( \Affilicard\Cron\RefreshScheduler::HOOK_ALL, \WP_Mock\Functions::type( 'callable' ) );

		// Cron: init 時の reconcile
		WP_Mock::expectActionAdded( 'init', array( \Affilicard\Cron\RefreshScheduler::class, 'reconcile' ) );

		// 予約投稿昇格時の refresh
		WP_Mock::expectActionAdded( 'transition_post_status', array( Plugin::class, 'onTransitionPostStatus' ), 10, 3 );

		Plugin::boot();

		$this->assertConditionsMet();
	}

	public function test_on_transition_refreshes_on_future_to_publish(): void {
		$post = (object) array(
			'ID'        => 77,
			'post_type' => 'affilicard_product',
		);
		WP_Mock::userFunction( 'get_post' )->with( 77 )->andReturn( null ); // find→null → save 不発
		\Affilicard\Plugin::onTransitionPostStatus( 'publish', 'future', $post );
		$this->assertConditionsMet();
	}

	public function test_on_transition_ignores_non_product(): void {
		$post = (object) array(
			'ID'        => 78,
			'post_type' => 'post',
		);
		\Affilicard\Plugin::onTransitionPostStatus( 'publish', 'future', $post );
		$this->assertConditionsMet();
	}

	public function test_on_transition_ignores_non_future_origin(): void {
		$post = (object) array(
			'ID'        => 79,
			'post_type' => 'affilicard_product',
		);
		\Affilicard\Plugin::onTransitionPostStatus( 'publish', 'draft', $post ); // draft→publish は対象外
		$this->assertConditionsMet();
	}

	public function test_enqueueSettingsAssets_skips_when_hook_not_settings(): void {
		// 別画面ではアセットを enqueue しない（wp_enqueue_* が一切呼ばれない）。
		Plugin::enqueueSettingsAssets( 'edit.php' );
		$this->assertConditionsMet();
	}

	public function test_enqueueSettingsAssets_enqueues_styles_on_settings_hook(): void {
		WP_Mock::userFunction( 'wp_enqueue_script' )->once();
		WP_Mock::userFunction( 'wp_set_script_translations' )->once();
		WP_Mock::userFunction( 'wp_json_encode' )
			->andReturnUsing(
				static function ( $data ) {
					return json_encode( $data );
				}
			);

		// wp-components スタイルと専用 admin CSS を enqueue する。
		WP_Mock::userFunction( 'wp_enqueue_style' )
			->once()
			->with( 'wp-components' );
		WP_Mock::userFunction( 'wp_enqueue_style' )
			->once()
			->with(
				'affilicard-admin-settings',
				AFFILICARD_PLUGIN_URL . 'assets/admin-settings.css',
				array( 'wp-components' ),
				AFFILICARD_VERSION
			);

		// 2 globals（affilicardAccounts / affilicardProviders）を settings script の直前に注入する。
		WP_Mock::userFunction( 'wp_add_inline_script' )
			->once()
			->with(
				'affilicard-settings',
				WP_Mock\Functions::type( 'string' ),
				'before'
			)
			->andReturnUsing(
				function ( $handle, $script, $position ) {
					$this->assertStringContainsString( 'window.affilicardAccounts=', $script );
					$this->assertStringContainsString( 'window.affilicardProviders=', $script );
					$this->assertStringContainsString( 'rakuten', $script );
					$this->assertStringContainsString( 'dmm-ebook', $script );
					return true;
				}
			);

		Plugin::enqueueSettingsAssets( 'affilicard_product_page_affilicard-settings' );

		$this->assertConditionsMet();
	}

	public function test_purgeLegacyProviderCredentials_skips_when_already_purged(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( true );
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_legacy_creds_purged' )
			->andReturn( 1 );
		WP_Mock::userFunction( 'update_option' )->never();
		WP_Mock::userFunction( 'delete_option' )->never();

		Plugin::purgeLegacyProviderCredentials();

		$this->assertConditionsMet();
	}

	/**
	 * manage_options 権限がないユーザー（例: admin_init が低権限ユーザーで走った場合）では
	 * purge 処理そのものに入らない（get_option/delete_option/update_option を一切呼ばない）。
	 */
	public function test_purgeLegacyProviderCredentials_skips_when_user_cannot_manage_options(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( false );
		WP_Mock::userFunction( 'get_option' )->never();
		WP_Mock::userFunction( 'update_option' )->never();
		WP_Mock::userFunction( 'delete_option' )->never();

		Plugin::purgeLegacyProviderCredentials();

		$this->assertConditionsMet();
	}

	public function test_purgeLegacyProviderCredentials_deletes_matching_options_and_sets_flag(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( true );
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_legacy_creds_purged' )
			->andReturn( false );

		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static function ( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}
		);
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $query, $arg ): string {
				return str_replace( '%s', (string) $arg, $query );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturn( array( 'affilicard_provider_rakuten_credentials', 'affilicard_provider_dmm_credentials' ) );
		$GLOBALS['wpdb'] = $wpdb;

		WP_Mock::userFunction( 'delete_option' )
			->twice()
			->andReturnUsing(
				function ( $key ) {
					$this->assertStringStartsWith( 'affilicard_provider_', $key );
					return true;
				}
			);
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( 'affilicard_legacy_creds_purged', 1, false )
			->andReturn( true );

		Plugin::purgeLegacyProviderCredentials();

		unset( $GLOBALS['wpdb'] );
		Mockery::close();

		$this->assertConditionsMet();
	}
}
