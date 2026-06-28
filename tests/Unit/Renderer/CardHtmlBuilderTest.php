<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Renderer;

use Affilicard\Renderer\CardHtmlBuilder;
use PHPUnit\Framework\TestCase;
use WP_Mock;

final class CardHtmlBuilderTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		WP_Mock::passthruFunction( 'esc_html' );
		WP_Mock::passthruFunction( 'esc_attr' );
		WP_Mock::passthruFunction( 'esc_url' );
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'sanitize_hex_color', array( 'return_arg' => 0 ) );
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_build_sanitizes_cta_overrides_and_filters_unknown_codes(): void {
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn( $v ) => is_string( $v ) ? trim( $v ) : ''
		);
		$known = array( 'dmm-books' );

		$builder = new CardHtmlBuilder();
		$result  = $builder->sanitizeCtaOverrides(
			array(
				'dmm-books'  => '  今すぐ読む  ',
				'unknown-pf' => '無視される',
			),
			$known
		);

		$this->assertSame( array( 'dmm-books' => '今すぐ読む' ), $result );
	}

	public function test_sanitize_removes_empty_after_trim(): void {
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn( $v ) => is_string( $v ) ? trim( $v ) : ''
		);

		$builder = new CardHtmlBuilder();
		$result  = $builder->sanitizeCtaOverrides(
			array(
				'dmm-books'  => '   ',
				'bookwalker' => 'BookWalkerで読む',
			),
			array( 'dmm-books', 'bookwalker' )
		);

		// 空白のみは空文字列になりスキップされる
		$this->assertArrayNotHasKey( 'dmm-books', $result );
		$this->assertSame( 'BookWalkerで読む', $result['bookwalker'] );
	}

	public function test_build_returns_html_for_published_product(): void {
		\WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		\WP_Mock::userFunction( 'get_post_thumbnail_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn( $v ) => is_string( $v ) ? trim( $v ) : ''
		);

		$builder = new CardHtmlBuilder();
		$product = array(
			'id'           => 1,
			'title'        => 'テスト商品',
			'content'      => '',
			'status'       => 'publish',
			'product_type' => 'generic',
			'stock_status' => 'available',
			'extras'       => array(),
			'listings'     => array(),
			'modified'     => '',
		);

		$html = $builder->build( $product, array() );
		$this->assertStringContainsString( 'テスト商品', $html );
		$this->assertStringContainsString( 'affilicard-card', $html );
	}

	public function test_sanitize_only_platforms_keeps_known_codes_only(): void {
		$builder = new CardHtmlBuilder();
		$result  = $builder->sanitizeOnlyPlatforms(
			array( 'dmm-books', 'unknown-pf', 123 ),
			array( 'dmm-books', 'example-store' )
		);
		$this->assertSame( array( 'dmm-books' ), $result );
	}

	public function test_build_passes_cta_label_overrides_to_renderer(): void {
		\WP_Mock::userFunction( 'get_option' )->andReturn(
			array(
				array(
					'code'             => 'dmm-books',
					'name'             => 'DMMブックス',
					'provider'         => 'dmm-ebook',
					'displayOrder'     => 1,
					'enabled'          => true,
					'applicableTypes'  => array( 'ebook' ),
					'buttonLabel'      => 'この値段で読む →',
					'brandColor'       => '#d72d65',
					'buttonTextColor'  => '#ffffff',
					'autoRefresh'      => true,
					'refreshFrequency' => 'weekly',
				),
			)
		);
		\WP_Mock::userFunction( 'get_post_thumbnail_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn( $v ) => is_string( $v ) ? trim( $v ) : ''
		);

		$builder = new CardHtmlBuilder();
		$product = array(
			'id'           => 1,
			'title'        => 'テスト漫画',
			'content'      => '',
			'status'       => 'publish',
			'product_type' => 'ebook',
			'stock_status' => 'available',
			'extras'       => array(),
			'listings'     => array(
				array(
					'platform'      => 'dmm-books',
					'enabled'       => true,
					'affiliate_url' => 'https://al.dmm.com/x',
				),
			),
			'modified'     => '',
		);

		$html = $builder->build( $product, array( 'ctaLabelOverrides' => array( 'dmm-books' => 'ブロック上書き' ) ) );
		$this->assertStringContainsString( 'ブロック上書き', $html );
		$this->assertStringNotContainsString( 'この値段で読む →', $html );
	}
}
