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
