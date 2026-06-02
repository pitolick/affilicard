<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Block;

use Affilicard\Block\Block;
use Affilicard\Repository\ProductRepository;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class BlockTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::passthruFunction( 'esc_html' );
		WP_Mock::passthruFunction( 'esc_attr' );
		WP_Mock::passthruFunction( 'esc_url' );
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'sanitize_hex_color', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'wp_enqueue_style', array( 'return' => true ) );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * get_post / get_post_meta をモックし、find() が解決可能な投稿を返すようにする。
	 */
	private function mockFoundPost( int $id, string $title ): void {
		$post = (object) array(
			'ID'            => $id,
			'post_type'     => 'affilicard_product',
			'post_title'    => $title,
			'post_content'  => '',
			'post_status'   => 'publish',
			'post_modified' => '2026-06-01 00:00:00',
		);
		WP_Mock::userFunction( 'get_post', array( 'return' => $post ) );
		WP_Mock::userFunction( 'get_post_meta', array( 'return' => '' ) );
	}

	public function test_render_resolves_by_product_id(): void {
		$this->mockFoundPost( 7, '解決された商品' );
		WP_Mock::userFunction( 'get_option', array( 'return' => array() ) );
		WP_Mock::userFunction( 'get_post_thumbnail_id', array( 'return' => 0 ) );

		$block = new Block( new ProductRepository() );
		$html  = $block->render( array( 'productId' => 7 ) );

		$this->assertStringContainsString( '解決された商品', $html );
	}

	public function test_render_returns_empty_for_non_published_product(): void {
		// 下書き/非公開商品は公開フロントに描画しない。
		$post = (object) array(
			'ID'            => 7,
			'post_type'     => 'affilicard_product',
			'post_title'    => '下書き商品',
			'post_content'  => '',
			'post_status'   => 'draft',
			'post_modified' => '2026-06-01 00:00:00',
		);
		WP_Mock::userFunction( 'get_post', array( 'return' => $post ) );
		WP_Mock::userFunction( 'get_post_meta', array( 'return' => '' ) );

		$block = new Block( new ProductRepository() );

		$this->assertSame( '', $block->render( array( 'productId' => 7 ) ) );
	}

	public function test_render_resolves_by_slug_when_no_id(): void {
		WP_Mock::userFunction( 'get_posts', array( 'return' => array( 7 ) ) );
		$this->mockFoundPost( 7, '解決された商品' );
		WP_Mock::userFunction( 'get_option', array( 'return' => array() ) );
		WP_Mock::userFunction( 'get_post_thumbnail_id', array( 'return' => 0 ) );

		$block = new Block( new ProductRepository() );
		$html  = $block->render( array( 'slug' => 'my-slug' ) );

		$this->assertStringContainsString( '解決された商品', $html );
	}

	public function test_render_resolves_by_external_id_and_platform(): void {
		WP_Mock::userFunction( 'get_posts', array( 'return' => array( 7 ) ) );
		$this->mockFoundPost( 7, '解決された商品' );
		WP_Mock::userFunction( 'get_option', array( 'return' => array() ) );
		WP_Mock::userFunction( 'get_post_thumbnail_id', array( 'return' => 0 ) );

		$block = new Block( new ProductRepository() );
		$html  = $block->render(
			array(
				'externalId' => '56869',
				'platform'   => 'dmm-books',
			)
		);

		$this->assertStringContainsString( '解決された商品', $html );
	}

	public function test_render_returns_empty_when_unresolved(): void {
		$block = new Block( new ProductRepository() );
		$this->assertSame( '', $block->render( array() ) );
	}

	public function test_render_returns_empty_when_product_not_found(): void {
		WP_Mock::userFunction( 'get_post', array( 'return' => null ) );

		$block = new Block( new ProductRepository() );
		$this->assertSame( '', $block->render( array( 'productId' => 999 ) ) );
	}

	public function test_render_passes_color_attributes_as_css_vars(): void {
		$this->mockFoundPost( 7, '解決された商品' );
		WP_Mock::userFunction( 'get_option', array( 'return' => array() ) );
		WP_Mock::userFunction( 'get_post_thumbnail_id', array( 'return' => 0 ) );

		$block = new Block( new ProductRepository() );
		$html  = $block->render(
			array(
				'productId'  => 7,
				'ctaBgColor' => '#abcdef',
			)
		);

		$this->assertStringContainsString( '--affilicard-cta-bg:#abcdef', $html );
	}

	public function test_register_registers_block_type(): void {
		WP_Mock::userFunction( 'wp_register_script', array( 'return' => true ) );
		WP_Mock::userFunction( 'wp_register_style', array( 'return' => true ) );
		WP_Mock::userFunction( 'wp_set_script_translations', array( 'return' => true ) );
		WP_Mock::userFunction( 'register_block_type', array( 'times' => 1 ) );

		$block = new Block( new ProductRepository() );
		$block->register();

		$this->assertConditionsMet();
	}
}
