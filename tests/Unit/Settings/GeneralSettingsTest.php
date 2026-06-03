<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Settings;

use Affilicard\Settings\GeneralSettings;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class GeneralSettingsTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_get_returns_defaults_when_option_missing(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		$result = GeneralSettings::get();

		$this->assertSame( 86400, $result['cache_ttl_seconds'] );
		$this->assertSame( 'generic', $result['default_product_type'] );
		$this->assertFalse( $result['cron_enabled'] );
		$this->assertSame( 1, $result['schema_version'] );
	}

	public function test_get_merges_defaults_with_stored_values(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn(
				array(
					'cache_ttl_seconds' => 3600,
					'cron_enabled'      => true,
				)
			);

		$result = GeneralSettings::get();

		$this->assertSame( 3600, $result['cache_ttl_seconds'] );
		$this->assertTrue( $result['cron_enabled'] );
		$this->assertSame( 'generic', $result['default_product_type'] );
		$this->assertSame( 1, $result['schema_version'] );
	}

	public function test_update_clamps_cache_ttl_seconds_and_validates_default_product_type(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( GeneralSettings::OPTION_KEY, $key );
					$this->assertFalse( $autoload );
					// 30 days max
					$this->assertSame( 2592000, $value['cache_ttl_seconds'] );
					// invalid type fallback to default
					$this->assertSame( 'generic', $value['default_product_type'] );
					$this->assertTrue( $value['cron_enabled'] );
					return true;
				}
			);

		$result = GeneralSettings::update(
			array(
				'cache_ttl_seconds'    => 99999999,
				'default_product_type' => 'invalid-type',
				'cron_enabled'         => 1,
			)
		);

		$this->assertSame( 2592000, $result['cache_ttl_seconds'] );
		$this->assertSame( 'generic', $result['default_product_type'] );
		$this->assertTrue( $result['cron_enabled'] );
	}

	public function test_update_clamps_minimum_cache_ttl_seconds(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( 60, $value['cache_ttl_seconds'] );
					return true;
				}
			);

		$result = GeneralSettings::update( array( 'cache_ttl_seconds' => 1 ) );
		$this->assertSame( 60, $result['cache_ttl_seconds'] );
	}

	public function test_update_accepts_ebook_as_default_product_type(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( 'ebook', $value['default_product_type'] );
					return true;
				}
			);

		$result = GeneralSettings::update( array( 'default_product_type' => 'ebook' ) );
		$this->assertSame( 'ebook', $result['default_product_type'] );
	}

	public function test_is_cron_enabled_reflects_option(): void {
		WP_Mock::userFunction( 'get_option' )->with( GeneralSettings::OPTION_KEY, array() )->andReturn( array( 'cron_enabled' => true ) );
		$this->assertTrue( GeneralSettings::isCronEnabled() );
	}

	public function test_is_cron_enabled_defaults_false(): void {
		WP_Mock::userFunction( 'get_option' )->with( GeneralSettings::OPTION_KEY, array() )->andReturn( array() );
		$this->assertFalse( GeneralSettings::isCronEnabled() );
	}
}
