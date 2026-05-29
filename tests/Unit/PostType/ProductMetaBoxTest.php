<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\PostType;

use Affilicard\PostType\ProductMetaBox;
use Affilicard\PostType\ProductPostType;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProductMetaBoxTest extends TestCase {

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

	public function test_register_hooks_add_meta_boxes_and_admin_enqueue_scripts(): void {
		WP_Mock::expectActionAdded( 'add_meta_boxes', array( ProductMetaBox::class, 'addMetaBox' ) );
		WP_Mock::expectActionAdded( 'admin_enqueue_scripts', array( ProductMetaBox::class, 'enqueueAssets' ) );

		ProductMetaBox::register();

		$this->assertConditionsMet();
	}

	public function test_addMetaBox_calls_add_meta_box_with_correct_arguments(): void {
		WP_Mock::userFunction( 'add_meta_box' )
			->once()
			->andReturnUsing(
				function ( $id, $title, $callback, $screen, $context, $priority ) {
					$this->assertSame( ProductMetaBox::META_BOX_ID, $id );
					$this->assertSame( 'Affilicard 商品設定', $title );
					$this->assertSame( array( ProductMetaBox::class, 'renderMetaBox' ), $callback );
					$this->assertSame( ProductPostType::POST_TYPE, $screen );
					$this->assertSame( 'normal', $context );
					$this->assertSame( 'high', $priority );
				}
			);

		ProductMetaBox::addMetaBox();

		$this->assertConditionsMet();
	}

	public function test_nonce_action_and_name_constants_match_design(): void {
		$this->assertSame( 'affilicard_metabox', ProductMetaBox::NONCE_ACTION );
		$this->assertSame( 'affilicard_metabox_nonce', ProductMetaBox::NONCE_NAME );
		$this->assertSame( 'affilicard_product_metabox', ProductMetaBox::META_BOX_ID );
	}
}
