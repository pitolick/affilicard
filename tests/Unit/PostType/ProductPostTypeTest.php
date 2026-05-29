<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\PostType;

use Affilicard\PostType\ProductPostType;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProductPostTypeTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_post_type_constant_matches_design(): void {
		$this->assertSame( 'affilicard_product', ProductPostType::POST_TYPE );
	}

	public function test_meta_key_constants_match_design(): void {
		$this->assertSame( 'affilicard_product_type', ProductPostType::META_PRODUCT_TYPE );
		$this->assertSame( 'affilicard_stock_status', ProductPostType::META_STOCK_STATUS );
		$this->assertSame( 'affilicard_extras', ProductPostType::META_EXTRAS );
		$this->assertSame( 'affilicard_listings', ProductPostType::META_LISTINGS );
		$this->assertSame( 'affilicard_schema_version', ProductPostType::META_SCHEMA_VERSION );
		$this->assertSame( 'affilicard_extid_', ProductPostType::META_EXTID_PREFIX );
	}

	public function test_external_id_meta_key_appends_platform_code(): void {
		$this->assertSame(
			'affilicard_extid_dmm-books',
			ProductPostType::externalIdMetaKey( 'dmm-books' )
		);
		$this->assertSame(
			'affilicard_extid_amazon',
			ProductPostType::externalIdMetaKey( 'amazon' )
		);
	}

	public function test_register_invokes_register_post_type_with_capability_type_post(): void {
		WP_Mock::userFunction( '__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);

		WP_Mock::userFunction( 'register_post_type' )
			->once()
			->with(
				'affilicard_product',
				WP_Mock\Functions::type( 'array' )
			)
			->andReturnUsing(
				function ( string $post_type, array $args ) {
					$this->assertSame( 'post', $args['capability_type'] );
					$this->assertTrue( $args['map_meta_cap'] );
					$this->assertTrue( $args['show_ui'] );
					$this->assertFalse( $args['public'] );
					$this->assertContains( 'title', $args['supports'] );
					$this->assertContains( 'thumbnail', $args['supports'] );
					$this->assertContains( 'author', $args['supports'] );
				}
			);

		ProductPostType::register();
	}
}
