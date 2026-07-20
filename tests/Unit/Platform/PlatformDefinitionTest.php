<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Platform;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Platform\PlatformDefinition;
use InvalidArgumentException;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class PlatformDefinitionTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_constructor_sets_readonly_properties(): void {
		$def = new PlatformDefinition(
			'dmm-books',
			'DMMブックス',
			'dmm-ebook',
			1,
			true,
			array( 'ebook' ),
			'この値段で読む →',
			'#d72d65',
			'#ffffff'
		);

		$this->assertSame( 'dmm-books', $def->code );
		$this->assertSame( 'DMMブックス', $def->name );
		$this->assertSame( 'dmm-ebook', $def->provider );
		$this->assertSame( 1, $def->displayOrder );
		$this->assertTrue( $def->enabled );
		$this->assertSame( array( 'ebook' ), $def->applicableTypes );
		$this->assertSame( 'この値段で読む →', $def->buttonLabel );
		$this->assertSame( '#d72d65', $def->brandColor );
		$this->assertSame( '#ffffff', $def->buttonTextColor );
	}

	public function test_to_array_round_trips_with_from_array(): void {
		$original = new PlatformDefinition(
			'amazon-kindle',
			'Amazon Kindle',
			'manual',
			2,
			true,
			array( 'ebook' ),
			'Kindleで読む',
			'#ff9900',
			'#000000'
		);

		$rebuilt = PlatformDefinition::fromArray( $original->toArray() );

		$this->assertSame( $original->toArray(), $rebuilt->toArray() );
	}

	public function test_from_array_applies_defaults_for_missing_keys(): void {
		$def = PlatformDefinition::fromArray( array( 'code' => 'custom' ) );

		$this->assertSame( 'custom', $def->code );
		$this->assertSame( 'custom', $def->name );
		$this->assertSame( 'manual', $def->provider );
		$this->assertSame( 999, $def->displayOrder );
		$this->assertTrue( $def->enabled );
		$this->assertSame( array( 'generic' ), $def->applicableTypes );
		$this->assertSame( '購入する', $def->buttonLabel );
		$this->assertSame( '#444444', $def->brandColor );
		$this->assertSame( '#ffffff', $def->buttonTextColor );
	}

	public function test_from_array_throws_when_code_is_empty(): void {
		$this->expectException( InvalidArgumentException::class );
		PlatformDefinition::fromArray( array( 'code' => '' ) );
	}

	public function test_constructor_throws_when_code_is_empty(): void {
		$this->expectException( InvalidArgumentException::class );
		new PlatformDefinition(
			'',
			'noname',
			'manual',
			1,
			true,
			array( 'generic' ),
			'buy',
			'#000000',
			'#ffffff'
		);
	}

	public function test_from_array_defaults_auto_refresh_off_and_interval_3h(): void {
		$d = PlatformDefinition::fromArray( array( 'code' => 'x' ) );
		$this->assertFalse( $d->autoRefresh );
		$this->assertSame( 3, $d->refreshIntervalHours );
	}

	public function test_from_array_reads_auto_refresh_and_migrates_frequency(): void {
		$d = PlatformDefinition::fromArray(
			array(
				'code'             => 'x',
				'autoRefresh'      => true,
				'refreshFrequency' => 'daily',
			)
		);
		$this->assertTrue( $d->autoRefresh );
		$this->assertSame( 24, $d->refreshIntervalHours );
	}

	public function test_from_array_未知のrefreshFrequency文字列は24hとして扱う(): void {
		$d = PlatformDefinition::fromArray(
			array(
				'code'             => 'x',
				'refreshFrequency' => 'hourly',
			)
		);
		$this->assertSame( 24, $d->refreshIntervalHours );
	}

	public function test_to_array_includes_new_fields(): void {
		$d   = PlatformDefinition::fromArray(
			array(
				'code'             => 'x',
				'autoRefresh'      => true,
				'refreshFrequency' => 'daily',
			)
		);
		$arr = $d->toArray();
		$this->assertTrue( $arr['autoRefresh'] );
		$this->assertSame( 24, $arr['refreshIntervalHours'] );
	}

	public function test_priceTtlHours_既定は24(): void {
		$def = PlatformDefinition::fromArray( array( 'code' => 'x' ) );
		$this->assertSame( 24, $def->priceTtlHours );
		$this->assertSame( 24, $def->toArray()['priceTtlHours'] );
	}

	public function test_refreshIntervalHours_明示値を保持(): void {
		$def = PlatformDefinition::fromArray(
			array(
				'code'                 => 'x',
				'refreshIntervalHours' => 3,
			)
		);
		$this->assertSame( 3, $def->refreshIntervalHours );
		$this->assertSame( 3, $def->toArray()['refreshIntervalHours'] );
	}

	public function test_旧refreshFrequencyを時間へ移行(): void {
		$daily  = PlatformDefinition::fromArray(
			array(
				'code'             => 'x',
				'refreshFrequency' => 'daily',
			)
		);
		$weekly = PlatformDefinition::fromArray(
			array(
				'code'             => 'y',
				'refreshFrequency' => 'weekly',
			)
		);
		$this->assertSame( 24, $daily->refreshIntervalHours );
		$this->assertSame( 168, $weekly->refreshIntervalHours );
	}

	public function test_refreshIntervalHours_1未満は既定3に矯正(): void {
		$def = PlatformDefinition::fromArray(
			array(
				'code'                 => 'x',
				'refreshIntervalHours' => 0,
			)
		);
		$this->assertSame( 3, $def->refreshIntervalHours );
	}

	public function test_toArrayはrefreshFrequencyを出力しない(): void {
		$def = PlatformDefinition::fromArray( array( 'code' => 'x' ) );
		$this->assertArrayNotHasKey( 'refreshFrequency', $def->toArray() );
	}

	public function test_imagePriority_defaults_to_999_when_absent(): void {
		$def = PlatformDefinition::fromArray( array( 'code' => 'x' ) );
		$this->assertSame( 999, $def->imagePriority );
	}

	public function test_imagePriority_roundtrips_through_fromArray_and_toArray(): void {
		$def = PlatformDefinition::fromArray(
			array(
				'code'          => 'dmm-books',
				'imagePriority' => 10,
			)
		);
		$this->assertSame( 10, $def->imagePriority );
		$this->assertSame( 10, $def->toArray()['imagePriority'] );
	}

	public function test_defaults_set_image_priority_for_book_platforms(): void {
		$by_code = array();
		foreach ( PlatformConfig::defaults() as $def ) {
			$by_code[ $def->code ] = $def->imagePriority;
		}
		$this->assertSame( 10, $by_code['dmm-books'] );
		$this->assertSame( 20, $by_code['amazon-kindle'] );
		$this->assertSame( 30, $by_code['rakuten-kobo'] );
	}

	public function test_eligibleProvider_toArrayとfromArrayを往復する(): void {
		$def = PlatformDefinition::fromArray(
			array(
				'code'             => 'rakuten-kobo',
				'eligibleProvider' => 'rakuten-kobo',
			)
		);
		$this->assertSame( 'rakuten-kobo', $def->eligibleProvider );
		$this->assertSame( 'rakuten-kobo', $def->toArray()['eligibleProvider'] );
	}

	public function test_eligibleProvider_欠損時は空文字(): void {
		$def = PlatformDefinition::fromArray( array( 'code' => 'x' ) );
		$this->assertSame( '', $def->eligibleProvider );
	}
}
