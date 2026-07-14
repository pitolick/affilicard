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
}
