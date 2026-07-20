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

	public function test_account_code_is_rakuten(): void {
		$this->assertSame( 'rakuten', ( new RakutenProvider() )->accountCode() );
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

	public function test_test_connection_normalizes_scheme_less_allowed_domain(): void {
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
				// スキーム無し＋ポート付き → https 補完・ポート保持で正規の Origin になる。
				'allowed_domain' => 'shop.example:8080',
			)
		);

		$this->assertSame( 'https://shop.example:8080', $captured['headers']['Origin'] );
		$this->assertSame( 'https://shop.example:8080/', $captured['headers']['Referer'] );
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
	 * credentials 用意（get_option 暗号化済み値 + Origin 解決に必要な home_url/wp_parse_url）。
	 */
	private function stubRakutenCredentials(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_account_rakuten_credentials', '' )
			->andReturn( $this->encryptedCredentials() );
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() のテストダブル
				return parse_url( $url );
			}
		);
	}

	/**
	 * API 応答を wp_remote_get 系スタブで用意する。
	 *
	 * @param int                  $code     HTTP ステータス
	 * @param array<string, mixed> $body     デコード前の JSON 相当配列
	 */
	private function stubRakutenResponse( int $code, array $body, ?string &$captured = null ): void {
		WP_Mock::userFunction( 'wp_remote_get' )->once()->andReturnUsing(
			static function ( $url ) use ( &$captured, $code ) {
				$captured = $url;
				return array( 'response' => array( 'code' => $code ) );
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( $code );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( $body ) );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function stubFetchResponse( array $item, ?string &$captured = null ): void {
		$this->stubRakutenCredentials();
		$this->stubRakutenResponse( 200, array( 'Items' => array( $item ) ), $captured );
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

	public function test_fetch_uses_keyword_query_for_non_numeric_external_id(): void {
		$captured = null;
		$this->stubFetchResponse( array( 'title' => 'サンプル作品' ), $captured );

		( new RakutenProvider() )->fetch( 'sample-slug', array() );

		$this->assertStringContainsString( 'keyword=sample-slug', $captured );
		$this->assertStringNotContainsString( 'itemNumber=', $captured );
	}

	public function test_fetch_search_keyでヒットしURLハッシュ一致の1件を採用する(): void {
		// credentials 用意（既存テストの helper に合わせる）
		$this->stubRakutenCredentials();
		// API 応答: 2 件中 1 件だけ external_id と rk ハッシュが一致
		$this->stubRakutenResponse(
			200,
			array(
				'Items' => array(
					array(
						'title'     => '別巻',
						'itemPrice' => 500,
						'itemUrl'   => 'https://books.rakuten.co.jp/rk/aaaaaaaa/',
					),
					array(
						'title'        => '対象巻',
						'itemPrice'    => 693,
						'itemUrl'      => 'https://books.rakuten.co.jp/rk/deadbeef01/',
						'affiliateUrl' => 'https://hb.afl.rakuten.co.jp/hgc/xxx/',
					),
				),
			)
		);

		$provider = new RakutenProvider();
		$result   = $provider->fetch( 'deadbeef01', array( 'search_key' => '対象巻タイトル' ) );

		$this->assertIsArray( $result );
		$this->assertSame( '693', $result['price'] );
		$this->assertSame( 'https://books.rakuten.co.jp/rk/deadbeef01/', $result['regular_url'] );
	}

	public function test_fetch_ハッシュ一致0件はnullで非破壊(): void {
		$this->stubRakutenCredentials();
		$this->stubRakutenResponse(
			200,
			array(
				'Items' => array(
					array(
						'title'     => '別巻',
						'itemPrice' => 500,
						'itemUrl'   => 'https://books.rakuten.co.jp/rk/aaaaaaaa/',
					),
				),
			)
		);
		$provider = new RakutenProvider();
		$this->assertNull( $provider->fetch( 'deadbeef01', array( 'search_key' => 'タイトル' ) ) );
	}

	public function test_fetch_ハッシュ一致が複数はnull_誤上書き防止(): void {
		$this->stubRakutenCredentials();
		$this->stubRakutenResponse(
			200,
			array(
				'Items' => array(
					array(
						'title'     => 'A',
						'itemPrice' => 500,
						'itemUrl'   => 'https://books.rakuten.co.jp/rk/dup/',
					),
					array(
						'title'     => 'B',
						'itemPrice' => 600,
						'itemUrl'   => 'https://books.rakuten.co.jp/rk/dup/',
					),
				),
			)
		);
		$provider = new RakutenProvider();
		$this->assertNull( $provider->fetch( 'dup', array( 'search_key' => 'タイトル' ) ) );
	}

	public function test_fetch_数字externalIdはitemNumber検索で先頭ヒット採用(): void {
		$this->stubRakutenCredentials();
		$this->stubRakutenResponse(
			200,
			array(
				'Items' => array(
					array(
						'title'     => '数字ID商品',
						'itemPrice' => 1200,
						'itemUrl'   => 'https://books.rakuten.co.jp/rk/zzzz/',
					),
				),
			)
		);
		$provider = new RakutenProvider();
		$result   = $provider->fetch( '123456', array() ); // search_key 無し・数字 → itemNumber
		$this->assertIsArray( $result );
		$this->assertSame( '1200', $result['price'] );
	}

	public function test_fetch_returns_null_when_credentials_missing(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_account_rakuten_credentials', '' )
			->andReturn( '' );

		$this->assertNull( ( new RakutenProvider() )->fetch( '123', array() ) );
	}

	public function test_fetch_returns_null_for_empty_external_id(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_account_rakuten_credentials', '' )
			->andReturn( $this->encryptedCredentials() );

		$this->assertNull( ( new RakutenProvider() )->fetch( '', array() ) );
	}

	/**
	 * @param mixed  $remoteReturn wp_remote_get の戻り値
	 * @param int    $code         HTTP ステータス
	 * @param string $body         レスポンスボディ
	 * @dataProvider provideFetchFailureCases
	 */
	public function test_fetch_returns_null_on_api_failure( $remoteReturn, int $code, string $body ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_account_rakuten_credentials', '' )
			->andReturn( $this->encryptedCredentials() );
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://shop.example' );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() のテストダブル
				return parse_url( $url );
			}
		);
		WP_Mock::userFunction( 'wp_remote_get' )->once()->andReturn( $remoteReturn );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( $code );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );

		$this->assertNull( ( new RakutenProvider() )->fetch( '123', array() ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: int, 2: string}>
	 */
	public function provideFetchFailureCases(): array {
		return array(
			'wp_error'       => array( new \WP_Error(), 0, '' ),
			'non_200'        => array( array( 'response' => array( 'code' => 429 ) ), 429, '' ),
			'errors_in_body' => array(
				array( 'response' => array( 'code' => 200 ) ),
				200,
				json_encode( array( 'errors' => array( 'errorCode' => 403 ) ) ),
			),
			'empty_items'    => array(
				array( 'response' => array( 'code' => 200 ) ),
				200,
				json_encode( array( 'Items' => array() ) ),
			),
			'not_json'       => array( array( 'response' => array( 'code' => 200 ) ), 200, 'not-json' ),
		);
	}

	public function test_normalize_date_returns_empty_for_invalid_format(): void {
		$this->stubFetchResponse(
			array(
				'title'     => 'サンプル作品',
				'salesDate' => '発売日未定',
			)
		);
		$result = ( new RakutenProvider() )->fetch( '123', array() );
		$this->assertSame( '', $result['platform_extras']['release_date'] );
	}
}
