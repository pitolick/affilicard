<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Stock;

use Affilicard\Stock\ReleaseDate;
use PHPUnit\Framework\TestCase;
use WP_Mock;

final class ReleaseDateTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		// __ は書式文字列（第1引数）をそのまま返し、sprintf で穴埋めさせる。
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_is_preorder_true_when_today_before_release(): void {
		$this->assertTrue( ReleaseDate::isPreorder( '2026-07-17', '2026-06-29' ) );
	}

	public function test_is_preorder_false_on_release_day(): void {
		$this->assertFalse( ReleaseDate::isPreorder( '2026-07-17', '2026-07-17' ) );
	}

	public function test_is_preorder_false_after_release(): void {
		$this->assertFalse( ReleaseDate::isPreorder( '2026-07-17', '2026-07-18' ) );
	}

	public function test_is_preorder_false_when_empty_or_invalid(): void {
		$this->assertFalse( ReleaseDate::isPreorder( '', '2026-06-29' ) );
		$this->assertFalse( ReleaseDate::isPreorder( '2026/07/17', '2026-06-29' ) );
	}

	public function test_label_formats_japanese_date(): void {
		$this->assertSame( '2026年7月17日発売', ReleaseDate::label( '2026-07-17' ) );
	}

	public function test_label_empty_for_invalid(): void {
		$this->assertSame( '', ReleaseDate::label( '' ) );
		$this->assertSame( '', ReleaseDate::label( 'bogus' ) );
	}
}
