<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider\Rakuten;

use Affilicard\Provider\Rakuten\RakutenClient;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class RakutenClientTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() のテストダブル
				return parse_url( $url );
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

	public function test_to_origin_adds_scheme_and_keeps_host(): void {
		$this->assertSame( 'https://e-comi.example.com', RakutenClient::toOrigin( 'e-comi.example.com' ) );
		$this->assertSame( 'https://e-comi.example.com', RakutenClient::toOrigin( 'https://e-comi.example.com/path' ) );
		$this->assertSame( 'http://localhost:8888', RakutenClient::toOrigin( 'http://localhost:8888' ) );
	}

	public function test_request_success_returns_parsed_response(): void {
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		WP_Mock::userFunction( 'wp_remote_get' )->andReturn(
			array( 'response' => array( 'code' => 200 ) )
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn(
			json_encode( array( 'Items' => array( 'item1' ) ) )
		);

		$client = new RakutenClient();
		$result = $client->request(
			array( 'applicationId' => '123' ),
			array(
				'access_key'     => 'SAMPLEKEY',
				'application_id' => '123',
				'affiliate_id'   => 'aff',
			)
		);

		$this->assertFalse( $result['error'] );
		$this->assertSame( 200, $result['code'] );
		$this->assertIsArray( $result['decoded'] );
		$this->assertSame( array( 'item1' ), $result['decoded']['Items'] );
	}

	public function test_request_non_200_returns_code_with_decoded_body(): void {
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		WP_Mock::userFunction( 'wp_remote_get' )->andReturn(
			array( 'response' => array( 'code' => 429 ) )
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 429 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn(
			json_encode( array( 'error' => 'Too Many Requests' ) )
		);

		$client = new RakutenClient();
		$result = $client->request(
			array( 'applicationId' => '123' ),
			array(
				'access_key'     => 'SAMPLEKEY',
				'application_id' => '123',
				'affiliate_id'   => 'aff',
			)
		);

		$this->assertFalse( $result['error'] );
		$this->assertSame( 429, $result['code'] );
		$this->assertIsArray( $result['decoded'] );
		$this->assertSame( 'Too Many Requests', $result['decoded']['error'] );
	}

	public function test_request_wp_error_returns_error_true(): void {
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		WP_Mock::userFunction( 'wp_remote_get' )->andReturn( new \WP_Error( 'connection_failed', 'Failed' ) );

		$client = new RakutenClient();
		$result = $client->request(
			array( 'applicationId' => '123' ),
			array(
				'access_key'     => 'SAMPLEKEY',
				'application_id' => '123',
				'affiliate_id'   => 'aff',
			)
		);

		$this->assertTrue( $result['error'] );
		$this->assertSame( 0, $result['code'] );
		$this->assertNull( $result['decoded'] );
	}

	public function test_request_non_array_body_sets_decoded_to_null(): void {
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		WP_Mock::userFunction( 'wp_remote_get' )->andReturn(
			array( 'response' => array( 'code' => 200 ) )
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '"not an array"' );

		$client = new RakutenClient();
		$result = $client->request(
			array( 'applicationId' => '123' ),
			array(
				'access_key'     => 'SAMPLEKEY',
				'application_id' => '123',
				'affiliate_id'   => 'aff',
			)
		);

		$this->assertFalse( $result['error'] );
		$this->assertSame( 200, $result['code'] );
		$this->assertNull( $result['decoded'] );
	}

	public function test_request_sends_access_key_header(): void {
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		$captured = null;
		WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = $args;
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '{}' );

		$client = new RakutenClient();
		$client->request(
			array( 'applicationId' => '123' ),
			array(
				'access_key'     => 'SAMPLEKEY',
				'application_id' => '123',
				'affiliate_id'   => 'aff',
			)
		);

		$this->assertSame( 'SAMPLEKEY', $captured['headers']['accessKey'] );
		$this->assertSame( 'https://shop.example', $captured['headers']['Origin'] );
		$this->assertSame( 'https://shop.example/', $captured['headers']['Referer'] );
		$this->assertSame( 10, $captured['timeout'] );
	}
}
