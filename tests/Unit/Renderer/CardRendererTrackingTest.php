<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Renderer;

use Affilicard\Platform\PlatformDefinition;
use Affilicard\Renderer\CardRenderer;
use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * 計測用 data 属性（data-affilicard-*）の描画を検証する。
 *
 * CardRendererTest とは別ファイルにしている理由: あちらは setUp で esc_attr を
 * passthru にしているためエスケープを検証できない。計測属性は値に作品タイトル
 * （ユーザー入力）が入るので、ここでは esc_attr を実際に escape する stub にする。
 */
final class CardRendererTrackingTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		WP_Mock::passthruFunction( 'esc_html' );
		WP_Mock::passthruFunction( 'esc_url' );
		WP_Mock::passthruFunction( 'esc_html__' );
		WP_Mock::passthruFunction( 'wp_kses_post' );
		WP_Mock::passthruFunction( 'esc_url_raw' );
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'sanitize_hex_color', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'wp_date', array( 'return' => '2026年8月13日 12:00' ) );
		// 実 WordPress の esc_attr は _wp_specialchars( $text, ENT_QUOTES ) 相当。
		WP_Mock::userFunction( 'esc_attr' )
			->andReturnUsing(
				static function ( $value ): string {
					return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
				}
			);
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	private function store(): PlatformDefinition {
		return new PlatformDefinition( 'example-store', 'サンプルストア', 'manual', 1, true, array( 'generic' ), 'ストアで見る', '#2563eb', '#ffffff' );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function product( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => 123,
				'slug'         => 'sample-title-vol1',
				'title'        => 'サンプル作品（1）',
				'content'      => '',
				'status'       => 'publish',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'extras'       => array(),
				'listings'     => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://aff.example/x',
					),
				),
				'modified'     => '2026-08-13 00:00:00',
			),
			$overrides
		);
	}

	public function test_cta_carries_all_four_tracking_attributes(): void {
		$html = ( new CardRenderer() )->render( $this->product(), array( $this->store() ) );

		$this->assertStringContainsString( 'data-affilicard-platform="example-store"', $html );
		$this->assertStringContainsString( 'data-affilicard-product-id="123"', $html );
		$this->assertStringContainsString( 'data-affilicard-product-slug="sample-title-vol1"', $html );
		$this->assertStringContainsString( 'data-affilicard-product-title="サンプル作品（1）"', $html );
	}

	public function test_cta_keeps_existing_link_attributes(): void {
		$html = ( new CardRenderer() )->render( $this->product(), array( $this->store() ) );

		$this->assertStringContainsString( 'href="https://aff.example/x"', $html );
		$this->assertStringContainsString( 'rel="nofollow sponsored noopener"', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertStringContainsString( 'class="affilicard-card__cta"', $html );
	}

	public function test_tracking_values_are_escaped(): void {
		$product = $this->product( array( 'title' => '"引用" & <タグ>' ) );
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );

		$this->assertStringContainsString( 'data-affilicard-product-title="&quot;引用&quot; &amp; &lt;タグ&gt;"', $html );
		$this->assertStringNotContainsString( 'data-affilicard-product-title=""引用"', $html );
	}

	public function test_empty_slug_and_title_omit_the_attributes_entirely(): void {
		$product = $this->product(
			array(
				'slug'  => '',
				'title' => '',
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );

		// 空文字を出すと GA4 に空パラメータが届くため、属性ごと省略する。
		$this->assertStringNotContainsString( 'data-affilicard-product-slug', $html );
		$this->assertStringNotContainsString( 'data-affilicard-product-title', $html );
		// id と platform は常に出る。
		$this->assertStringContainsString( 'data-affilicard-product-id="123"', $html );
		$this->assertStringContainsString( 'data-affilicard-platform="example-store"', $html );
	}

	public function test_card_root_carries_product_identifiers(): void {
		$html = ( new CardRenderer() )->render( $this->product(), array( $this->store() ) );

		$this->assertMatchesRegularExpression(
			'/<div class="affilicard-card"[^>]*data-affilicard-product-id="123"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/<div class="affilicard-card"[^>]*data-affilicard-product-slug="sample-title-vol1"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/<div class="affilicard-card"[^>]*data-affilicard-product-title="サンプル作品（1）"/',
			$html
		);
	}

	/**
	 * ルートには platform を載せない（1 カードに複数ストアが並ぶため単一値にならない）。
	 */
	public function test_card_root_has_no_platform_attribute(): void {
		$html = ( new CardRenderer() )->render( $this->product(), array( $this->store() ) );

		$this->assertDoesNotMatchRegularExpression(
			'/<div class="affilicard-card"[^>]*data-affilicard-platform/',
			$html
		);
	}

	/**
	 * ルート要素の既存の style 属性（色変数の注入）を壊さない。
	 */
	public function test_card_root_keeps_inline_style(): void {
		$html = ( new CardRenderer() )->render(
			$this->product(),
			array( $this->store() ),
			array( 'colors' => array( 'card_bg' => '#ffffff' ) )
		);

		$this->assertMatchesRegularExpression(
			'/<div class="affilicard-card"[^>]*style="[^"]*--affilicard-card-bg/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/<div class="affilicard-card"[^>]*data-affilicard-product-id="123"/',
			$html
		);
	}
}
