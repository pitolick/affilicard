<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Util;

use Affilicard\Util\Crypto;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class CryptoTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( 'wp_salt' )
			->with( 'auth' )
			->andReturn( 'test-salt-1234567890abcdef' );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_round_trip_returns_original_plaintext(): void {
		$plaintext = 'super-secret-api-key-12345';

		$encrypted = Crypto::encrypt( $plaintext );
		$this->assertNotSame( $plaintext, $encrypted );
		$this->assertNotEmpty( $encrypted );

		$decrypted = Crypto::decrypt( $encrypted );
		$this->assertSame( $plaintext, $decrypted );
	}

	public function test_decrypt_returns_empty_string_for_garbage(): void {
		$this->assertSame( '', Crypto::decrypt( 'not-base64!!!@@@' ) );
		$this->assertSame( '', Crypto::decrypt( '' ) );
		$this->assertSame( '', Crypto::decrypt( base64_encode( 'too-short' ) ) );
	}

	public function test_encrypt_produces_different_outputs_for_same_input(): void {
		$plaintext = 'same-plaintext';

		$first  = Crypto::encrypt( $plaintext );
		$second = Crypto::encrypt( $plaintext );

		// IV はランダムなので 2 回の暗号化結果は一致しない。
		$this->assertNotSame( $first, $second );

		// それぞれを復号すると元の平文に戻る。
		$this->assertSame( $plaintext, Crypto::decrypt( $first ) );
		$this->assertSame( $plaintext, Crypto::decrypt( $second ) );
	}
}
