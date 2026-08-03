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

	/**
	 * affiliate_url は API 応答の affiliateURL ではなく affiliate_link_id から組み立てる。
	 *
	 * ItemList はリクエストに使った affiliate_id（末尾 990〜999 の API 用 ID）を
	 * そのまま affiliateURL に埋めて返すため、それを採ると al.dmm.com が HTTP 400
	 * 「無効リンク」を返す（2026-08-03 実測）。ここが崩れると全 DMM リンクが死ぬ。
	 */
	public function test_fetch_はaffiliate_link_idからアフィリエイトURLを組む(): void {
		$credentials = array(
			'api_id'            => 'test-api-id',
			'affiliate_id'      => 'pitolick-990',
			'affiliate_link_id' => 'pitolick-007',
		);
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_account_dmm_credentials', '' )
			->andReturn( Crypto::encrypt( JsonField::encode( $credentials ) ) );

		$item_json = json_encode(
			array(
				'result' => array(
					'items' => array(
						array(
							'title'        => 'サンプル商品タイトル',
							'URL'          => 'https://book.dmm.com/product/1/b1/',
							// API はリクエスト用 ID を埋めた URL を返す（＝無効リンク）。使ってはいけない。
							'affiliateURL' => 'https://al.dmm.com/?lurl=x&af_id=pitolick-990&ch=api',
						),
					),
				),
			)
		);

		WP_Mock::userFunction( 'wp_remote_get' )
			->once()
			->andReturn( array( 'response' => array( 'code' => 200 ) ) );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $item_json );

		$result = ( new DmmProvider() )->fetch( 'b1', array() );
		$this->assertTrue( $result->isHit() );
		$this->assertSame(
			'https://al.dmm.com/?lurl=https%3A%2F%2Fbook.dmm.com%2Fproduct%2F1%2Fb1%2F&af_id=pitolick-007&ch=api',
			$result->data['affiliate_url']
		);
		$this->assertStringNotContainsString( 'pitolick-990', $result->data['affiliate_url'] );
	}

	/**
	 * リンク用 ID 未設定なら affiliate_url は空。ListingRefresher は空の取得値では
	 * 既存値を保持するため、手で登録した正しいリンクを壊さない（カードは regular_url へ
	 * フォールバックする）。「無効リンクで上書きする」より安全側に倒す。
	 */
	public function test_fetch_はaffiliate_link_id未設定なら空のaffiliate_urlを返す(): void {
		$credentials = array(
			'api_id'       => 'test-api-id',
			'affiliate_id' => 'pitolick-990',
		);
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_account_dmm_credentials', '' )
			->andReturn( Crypto::encrypt( JsonField::encode( $credentials ) ) );

		$item_json = json_encode(
			array(
				'result' => array(
					'items' => array(
						array(
							'title'        => 'サンプル商品タイトル',
							'URL'          => 'https://book.dmm.com/product/1/b1/',
							'affiliateURL' => 'https://al.dmm.com/?lurl=x&af_id=pitolick-990&ch=api',
						),
					),
				),
			)
		);

		WP_Mock::userFunction( 'wp_remote_get' )
			->once()
			->andReturn( array( 'response' => array( 'code' => 200 ) ) );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $item_json );

		$result = ( new DmmProvider() )->fetch( 'b1', array() );
		$this->assertTrue( $result->isHit() );
		$this->assertSame( '', $result->data['affiliate_url'] );
	}

	public function test_fetch_includes_title_from_item(): void {
		$credentials = array(
			'api_id'            => 'test-api-id',
			'affiliate_id'      => 'test-aff-id',
			'affiliate_link_id' => 'test-link-id',
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
		$this->assertTrue( $result->isHit() );
		$this->assertSame( 'サンプル商品タイトル', $result->data['title'] );
	}

	public function test_fetch_credentials未設定はerror_transient(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_account_dmm_credentials', '' )
			->andReturn( '' );

		$result = ( new DmmProvider() )->fetch( 'ext-123', array() );
		// creds 未設定は一時失敗（transient）。後で設定されれば成功し得るため give-up しない。
		$this->assertFalse( $result->isHit() );
		$this->assertFalse( $result->isTerminalMiss() );
	}

	public function test_fetch_API到達不可はerror_transient(): void {
		$credentials = array(
			'api_id'       => 'test-api-id',
			'affiliate_id' => 'test-aff-id',
		);
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_account_dmm_credentials', '' )
			->andReturn( Crypto::encrypt( JsonField::encode( $credentials ) ) );
		WP_Mock::userFunction( 'wp_remote_get' )->once()->andReturn( new \WP_Error() );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 0 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '' );

		$result = ( new DmmProvider() )->fetch( 'ext-123', array() );
		$this->assertFalse( $result->isHit() );
		$this->assertFalse( $result->isTerminalMiss() );
	}

	public function test_fetch_該当商品なしはmiss_terminal(): void {
		$credentials = array(
			'api_id'       => 'test-api-id',
			'affiliate_id' => 'test-aff-id',
		);
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_account_dmm_credentials', '' )
			->andReturn( Crypto::encrypt( JsonField::encode( $credentials ) ) );
		WP_Mock::userFunction( 'wp_remote_get' )->once()->andReturn( array( 'response' => array( 'code' => 200 ) ) );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn(
			json_encode( array( 'result' => array( 'items' => array() ) ) )
		);

		// 該当商品なしは恒久失敗（terminal）。同じ ID で再取得しても現れないため give-up してよい。
		$this->assertTrue( ( new DmmProvider() )->fetch( 'ext-123', array() )->isTerminalMiss() );
	}

	public function test_fetch_external_id空はmiss_terminal(): void {
		$credentials = array(
			'api_id'       => 'test-api-id',
			'affiliate_id' => 'test-aff-id',
		);
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_account_dmm_credentials', '' )
			->andReturn( Crypto::encrypt( JsonField::encode( $credentials ) ) );

		// external_id 空はデータ不備（無効）＝リトライで解決しない恒久失敗。
		$this->assertTrue( ( new DmmProvider() )->fetch( '', array() )->isTerminalMiss() );
	}

	public function test_minRequestIntervalMs_DMMは1000(): void {
		$this->assertSame( 1000, ( new DmmProvider() )->minRequestIntervalMs() );
	}
}
