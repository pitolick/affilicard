<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Util;

use Affilicard\Util\JsonField;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class JsonFieldTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_encode_returns_json_string_via_wp_json_encode(): void {
		WP_Mock::userFunction( 'wp_json_encode' )
			->once()
			->with( array( 'foo' => 'bar' ) )
			->andReturn( '{"foo":"bar"}' );

		$this->assertSame( '{"foo":"bar"}', JsonField::encode( array( 'foo' => 'bar' ) ) );
	}

	public function test_encode_falls_back_to_json_encode_when_wp_json_encode_fails(): void {
		WP_Mock::userFunction( 'wp_json_encode' )
			->andReturn( false );

		$result = JsonField::encode( array( 'foo' => 'bar' ) );
		$this->assertSame( '{"foo":"bar"}', $result );
	}

	public function test_decode_valid_json_returns_array(): void {
		$this->assertSame(
			array( 'a' => 1, 'b' => 2 ),
			JsonField::decode( '{"a":1,"b":2}' )
		);
	}

	public function test_decode_invalid_json_returns_default(): void {
		$this->assertSame( array(), JsonField::decode( 'not-json' ) );
		$this->assertSame(
			array( 'fallback' => true ),
			JsonField::decode( 'not-json', array( 'fallback' => true ) )
		);
	}

	public function test_decode_non_array_json_returns_default(): void {
		// JSON としては正当だが、配列ではない（スカラー）。
		$this->assertSame( array(), JsonField::decode( '"a string"' ) );
		$this->assertSame( array(), JsonField::decode( '42' ) );
		$this->assertSame(
			array( 'd' => 1 ),
			JsonField::decode( 'null', array( 'd' => 1 ) )
		);
	}
}
