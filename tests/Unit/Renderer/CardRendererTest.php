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
		// 実 WordPress の esc_url_raw() は javascript:/data: 等の危険スキームを排除して空文字を返す
		// （wp_kses_bad_protocol の再帰比較が不一致になり clean_url() が '' を返す挙動を単純化した stub）。
		// selectCardImage() の sanitize（本 PR の対象）を検証するため、passthru ではなく最小限だけ再現する。
		WP_Mock::userFunction( 'esc_url_raw' )
			->andReturnUsing(
				static function ( $value ) {
					$value = is_scalar( $value ) ? (string) $value : '';
					if ( 1 === preg_match( '/^\s*(javascript|data|vbscript)\s*:/i', $value ) ) {
						return '';
					}
					return $value;
				}
			);
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	private function store(): PlatformDefinition {
		// 汎用型(generic)の基準 platform。ebook 固有要素は別タスクで追加する。
		return new PlatformDefinition( 'example-store', 'サンプルストア', 'manual', 1, true, array( 'generic' ), 'ストアで見る', '#2563eb', '#ffffff' );
	}

	/**
	 * price/list_price/badge を持つ 1 listing の商品を組み立てる（URL 必須＝価格行が描画される）。
	 */
	private function pricedProduct( string $price, string $listPrice, string $badge = '' ): array {
		$listing = array(
			'platform'      => 'example-store',
			'enabled'       => true,
			'affiliate_url' => 'https://x',
			'price'         => $price,
			'list_price'    => $listPrice,
		);
		if ( '' !== $badge ) {
			$listing['badge'] = $badge;
		}
		return $this->product( array( 'listings' => array( $listing ) ) );
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

	public function test_list_price_shown_when_greater_than_price(): void {
		$html = ( new CardRenderer() )->render( $this->pricedProduct( '100', '814' ), array( $this->store() ) );
		$this->assertStringContainsString( 'affilicard-card__list-price', $html );
		$this->assertStringContainsString( '¥814', $html );
		// 出力順: list-price が price より前
		$this->assertLessThan(
			strpos( $html, 'affilicard-card__price' ),
			strpos( $html, 'affilicard-card__list-price' )
		);
	}

	public function test_list_price_hidden_when_equal_to_price(): void {
		$html = ( new CardRenderer() )->render( $this->pricedProduct( '100', '100' ), array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__list-price', $html );
	}

	public function test_list_price_hidden_when_less_than_price(): void {
		$html = ( new CardRenderer() )->render( $this->pricedProduct( '100', '80' ), array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__list-price', $html );
	}

	public function test_list_price_hidden_when_string_equal_values(): void {
		$html1 = ( new CardRenderer() )->render( $this->pricedProduct( '100', '100.0' ), array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__list-price', $html1 );
		$html2 = ( new CardRenderer() )->render( $this->pricedProduct( '1000', '1,000' ), array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__list-price', $html2 );
	}

	public function test_list_price_hidden_when_empty(): void {
		$html = ( new CardRenderer() )->render( $this->pricedProduct( '100', '' ), array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__list-price', $html );
	}

	public function test_list_price_hidden_when_non_numeric(): void {
		$html = ( new CardRenderer() )->render( $this->pricedProduct( '100', '無料' ), array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__list-price', $html );
	}

	public function test_list_price_hidden_when_price_empty(): void {
		$html = ( new CardRenderer() )->render( $this->pricedProduct( '', '814' ), array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__list-price', $html );
	}

	public function test_list_price_hidden_when_price_non_numeric(): void {
		$html = ( new CardRenderer() )->render( $this->pricedProduct( '無料', '814' ), array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__list-price', $html );
		// price 自体は従来どおり描画される（非空のため）
		$this->assertStringContainsString( 'affilicard-card__price', $html );
	}

	public function test_list_price_hidden_for_zero_or_negative(): void {
		$html0 = ( new CardRenderer() )->render( $this->pricedProduct( '100', '0' ), array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__list-price', $html0 );
		$htmlNeg = ( new CardRenderer() )->render( $this->pricedProduct( '100', '-100' ), array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__list-price', $htmlNeg );
	}

	public function test_list_price_normalizes_yen_and_comma(): void {
		$html = ( new CardRenderer() )->render( $this->pricedProduct( '1,000', '¥1,200' ), array( $this->store() ) );
		$this->assertStringContainsString( 'affilicard-card__list-price', $html );
		$this->assertStringContainsString( '¥1,200', $html );
	}

	public function test_list_price_coexists_with_badge(): void {
		$html = ( new CardRenderer() )->render( $this->pricedProduct( '100', '814', '87%OFF' ), array( $this->store() ) );
		$this->assertStringContainsString( 'affilicard-card__list-price', $html );
		$this->assertStringContainsString( 'affilicard-card__discount', $html );
		$this->assertStringContainsString( '87%OFF', $html );
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

	public function test_renders_inner_grid_wrapper(): void {
		$html = ( new CardRenderer() )->render( $this->product(), array( $this->store() ) );
		$this->assertStringContainsString( 'affilicard-card__inner', $html );
	}

	public function test_renders_author_publisher_meta_header_for_ebook(): void {
		$product = $this->product(
			array(
				'product_type' => 'ebook',
				'extras'       => array(
					array(
						'key'   => 'author',
						'label' => '著者',
						'value' => '架空 太郎',
					),
					array(
						'key'   => 'publisher',
						'label' => '出版社',
						'value' => 'サンプル出版社',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringContainsString( 'affilicard-card__meta', $html );
		$this->assertStringContainsString( '架空 太郎 著', $html );
		$this->assertStringContainsString( 'サンプル出版社', $html );
	}

	public function test_author_publisher_excluded_from_extras_dl(): void {
		// CardRenderer 単体（options 未指定）の挙動。Block 経由では EbookType::cardHiddenKeys()=['isbn'] が渡り ISBN は非表示になる。
		$product = $this->product(
			array(
				'product_type' => 'ebook',
				'extras'       => array(
					array(
						'key'   => 'author',
						'label' => '著者',
						'value' => '架空 太郎',
					),
					array(
						'key'   => 'isbn',
						'label' => 'ISBN',
						'value' => '978-4-00-000000-0',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringContainsString( 'affilicard-card__extras', $html );
		$this->assertStringContainsString( 'ISBN', $html );
		$this->assertStringNotContainsString( '<dt>著者</dt>', $html );
	}

	public function test_no_meta_header_when_no_author_publisher(): void {
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
		$this->assertStringNotContainsString( 'affilicard-card__meta', $html );
		$this->assertStringContainsString( 'カラー', $html );
	}

	public function test_hidden_keys_option_removes_extra_from_dl(): void {
		$product = $this->product(
			array(
				'product_type' => 'ebook',
				'extras'       => array(
					array(
						'key'   => 'isbn',
						'label' => 'ISBN',
						'value' => '978-4-00-000000-0',
					),
					array(
						'key'   => 'series',
						'label' => 'シリーズ',
						'value' => 'サンプルシリーズ',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render(
			$product,
			array( $this->store() ),
			array( 'hidden_keys' => array( 'isbn' ) )
		);
		$this->assertStringNotContainsString( '978-4-00-000000-0', $html );
		$this->assertStringContainsString( 'シリーズ', $html );
	}

	public function test_media_label_option_used_for_placeholder(): void {
		// image_url 未指定 → プレースホルダの aria-label に type別 media_label が使われる
		// （可視ラベルは中立の「画像がありません」に固定・media_label はそこには出ない）。
		$html = ( new CardRenderer() )->render(
			$this->product(),
			array( $this->store() ),
			array( 'media_label' => '商品画像' )
		);
		$this->assertStringContainsString( 'affilicard-card__media-placeholder', $html );
		$this->assertStringContainsString( 'aria-label="商品画像がありません"', $html );
	}

	public function test_default_placeholder_label_when_no_option(): void {
		$html = ( new CardRenderer() )->render( $this->product(), array( $this->store() ) );
		$this->assertStringContainsString( 'aria-label="商品画像がありません"', $html );
		$this->assertStringContainsString( '画像がありません', $html );
	}

	public function test_media_frame_has_aspect_ratio_from_option(): void {
		$html = ( new CardRenderer() )->render(
			$this->product(),
			array( $this->store() ),
			array(
				'image_url'          => 'https://img/photo.jpg',
				'media_aspect_ratio' => '2 / 3',
			)
		);
		$this->assertStringContainsString( 'aspect-ratio: 2 / 3', $html );
	}

	public function test_empty_media_aspect_ratio_falls_back_to_default(): void {
		$html = ( new CardRenderer() )->render(
			$this->product(),
			array( $this->store() ),
			array(
				'image_url'          => 'https://img/photo.jpg',
				'media_aspect_ratio' => '',
			)
		);
		$this->assertStringContainsString( 'aspect-ratio: 1 / 1', $html );
		$this->assertDoesNotMatchRegularExpression( '/aspect-ratio:\s*"/', $html );
	}

	public function test_whitespace_only_media_aspect_ratio_falls_back_to_default(): void {
		$html = ( new CardRenderer() )->render(
			$this->product(),
			array( $this->store() ),
			array(
				'image_url'          => 'https://img/photo.jpg',
				'media_aspect_ratio' => '   ',
			)
		);
		$this->assertStringContainsString( 'aspect-ratio: 1 / 1', $html );
	}

	public function test_media_image_uses_object_fit_contain_class(): void {
		$html = ( new CardRenderer() )->render(
			$this->product(),
			array( $this->store() ),
			array(
				'image_url'          => 'https://img/photo.jpg',
				'media_aspect_ratio' => '1 / 1',
			)
		);
		// 全 type 共通の contain 用クラスが画像に付く。
		$this->assertStringContainsString( 'affilicard-card__media-image', $html );
	}

	public function test_unmasked_image_carries_aspect_ratio_not_media_container(): void {
		// content box(実画像領域)を歪ませないため、aspect-ratio は padding 込みの
		// .affilicard-card__media(枠)ではなく実画像要素そのものに付く。
		$html = ( new CardRenderer() )->render(
			$this->product(),
			array( $this->store() ),
			array(
				'image_url'          => 'https://img/photo.jpg',
				'media_aspect_ratio' => '2 / 3',
			)
		);
		$this->assertStringContainsString( '<div class="affilicard-card__media">', $html );
		$this->assertMatchesRegularExpression(
			'/<img class="affilicard-card__media-image"[^>]*style="aspect-ratio: 2 \/ 3"/',
			$html
		);
	}

	public function test_masked_cover_wrapper_carries_aspect_ratio_not_inner_image(): void {
		// マスク時は cover ラッパが aspect-ratio を持ち、内側のぼかし画像は持たない
		// （ぼかし画像は cover の content box をそのまま埋める）。
		$html = ( new CardRenderer() )->render(
			$this->product(),
			array(),
			array(
				'image_url'          => 'https://img/cover.jpg',
				'mask_blur'          => true,
				'media_aspect_ratio' => '2 / 3',
			)
		);
		$this->assertMatchesRegularExpression(
			'/<div class="affilicard-card__cover affilicard-card__cover--masked" style="aspect-ratio: 2 \/ 3">/',
			$html
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<img class="affilicard-card__media-image"[^>]*aspect-ratio/',
			$html
		);
	}

	public function test_placeholder_has_label_and_icon_and_aspect(): void {
		$html = ( new CardRenderer() )->render(
			$this->product(),
			array( $this->store() ),
			array(
				'media_label'        => 'キービジュアル',
				'media_aspect_ratio' => '1 / 1',
			) // image_url 未指定
		);
		$this->assertStringContainsString( 'affilicard-card__media-placeholder', $html );
		$this->assertStringContainsString( 'affilicard-card__media-placeholder-icon', $html ); // 汎用アイコン
		$this->assertStringContainsString( 'aspect-ratio: 1 / 1', $html );
		// 可視ラベルは type 名ではなく中立の「画像がありません」に固定（読み込み失敗に見えるのを防ぐ）。
		$this->assertMatchesRegularExpression(
			'/<span class="affilicard-card__media-placeholder-label" aria-hidden="true">画像がありません<\/span>/',
			$html
		);
		// type別ラベル（キービジュアル等）はスクリーンリーダー向け aria-label に「〜がありません」で残す。
		$this->assertStringContainsString( 'aria-label="キービジュアルがありません"', $html );
		// aspect-ratio と role="img"/aria-label はプレースホルダ要素自身が持つ（枠 .affilicard-card__media 側ではない）。
		$this->assertMatchesRegularExpression(
			'/<div class="affilicard-card__media-placeholder" style="aspect-ratio: 1 \/ 1" role="img" aria-label="キービジュアルがありません">/',
			$html
		);
	}

	public function test_custom_header_keys_option_promotes_to_meta(): void {
		$product = $this->product(
			array(
				'extras' => array(
					array(
						'key'   => 'brand',
						'label' => 'ブランド',
						'value' => 'サンプルブランド',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render(
			$product,
			array( $this->store() ),
			array( 'header_keys' => array( 'brand' ) )
		);
		$this->assertStringContainsString( 'affilicard-card__meta', $html );
		$this->assertStringContainsString( 'サンプルブランド', $html );
	}

	private function dmmBooks(): PlatformDefinition {
		return new PlatformDefinition( 'dmm-books', 'DMMブックス', 'dmm-ebook', 1, true, array( 'ebook' ), 'DMMブックスで読む', '#d72d65', '#ffffff' );
	}

	private function makePlatform( string $code, string $name, string $buttonLabel ): PlatformDefinition {
		return new PlatformDefinition( $code, $name, 'manual', 1, true, array( 'generic' ), $buttonLabel, '#444444', '#ffffff' );
	}

	public function test_cta_label_override_takes_priority_over_listing_and_platform(): void {
		$platform = $this->makePlatform( 'dmm-books', 'DMMブックス', 'プラットフォーム既定' );
		$product  = array(
			'title'        => 'テスト商品',
			'stock_status' => 'available',
			'listings'     => array(
				array(
					'platform'              => 'dmm-books',
					'affiliate_url'         => 'https://example.test/a',
					'button_label_override' => 'listing上書き',
					'enabled'               => true,
				),
			),
		);

		$html = ( new CardRenderer() )->render(
			$product,
			array( $platform ),
			array( 'cta_label_overrides' => array( 'dmm-books' => 'ブロック上書き' ) )
		);

		$this->assertStringContainsString( 'ブロック上書き', $html );
		$this->assertStringNotContainsString( 'listing上書き', $html );
		$this->assertStringNotContainsString( 'プラットフォーム既定', $html );
	}

	public function test_cta_falls_back_to_listing_override_when_block_override_empty(): void {
		$platform = $this->makePlatform( 'dmm-books', 'DMMブックス', 'プラットフォーム既定' );
		$product  = array(
			'title'        => 'テスト商品',
			'stock_status' => 'available',
			'listings'     => array(
				array(
					'platform'              => 'dmm-books',
					'affiliate_url'         => 'https://example.test/a',
					'button_label_override' => 'listing上書き',
					'enabled'               => true,
				),
			),
		);

		$html = ( new CardRenderer() )->render(
			$product,
			array( $platform ),
			array( 'cta_label_overrides' => array( 'dmm-books' => '' ) )
		);

		$this->assertStringContainsString( 'listing上書き', $html );
	}

	public function test_ebook_renders_dmm_listing_with_brand_color(): void {
		$product = $this->product(
			array(
				'product_type' => 'ebook',
				'title'        => 'テスト漫画 1巻',
				'listings'     => array(
					array(
						'platform'      => 'dmm-books',
						'enabled'       => true,
						'affiliate_url' => 'https://al.dmm.com/x',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->dmmBooks() ) );
		$this->assertStringContainsString( 'DMMブックスで読む', $html );
		$this->assertStringContainsString( 'var(--affilicard-cta-bg,#d72d65)', $html );
	}

	public function test_ebook_renders_author_and_publisher_extras(): void {
		// EbookType 由来の key 付き extras（著者/出版社/ISBN）。
		$product = $this->product(
			array(
				'product_type' => 'ebook',
				'extras'       => array(
					array(
						'key'   => 'author',
						'label' => '著者',
						'value' => '架空 太郎',
					),
					array(
						'key'   => 'publisher',
						'label' => '出版社',
						'value' => 'サンプル出版社',
					),
					array(
						'key'   => 'isbn',
						'label' => 'ISBN',
						'value' => '978-4-00-000000-0',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->dmmBooks() ) );
		$this->assertStringContainsString( 'affilicard-card__meta', $html );
		$this->assertStringContainsString( '架空 太郎 著', $html );
		$this->assertStringContainsString( 'サンプル出版社', $html );
		$this->assertStringContainsString( 'ISBN', $html );
	}

	public function test_renders_store_row_with_platform_name_price_tax(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://x',
						'price'         => '600',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringContainsString( 'affilicard-card__row', $html );
		$this->assertStringContainsString( 'affilicard-card__platform', $html );
		$this->assertStringContainsString( 'サンプルストア', $html );
		$this->assertStringContainsString( '¥600', $html );
		$this->assertStringContainsString( 'affilicard-card__tax', $html );
		$this->assertStringContainsString( '（税込）', $html );
	}

	public function test_price_yen_not_doubled_when_already_prefixed(): void {
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
		$this->assertStringContainsString( '¥1,200', $html );
		$this->assertStringNotContainsString( '¥¥', $html );
	}

	public function test_renders_discount_badge_from_listing(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://x',
						'price'         => '600',
						'badge'         => '40%OFF',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringContainsString( 'affilicard-card__discount', $html );
		$this->assertStringContainsString( '40%OFF', $html );
	}

	public function test_no_tax_note_when_price_empty(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://x',
						'price'         => '',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__tax', $html );
		$this->assertStringContainsString( 'affilicard-card__cta', $html );
	}

	public function test_renders_price_timestamp_footer_from_last_fetched_at(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'        => 'example-store',
						'enabled'         => true,
						'affiliate_url'   => 'https://x',
						'price'           => '600',
						'last_fetched_at' => '2026-04-20T10:30:00+09:00',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringContainsString( 'affilicard-card__timestamp', $html );
		$this->assertStringContainsString( '2026年4月20日時点の価格', $html );
	}

	public function test_uses_latest_last_fetched_at_across_listings(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'        => 'example-store',
						'enabled'         => true,
						'affiliate_url'   => 'https://a',
						'price'           => '600',
						'last_fetched_at' => '2026-04-18T09:00:00+09:00',
					),
					array(
						'platform'        => 'example-store',
						'enabled'         => true,
						'affiliate_url'   => 'https://b',
						'price'           => '660',
						'last_fetched_at' => '2026-04-20T09:00:00+09:00',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringContainsString( '2026年4月20日時点の価格', $html );
	}

	public function test_no_timestamp_footer_when_no_last_fetched_at(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://x',
						'price'         => '600',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__timestamp', $html );
	}

	public function test_renders_badge_without_price(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://x',
						'badge'         => 'NEW',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringContainsString( 'affilicard-card__discount', $html );
		$this->assertStringContainsString( 'NEW', $html );
		$this->assertStringNotContainsString( 'affilicard-card__tax', $html );
	}

	public function test_ignores_non_date_last_fetched_at(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'        => 'example-store',
						'enabled'         => true,
						'affiliate_url'   => 'https://x',
						'price'           => '600',
						'last_fetched_at' => 'not-a-date',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__timestamp', $html );
	}

	public function test_only_platforms_shows_listed_platform_only(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'dmm-books',
						'enabled'       => true,
						'affiliate_url' => 'https://dmm',
					),
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://store',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render(
			$product,
			array( $this->dmmBooks(), $this->store() ),
			array( 'only_platforms' => array( 'dmm-books' ) )
		);
		$this->assertStringContainsString( 'https://dmm', $html );
		$this->assertStringNotContainsString( 'https://store', $html );
	}

	public function test_only_platforms_empty_shows_all(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'dmm-books',
						'enabled'       => true,
						'affiliate_url' => 'https://dmm',
					),
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://store',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render(
			$product,
			array( $this->dmmBooks(), $this->store() ),
			array( 'only_platforms' => array() )
		);
		$this->assertStringContainsString( 'https://dmm', $html );
		$this->assertStringContainsString( 'https://store', $html );
	}

	public function test_only_platforms_combined_with_hide(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'dmm-books',
						'enabled'       => true,
						'affiliate_url' => 'https://dmm',
					),
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://store',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render(
			$product,
			array( $this->dmmBooks(), $this->store() ),
			array(
				'only_platforms' => array( 'dmm-books', 'example-store' ),
				'hide_platforms' => array( 'example-store' ),
			)
		);
		$this->assertStringContainsString( 'https://dmm', $html );
		$this->assertStringNotContainsString( 'https://store', $html );
	}

	public function test_timestamp_ignores_listing_hidden_by_only_platforms(): void {
		// 非表示プラットフォーム（example-store）の方が新しい last_fetched_at を持つ場合でも、
		// 表示中（dmm-books）の日付がフッターに出る（CTA 表示と価格鮮度を一致させる）。
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'        => 'dmm-books',
						'enabled'         => true,
						'affiliate_url'   => 'https://dmm',
						'last_fetched_at' => '2026-04-18T09:00:00+09:00',
					),
					array(
						'platform'        => 'example-store',
						'enabled'         => true,
						'affiliate_url'   => 'https://store',
						'last_fetched_at' => '2026-04-25T09:00:00+09:00',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render(
			$product,
			array( $this->dmmBooks(), $this->store() ),
			array( 'only_platforms' => array( 'dmm-books' ) )
		);
		$this->assertStringContainsString( '2026年4月18日時点の価格', $html );
		$this->assertStringNotContainsString( '2026年4月25日', $html );
	}

	public function test_timestamp_ignores_listing_without_url(): void {
		// URL 無し listing は CTA 行が出ないので、その last_fetched_at もフッターに採用されない。
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'        => 'dmm-books',
						'enabled'         => true,
						'affiliate_url'   => 'https://dmm',
						'last_fetched_at' => '2026-04-18T09:00:00+09:00',
					),
					array(
						'platform'        => 'example-store',
						'enabled'         => true,
						'affiliate_url'   => '',
						'regular_url'     => '',
						'last_fetched_at' => '2026-04-25T09:00:00+09:00',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->dmmBooks(), $this->store() ) );
		$this->assertStringContainsString( '2026年4月18日時点の価格', $html );
		$this->assertStringNotContainsString( '2026年4月25日', $html );
	}

	public function test_timestamp_ignores_listing_hidden_by_hide_platforms(): void {
		// hide_platforms でも同様に、非表示 listing の日付はフッターに採用されない。
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'        => 'dmm-books',
						'enabled'         => true,
						'affiliate_url'   => 'https://dmm',
						'last_fetched_at' => '2026-04-18T09:00:00+09:00',
					),
					array(
						'platform'        => 'example-store',
						'enabled'         => true,
						'affiliate_url'   => 'https://store',
						'last_fetched_at' => '2026-04-25T09:00:00+09:00',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render(
			$product,
			array( $this->dmmBooks(), $this->store() ),
			array( 'hide_platforms' => array( 'example-store' ) )
		);
		$this->assertStringContainsString( '2026年4月18日時点の価格', $html );
		$this->assertStringNotContainsString( '2026年4月25日', $html );
	}

	private function availableWithCta(): array {
		return $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://aff.example/x',
					),
				),
			)
		);
	}

	public function test_preorder_shows_badge_release_date_and_reserve_cta(): void {
		$html = ( new CardRenderer() )->render(
			$this->availableWithCta(),
			array( $this->store() ),
			array(
				'is_preorder'        => true,
				'release_date_label' => '2026年7月17日発売',
			)
		);
		$this->assertStringContainsString( 'affilicard-card__badge--preorder', $html );
		$this->assertStringContainsString( '予約受付中', $html );
		$this->assertStringContainsString( '2026年7月17日発売', $html );
		$this->assertStringContainsString( '予約する', $html );
		$this->assertStringContainsString( 'https://aff.example/x', $html ); // CTA は隠れない
		// バッジと発売日が同一の flex コンテナ内に収まることを確認する。
		$this->assertStringContainsString( 'affilicard-card__preorder', $html );
		$preorder_pos = strpos( $html, 'affilicard-card__preorder' );
		$badge_pos    = strpos( $html, 'affilicard-card__badge--preorder' );
		$release_pos  = strpos( $html, '2026年7月17日発売' );
		$preorder_end = strpos( $html, '</div>', (int) $preorder_pos );
		$this->assertGreaterThan( $preorder_pos, $badge_pos );
		$this->assertLessThan( $preorder_end, $release_pos );
	}

	public function test_not_preorder_uses_platform_label_and_no_badge(): void {
		$html = ( new CardRenderer() )->render(
			$this->availableWithCta(),
			array( $this->store() ),
			array( 'is_preorder' => false )
		);
		$this->assertStringNotContainsString( 'affilicard-card__badge--preorder', $html );
		$this->assertStringContainsString( 'ストアで見る', $html );
	}

	public function test_explicit_cta_override_wins_over_preorder(): void {
		$html = ( new CardRenderer() )->render(
			$this->availableWithCta(),
			array( $this->store() ),
			array(
				'is_preorder'         => true,
				'cta_label_overrides' => array( 'example-store' => 'いますぐ見る' ),
			)
		);
		$this->assertStringContainsString( 'いますぐ見る', $html );
		$this->assertStringNotContainsString( '予約する', $html );
	}

	public function test_listing_override_wins_over_preorder(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'              => 'example-store',
						'enabled'               => true,
						'affiliate_url'         => 'https://aff.example/x',
						'button_label_override' => 'いますぐ予約',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render(
			$product,
			array( $this->store() ),
			array( 'is_preorder' => true )
		);
		$this->assertStringContainsString( 'いますぐ予約', $html );
		$this->assertStringNotContainsString( '予約する', $html );
	}

	public function test_preorder_without_release_date_label_omits_date_line(): void {
		$html = ( new CardRenderer() )->render(
			$this->availableWithCta(),
			array( $this->store() ),
			array( 'is_preorder' => true )
		);
		$this->assertStringContainsString( 'affilicard-card__badge--preorder', $html );
		$this->assertStringContainsString( '予約受付中', $html );
		$this->assertStringNotContainsString( 'affilicard-card__release-date', $html );
	}

	public function test_mask_blur_wraps_cover_with_blur_class(): void {
		$html = ( new CardRenderer() )->render(
			array(
				'title'        => 'サンプル作品',
				'stock_status' => 'available',
				'listings'     => array(),
			),
			array(),
			array(
				'image_url' => 'https://example.com/c.jpg',
				'mask_blur' => true,
			)
		);
		$this->assertStringContainsString( 'affilicard-card__cover--masked', $html );
		$this->assertStringContainsString( 'affilicard-card__cover-blur', $html );
		// blur のみ（R18 バッジもラベルも無い）場合は overlay div 自体が省略される。
		$this->assertStringNotContainsString( 'affilicard-card__cover-overlay', $html );
	}

	public function test_mask_r18_forces_blur_and_shows_badge(): void {
		$html = ( new CardRenderer() )->render(
			array(
				'title'        => 'サンプル作品',
				'stock_status' => 'available',
				'listings'     => array(),
			),
			array(),
			array(
				'image_url' => 'https://example.com/c.jpg',
				'mask_blur' => false,
				'mask_r18'  => true,
			)
		);
		// R18 はぼかしを強制する。
		$this->assertStringContainsString( 'affilicard-card__cover--masked', $html );
		$this->assertStringContainsString( 'affilicard-card__cover-badge', $html );
	}

	public function test_mask_label_shown_only_when_masked_and_nonempty(): void {
		$masked = ( new CardRenderer() )->render(
			array(
				'title'        => 'サンプル作品',
				'stock_status' => 'available',
				'listings'     => array(),
			),
			array(),
			array(
				'image_url'  => 'https://example.com/c.jpg',
				'mask_blur'  => true,
				'mask_label' => 'ご注意',
			)
		);
		$this->assertStringContainsString( 'affilicard-card__cover-label', $masked );
		$this->assertStringContainsString( 'ご注意', $masked );

		// マスクなし＋ラベルありでもラベルは出さない（通常描画）。
		$plain = ( new CardRenderer() )->render(
			array(
				'title'        => 'サンプル作品',
				'stock_status' => 'available',
				'listings'     => array(),
			),
			array(),
			array(
				'image_url'  => 'https://example.com/c.jpg',
				'mask_blur'  => false,
				'mask_r18'   => false,
				'mask_label' => 'ご注意',
			)
		);
		$this->assertStringNotContainsString( 'affilicard-card__cover', $plain );
		$this->assertStringNotContainsString( 'ご注意', $plain );
	}

	/** @return list<PlatformDefinition> */
	private function bookPlatforms(): array {
		return array(
			new PlatformDefinition( 'dmm-books', 'DMMブックス', 'manual', 1, true, array( 'ebook' ), 'DMMで読む', '#000', '#fff', imagePriority: 10 ),
			new PlatformDefinition( 'amazon-kindle', 'Amazon', 'manual', 2, true, array( 'ebook' ), 'Kindleで読む', '#000', '#fff', imagePriority: 20 ),
			new PlatformDefinition( 'rakuten-kobo', '楽天Kobo', 'manual', 3, true, array( 'ebook' ), 'Koboで読む', '#000', '#fff', imagePriority: 30 ),
		);
	}

	public function test_card_image_follows_platform_priority_dmm_over_kobo(): void {
		$product = array(
			'title'        => 'X',
			'stock_status' => 'available',
			'listings'     => array(
				array(
					'platform'      => 'rakuten-kobo',
					'affiliate_url' => 'https://a/kobo',
					'image_url'     => 'https://cdn/kobo.jpg',
				),
				array(
					'platform'      => 'dmm-books',
					'affiliate_url' => 'https://a/dmm',
					'image_url'     => 'https://cdn/dmm.jpg',
				),
			),
		);
		$html    = ( new CardRenderer() )->render( $product, $this->bookPlatforms(), array( 'image_url' => 'https://cdn/eyecatch.jpg' ) );
		$this->assertStringContainsString( 'https://cdn/dmm.jpg', $html );
		$this->assertStringNotContainsString( 'https://cdn/eyecatch.jpg', $html );
		$this->assertStringNotContainsString( 'https://cdn/kobo.jpg', $html );
	}

	public function test_card_image_follows_only_platform_restriction(): void {
		$product = array(
			'title'        => 'X',
			'stock_status' => 'available',
			'listings'     => array(
				array(
					'platform'      => 'dmm-books',
					'affiliate_url' => 'https://a/dmm',
					'image_url'     => 'https://cdn/dmm.jpg',
				),
				array(
					'platform'      => 'rakuten-kobo',
					'affiliate_url' => 'https://a/kobo',
					'image_url'     => 'https://cdn/kobo.jpg',
				),
			),
		);
		// 楽天Kobo のみ表示 → DMM の方が優先度高いが表示外なので Kobo 画像を使う。
		$html = ( new CardRenderer() )->render(
			$product,
			$this->bookPlatforms(),
			array(
				'only_platforms' => array( 'rakuten-kobo' ),
				'image_url'      => 'https://cdn/eyecatch.jpg',
			)
		);
		$this->assertStringContainsString( 'https://cdn/kobo.jpg', $html );
		$this->assertStringNotContainsString( 'https://cdn/dmm.jpg', $html );
	}

	public function test_card_image_falls_back_to_eyecatch_when_no_listing_image(): void {
		$product = array(
			'title'        => 'X',
			'stock_status' => 'available',
			'listings'     => array(
				array(
					'platform'      => 'dmm-books',
					'affiliate_url' => 'https://a/dmm',
				), // image_url 無し
			),
		);
		$html    = ( new CardRenderer() )->render( $product, $this->bookPlatforms(), array( 'image_url' => 'https://cdn/eyecatch.jpg' ) );
		$this->assertStringContainsString( 'https://cdn/eyecatch.jpg', $html );
	}

	public function test_card_image_tiebreak_prefers_lower_display_order_on_equal_priority(): void {
		$platforms = array(
			new PlatformDefinition( 'store-a', 'A', 'manual', 2, true, array( 'ebook' ), 'Aで読む', '#000', '#fff', imagePriority: 10 ),
			new PlatformDefinition( 'store-b', 'B', 'manual', 1, true, array( 'ebook' ), 'Bで読む', '#000', '#fff', imagePriority: 10 ),
		);
		$product   = array(
			'title'        => 'X',
			'stock_status' => 'available',
			'listings'     => array(
				array(
					'platform'      => 'store-a',
					'affiliate_url' => 'https://a/a',
					'image_url'     => 'https://cdn/a.jpg',
				),
				array(
					'platform'      => 'store-b',
					'affiliate_url' => 'https://a/b',
					'image_url'     => 'https://cdn/b.jpg',
				),
			),
		);
		$html      = ( new CardRenderer() )->render( $product, $platforms, array( 'image_url' => 'https://cdn/eye.jpg' ) );
		$this->assertStringContainsString( 'https://cdn/b.jpg', $html );
		$this->assertStringNotContainsString( 'https://cdn/a.jpg', $html );
	}

	public function test_card_image_skips_empty_string_image_url(): void {
		$product = array(
			'title'        => 'X',
			'stock_status' => 'available',
			'listings'     => array(
				array(
					'platform'      => 'dmm-books',
					'affiliate_url' => 'https://a/dmm',
					'image_url'     => '',
				),
			),
		);
		$html    = ( new CardRenderer() )->render( $product, $this->bookPlatforms(), array( 'image_url' => 'https://cdn/eye.jpg' ) );
		$this->assertStringContainsString( 'https://cdn/eye.jpg', $html );
	}

	public function test_card_image_skips_invalid_url_and_uses_fallback(): void {
		// esc_url_raw で空になる無効 URL（javascript: スキーム）は候補から除外され、
		// 他に有効な listing 画像が無ければアイキャッチ fallback が使われる。
		$product = array(
			'title'        => 'X',
			'stock_status' => 'available',
			'listings'     => array(
				array(
					'platform'      => 'dmm-books',
					'affiliate_url' => 'https://a/dmm',
					'image_url'     => 'javascript:alert(1)',
				),
			),
		);
		$html    = ( new CardRenderer() )->render( $product, $this->bookPlatforms(), array( 'image_url' => 'https://cdn/eye.jpg' ) );
		$this->assertStringContainsString( 'https://cdn/eye.jpg', $html );
		$this->assertStringNotContainsString( 'javascript:alert', $html );
	}
}
