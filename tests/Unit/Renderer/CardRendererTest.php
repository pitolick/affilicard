<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Renderer;

use Affilicard\Platform\PlatformDefinition;
use Affilicard\Renderer\CardRenderer;
use PHPUnit\Framework\TestCase;
use WP_Mock;

final class CardRendererTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		WP_Mock::passthruFunction( 'esc_html' );
		WP_Mock::passthruFunction( 'esc_attr' );
		WP_Mock::passthruFunction( 'esc_url' );
		WP_Mock::passthruFunction( 'esc_html__' );
		WP_Mock::passthruFunction( 'wp_kses_post' );
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'sanitize_hex_color', array( 'return_arg' => 0 ) );
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	private function store(): PlatformDefinition {
		// 汎用型(generic)の基準 platform。ebook 固有要素は別タスクで追加する。
		return new PlatformDefinition( 'example-store', 'サンプルストア', 'manual', 1, true, array( 'generic' ), 'ストアで見る', '#2563eb', '#ffffff' );
	}

	private function product( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => 1,
				'title'        => 'サンプル商品',
				'content'      => '',
				'status'       => 'publish',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'extras'       => array(),
				'listings'     => array(),
				'modified'     => '2026-06-01 00:00:00',
			),
			$overrides
		);
	}

	public function test_renders_title_and_root_class(): void {
		$html = ( new CardRenderer() )->render( $this->product(), array( $this->store() ) );
		$this->assertStringContainsString( 'affilicard-card', $html );
		$this->assertStringContainsString( 'サンプル商品', $html );
	}

	public function test_renders_cta_with_affiliate_url(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://aff.example/x',
						'regular_url'   => 'https://example.com/1',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringContainsString( 'https://aff.example/x', $html );
		$this->assertStringContainsString( 'ストアで見る', $html );
		$this->assertStringContainsString( 'rel="nofollow sponsored noopener"', $html );
	}

	public function test_falls_back_to_regular_url_when_affiliate_empty(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => '',
						'regular_url'   => 'https://example.com/1',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringContainsString( 'https://example.com/1', $html );
	}

	public function test_skips_listing_when_both_urls_empty(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => '',
						'regular_url'   => '',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__cta', $html );
	}

	public function test_suppresses_all_cta_when_out_of_stock(): void {
		$product = $this->product(
			array(
				'stock_status' => 'out_of_stock',
				'listings'     => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://aff.example/x',
						'regular_url'   => '',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__cta', $html );
		$this->assertStringContainsString( '在庫切れ', $html );
	}

	public function test_uses_button_label_override(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'              => 'example-store',
						'enabled'               => true,
						'affiliate_url'         => 'https://x',
						'button_label_override' => '今すぐ購入',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringContainsString( '今すぐ購入', $html );
	}

	public function test_hides_platform_in_hide_list(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://x',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ), array( 'hide_platforms' => array( 'example-store' ) ) );
		$this->assertStringNotContainsString( 'affilicard-card__cta', $html );
	}

	public function test_skips_unknown_platform_listing(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'unknown',
						'enabled'       => true,
						'affiliate_url' => 'https://x',
					),
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://ok',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringContainsString( 'https://ok', $html );
		$this->assertStringNotContainsString( 'https://x', $html );
	}

	public function test_renders_extras_dl(): void {
		// 汎用型 extras はキー無しの自由ラベル/値（Hybrid のカスタム行）。
		$product = $this->product(
			array(
				'extras' => array(
					array(
						'label' => 'カラー',
						'value' => 'レッド',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringContainsString( 'カラー', $html );
		$this->assertStringContainsString( 'レッド', $html );
	}

	public function test_renders_image_when_url_given(): void {
		$html = ( new CardRenderer() )->render( $this->product(), array( $this->store() ), array( 'image_url' => 'https://img/photo.jpg' ) );
		$this->assertStringContainsString( 'https://img/photo.jpg', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
	}

	public function test_injects_only_nonempty_color_vars(): void {
		$html = ( new CardRenderer() )->render(
			$this->product(),
			array( $this->store() ),
			array(
				'colors' => array(
					'cta_bg'  => '#123456',
					'card_bg' => '',
				),
			)
		);
		$this->assertStringContainsString( '--affilicard-cta-bg:#123456', $html );
		$this->assertStringNotContainsString( '--affilicard-card-bg', $html );
	}

	public function test_button_uses_brand_color_fallback(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://x',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringContainsString( 'var(--affilicard-cta-bg,#2563eb)', $html );
	}

	public function test_listing_disabled_flag_suppresses_cta(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => false,
						'affiliate_url' => 'https://x',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__cta', $html );
	}

	public function test_renders_price_span_when_price_present(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://x',
						'price'         => '¥1,200',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringContainsString( 'affilicard-card__price', $html );
		$this->assertStringContainsString( '¥1,200', $html );
	}

	public function test_renders_discontinued_badge(): void {
		$product = $this->product( array( 'stock_status' => 'discontinued' ) );
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringContainsString( 'affilicard-card__badge--discontinued', $html );
		$this->assertStringContainsString( '取扱終了', $html );
	}

	public function test_skips_extra_row_with_empty_value(): void {
		$product = $this->product(
			array(
				'extras' => array(
					array(
						'label' => 'カラー',
						'value' => '',
					),
					array(
						'label' => 'サイズ',
						'value' => 'L',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringNotContainsString( 'カラー', $html );
		$this->assertStringContainsString( 'サイズ', $html );
		$this->assertStringContainsString( 'L', $html );
	}
}
