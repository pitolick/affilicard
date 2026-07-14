<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderCredentials;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Rest\CredentialsController;
use Affilicard\Util\Crypto;
use Affilicard\Util\JsonField;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;

final class CredentialsControllerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
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

	/**
	 * 1 件だけの platform を返す get_option mock を仕込む。
	 */
	private function mockSinglePlatform( string $platformCode, string $providerCode ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'            => $platformCode,
						'name'            => $platformCode,
						'provider'        => $providerCode,
						'displayOrder'    => 1,
						'enabled'         => true,
						'applicableTypes' => array( 'ebook' ),
						'buttonLabel'     => 'L',
						'brandColor'      => '#000000',
						'buttonTextColor' => '#ffffff',
					),
				)
			);
	}

	public function test_get_returns_masked_credentials_via_provider_credentials_get_masked(): void {
		$this->mockSinglePlatform( 'dmm-books', 'dmm-ebook' );

		$values    = array(
			'api_id'       => 'apikey-abc',
			'affiliate_id' => 'aff-xyz',
		);
		$encrypted = Crypto::encrypt( JsonField::encode( $values ) );

		WP_Mock::userFunction( 'get_option' )
			->with( ProviderCredentials::optionKey( 'dmm-ebook' ), '' )
			->andReturn( $encrypted );

		$controller = new CredentialsController( new ProviderRegistry() );
		$request    = new WP_REST_Request( 'GET', '/' );
		$request->set_param( 'code', 'dmm-books' );

		$response = $controller->get( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( '********bc', $data['api_id'] );
		$this->assertSame( '*****yz', $data['affiliate_id'] );
	}

	public function test_get_returns_404_when_platform_not_found(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn( array() );

		$controller = new CredentialsController( new ProviderRegistry() );
		$request    = new WP_REST_Request( 'GET', '/' );
		$request->set_param( 'code', 'unknown' );

		$response = $controller->get( $request );

		$this->assertSame( 404, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'affilicard_platform_not_found', $data['code'] );
	}

	public function test_update_patches_and_returns_new_masked(): void {
		$this->mockSinglePlatform( 'dmm-books', 'dmm-ebook' );

		// patch() の current 取得用と、再表示用の get() の 2 回読み。
		WP_Mock::userFunction( 'get_option' )
			->with( ProviderCredentials::optionKey( 'dmm-ebook' ), '' )
			->andReturnUsing(
				static function ( $key, $default ) {
					static $first = true;
					if ( $first ) {
						$first = false;
						return '';
					}
					return Crypto::encrypt( JsonField::encode( array( 'api_id' => 'new-api-key' ) ) );
				}
			);

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( ProviderCredentials::optionKey( 'dmm-ebook' ), $key );
					$decrypted = Crypto::decrypt( $value );
					$decoded   = JsonField::decode( $decrypted );
					$this->assertSame( 'new-api-key', $decoded['api_id'] );
					return true;
				}
			);

		$controller = new CredentialsController( new ProviderRegistry() );
		$request    = new WP_REST_Request( 'PUT', '/' );
		$request->set_param( 'code', 'dmm-books' );
		$request->set_param( 'api_id', 'new-api-key' );

		$response = $controller->update( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'api_id', $data );
		$this->assertSame( '*********ey', $data['api_id'] );
	}

	public function test_test_connection_resolves_platform_to_provider_and_calls_test_connection(): void {
		$this->mockSinglePlatform( 'dmm-books', 'dmm-ebook' );

		WP_Mock::userFunction( 'get_option' )
			->with( ProviderCredentials::optionKey( 'dmm-ebook' ), '' )
			->andReturn( Crypto::encrypt( JsonField::encode( array( 'api_id' => 'KEY' ) ) ) );

		$provider = new class() implements ProviderInterface {
			public array $received_credentials = array();

			public function code(): string {
				return 'dmm-ebook';
			}

			public function label(): string {
				return 'DMM';
			}

			public function isAutomatic(): bool {
				return true;
			}

			public function accountCode(): ?string {
				return null;
			}

			public function fetch( string $externalId, array $platformConfig ): ?array {
				return null;
			}

			public function testConnection( array $credentials ): array {
				$this->received_credentials = $credentials;
				return array(
					'ok'      => true,
					'message' => '疎通 OK',
				);
			}
		};

		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$controller = new CredentialsController( $registry );
		$request    = new WP_REST_Request( 'POST', '/' );
		$request->set_param( 'code', 'dmm-books' );

		$response = $controller->testConnection( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( '疎通 OK', $data['message'] );
		$this->assertSame( 'KEY', $provider->received_credentials['api_id'] );
	}

	public function test_test_connection_returns_404_when_platform_not_found(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn( array() );

		$controller = new CredentialsController( new ProviderRegistry() );
		$request    = new WP_REST_Request( 'POST', '/' );
		$request->set_param( 'code', 'unknown' );

		$response = $controller->testConnection( $request );

		$this->assertSame( 404, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'affilicard_platform_not_found', $data['code'] );
	}

	public function test_test_connection_returns_404_when_provider_not_registered(): void {
		$this->mockSinglePlatform( 'dmm-books', 'missing-provider' );

		$controller = new CredentialsController( new ProviderRegistry() );
		$request    = new WP_REST_Request( 'POST', '/' );
		$request->set_param( 'code', 'dmm-books' );

		$response = $controller->testConnection( $request );

		$this->assertSame( 404, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['ok'] );
	}

	// ─── Provider 単位ルート ───────────────────────────────────────────────

	/**
	 * ProviderRegistry に provider を登録するヘルパ。
	 */
	private function makeRegistryWithProvider( string $providerCode ): ProviderRegistry {
		$provider = new class( $providerCode ) implements ProviderInterface {
			public function __construct( private string $code ) {}

			public function code(): string {
				return $this->code;
			}

			public function label(): string {
				return $this->code;
			}

			public function isAutomatic(): bool {
				return true;
			}

			public function accountCode(): ?string {
				return null;
			}

			public function fetch( string $externalId, array $platformConfig ): ?array {
				return null;
			}

			public function testConnection( array $credentials ): array {
				return array(
					'ok'      => true,
					'message' => '疎通 OK',
				);
			}
		};

		$registry = new ProviderRegistry();
		$registry->register( $provider );
		return $registry;
	}

	public function test_getProvider_returns_masked_credentials_for_known_provider(): void {
		$values    = array(
			'api_id'       => 'apikey-abc',
			'affiliate_id' => 'aff-xyz',
		);
		$encrypted = Crypto::encrypt( JsonField::encode( $values ) );

		WP_Mock::userFunction( 'get_option' )
			->with( ProviderCredentials::optionKey( 'dmm-ebook' ), '' )
			->andReturn( $encrypted );

		$registry   = $this->makeRegistryWithProvider( 'dmm-ebook' );
		$controller = new CredentialsController( $registry );
		$request    = new WP_REST_Request( 'GET', '/' );
		$request->set_param( 'code', 'dmm-ebook' );

		$response = $controller->getProvider( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( '********bc', $data['api_id'] );
		$this->assertSame( '*****yz', $data['affiliate_id'] );
	}

	public function test_getProvider_returns_404_for_unknown_provider(): void {
		$controller = new CredentialsController( new ProviderRegistry() );
		$request    = new WP_REST_Request( 'GET', '/' );
		$request->set_param( 'code', 'unknown-provider' );

		$response = $controller->getProvider( $request );

		$this->assertSame( 404, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'affilicard_provider_not_found', $data['code'] );
	}

	public function test_testConnectionProvider_returns_ok_for_known_provider(): void {
		$values    = array( 'api_id' => 'KEY' );
		$encrypted = Crypto::encrypt( JsonField::encode( $values ) );

		WP_Mock::userFunction( 'get_option' )
			->with( ProviderCredentials::optionKey( 'dmm-ebook' ), '' )
			->andReturn( $encrypted );

		$registry   = $this->makeRegistryWithProvider( 'dmm-ebook' );
		$controller = new CredentialsController( $registry );
		$request    = new WP_REST_Request( 'POST', '/' );
		$request->set_param( 'code', 'dmm-ebook' );

		$response = $controller->testConnectionProvider( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( '疎通 OK', $data['message'] );
	}

	public function test_testConnectionProvider_returns_404_for_unknown_provider(): void {
		$controller = new CredentialsController( new ProviderRegistry() );
		$request    = new WP_REST_Request( 'POST', '/' );
		$request->set_param( 'code', 'no-such-provider' );

		$response = $controller->testConnectionProvider( $request );

		$this->assertSame( 404, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'affilicard_provider_not_found', $data['code'] );
	}

	public function test_updateProvider_patches_and_returns_new_masked(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( ProviderCredentials::optionKey( 'dmm-ebook' ), '' )
			->andReturnUsing(
				static function ( $key, $default ) {
					static $first = true;
					if ( $first ) {
						$first = false;
						return '';
					}
					return Crypto::encrypt( JsonField::encode( array( 'api_id' => 'new-api-key' ) ) );
				}
			);

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( ProviderCredentials::optionKey( 'dmm-ebook' ), $key );
					$decrypted = Crypto::decrypt( $value );
					$decoded   = JsonField::decode( $decrypted );
					$this->assertSame( 'new-api-key', $decoded['api_id'] );
					return true;
				}
			);

		$registry   = $this->makeRegistryWithProvider( 'dmm-ebook' );
		$controller = new CredentialsController( $registry );
		$request    = new WP_REST_Request( 'PUT', '/' );
		$request->set_param( 'code', 'dmm-ebook' );
		$request->set_param( 'api_id', 'new-api-key' );

		$response = $controller->updateProvider( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'api_id', $data );
		$this->assertSame( '*********ey', $data['api_id'] );
	}
}
