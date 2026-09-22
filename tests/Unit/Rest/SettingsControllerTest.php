<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Rest\SettingsController;
use Affilicard\Settings\GeneralSettings;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;

final class SettingsControllerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	private function stubManageOptions( bool $allowed ): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( $allowed );
	}

	public function test_get_returns_200_with_merged_settings(): void {
		$this->stubManageOptions( true );
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn(
				array(
					'cache_ttl_seconds' => 7200,
				)
			);

		$controller = new SettingsController();
		$request    = new WP_REST_Request( 'GET', '/affilicard/v1/settings' );

		$response = $controller->get( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 7200, $data['cache_ttl_seconds'] );
		$this->assertSame( 'generic', $data['default_product_type'] );
	}

	public function test_update_calls_general_settings_update_and_returns_new_settings(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( GeneralSettings::OPTION_KEY, $key );
					$this->assertSame( 1800, $value['cache_ttl_seconds'] );
					$this->assertTrue( $value['cron_enabled'] );
					return true;
				}
			);

		$controller = new SettingsController();
		$request    = new WP_REST_Request( 'PUT', '/affilicard/v1/settings' );
		$request->set_param( 'cache_ttl_seconds', 1800 );
		$request->set_param( 'cron_enabled', true );

		$response = $controller->update( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1800, $data['cache_ttl_seconds'] );
		$this->assertTrue( $data['cron_enabled'] );
	}

	public function test_get_returns_stocktake_settings(): void {
		$this->stubManageOptions( true );
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn(
				array(
					'stocktake_enabled' => false,
					'stocktake_days'    => 90,
				)
			);

		$controller = new SettingsController();
		$request    = new WP_REST_Request( 'GET', '/affilicard/v1/settings' );

		$response = $controller->get( $request );
		$data     = $response->get_data();

		$this->assertFalse( $data['stocktake_enabled'] );
		$this->assertSame( 90, $data['stocktake_days'] );
	}

	public function test_update_persists_stocktake_settings_with_clamping(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( GeneralSettings::OPTION_KEY, $key );
					$this->assertFalse( $value['stocktake_enabled'] );
					// 0 は GeneralSettings::sanitize() 側で 1 へクランプされる
					// （REST を経由しても抜け道にならないことの確認）。
					$this->assertSame( 1, $value['stocktake_days'] );
					return true;
				}
			);

		$controller = new SettingsController();
		$request    = new WP_REST_Request( 'PUT', '/affilicard/v1/settings' );
		$request->set_param( 'stocktake_enabled', false );
		$request->set_param( 'stocktake_days', 0 );

		$response = $controller->update( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['stocktake_enabled'] );
		$this->assertSame( 1, $data['stocktake_days'] );
	}

	public function test_can_manage_options_checks_current_user_can(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( true );

		$controller = new SettingsController();
		$this->assertTrue( $controller->canManageOptions() );
	}

	/**
	 * 読み取り（GET）は manage_options より緩い edit_posts を要求する。
	 * affilicard_product は capability_type='post' で Editor は
	 * manage_options を持たないため、ProductSettingsPanel が
	 * fallback_on_terminal を読めるように GET だけ緩めてある
	 * （ProductRestController が商品自体の read 許可を edit_posts に
	 * 合わせているのと同じ capability）。
	 */
	/**
	 * 編集画面向けルートの許可判定は edit_posts。
	 *
	 * affilicard_product の capability_type は 'post' で、Editor は manage_options を
	 * 持たない。ここを manage_options にすると商品編集画面のサイドバーが 403 になり、
	 * 呼び出し側が既定 false へ倒れて「使用中」の印が ON 設定なのに間違う。
	 * 一般設定そのもの（/settings）は manage_options 必須のままである。
	 */
	public function test_編集画面向けルートの許可はedit_postsで判定する(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts' )
			->andReturn( true );

		$controller = new SettingsController();
		$this->assertTrue( $controller->canReadSettings() );
	}

	public function test_can_read_settings_denies_when_edit_posts_missing(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts' )
			->andReturn( false );

		$controller = new SettingsController();
		$this->assertFalse( $controller->canReadSettings() );
	}

	/**
	 * GET の許可は edit_posts まで緩めてあるが、返す中身まで緩めない。
	 *
	 * 商品編集画面（ProductSettingsPanel）が必要とするのは fallback_on_terminal 1 つ
	 * だけである。manage_options を持たない読み手へ一般設定オブジェクト全体（キューの
	 * 状態・保持期間・スロットル上書き等）を返すと、権限を 1 つのフラグのために
	 * 緩めたつもりが読み取り面ごと広がる。公開プラグインでは、広げた endpoint は
	 * そのまま固定化する。
	 */
	/**
	 * 商品編集画面向けのルートは `fallback_on_terminal` 1 項目だけを返す。
	 *
	 * `/settings` は manage_options 必須（管理者向けの設定オブジェクト全体を返す）。
	 * 編集画面が必要とするのはこの真偽値 1 つだけなので、権限で中身を出し分けるのでは
	 * なく URL を分けてある——そうしないと呼び出し側から「管理者専用の URL」なのか
	 * 「誰でも読める URL」なのか判別できない。
	 */
	public function test_編集画面向けルートはfallback_on_terminalだけ返す(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn(
				array(
					'fallback_on_terminal' => true,
					'cache_ttl_seconds'    => 7200,
				)
			);

		$controller = new SettingsController();
		$request    = new WP_REST_Request( 'GET', '/affilicard/v1/editor-settings' );

		$response = $controller->getEditorSettings( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'fallback_on_terminal' => true ), $data );
	}

	/**
	 * 権限の契約を「ルート登録」の段で固定する。
	 *
	 * コールバック単体（canManageOptions / canReadSettings）のテストだけでは、
	 * どのルートにどちらを結線したかが固定されない。緩い方を /settings に繋いでも
	 * 従来のテストは全て通ってしまう。
	 */
	public function test_ルートごとの権限コールバックを固定する(): void {
		$routes = array();
		WP_Mock::userFunction( 'register_rest_route' )
			->andReturnUsing(
				static function ( $namespace, $route, $args ) use ( &$routes ): bool {
					$routes[ $route ] = $args;
					return true;
				}
			);

		( new SettingsController() )->registerRoutes( 'affilicard/v1' );

		$this->assertArrayHasKey( '/settings', $routes );
		$this->assertArrayHasKey( '/editor-settings', $routes );

		foreach ( $routes['/settings'] as $entry ) {
			$this->assertSame(
				'canManageOptions',
				$entry['permission_callback'][1],
				sprintf( '/settings の %s は manage_options 必須でなければならない', $entry['methods'] )
			);
		}

		foreach ( $routes['/editor-settings'] as $entry ) {
			$this->assertSame( 'GET', $entry['methods'], '/editor-settings は読み取り専用' );
			$this->assertSame( 'canReadSettings', $entry['permission_callback'][1] );
		}
	}
}
