<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Types;

use Affilicard\Types\VodType;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class VodTypeTest extends TestCase {

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
		$type = new VodType();
		$this->assertSame( 'vod', $type->code() );
		$this->assertSame( '動画配信', $type->label() );
	}

	public function test_extras_schema_returns_three_recommended_fields(): void {
		$type   = new VodType();
		$schema = $type->extrasSchema();

		$this->assertCount( 3, $schema );
		$this->assertSame( 'director', $schema[0]['key'] );
		$this->assertSame( '監督', $schema[0]['label'] );
		$this->assertSame( 'cast', $schema[1]['key'] );
		$this->assertSame( '出演', $schema[1]['label'] );
		$this->assertSame( 'distributor', $schema[2]['key'] );
		$this->assertSame( '配給', $schema[2]['label'] );
		$this->assertSame( 'header', $schema[0]['card'] );
		$this->assertSame( 'header', $schema[1]['card'] );
		$this->assertSame( 'detail', $schema[2]['card'] );
	}

	public function test_card_header_keys_are_director_and_cast(): void {
		$type = new VodType();
		$this->assertSame( array( 'director', 'cast' ), $type->cardHeaderKeys() );
	}

	public function test_card_hidden_keys_is_empty(): void {
		$type = new VodType();
		$this->assertSame( array(), $type->cardHiddenKeys() );
	}

	public function test_card_media_label(): void {
		$type = new VodType();
		$this->assertSame( 'キービジュアル', $type->cardMediaLabel() );
	}

	public function test_card_media_aspect_ratio_defaults_to_square(): void {
		$type = new VodType();
		$this->assertSame( '1 / 1', $type->cardMediaAspectRatio() );
	}

	public function test_extract_extras_from_provider_returns_empty(): void {
		$type = new VodType();
		$this->assertSame( array(), $type->extractExtrasFromProvider( 'manual', array() ) );
		$this->assertSame( array(), $type->extractExtrasFromProvider( 'dmm-ebook', array( 'isbn' => '123' ) ) );
	}
}
