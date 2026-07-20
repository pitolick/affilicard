<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Platform;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Platform\PlatformDefinition;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class PlatformConfigTest extends TestCase {

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

	public function test_all_returns_empty_when_option_missing(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_platforms', array() )
			->andReturn( array() );

		$this->assertSame( array(), PlatformConfig::all() );
	}

	public function test_all_returns_sorted_platform_definitions(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_platforms', array() )
			->andReturn(
				array(
					array(
						'code'            => 'b-platform',
						'name'            => 'B',
						'provider'        => 'manual',
						'displayOrder'    => 5,
						'enabled'         => true,
						'applicableTypes' => array( 'generic' ),
						'buttonLabel'     => 'Bを買う',
						'brandColor'      => '#000000',
						'buttonTextColor' => '#ffffff',
					),
					array(
						'code'            => 'a-platform',
						'name'            => 'A',
						'provider'        => 'manual',
						'displayOrder'    => 1,
						'enabled'         => true,
						'applicableTypes' => array( 'generic' ),
						'buttonLabel'     => 'Aを買う',
						'brandColor'      => '#111111',
						'buttonTextColor' => '#ffffff',
					),
				)
			);

		$result = PlatformConfig::all();

		$this->assertCount( 2, $result );
		$this->assertSame( 'a-platform', $result[0]->code );
		$this->assertSame( 'b-platform', $result[1]->code );
		$this->assertInstanceOf( PlatformDefinition::class, $result[0] );
	}

	public function test_find_returns_match_or_null(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_platforms', array() )
			->andReturn(
				array(
					array(
						'code'            => 'dmm-books',
						'name'            => 'DMMブックス',
						'provider'        => 'dmm-ebook',
						'displayOrder'    => 1,
						'enabled'         => true,
						'applicableTypes' => array( 'ebook' ),
						'buttonLabel'     => 'この値段で読む →',
						'brandColor'      => '#d72d65',
						'buttonTextColor' => '#ffffff',
					),
				)
			);

		$found = PlatformConfig::find( 'dmm-books' );
		$this->assertNotNull( $found );
		$this->assertSame( 'dmm-books', $found->code );

		$missing = PlatformConfig::find( 'nope' );
		$this->assertNull( $missing );
	}

	public function test_save_deduplicates_by_code_and_sorts_by_display_order(): void {
		$first  = new PlatformDefinition(
			'dup',
			'first',
			'manual',
			5,
			true,
			array( 'generic' ),
			'first-label',
			'#000000',
			'#ffffff'
		);
		$second = new PlatformDefinition(
			'dup',
			'second',
			'manual',
			3,
			true,
			array( 'generic' ),
			'second-label',
			'#222222',
			'#ffffff'
		);
		$other  = new PlatformDefinition(
			'other',
			'other',
			'manual',
			1,
			true,
			array( 'generic' ),
			'other-label',
			'#333333',
			'#ffffff'
		);

		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( 'affilicard_platforms', $key );
					$this->assertFalse( $autoload );
					$this->assertIsArray( $value );
					$this->assertCount( 2, $value );
					// dedupe: last write wins → 'dup' は second（displayOrder=3, label=second-label）
					// sort: other (1) → dup (3)
					$this->assertSame( 'other', $value[0]['code'] );
					$this->assertSame( 'dup', $value[1]['code'] );
					$this->assertSame( 'second-label', $value[1]['buttonLabel'] );
					return true;
				}
			);

		PlatformConfig::save( array( $first, $other, $second ) );
	}

	public function test_defaults_returns_eight_entries_with_expected_codes(): void {
		$defaults = PlatformConfig::defaults();

		$this->assertCount( 8, $defaults );
		$codes = array_map(
			static function ( PlatformDefinition $d ): string {
				return $d->code;
			},
			$defaults
		);
		$this->assertSame(
			array( 'dmm-books', 'amazon-kindle', 'rakuten-kobo', 'u-next', 'netflix', 'hulu', 'prime-video', 'danime' ),
			$codes
		);
		$this->assertSame( 'dmm-ebook', $defaults[0]->provider );
		$this->assertSame( 'manual', $defaults[1]->provider );
		$this->assertSame( 1, $defaults[0]->displayOrder );
		$this->assertSame( 3, $defaults[2]->displayOrder );
	}

	public function test_defaults_include_vod_platforms(): void {
		$defaults = PlatformConfig::defaults();
		$this->assertCount( 8, $defaults );

		$by_code = array();
		foreach ( $defaults as $def ) {
			$by_code[ $def->code ] = $def;
		}

		foreach ( array( 'u-next', 'netflix', 'hulu', 'prime-video', 'danime' ) as $code ) {
			$this->assertArrayHasKey( $code, $by_code, "VOD platform {$code} が defaults に存在する" );
			$this->assertSame( 'manual', $by_code[ $code ]->provider );
			$this->assertSame( array( 'vod' ), $by_code[ $code ]->applicableTypes );
			$this->assertTrue( $by_code[ $code ]->enabled );
		}

		// ebook (1-3) に続く VOD の displayOrder が 5-9 であることを確認する（4 は BookWalker 削除で欠番）。
		$expected_orders = array(
			'u-next'      => 5,
			'netflix'     => 6,
			'hulu'        => 7,
			'prime-video' => 8,
			'danime'      => 9,
		);
		foreach ( $expected_orders as $code => $order ) {
			$this->assertSame( $order, $by_code[ $code ]->displayOrder, "{$code} の displayOrder" );
		}
	}

	public function test_defaults_dmm_books_has_auto_refresh_on(): void {
		$defaults = PlatformConfig::defaults();
		$dmm      = array_values( array_filter( $defaults, static fn( $d ) => 'dmm-books' === $d->code ) )[0];
		$this->assertTrue( $dmm->autoRefresh );
	}
}
