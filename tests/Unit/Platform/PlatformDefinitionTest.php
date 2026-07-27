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

	public function test_to_array_does_not_contain_removed_auto_refresh_fields(): void {
		$def = PlatformDefinition::fromArray( array( 'code' => 'x' ) );
		$arr = $def->toArray();
		$this->assertArrayNotHasKey( 'autoRefresh', $arr );
		$this->assertArrayNotHasKey( 'refreshIntervalHours', $arr );
	}

	public function test_from_array_ignores_legacy_auto_refresh_keys_without_error(): void {
		$def = PlatformDefinition::fromArray(
			array(
				'code'                 => 'x',
				'provider'             => 'dmm-ebook',
				'autoRefresh'          => true,
				'refreshIntervalHours' => 6,
				'refreshFrequency'     => 'daily',
				'eligibleProvider'     => 'dmm-ebook',
				'priceTtlHours'        => 12,
			)
		);

		$this->assertSame( 'dmm-ebook', $def->provider );
		$this->assertSame( 'dmm-ebook', $def->eligibleProvider );
		$this->assertSame( 12, $def->priceTtlHours );
		$this->assertFalse( property_exists( $def, 'autoRefresh' ) );
		$this->assertFalse( property_exists( $def, 'refreshIntervalHours' ) );
	}

	public function test_priceTtlHours_既定は24(): void {
		$def = PlatformDefinition::fromArray( array( 'code' => 'x' ) );
		$this->assertSame( 24, $def->priceTtlHours );
		$this->assertSame( 24, $def->toArray()['priceTtlHours'] );
	}

	public function test_toArrayはrefreshFrequencyを出力しない(): void {
		$def = PlatformDefinition::fromArray( array( 'code' => 'x' ) );
		$this->assertArrayNotHasKey( 'refreshFrequency', $def->toArray() );
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

	public function test_toArray_has_no_image_priority_key(): void {
		$def = new PlatformDefinition( 'store-a', 'ストアA', 'manual', 1, true, array( 'ebook' ), 'Aで読む', '#444444', '#ffffff' );
		$this->assertArrayNotHasKey( 'imagePriority', $def->toArray() );
	}

	public function test_fromArray_ignores_leftover_image_priority_from_old_installs(): void {
		// 旧バージョンで保存された option には imagePriority キーが残る。読み捨てて壊れないこと。
		$def = PlatformDefinition::fromArray(
			array(
				'code'          => 'store-a',
				'name'          => 'ストアA',
				'displayOrder'  => 2,
				'imagePriority' => 10,
			)
		);
		$this->assertSame( 'store-a', $def->code );
		$this->assertSame( 2, $def->displayOrder );
		$this->assertArrayNotHasKey( 'imagePriority', $def->toArray() );
	}
}
