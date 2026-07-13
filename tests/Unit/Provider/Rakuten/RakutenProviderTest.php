<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider\Rakuten;

use Affilicard\Provider\Rakuten\RakutenProvider;
use Affilicard\Util\Crypto;
use Affilicard\Util\JsonField;
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
		WP_Mock::userFunction( 'wp_salt' )->with( 'auth' )->andReturn( 'test-salt-1234567890abcdef' );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing(
			static function ( $value ) {
				return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
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
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() のテストダブル
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
			json_encode(
				array(
					'count' => 1,
					'Items' => array(),
				)
			)
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
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() のテストダブル
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
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() のテストダブル
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
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() のテストダブル
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

	/**
	 * @return string 暗号化済み credentials（get_option が返す値）
	 */
	private function encryptedCredentials(): string {
		return Crypto::encrypt(
			JsonField::encode(
				array(
					'application_id' => 'app-1',
					'access_key'     => 'pk_test',
					'affiliate_id'   => 'aff-1',
				)
			)
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function stubFetchResponse( array $item, ?string &$captured = null ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_provider_rakuten-kobo_credentials', '' )
			->andReturn( $this->encryptedCredentials() );
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() のテストダブル
				return parse_url( $url );
			}
		);
		WP_Mock::userFunction( 'wp_remote_get' )->once()->andReturnUsing(
			static function ( $url ) use ( &$captured ) {
				$captured = $url;
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn(
			json_encode( array( 'Items' => array( $item ) ) )
		);
	}

	public function test_fetch_uses_item_number_query_for_numeric_external_id(): void {
		$captured = null;
		$this->stubFetchResponse( array( 'title' => 'サンプル作品' ), $captured );

		$result = ( new RakutenProvider() )->fetch( '8913122576600', array() );

		$this->assertSame( 'サンプル作品', $result['title'] );
		$this->assertStringContainsString( 'itemNumber=8913122576600', $captured );
		$this->assertStringNotContainsString( 'keyword=', $captured );
	}

	public function test_fetch_normalizes_all_fields(): void {
		$item = array(
			'title'          => 'サンプル作品',
			'itemPrice'      => 660,
			'salesDate'      => '2026年07月10日',
			'itemUrl'        => 'https://shop.example/item/1',
			'affiliateUrl'   => 'https://aff.example/hgc/xxx',
			'largeImageUrl'  => 'https://img.example/large.jpg',
			'mediumImageUrl' => 'https://img.example/medium.jpg',
			'seriesName'     => 'サンプルシリーズ',
			'author'         => 'サンプル著者',
			'publisherName'  => 'サンプル出版',
		);
		$this->stubFetchResponse( $item );

		$result = ( new RakutenProvider() )->fetch( '8913122576600', array() );

		$this->assertSame( 'サンプル作品', $result['title'] );
		$this->assertSame( '660', $result['price'] );
		$this->assertSame( '', $result['list_price'] );
		$this->assertSame( '', $result['badge'] );
		$this->assertSame( 'https://img.example/large.jpg', $result['image_url'] );
		$this->assertSame( 'https://shop.example/item/1', $result['regular_url'] );
		$this->assertSame( 'https://aff.example/hgc/xxx', $result['affiliate_url'] );
		$this->assertSame( '2026-07-10', $result['platform_extras']['release_date'] );
		$this->assertSame( 'サンプルシリーズ', $result['platform_extras']['series_name'] );
		$this->assertSame( 'サンプル著者', $result['platform_extras']['author'] );
		$this->assertSame( 'サンプル出版', $result['platform_extras']['publisher'] );
	}

	public function test_fetch_falls_back_to_medium_image_when_large_missing(): void {
		$this->stubFetchResponse(
			array(
				'title'          => 'サンプル作品',
				'mediumImageUrl' => 'https://img.example/medium.jpg',
			)
		);
		$result = ( new RakutenProvider() )->fetch( '123', array() );
		$this->assertSame( 'https://img.example/medium.jpg', $result['image_url'] );
	}
}
