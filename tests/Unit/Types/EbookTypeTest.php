<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Types;

use Affilicard\Types\EbookType;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class EbookTypeTest extends TestCase {

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

	public function test_code_and_label(): void {
		$type = new EbookType();
		$this->assertSame( 'ebook', $type->code() );
		$this->assertSame( '電子書籍', $type->label() );
	}

	public function test_extras_schema_returns_three_recommended_fields(): void {
		$type   = new EbookType();
		$schema = $type->extrasSchema();

		$this->assertCount( 3, $schema );
		$this->assertSame( 'author', $schema[0]['key'] );
		$this->assertSame( '著者', $schema[0]['label'] );
		$this->assertSame( 'publisher', $schema[1]['key'] );
		$this->assertSame( '出版社', $schema[1]['label'] );
		$this->assertSame( 'isbn', $schema[2]['key'] );
		$this->assertSame( 'ISBN', $schema[2]['label'] );
	}

	public function test_extract_extras_from_provider_with_dmm_ebook(): void {
		$type = new EbookType();
		$raw  = array(
			'iteminfo' => array(
				'author' => array(
					array( 'name' => '夏目漱石' ),
				),
				'maker'  => array(
					array( 'name' => 'Pitolick Books' ),
				),
			),
			'isbn'     => '978-4-1234-5678-9',
		);

		$result = $type->extractExtrasFromProvider( 'dmm-ebook', $raw );

		$this->assertSame(
			array(
				array( 'key' => 'author',    'label' => '著者',    'value' => '夏目漱石' ),
				array( 'key' => 'publisher', 'label' => '出版社',  'value' => 'Pitolick Books' ),
				array( 'key' => 'isbn',      'label' => 'ISBN',    'value' => '978-4-1234-5678-9' ),
			),
			$result
		);
	}

	public function test_extract_extras_from_provider_skips_missing_keys(): void {
		$type = new EbookType();

		// author だけある／publisher と isbn は欠損。
		$result = $type->extractExtrasFromProvider(
			'dmm-ebook',
			array(
				'iteminfo' => array(
					'author' => array(
						array( 'name' => 'Only Author' ),
					),
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 'author', $result[0]['key'] );
		$this->assertSame( 'Only Author', $result[0]['value'] );
	}

	public function test_extract_extras_from_provider_with_unknown_provider_returns_empty(): void {
		$type = new EbookType();

		$this->assertSame(
			array(),
			$type->extractExtrasFromProvider(
				'unknown-provider',
				array(
					'iteminfo' => array( 'author' => array( array( 'name' => 'X' ) ) ),
					'isbn'     => '123',
				)
			)
		);
	}

	public function test_validate_extras_keeps_known_keys_and_strips_unknown(): void {
		$type = new EbookType();

		$result = $type->validateExtras(
			array(
				array( 'label' => '著者',     'value' => '夏目漱石',  'key' => 'author' ),
				array( 'label' => 'カラー',   'value' => '赤',        'key' => 'randomkey' ),
				array( 'label' => 'ISBN',     'value' => '978-foo',  'key' => 'isbn' ),
				array( 'label' => '',         'value' => 'dropped'  ),
				array( 'label' => 'no-value', 'value' => '' ),
			)
		);

		$this->assertSame(
			array(
				array( 'label' => '著者',   'value' => '夏目漱石', 'key' => 'author' ),
				array( 'label' => 'カラー', 'value' => '赤' ),
				array( 'label' => 'ISBN',   'value' => '978-foo',  'key' => 'isbn' ),
			),
			$result
		);
	}
}
