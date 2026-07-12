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

	/**
	 * テスト用商品配列を生成するヘルパ。デフォルト値に $overrides をマージして返す。
	 *
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function productWith( array $overrides = array() ): array {
		$defaults = array(
			'id'           => 1,
			'title'        => 'テスト商品',
			'content'      => '',
			'status'       => 'publish',
			'product_type' => 'generic',
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
		return array_merge( $defaults, $overrides );
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
		WP_Mock::userFunction( 'current_time', array( 'return' => '2026-06-29' ) );

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
			// 重複（dmm-books×2）・未知 code・非文字列を含め、既知 code のみ 1 件ずつ残ることを押さえる。
			array( 'dmm-books', 'dmm-books', 'unknown-pf', 123 ),
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
		WP_Mock::userFunction( 'current_time', array( 'return' => '2026-06-29' ) );

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

	public function test_future_release_date_renders_preorder(): void {
		WP_Mock::userFunction( 'current_time', array( 'return' => '2026-06-29' ) );
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
		$product = $this->productWith( array( 'release_date' => '2026-07-17' ) );
		$html    = ( new \Affilicard\Renderer\CardHtmlBuilder() )->build( $product, array() );
		$this->assertStringContainsString( '予約受付中', $html );
		$this->assertStringContainsString( '予約する', $html );
	}

	public function test_past_release_date_renders_normal(): void {
		WP_Mock::userFunction( 'current_time', array( 'return' => '2026-08-01' ) );
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
		$product = $this->productWith( array( 'release_date' => '2026-07-17' ) );
		$html    = ( new \Affilicard\Renderer\CardHtmlBuilder() )->build( $product, array() );
		$this->assertStringNotContainsString( '予約受付中', $html );
	}

	public function test_resolve_mask_block_overrides_product_meta(): void {
		$builder = new \Affilicard\Renderer\CardHtmlBuilder();
		$product = array(
			'mask_blur'  => false,
			'mask_r18'   => false,
			'mask_label' => '継承ラベル',
		);

		// ブロック属性が明示 → ブロック優先。maskLabel 未定義 → 継承。
		$resolved = $builder->resolveMask(
			array(
				'maskBlur' => true,
				'maskR18'  => false,
			),
			$product
		);
		$this->assertTrue( $resolved['blur'] );
		$this->assertFalse( $resolved['r18'] );
		$this->assertSame( '継承ラベル', $resolved['label'] );
	}

	public function test_resolve_mask_false_override_wins_over_true_product_meta_blur(): void {
		$builder = new \Affilicard\Renderer\CardHtmlBuilder();
		$product = array( 'mask_blur' => true );

		// ブロック属性 false は「未指定」ではなく明示的な上書き → 商品 meta の true より優先される。
		$resolved = $builder->resolveMask( array( 'maskBlur' => false ), $product );
		$this->assertFalse( $resolved['blur'] );
	}

	public function test_resolve_mask_false_override_wins_over_true_product_meta_r18(): void {
		$builder = new \Affilicard\Renderer\CardHtmlBuilder();
		$product = array( 'mask_r18' => true );

		// ブロック属性 false は「未指定」ではなく明示的な上書き → 商品 meta の true より優先される。
		$resolved = $builder->resolveMask( array( 'maskR18' => false ), $product );
		$this->assertFalse( $resolved['r18'] );
	}

	public function test_resolve_mask_inherits_when_attribute_absent(): void {
		$builder  = new \Affilicard\Renderer\CardHtmlBuilder();
		$product  = array(
			'mask_blur'  => true,
			'mask_r18'   => true,
			'mask_label' => 'x',
		);
		$resolved = $builder->resolveMask( array(), $product );
		$this->assertTrue( $resolved['blur'] );
		$this->assertTrue( $resolved['r18'] );
		$this->assertSame( 'x', $resolved['label'] );
	}

	public function test_resolve_mask_empty_string_label_is_override(): void {
		$builder = new \Affilicard\Renderer\CardHtmlBuilder();
		$product = array( 'mask_label' => '継承' );
		// maskLabel が空文字で定義済み → 上書き（＝ラベル消し）。
		$resolved = $builder->resolveMask( array( 'maskLabel' => '' ), $product );
		$this->assertSame( '', $resolved['label'] );
	}

	public function test_build_applies_ebook_media_aspect_ratio(): void {
		\WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		\WP_Mock::userFunction( 'get_post_thumbnail_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn( $v ) => is_string( $v ) ? trim( $v ) : ''
		);
		WP_Mock::userFunction( 'current_time', array( 'return' => '2026-06-29' ) );

		$builder = new CardHtmlBuilder();
		$product = $this->productWith( array( 'product_type' => 'ebook' ) );

		$html = $builder->build( $product, array() );
		$this->assertStringContainsString( 'aspect-ratio: 2 / 3', $html );
	}
}
