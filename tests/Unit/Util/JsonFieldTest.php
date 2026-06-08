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

	public function test_encode_passes_unescaped_unicode_and_slashes_options(): void {
		WP_Mock::userFunction( 'wp_json_encode' )
			->once()
			->with( array( 'foo' => 'bar' ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			->andReturn( '{"foo":"bar"}' );

		$this->assertSame( '{"foo":"bar"}', JsonField::encode( array( 'foo' => 'bar' ) ) );
	}

	public function test_encode_keeps_japanese_raw_not_unicode_escaped(): void {
		// 本物の wp_json_encode を、指定オプションを尊重する形で擬似再現する。
		WP_Mock::userFunction( 'wp_json_encode' )
			->andReturnUsing(
				static function ( $value, $options = 0 ) {
					return json_encode( $value, $options );
				}
			);

		$json = JsonField::encode(
			array(
				array(
					'label' => '著者',
					'value' => '架空 太郎',
				),
			)
		);

		$this->assertStringContainsString( '著者', $json );
		// PHP 文字列リテラル '\\u' はバックスラッシュ + u の2文字（= JSON の \uXXXX エスケープ列）を表す。
		$this->assertStringNotContainsString( '\\u', $json );
	}

	public function test_encode_falls_back_to_json_encode_when_wp_json_encode_fails(): void {
		WP_Mock::userFunction( 'wp_json_encode' )
			->andReturn( false );

		$result = JsonField::encode( array( 'foo' => 'bar' ) );
		$this->assertSame( '{"foo":"bar"}', $result );
	}

	public function test_encode_fallback_also_keeps_japanese_raw(): void {
		WP_Mock::userFunction( 'wp_json_encode' )->andReturn( false );
		$json = JsonField::encode( array( 'title' => '著者' ) );
		$this->assertStringContainsString( '著者', $json );
		// PHP 文字列リテラル '\\u' はバックスラッシュ + u の2文字（= JSON の \uXXXX エスケープ列）を表す。
		$this->assertStringNotContainsString( '\\u', $json );
	}

	public function test_decode_valid_json_returns_array(): void {
		$this->assertSame(
			array(
				'a' => 1,
				'b' => 2,
			),
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
