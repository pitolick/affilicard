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

	public function test_data_field_constant_is_affilicard_data(): void {
		$this->assertSame( 'affilicard_data', ProductMetaBox::DATA_FIELD );
	}

	public function test_register_hooks_include_save_post_handler(): void {
		WP_Mock::expectActionAdded( 'add_meta_boxes', array( ProductMetaBox::class, 'addMetaBox' ) );
		WP_Mock::expectActionAdded( 'admin_enqueue_scripts', array( ProductMetaBox::class, 'enqueueAssets' ) );
		WP_Mock::expectActionAdded(
			'save_post_' . ProductPostType::POST_TYPE,
			array( ProductMetaBox::class, 'handleSave' ),
			10,
			1
		);

		ProductMetaBox::register();

		$this->assertConditionsMet();
	}

	public function test_handleSave_returns_early_when_nonce_missing(): void {
		// Clean any leftover $_POST.
		$_POST = array();

		WP_Mock::userFunction( 'wp_is_post_autosave' )->andReturn( false );
		WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		// update_post_meta must NOT be called.
		WP_Mock::userFunction( 'update_post_meta' )->never();

		ProductMetaBox::handleSave( 10 );

		$this->assertConditionsMet();
	}

	public function test_handleSave_returns_early_on_autosave(): void {
		WP_Mock::userFunction( 'wp_is_post_autosave' )
			->with( 10 )
			->andReturn( true );
		WP_Mock::userFunction( 'update_post_meta' )->never();

		ProductMetaBox::handleSave( 10 );

		$this->assertConditionsMet();
	}

	public function test_handleSave_persists_meta_on_valid_nonce_and_capability(): void {
		$post_id = 77;

		$payload = array(
			'product_type' => 'ebook',
			'stock_status' => 'available',
			'extras'       => array(),
			'listings'     => array(),
		);

		$_POST = array(
			ProductMetaBox::NONCE_NAME => 'valid-nonce',
			ProductMetaBox::DATA_FIELD => json_encode( $payload ),
		);

		WP_Mock::userFunction( 'wp_is_post_autosave' )->andReturn( false );
		WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );

		WP_Mock::userFunction( 'wp_unslash' )
			->andReturnUsing(
				static function ( $v ) {
					return $v;
				}
			);

		WP_Mock::userFunction( 'sanitize_text_field' )
			->andReturnUsing(
				static function ( $v ) {
					return $v;
				}
			);

		WP_Mock::userFunction( 'wp_verify_nonce' )
			->with( 'valid-nonce', ProductMetaBox::NONCE_ACTION )
			->andReturn( true );

		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_post', $post_id )
			->andReturn( true );

		WP_Mock::userFunction( 'sanitize_key' )
			->andReturnUsing(
				static function ( $v ) {
					return $v;
				}
			);

		WP_Mock::userFunction( 'sanitize_text_field' )
			->andReturnUsing(
				static function ( $v ) {
					return $v;
				}
			);

		WP_Mock::userFunction( 'esc_url_raw' )
			->andReturnUsing(
				static function ( $v ) {
					return $v;
				}
			);

		WP_Mock::userFunction( 'wp_json_encode' )
			->andReturnUsing(
				static function ( $v ) {
					return json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				}
			);

		$called_keys = array();
		WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $pid, $key, $value ) use ( &$called_keys, $post_id ) {
					$this->assertSame( $post_id, $pid );
					$called_keys[] = $key;
					return true;
				}
			);

		ProductMetaBox::handleSave( $post_id );

		$this->assertContains( \Affilicard\PostType\ProductPostType::META_PRODUCT_TYPE, $called_keys );
		$this->assertContains( \Affilicard\PostType\ProductPostType::META_STOCK_STATUS, $called_keys );
		$this->assertContains( \Affilicard\PostType\ProductPostType::META_EXTRAS, $called_keys );
		$this->assertContains( \Affilicard\PostType\ProductPostType::META_LISTINGS, $called_keys );
		$this->assertContains( \Affilicard\PostType\ProductPostType::META_SCHEMA_VERSION, $called_keys );

		$_POST = array();
	}

	public function test_handleSave_returns_early_when_capability_fails(): void {
		$post_id = 88;

		$_POST = array(
			ProductMetaBox::NONCE_NAME => 'some-nonce',
			ProductMetaBox::DATA_FIELD => '{}',
		);

		WP_Mock::userFunction( 'wp_is_post_autosave' )->andReturn( false );
		WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		WP_Mock::userFunction( 'wp_unslash' )
			->andReturnUsing(
				static function ( $v ) {
					return $v;
				}
			);
		WP_Mock::userFunction( 'sanitize_text_field' )
			->andReturnUsing(
				static function ( $v ) {
					return $v;
				}
			);
		WP_Mock::userFunction( 'wp_verify_nonce' )
			->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_post', $post_id )
			->andReturn( false );

		WP_Mock::userFunction( 'update_post_meta' )->never();

		ProductMetaBox::handleSave( $post_id );

		$this->assertConditionsMet();

		$_POST = array();
	}
}
