<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit;

use Affilicard\Uninstall;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class UninstallTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		if ( isset( $GLOBALS['wpdb'] ) ) {
			unset( $GLOBALS['wpdb'] );
		}
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * provider credentials の一括 DELETE を捕捉する $wpdb モックを $GLOBALS に設定する。
	 *
	 * @param array<int, string> $captured DELETE に渡された option_name LIKE 値を蓄積する参照。
	 */
	private function mockWpdb( array &$captured ): void {
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
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( string $query ) use ( &$captured ) {
				$captured[] = $query;
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	public function test_run_deletes_known_options_and_all_products(): void {
		$captured = array();
		$this->mockWpdb( $captured );

		foreach ( Uninstall::OPTION_KEYS as $option_key ) {
			WP_Mock::userFunction( 'delete_option' )
				->once()
				->with( $option_key )
				->andReturn( true );
		}

		WP_Mock::userFunction( 'get_posts' )
			->once()
			->with(
				WP_Mock\Functions::type( 'array' )
			)
			->andReturnUsing(
				function ( array $args ) {
					$this->assertSame( 'affilicard_product', $args['post_type'] );
					$this->assertSame( 'any', $args['post_status'] );
					$this->assertSame( -1, $args['numberposts'] );
					$this->assertSame( 'ids', $args['fields'] );
					return array( 101, 202, 303 );
				}
			);

		WP_Mock::userFunction( 'wp_delete_post' )
			->times( 3 )
			->with( WP_Mock\Functions::type( 'int' ), true )
			->andReturn( true );

		Uninstall::run();

		$this->assertConditionsMet();
	}

	public function test_run_skips_wp_delete_post_when_no_products_exist(): void {
		$captured = array();
		$this->mockWpdb( $captured );

		foreach ( Uninstall::OPTION_KEYS as $option_key ) {
			WP_Mock::userFunction( 'delete_option' )
				->once()
				->with( $option_key )
				->andReturn( true );
		}

		WP_Mock::userFunction( 'get_posts' )
			->once()
			->andReturn( array() );

		// wp_delete_post should NOT be called.
		WP_Mock::userFunction( 'wp_delete_post' )
			->never();

		Uninstall::run();

		$this->assertConditionsMet();
	}

	public function test_option_keys_include_currently_written_settings(): void {
		$this->assertContains( \Affilicard\Platform\PlatformConfig::OPTION_KEY, Uninstall::OPTION_KEYS );
		$this->assertContains( \Affilicard\Settings\GeneralSettings::OPTION_KEY, Uninstall::OPTION_KEYS );
		$this->assertContains( \Affilicard\Plugin::SEEDED_AT_OPTION, Uninstall::OPTION_KEYS );
	}

	public function test_run_deletes_provider_credentials_via_wpdb_like(): void {
		$captured = array();
		$this->mockWpdb( $captured );

		WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array() );
		WP_Mock::userFunction( 'wp_delete_post' )->never();

		Uninstall::run();

		$this->assertCount( 1, $captured, 'provider credentials DELETE が 1 回実行されること' );
		$this->assertStringContainsString( 'DELETE FROM wp_options', $captured[0] );
		$this->assertStringContainsString( 'affilicard', $captured[0] );
		$this->assertStringContainsString( 'provider', $captured[0] );
		$this->assertStringContainsString( 'LIKE', $captured[0] );
	}
}
