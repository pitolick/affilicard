<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider\Dmm;

use Affilicard\Provider\Dmm\DmmProvider;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class DmmProviderTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);
		WP_Mock::userFunction( 'is_wp_error' )
			->andReturnUsing(
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
		$provider = new DmmProvider();
		$this->assertSame( 'dmm-ebook', $provider->code() );
		$this->assertSame( 'DMM ebook API', $provider->label() );
		$this->assertTrue( $provider->isAutomatic() );
	}

	public function test_credentials_schema_has_two_required_entries(): void {
		$provider = new DmmProvider();
		$schema   = $provider->credentialsSchema();

		$this->assertCount( 2, $schema );
		$this->assertSame( 'api_id', $schema[0]['key'] );
		$this->assertTrue( $schema[0]['required'] );
		$this->assertSame( 'password', $schema[0]['type'] );

		$this->assertSame( 'affiliate_id', $schema[1]['key'] );
		$this->assertTrue( $schema[1]['required'] );
		$this->assertSame( 'password', $schema[1]['type'] );
	}

	public function test_test_connection_fails_with_empty_credentials(): void {
		$provider = new DmmProvider();
		$result   = $provider->testConnection( array() );
		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( $result['message'] );
	}

	public function test_test_connection_succeeds_when_api_returns_200_status_200(): void {
		WP_Mock::userFunction( 'wp_remote_get' )
			->once()
			->andReturn( array( 'response' => array( 'code' => 200 ) ) );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )
			->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturn( json_encode( array( 'result' => array( 'status' => 200 ) ) ) );

		$provider = new DmmProvider();
		$result   = $provider->testConnection(
			array(
				'api_id'       => 'id',
				'affiliate_id' => 'aff',
			)
		);

		$this->assertTrue( $result['ok'] );
	}

	public function test_test_connection_fails_when_api_returns_wp_error(): void {
		WP_Mock::userFunction( 'wp_remote_get' )
			->once()
			->andReturn( new \WP_Error() );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )
			->andReturn( 0 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturn( '' );

		$provider = new DmmProvider();
		$result   = $provider->testConnection(
			array(
				'api_id'       => 'id',
				'affiliate_id' => 'aff',
			)
		);

		$this->assertFalse( $result['ok'] );
	}

	public function test_test_connection_fails_when_api_returns_non_200(): void {
		WP_Mock::userFunction( 'wp_remote_get' )
			->once()
			->andReturn( array( 'response' => array( 'code' => 401 ) ) );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )
			->andReturn( 401 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturn( '' );

		$provider = new DmmProvider();
		$result   = $provider->testConnection(
			array(
				'api_id'       => 'id',
				'affiliate_id' => 'aff',
			)
		);

		$this->assertFalse( $result['ok'] );
	}
}
