<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Stock;

use Affilicard\Stock\StockStatus;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class StockStatusTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_constants_match_design(): void {
		$this->assertSame( 'available', StockStatus::AVAILABLE );
		$this->assertSame( 'out_of_stock', StockStatus::OUT_OF_STOCK );
		$this->assertSame( 'discontinued', StockStatus::DISCONTINUED );
	}

	public function test_all_returns_three_known_values(): void {
		$this->assertSame(
			array( 'available', 'out_of_stock', 'discontinued' ),
			StockStatus::all()
		);
	}

	public function test_is_valid_distinguishes_known_and_unknown(): void {
		$this->assertTrue( StockStatus::isValid( 'available' ) );
		$this->assertTrue( StockStatus::isValid( 'out_of_stock' ) );
		$this->assertTrue( StockStatus::isValid( 'discontinued' ) );
		$this->assertFalse( StockStatus::isValid( 'unknown' ) );
		$this->assertFalse( StockStatus::isValid( '' ) );
	}

	public function test_normalize_falls_back_to_available_for_invalid_or_null(): void {
		$this->assertSame( 'available', StockStatus::normalize( null ) );
		$this->assertSame( 'available', StockStatus::normalize( '' ) );
		$this->assertSame( 'available', StockStatus::normalize( 'bogus' ) );
		$this->assertSame( 'out_of_stock', StockStatus::normalize( 'out_of_stock' ) );
	}

	public function test_label_returns_japanese_via_translation(): void {
		WP_Mock::userFunction( '__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);

		$this->assertSame( '販売中', StockStatus::label( StockStatus::AVAILABLE ) );
		$this->assertSame( '在庫切れ', StockStatus::label( StockStatus::OUT_OF_STOCK ) );
		$this->assertSame( '取扱終了', StockStatus::label( StockStatus::DISCONTINUED ) );
		$this->assertSame( '販売中', StockStatus::label( 'unknown' ) );
	}
}
