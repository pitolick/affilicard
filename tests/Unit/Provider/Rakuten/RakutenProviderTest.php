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
		$this->assertSame( 'password', $schema[0]['type'] );
		$this->assertSame( 'access_key', $schema[1]['key'] );
		$this->assertTrue( $schema[1]['required'] );
		$this->assertSame( 'password', $schema[1]['type'] );
		$this->assertSame( 'affiliate_id', $schema[2]['key'] );
		$this->assertTrue( $schema[2]['required'] );
		$this->assertSame( 'password', $schema[2]['type'] );
		$this->assertSame( 'allowed_domain', $schema[3]['key'] );
		$this->assertFalse( $schema[3]['required'] );
		$this->assertSame( 'text', $schema[3]['type'] );
	}

	public function test_test_connection_fails_with_empty_credentials(): void {
		$result = ( new RakutenProvider() )->testConnection( array() );
		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( $result['message'] );
	}

	public function test_test_connection_succeeds_and_sends_accesskey_and_origin_headers(): void {
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) {
				return parse_url( $url );
			}
		);

		$captured = null;
		WP_Mock::userFunction( 'wp_remote_get' )
			->once()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$captured ) {
					$captured = array(
						'url'  => $url,
						'args' => $args,
					);
					return array( 'response' => array( 'code' => 200 ) );
				}
			);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn(
			json_encode( array( 'count' => 1, 'Items' => array() ) )
		);

		$result = ( new RakutenProvider() )->testConnection(
			array(
				'application_id' => 'app-1',
				'access_key'     => 'pk_test',
				'affiliate_id'   => 'aff-1',
			)
		);

		$this->assertTrue( $result['ok'] );
		// accessKey はヘッダー・クエリに載らない
		$this->assertSame( 'pk_test', $captured['args']['headers']['accessKey'] );
		$this->assertSame( 'https://shop.example', $captured['args']['headers']['Origin'] );
		$this->assertSame( 'https://shop.example/', $captured['args']['headers']['Referer'] );
		$this->assertStringNotContainsString( 'accessKey=', $captured['url'] );
		$this->assertStringContainsString( 'applicationId=app-1', $captured['url'] );
	}

	public function test_test_connection_uses_allowed_domain_override(): void {
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) {
				return parse_url( $url );
			}
		);
		$captured = null;
		WP_Mock::userFunction( 'wp_remote_get' )->once()->andReturnUsing(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = $args;
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( array( 'Items' => array() ) ) );

		( new RakutenProvider() )->testConnection(
			array(
				'application_id' => 'app-1',
				'access_key'     => 'pk_test',
				'affiliate_id'   => 'aff-1',
				'allowed_domain' => 'https://www.other.example/path',
			)
		);

		$this->assertSame( 'https://www.other.example', $captured['headers']['Origin'] );
	}

	public function test_test_connection_maps_403_to_referrer_message(): void {
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) {
				return parse_url( $url );
			}
		);
		WP_Mock::userFunction( 'wp_remote_get' )->once()->andReturn( array( 'response' => array( 'code' => 403 ) ) );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 403 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn(
			json_encode( array( 'errors' => array( 'errorCode' => 403 ) ) )
		);

		$result = ( new RakutenProvider() )->testConnection(
			array(
				'application_id' => 'app-1',
				'access_key'     => 'pk_test',
				'affiliate_id'   => 'aff-1',
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( '許可ドメイン', $result['message'] );
	}

	public function test_test_connection_fails_on_wp_error(): void {
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) {
				return parse_url( $url );
			}
		);
		WP_Mock::userFunction( 'wp_remote_get' )->once()->andReturn( new \WP_Error() );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 0 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '' );

		$result = ( new RakutenProvider() )->testConnection(
			array(
				'application_id' => 'app-1',
				'access_key'     => 'pk_test',
				'affiliate_id'   => 'aff-1',
			)
		);
		$this->assertFalse( $result['ok'] );
	}
}
