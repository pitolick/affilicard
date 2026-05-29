<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider;

use Affilicard\Provider\ProviderCredentials;
use Affilicard\Util\Crypto;
use Affilicard\Util\JsonField;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProviderCredentialsTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
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

	public function test_option_key_uses_expected_prefix_and_suffix(): void {
		$this->assertSame(
			'affilicard_provider_dmm-ebook_credentials',
			ProviderCredentials::optionKey( 'dmm-ebook' )
		);
	}

	public function test_get_returns_empty_when_option_missing(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_provider_manual_credentials', '' )
			->andReturn( false );

		$this->assertSame( array(), ProviderCredentials::get( 'manual' ) );
	}

	public function test_get_decrypts_and_returns_credentials_round_trip(): void {
		$values    = array(
			'api_id'       => 'apikey-abc',
			'affiliate_id' => 'aff-xyz',
		);
		$encrypted = Crypto::encrypt( JsonField::encode( $values ) );

		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_provider_dmm-ebook_credentials', '' )
			->andReturn( $encrypted );

		$this->assertSame( $values, ProviderCredentials::get( 'dmm-ebook' ) );
	}

	public function test_get_masked_masks_values_correctly(): void {
		$values    = array(
			'long'   => 'abcdef12',
			'two'    => 'ab',
			'single' => 'x',
			'empty'  => '',
		);
		$encrypted = Crypto::encrypt( JsonField::encode( $values ) );

		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_provider_dmm-ebook_credentials', '' )
			->andReturn( $encrypted );

		$masked = ProviderCredentials::getMasked( 'dmm-ebook' );

		$this->assertSame( '******12', $masked['long'] );
		$this->assertSame( '**', $masked['two'] );
		$this->assertSame( '*', $masked['single'] );
		$this->assertSame( '', $masked['empty'] );
	}

	public function test_patch_merges_with_null_skip_and_empty_clear(): void {
		$existing  = array(
			'api_id'       => 'old-api',
			'affiliate_id' => 'old-aff',
		);
		$encrypted = Crypto::encrypt( JsonField::encode( $existing ) );

		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_provider_dmm-ebook_credentials', '' )
			->andReturn( $encrypted );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( 'affilicard_provider_dmm-ebook_credentials', $key );
					$this->assertFalse( $autoload );
					$this->assertIsString( $value );
					$this->assertNotEmpty( $value );

					$decrypted = Crypto::decrypt( $value );
					$decoded   = JsonField::decode( $decrypted );
					// null は無視されて api_id は維持、affiliate_id は空文字でクリア。
					$this->assertSame( 'old-api', $decoded['api_id'] );
					$this->assertSame( '', $decoded['affiliate_id'] );
					return true;
				}
			);

		ProviderCredentials::patch(
			'dmm-ebook',
			array(
				'api_id'       => null,
				'affiliate_id' => '',
			)
		);
	}

	public function test_patch_sets_new_value(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_provider_manual_credentials', '' )
			->andReturn( false );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( 'affilicard_provider_manual_credentials', $key );
					$this->assertFalse( $autoload );
					$decrypted = Crypto::decrypt( $value );
					$decoded   = JsonField::decode( $decrypted );
					$this->assertSame( 'new-token', $decoded['token'] );
					return true;
				}
			);

		ProviderCredentials::patch( 'manual', array( 'token' => 'new-token' ) );
	}

	public function test_delete_calls_delete_option_with_key(): void {
		WP_Mock::userFunction( 'delete_option' )
			->once()
			->with( 'affilicard_provider_dmm-ebook_credentials' )
			->andReturn( true );

		ProviderCredentials::delete( 'dmm-ebook' );

		// WP_Mock の expectation を最終アサーションとして消費する。
		$this->assertConditionsMet();
	}
}
