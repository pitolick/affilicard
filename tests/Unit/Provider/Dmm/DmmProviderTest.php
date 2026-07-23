<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider\Dmm;

use Affilicard\Provider\Dmm\DmmProvider;
use Affilicard\Util\Crypto;
use Affilicard\Util\JsonField;
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
		WP_Mock::userFunction( 'wp_salt' )
			->with( 'auth' )
			->andReturn( 'test-salt-1234567890abcdef' );
		WP_Mock::userFunction( 'wp_json_encode' )
			->andReturnUsing(
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
		$provider = new DmmProvider();
		$this->assertSame( 'dmm-ebook', $provider->code() );
		$this->assertSame( 'DMM ebook API', $provider->label() );
		$this->assertTrue( $provider->isAutomatic() );
	}

	public function test_account_code_is_dmm(): void {
		$this->assertSame( 'dmm', ( new DmmProvider() )->accountCode() );
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

	public function test_fetch_includes_title_from_item(): void {
		$credentials = array(
			'api_id'       => 'test-api-id',
			'affiliate_id' => 'test-aff-id',
		);
		$encrypted   = Crypto::encrypt( JsonField::encode( $credentials ) );

		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_account_dmm_credentials', '' )
			->andReturn( $encrypted );

		$item_json = json_encode(
			array(
				'result' => array(
					'items' => array(
						array(
							'title'        => 'サンプル商品タイトル',
							'prices'       => array(
								'price'      => '500',
								'list_price' => '1000',
							),
							'imageURL'     => array( 'large' => 'https://example.com/image.jpg' ),
							'URL'          => 'https://example.com/product',
							'affiliateURL' => 'https://example.com/aff',
						),
					),
				),
			)
		);

		WP_Mock::userFunction( 'wp_remote_get' )
			->once()
			->andReturn( array( 'response' => array( 'code' => 200 ) ) );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )
			->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturn( $item_json );

		$result = ( new DmmProvider() )->fetch( 'ext-123', array() );
		$this->assertSame( 'サンプル商品タイトル', $result['title'] );
	}

	public function test_minRequestIntervalMs_DMMは1000(): void {
		$this->assertSame( 1000, ( new DmmProvider() )->minRequestIntervalMs() );
	}
}
