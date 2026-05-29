<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit;

use Affilicard\Uninstall;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class UninstallTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_run_deletes_known_options_and_all_products(): void {
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
}
