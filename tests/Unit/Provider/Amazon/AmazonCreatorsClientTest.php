<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider\Amazon;

use Affilicard\Provider\Amazon\AmazonCreatorsClient;
use Affilicard\Util\Crypto;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class AmazonCreatorsClientTest extends TestCase {

	private const CREDS = array(
		'credential_id'     => 'amzn1.application-oa2-client.test',
		'credential_secret' => 'secret',
		'partner_tag'       => 'example-22',
	);

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( 'wp_salt' )->with( 'auth' )->andReturn( 'test-salt-1234567890abcdef' );
		WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing(
			static fn( $value ) => $value instanceof \WP_Error
		);
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing(
			static fn( $value ) => json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing(
			static fn( $res ) => $res['response']['code']
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing(
			static fn( $res ) => $res['body']
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private static function response( int $code, array $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) json_encode( $body ),
		);
	}

	public function test_marketplace_は空なら日本_URLやスラッシュは落とす(): void {
		$this->assertSame( 'www.amazon.co.jp', AmazonCreatorsClient::marketplace( array() ) );
		$this->assertSame( 'www.amazon.com', AmazonCreatorsClient::marketplace( array( 'marketplace' => 'https://WWW.Amazon.com/' ) ) );
	}

	public function test_tokenHost_はマーケットプレイスのリージョンで決まる(): void {
		$this->assertSame( 'api.amazon.co.jp', AmazonCreatorsClient::tokenHost( 'www.amazon.co.jp' ) );
		$this->assertSame( 'api.amazon.co.jp', AmazonCreatorsClient::tokenHost( 'www.amazon.com.au' ) );
		$this->assertSame( 'api.amazon.com', AmazonCreatorsClient::tokenHost( 'www.amazon.com' ) );
		$this->assertSame( 'api.amazon.com', AmazonCreatorsClient::tokenHost( 'www.amazon.com.br' ) );
		$this->assertSame( 'api.amazon.co.uk', AmazonCreatorsClient::tokenHost( 'www.amazon.de' ) );
	}

	public function test_tokenTransientKey_は認証情報が変わると別キーになる(): void {
		$a = AmazonCreatorsClient::tokenTransientKey( self::CREDS );
		$b = AmazonCreatorsClient::tokenTransientKey( array_merge( self::CREDS, array( 'credential_id' => 'other' ) ) );
		$this->assertStringStartsWith( 'affilicard_amazon_token_', $a );
		$this->assertNotSame( $a, $b );
		$this->assertStringNotContainsString( self::CREDS['credential_id'], $a );
	}

	public function test_キャッシュが無ければトークンを取得して暗号化保存し_getItemsを送る(): void {
		WP_Mock::userFunction( 'get_transient' )->once()->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $ttl ) {
					$this->assertSame( AmazonCreatorsClient::tokenTransientKey( self::CREDS ), $key );
					$this->assertNotSame( 'tok-1', $value ); // 平文では保存しない
					$this->assertSame( 'tok-1', Crypto::decrypt( $value ) );
					$this->assertSame( 3540, $ttl ); // expires_in − 60 秒
					return true;
				}
			);

		$calls = array();
		WP_Mock::userFunction( 'wp_remote_post' )
			->twice()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$calls ) {
					$calls[] = array( $url, $args );
					if ( str_contains( $url, '/auth/o2/token' ) ) {
						return self::response(
							200,
							array(
								'access_token' => 'tok-1',
								'expires_in'   => 3600,
							)
						);
					}
					return self::response( 200, array( 'itemsResult' => array( 'items' => array() ) ) );
				}
			);

		$res = ( new AmazonCreatorsClient() )->getItems( self::CREDS, array( 'B008ODF84I' ), array( 'itemInfo.title' ) );

		$this->assertFalse( $res['error'] );
		$this->assertSame( 200, $res['code'] );
		$this->assertSame( 'https://api.amazon.co.jp/auth/o2/token', $calls[0][0] );
		$tokenBody = json_decode( $calls[0][1]['body'], true );
		$this->assertSame( 'creatorsapi::default', $tokenBody['scope'] );
		$this->assertSame( 'client_credentials', $tokenBody['grant_type'] );

		$this->assertSame( 'https://creatorsapi.amazon/catalog/v1/getItems', $calls[1][0] );
		$this->assertSame( 'Bearer tok-1', $calls[1][1]['headers']['Authorization'] );
		$this->assertSame( 'www.amazon.co.jp', $calls[1][1]['headers']['x-marketplace'] );
		$body = json_decode( $calls[1][1]['body'], true );
		$this->assertSame( array( 'B008ODF84I' ), $body['itemIds'] );
		$this->assertSame( 'example-22', $body['partnerTag'] );
		$this->assertSame( 'www.amazon.co.jp', $body['marketplace'] );
	}

	public function test_キャッシュ済みトークンがあればトークンを取りに行かない(): void {
		WP_Mock::userFunction( 'get_transient' )->once()->andReturn( Crypto::encrypt( 'cached-tok' ) );
		WP_Mock::userFunction( 'set_transient' )->never();
		WP_Mock::userFunction( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				function ( $url, $args ) {
					$this->assertStringContainsString( 'creatorsapi.amazon', $url );
					$this->assertSame( 'Bearer cached-tok', $args['headers']['Authorization'] );
					return self::response( 200, array() );
				}
			);

		$res = ( new AmazonCreatorsClient() )->getItems( self::CREDS, array( 'B008ODF84I' ), array() );
		$this->assertSame( 200, $res['code'] );
	}

	public function test_401ならトークンを捨てて1回だけ取り直して再送する(): void {
		WP_Mock::userFunction( 'get_transient' )->once()->andReturn( Crypto::encrypt( 'stale' ) );
		WP_Mock::userFunction( 'delete_transient' )->once()->andReturn( true );
		WP_Mock::userFunction( 'set_transient' )->once()->andReturn( true );

		$auths = array();
		WP_Mock::userFunction( 'wp_remote_post' )
			->times( 3 )
			->andReturnUsing(
				static function ( $url, $args ) use ( &$auths ) {
					if ( str_contains( $url, '/auth/o2/token' ) ) {
						return self::response(
							200,
							array(
								'access_token' => 'fresh',
								'expires_in'   => 3600,
							)
						);
					}
					$auths[] = $args['headers']['Authorization'];
					return 'Bearer stale' === $args['headers']['Authorization']
						? self::response( 401, array( 'reason' => 'InvalidToken' ) )
						: self::response( 200, array( 'ok' => true ) );
				}
			);

		$res = ( new AmazonCreatorsClient() )->getItems( self::CREDS, array( 'B008ODF84I' ), array() );

		$this->assertSame( 200, $res['code'] );
		$this->assertSame( array( 'Bearer stale', 'Bearer fresh' ), $auths );
		// API には 2 回届いている（呼び出し回数の集計に使う）。
		$this->assertSame( 2, $res['requests'] );
	}

	/**
	 * 401 のあとトークンを取り直せなかった・再送が通信断だったときも、最初の 401 は API に
	 * 届いているので requests は 1。
	 *
	 * @dataProvider provideRetryFailures
	 */
	public function test_401のあと取り直しや再送に失敗してもrequestsは1( bool $tokenFails ): void {
		WP_Mock::userFunction( 'get_transient' )->once()->andReturn( Crypto::encrypt( 'stale' ) );
		WP_Mock::userFunction( 'delete_transient' )->andReturn( true );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );
		WP_Mock::userFunction( 'wp_remote_post' )->andReturnUsing(
			static function ( $url, $args ) use ( $tokenFails ) {
				if ( str_contains( $url, '/auth/o2/token' ) ) {
					return $tokenFails
						? self::response( 401, array( 'error' => 'invalid_client' ) )
						: self::response(
							200,
							array(
								'access_token' => 'fresh',
								'expires_in'   => 3600,
							)
						);
				}
				return 'Bearer stale' === $args['headers']['Authorization']
					? self::response( 401, array( 'reason' => 'InvalidToken' ) )
					: new \WP_Error( 'http_request_failed', 'timeout' );
			}
		);

		$res = ( new AmazonCreatorsClient() )->getItems( self::CREDS, array( 'B008ODF84I' ), array() );

		$this->assertTrue( $res['error'] );
		$this->assertSame( 1, $res['requests'] );
	}

	/**
	 * @return array<string, array{0: bool}>
	 */
	public static function provideRetryFailures(): array {
		return array(
			'トークンを取り直せない' => array( true ),
			'再送が通信断'      => array( false ),
		);
	}

	public function test_トークンを取得できなければ_HTTPせずerrorを返す(): void {
		WP_Mock::userFunction( 'get_transient' )->once()->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->never();
		WP_Mock::userFunction( 'wp_remote_post' )
			->once()
			->andReturn( self::response( 401, array( 'error' => 'invalid_client' ) ) );

		$res = ( new AmazonCreatorsClient() )->getItems( self::CREDS, array( 'B008ODF84I' ), array() );

		$this->assertTrue( $res['error'] );
		$this->assertSame( 0, $res['code'] );
		$this->assertSame( 0, $res['requests'] );
	}

	public function test_通信断はerrorを返す(): void {
		WP_Mock::userFunction( 'get_transient' )->once()->andReturn( Crypto::encrypt( 'tok' ) );
		WP_Mock::userFunction( 'wp_remote_post' )->once()->andReturn( new \WP_Error( 'http_request_failed', 'timeout' ) );

		$res = ( new AmazonCreatorsClient() )->getItems( self::CREDS, array( 'B008ODF84I' ), array() );

		$this->assertTrue( $res['error'] );
	}
}
