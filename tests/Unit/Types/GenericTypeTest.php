<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Types;

use Affilicard\Types\GenericType;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class GenericTypeTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_code_label_and_empty_schema(): void {
		$type = new GenericType();

		$this->assertSame( 'generic', $type->code() );
		$this->assertSame( '汎用', $type->label() );
		$this->assertSame( array(), $type->extrasSchema() );
	}

	public function test_card_header_and_hidden_keys_are_empty(): void {
		$this->assertSame( array(), ( new GenericType() )->cardHeaderKeys() );
		$this->assertSame( array(), ( new GenericType() )->cardHiddenKeys() );
	}

	public function test_card_media_label_default(): void {
		$this->assertSame( '商品画像', ( new GenericType() )->cardMediaLabel() );
	}

	public function test_card_media_aspect_ratio_defaults_to_square(): void {
		$this->assertSame( '1 / 1', ( new GenericType() )->cardMediaAspectRatio() );
	}

	public function test_extract_extras_from_provider_returns_empty(): void {
		$type = new GenericType();

		$this->assertSame(
			array(),
			$type->extractExtrasFromProvider( 'dmm-ebook', array( 'iteminfo' => array( 'author' => array( array( 'name' => 'x' ) ) ) ) )
		);
		$this->assertSame(
			array(),
			$type->extractExtrasFromProvider( 'unknown', array() )
		);
	}

	public function test_validate_extras_filters_empty_rows_and_strips_unknown_keys(): void {
		$type = new GenericType();

		$result = $type->validateExtras(
			array(
				array(
					'label' => 'Color',
					'value' => 'Red',
					'key'   => 'somekey',
				),
				array(
					'label' => '',
					'value' => 'no-label',
				),
				array(
					'label' => 'no-value',
					'value' => '',
				),
				array(
					'label' => '  Size  ',
					'value' => '  Large  ',
				),
				'not-an-array',
			)
		);

		// GenericType の schema は空なので、'somekey' を含むあらゆる key が剥がされる。
		$this->assertSame(
			array(
				array(
					'label' => 'Color',
					'value' => 'Red',
				),
				array(
					'label' => 'Size',
					'value' => 'Large',
				),
			),
			$result
		);
	}

	public function test_validate_extras_returns_empty_for_non_array_input(): void {
		$type = new GenericType();
		$this->assertSame( array(), $type->validateExtras( 'string' ) );
		$this->assertSame( array(), $type->validateExtras( null ) );
	}
}
