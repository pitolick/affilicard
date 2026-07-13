<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider\Rakuten;

use Affilicard\Provider\Rakuten\RakutenProvider;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class RakutenProviderTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing(
			static function ( $text ) {
				return $text;
			}
		);
		WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing(
			static function ( $value ) {
				return $value instanceof \WP_Error;
			}
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_basic_metadata(): void {
		$provider = new RakutenProvider();
		$this->assertSame( 'rakuten-kobo', $provider->code() );
		$this->assertSame( '楽天Kobo API', $provider->label() );
		$this->assertTrue( $provider->isAutomatic() );
	}

	public function test_credentials_schema_has_four_entries(): void {
		$schema = ( new RakutenProvider() )->credentialsSchema();

		$this->assertCount( 4, $schema );
		$this->assertSame( 'application_id', $schema[0]['key'] );
		$this->assertTrue( $schema[0]['required'] );
		$this->assertSame( 'access_key', $schema[1]['key'] );
		$this->assertTrue( $schema[1]['required'] );
		$this->assertSame( 'affiliate_id', $schema[2]['key'] );
		$this->assertTrue( $schema[2]['required'] );
		$this->assertSame( 'allowed_domain', $schema[3]['key'] );
		$this->assertFalse( $schema[3]['required'] );
		$this->assertSame( 'text', $schema[3]['type'] );
	}
}
