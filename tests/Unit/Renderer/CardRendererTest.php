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
		// renderTimestamp() の日付整形用。wp_date() は「サイトのタイムゾーン」で整形する契約。
		// UTC 固定実装（gmdate を直呼びする等）を検出できるよう、stub は非 UTC（Asia/Tokyo,
		// +09:00）を模す。期待値も同じ Asia/Tokyo 換算（jstFormat()）で作るため、実装が wp_date
		// を経由せず UTC 整形するとこのテストは失敗する。
		WP_Mock::userFunction( 'wp_date' )
			->andReturnUsing(
				static function ( $format, $timestamp = null ) {
					$ts = null !== $timestamp ? (int) $timestamp : time();
					return ( new \DateTimeImmutable( '@' . $ts ) )
						->setTimezone( new \DateTimeZone( 'Asia/Tokyo' ) )
						->format( (string) $format );
				}
			);
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

	/**
	 * wp_date() stub と同じ Asia/Tokyo（サイト tz を模す）換算で「時点の価格」注記の期待値を作る。
	 * これにより、実装が wp_date を経由せず UTC 固定で整形すると assertion が一致せず失敗する。
	 */
	private function jstFormat( int $ts, string $format = 'Y年n月j日 H:i' ): string {
		return ( new \DateTimeImmutable( '@' . $ts ) )
			->setTimezone( new \DateTimeZone( 'Asia/Tokyo' ) )
			->format( $format );
	}

	/**
	 * @param int $priceTtlHours PriceFreshness の TTL。renderTimestamp の「最新選択/フィルタ」系
	 *                           テストでは固定日付を使い続けたいので、実質無期限の大きな値を渡せるようにする
	 *                           （既定 24 は本番既定と一致。呼び出し側を壊さないためデフォルト引数で維持）。
	 */
	private function store( int $priceTtlHours = 24 ): PlatformDefinition {
		// 汎用型(generic)の基準 platform。ebook 固有要素は別タスクで追加する。
		return new PlatformDefinition( 'example-store', 'サンプルストア', 'manual', 1, true, array( 'generic' ), 'ストアで見る', '#2563eb', '#ffffff', priceTtlHours: $priceTtlHours );
	}

	/**
	 * price/list_price/badge を持つ 1 listing の商品を組み立てる（URL 必須＝価格行が描画される）。
	 * last_verified_at は「1時間前」固定＝ store() 既定 TTL(24h) 内で常に displayable。
	 */
	private function pricedProduct( string $price, string $listPrice, string $badge = '' ): array {
		$listing = array(
			'platform'         => 'example-store',
			'enabled'          => true,
			'affiliate_url'    => 'https://x',
			'price'            => $price,
			'list_price'       => $listPrice,
			'last_verified_at' => gmdate( 'c', time() - 3600 ),
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

	public function test_hide_media_omits_image_column_entirely(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://x',
						'image_url'     => 'https://cdn.example/cover.jpg',
					),
				),
			)
		);

		$shown  = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$hidden = ( new CardRenderer() )->render( $product, array( $this->store() ), array( 'hide_media' => true ) );

		// 通常は書影が出る
		$this->assertStringContainsString( 'https://cdn.example/cover.jpg', $shown );
		$this->assertStringContainsString( 'affilicard-card__media', $shown );

		// 非表示にすると画像 URL もカラムごと消える
		$this->assertStringNotContainsString( 'https://cdn.example/cover.jpg', $hidden );
		$this->assertStringNotContainsString( 'affilicard-card__media', $hidden );
		// 「画像がありません」のプレースホルダにも落とさない（読み込み失敗に見えるため）
		$this->assertStringNotContainsString( '画像がありません', $hidden );
		// 本文を全幅にするための修飾クラスが付く
		$this->assertStringContainsString( 'affilicard-card--no-media', $hidden );
		// CTA と本文は残る
		$this->assertStringContainsString( 'affilicard-card__cta', $hidden );
		$this->assertStringContainsString( 'affilicard-card__body', $hidden );
	}

	public function test_hide_media_also_suppresses_masked_cover(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://x',
						'image_url'     => 'https://cdn.example/cover.jpg',
					),
				),
			)
		);

		$html = ( new CardRenderer() )->render(
			$product,
			array( $this->store() ),
			array(
				'hide_media' => true,
				'mask_r18'   => true,
			)
		);

		$this->assertStringNotContainsString( 'affilicard-card__cover', $html );
		$this->assertStringNotContainsString( 'https://cdn.example/cover.jpg', $html );
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
						'platform'         => 'example-store',
						'enabled'          => true,
						'affiliate_url'    => 'https://x',
						'price'            => '¥1,200',
						'last_verified_at' => gmdate( 'c', time() - 3600 ),
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

	private function dmmBooks( int $priceTtlHours = 24 ): PlatformDefinition {
		return new PlatformDefinition( 'dmm-books', 'DMMブックス', 'dmm-ebook', 1, true, array( 'ebook' ), 'DMMブックスで読む', '#d72d65', '#ffffff', priceTtlHours: $priceTtlHours );
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
						'platform'         => 'example-store',
						'enabled'          => true,
						'affiliate_url'    => 'https://x',
						'price'            => '600',
						'last_verified_at' => gmdate( 'c', time() - 3600 ),
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
						'platform'         => 'example-store',
						'enabled'          => true,
						'affiliate_url'    => 'https://x',
						'price'            => '¥1,200',
						'last_verified_at' => gmdate( 'c', time() - 3600 ),
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
						'platform'         => 'example-store',
						'enabled'          => true,
						'affiliate_url'    => 'https://x',
						'price'            => '600',
						'badge'            => '40%OFF',
						'last_verified_at' => gmdate( 'c', time() - 3600 ),
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

	public function test_renders_price_timestamp_footer_from_last_verified_at(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'         => 'example-store',
						'enabled'          => true,
						'affiliate_url'    => 'https://x',
						'price'            => '600',
						'last_verified_at' => '2026-04-20T10:30:00+09:00',
					),
				),
			)
		);
		// long-TTL の store() を使い、実行日に依存せず固定日付のまま displayable にする
		// （フッターの「最新日付選択」ロジック自体は Task 10 の鮮度ゲートとは別に検証したい）。
		$html = ( new CardRenderer() )->render( $product, array( $this->store( 87600 ) ) );
		$this->assertStringContainsString( 'affilicard-card__timestamp', $html );
		// 日付＋時刻（サイト tz）まで表示して鮮度を一意にする。
		$this->assertStringContainsString( $this->jstFormat( strtotime( '2026-04-20T10:30:00+09:00' ) ) . '時点の価格', $html );
	}

	public function test_uses_latest_last_verified_at_across_listings(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'         => 'example-store',
						'enabled'          => true,
						'affiliate_url'    => 'https://a',
						'price'            => '600',
						'last_verified_at' => '2026-04-18T09:00:00+09:00',
					),
					array(
						'platform'         => 'example-store',
						'enabled'          => true,
						'affiliate_url'    => 'https://b',
						'price'            => '660',
						'last_verified_at' => '2026-04-20T09:00:00+09:00',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store( 87600 ) ) );
		$this->assertStringContainsString( $this->jstFormat( strtotime( '2026-04-20T09:00:00+09:00' ) ) . '時点の価格', $html );
	}

	public function test_no_timestamp_footer_when_no_last_verified_at(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'      => 'example-store',
						'enabled'       => true,
						'affiliate_url' => 'https://x',
						'price'         => '600',
						// last_verified_at 無し＝手動/未確認。price はあっても表示ゲート対象外。
					),
				),
			)
		);
		$html = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__timestamp', $html );
	}

	public function test_renders_badge_hidden_when_price_absent_even_with_verified_at(): void {
		// badge は「価格スパン」の一部（list-price/price/tax/discount）として扱うため、
		// last_verified_at があっても price が空なら表示ゲート対象外＝badge も出ない（CTA のみ残る）。
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'         => 'example-store',
						'enabled'          => true,
						'affiliate_url'    => 'https://x',
						'badge'            => 'NEW',
						'last_verified_at' => gmdate( 'c', time() - 3600 ),
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__discount', $html );
		$this->assertStringContainsString( 'affilicard-card__cta', $html );
	}

	public function test_ignores_non_date_last_verified_at(): void {
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'         => 'example-store',
						'enabled'          => true,
						'affiliate_url'    => 'https://x',
						'price'            => '600',
						'last_verified_at' => 'not-a-date',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->store() ) );
		$this->assertStringNotContainsString( 'affilicard-card__timestamp', $html );
	}

	// ------------------------------------------------------------------
	// Task 10: 価格表示ゲート（PriceFreshness::isPriceDisplayable）。
	// rakuten-kobo（priceTtlHours=24, 本番既定と同値）を $by_code 経由で解決させ、
	// last_verified_at の有無・鮮度で価格スパン／免責文言の表示可否を検証する。
	// ------------------------------------------------------------------

	/**
	 * rakuten-kobo（priceTtlHours=24）で単一 listing を描画する薄い helper。
	 *
	 * @param array<string, mixed> $listingOverrides
	 */
	private function renderCardWithListing( array $listingOverrides ): string {
		$listing  = array_merge(
			array(
				'platform' => 'rakuten-kobo',
				'enabled'  => true,
			),
			$listingOverrides
		);
		$product  = $this->product( array( 'listings' => array( $listing ) ) );
		$platform = new PlatformDefinition( 'rakuten-kobo', '楽天Kobo', 'manual', 3, true, array( 'ebook' ), 'Koboで読む', '#bf0000', '#ffffff', priceTtlHours: 24 );
		return ( new CardRenderer() )->render( $product, array( $platform ) );
	}

	public function test_確認済み鮮度内の価格は表示される(): void {
		$html = $this->renderCardWithListing(
			array(
				'platform'         => 'rakuten-kobo',
				'price'            => '693',
				'affiliate_url'    => 'https://hb.afl.rakuten.co.jp/hgc/x/',
				'last_verified_at' => gmdate( 'c', time() - 3600 ),
			)
		);
		$this->assertStringContainsString( 'affilicard-card__price', $html );
		$this->assertStringContainsString( '693', $html );
	}

	public function test_未確認価格はCTAのみで価格非表示(): void {
		$html = $this->renderCardWithListing(
			array(
				'platform'      => 'rakuten-kobo',
				'price'         => '693',
				'affiliate_url' => 'https://hb.afl.rakuten.co.jp/hgc/x/',
				// last_verified_at 無し
			)
		);
		$this->assertStringNotContainsString( 'affilicard-card__price', $html );
		$this->assertStringContainsString( 'affilicard-card__cta', $html ); // ボタンは残る
	}

	public function test_TTL超過価格は非表示(): void {
		$html = $this->renderCardWithListing(
			array(
				'platform'         => 'rakuten-kobo',
				'price'            => '693',
				'affiliate_url'    => 'https://hb.afl.rakuten.co.jp/hgc/x/',
				'last_verified_at' => gmdate( 'c', time() - 25 * 3600 ),
			)
		);
		$this->assertStringNotContainsString( 'affilicard-card__price', $html );
	}

	public function test_確認済み鮮度内では免責文言に日付が出る(): void {
		$ts   = time() - 3600;
		$html = $this->renderCardWithListing(
			array(
				'price'            => '693',
				'affiliate_url'    => 'https://hb.afl.rakuten.co.jp/hgc/x/',
				'last_verified_at' => gmdate( 'c', $ts ),
			)
		);
		$this->assertStringContainsString( 'affilicard-card__timestamp', $html );
		$this->assertStringContainsString( $this->jstFormat( $ts ) . '時点の価格', $html );
	}

	public function test_未確認価格のみではフッターの免責文言は出ない(): void {
		$html = $this->renderCardWithListing(
			array(
				'price'         => '693',
				'affiliate_url' => 'https://hb.afl.rakuten.co.jp/hgc/x/',
				// last_verified_at 無し
			)
		);
		$this->assertStringNotContainsString( 'affilicard-card__timestamp', $html );
	}

	public function test_TTL超過価格のみではフッターの免責文言は出ない(): void {
		$html = $this->renderCardWithListing(
			array(
				'price'            => '693',
				'affiliate_url'    => 'https://hb.afl.rakuten.co.jp/hgc/x/',
				'last_verified_at' => gmdate( 'c', time() - 25 * 3600 ),
			)
		);
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
		// 非表示プラットフォーム（example-store）の方が新しい last_verified_at を持つ場合でも、
		// 表示中（dmm-books）の日付がフッターに出る（CTA 表示と価格鮮度を一致させる）。
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'         => 'dmm-books',
						'enabled'          => true,
						'affiliate_url'    => 'https://dmm',
						'price'            => '600',
						'last_verified_at' => '2026-04-18T09:00:00+09:00',
					),
					array(
						'platform'         => 'example-store',
						'enabled'          => true,
						'affiliate_url'    => 'https://store',
						'price'            => '600',
						'last_verified_at' => '2026-04-25T09:00:00+09:00',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render(
			$product,
			array( $this->dmmBooks( 87600 ), $this->store( 87600 ) ),
			array( 'only_platforms' => array( 'dmm-books' ) )
		);
		$this->assertStringContainsString( $this->jstFormat( strtotime( '2026-04-18T09:00:00+09:00' ) ) . '時点の価格', $html );
		$this->assertStringNotContainsString( '2026年4月25日', $html );
	}

	public function test_timestamp_ignores_listing_without_url(): void {
		// URL 無し listing は CTA 行が出ないので、その last_verified_at もフッターに採用されない。
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'         => 'dmm-books',
						'enabled'          => true,
						'affiliate_url'    => 'https://dmm',
						'price'            => '600',
						'last_verified_at' => '2026-04-18T09:00:00+09:00',
					),
					array(
						'platform'         => 'example-store',
						'enabled'          => true,
						'affiliate_url'    => '',
						'regular_url'      => '',
						'price'            => '600',
						'last_verified_at' => '2026-04-25T09:00:00+09:00',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render( $product, array( $this->dmmBooks( 87600 ), $this->store( 87600 ) ) );
		$this->assertStringContainsString( $this->jstFormat( strtotime( '2026-04-18T09:00:00+09:00' ) ) . '時点の価格', $html );
		$this->assertStringNotContainsString( '2026年4月25日', $html );
	}

	public function test_timestamp_ignores_listing_hidden_by_hide_platforms(): void {
		// hide_platforms でも同様に、非表示 listing の日付はフッターに採用されない。
		$product = $this->product(
			array(
				'listings' => array(
					array(
						'platform'         => 'dmm-books',
						'enabled'          => true,
						'affiliate_url'    => 'https://dmm',
						'price'            => '600',
						'last_verified_at' => '2026-04-18T09:00:00+09:00',
					),
					array(
						'platform'         => 'example-store',
						'enabled'          => true,
						'affiliate_url'    => 'https://store',
						'price'            => '600',
						'last_verified_at' => '2026-04-25T09:00:00+09:00',
					),
				),
			)
		);
		$html    = ( new CardRenderer() )->render(
			$product,
			array( $this->dmmBooks( 87600 ), $this->store( 87600 ) ),
			array( 'hide_platforms' => array( 'example-store' ) )
		);
		$this->assertStringContainsString( $this->jstFormat( strtotime( '2026-04-18T09:00:00+09:00' ) ) . '時点の価格', $html );
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
			new PlatformDefinition( 'dmm-books', 'DMMブックス', 'manual', 1, true, array( 'ebook' ), 'DMMで読む', '#000', '#fff' ),
			new PlatformDefinition( 'amazon-kindle', 'Amazon', 'manual', 2, true, array( 'ebook' ), 'Kindleで読む', '#000', '#fff' ),
			new PlatformDefinition( 'rakuten-kobo', '楽天Kobo', 'manual', 3, true, array( 'ebook' ), 'Koboで読む', '#000', '#fff' ),
		);
	}

	public function test_card_image_follows_display_order_dmm_over_kobo(): void {
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
		// 楽天Kobo のみ表示 → DMM の方が表示順は先だが表示外なので Kobo 画像を使う。
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

	/**
	 * 表示順テスト用の platform を作る。displayOrder を任意の値に設定でき、
	 * listing の登録順とは独立に並びを検証できる。
	 */
	private function orderedPlatform( string $code, string $name, int $displayOrder ): PlatformDefinition {
		return new PlatformDefinition(
			$code,
			$name,
			'manual',
			$displayOrder,
			true,
			array( 'ebook' ),
			$name . 'で読む',
			'#444444',
			'#ffffff'
		);
	}

	/**
	 * @param list<string> $codes
	 * @return array<string, mixed>
	 */
	private function productWithListings( array $codes ): array {
		$listings = array();
		foreach ( $codes as $code ) {
			$listings[] = array(
				'platform'      => $code,
				'enabled'       => true,
				'affiliate_url' => 'https://example.test/' . $code,
			);
		}
		return array(
			'title'        => 'テスト商品',
			'stock_status' => 'available',
			'listings'     => $listings,
		);
	}

	public function test_cta_rows_follow_display_order_not_listing_order(): void {
		// listing は登録順（ストアC → ストアA → ストアB）だが、displayOrder は A=1, B=2, C=3。
		$platforms = array(
			$this->orderedPlatform( 'store-a', 'ストアA', 1 ),
			$this->orderedPlatform( 'store-b', 'ストアB', 2 ),
			$this->orderedPlatform( 'store-c', 'ストアC', 3 ),
		);
		$html      = ( new CardRenderer() )->render(
			$this->productWithListings( array( 'store-c', 'store-a', 'store-b' ) ),
			$platforms
		);
		$pos_a     = strpos( $html, 'https://example.test/store-a' );
		$pos_b     = strpos( $html, 'https://example.test/store-b' );
		$pos_c     = strpos( $html, 'https://example.test/store-c' );
		$this->assertLessThan( $pos_b, $pos_a );
		$this->assertLessThan( $pos_c, $pos_b );
	}

	public function test_cta_rows_keep_listing_order_when_display_order_ties(): void {
		// displayOrder が同値なら登録順を保つ（安定ソート）。
		$platforms = array(
			$this->orderedPlatform( 'store-a', 'ストアA', 7 ),
			$this->orderedPlatform( 'store-b', 'ストアB', 7 ),
		);
		$html      = ( new CardRenderer() )->render(
			$this->productWithListings( array( 'store-b', 'store-a' ) ),
			$platforms
		);
		$this->assertLessThan(
			strpos( $html, 'https://example.test/store-a' ),
			strpos( $html, 'https://example.test/store-b' )
		);
	}

	public function test_disabled_platform_is_excluded_and_rest_follows_display_order(): void {
		$platforms = array(
			$this->orderedPlatform( 'store-a', 'ストアA', 1 ),
			new PlatformDefinition( 'store-b', 'ストアB', 'manual', 2, false, array( 'ebook' ), 'ストアBで読む', '#444444', '#ffffff' ),
			$this->orderedPlatform( 'store-c', 'ストアC', 3 ),
		);
		$html      = ( new CardRenderer() )->render(
			$this->productWithListings( array( 'store-c', 'store-b', 'store-a' ) ),
			$platforms
		);
		$this->assertStringNotContainsString( 'https://example.test/store-b', $html );
		$this->assertLessThan(
			strpos( $html, 'https://example.test/store-c' ),
			strpos( $html, 'https://example.test/store-a' )
		);
	}

	public function test_only_platforms_is_a_filter_and_does_not_define_order(): void {
		// only_platforms の指定順は許可リストであって順序ではない。
		$platforms = array(
			$this->orderedPlatform( 'store-a', 'ストアA', 1 ),
			$this->orderedPlatform( 'store-b', 'ストアB', 2 ),
		);
		$html      = ( new CardRenderer() )->render(
			$this->productWithListings( array( 'store-a', 'store-b' ) ),
			$platforms,
			array( 'only_platforms' => array( 'store-b', 'store-a' ) )
		);
		$this->assertLessThan(
			strpos( $html, 'https://example.test/store-b' ),
			strpos( $html, 'https://example.test/store-a' )
		);
	}

	public function test_card_image_comes_from_the_first_platform_in_display_order(): void {
		$platforms = array(
			$this->orderedPlatform( 'store-a', 'ストアA', 1 ),
			$this->orderedPlatform( 'store-b', 'ストアB', 2 ),
		);
		// listing の登録順は逆（B → A）。表示順の先頭は A なので A の画像が選ばれる。
		$product = array(
			'title'        => 'テスト商品',
			'stock_status' => 'available',
			'listings'     => array(
				array(
					'platform'      => 'store-b',
					'enabled'       => true,
					'affiliate_url' => 'https://example.test/store-b',
					'image_url'     => 'https://cdn.test/b.jpg',
				),
				array(
					'platform'      => 'store-a',
					'enabled'       => true,
					'affiliate_url' => 'https://example.test/store-a',
					'image_url'     => 'https://cdn.test/a.jpg',
				),
			),
		);
		$html    = ( new CardRenderer() )->render( $product, $platforms, array( 'image_url' => 'https://cdn.test/eyecatch.jpg' ) );
		$this->assertStringContainsString( 'https://cdn.test/a.jpg', $html );
		$this->assertStringNotContainsString( 'https://cdn.test/b.jpg', $html );
	}

	public function test_card_image_falls_back_to_next_listing_when_first_has_no_image(): void {
		$platforms = array(
			$this->orderedPlatform( 'store-a', 'ストアA', 1 ),
			$this->orderedPlatform( 'store-b', 'ストアB', 2 ),
		);
		$product   = array(
			'title'        => 'テスト商品',
			'stock_status' => 'available',
			'listings'     => array(
				array(
					'platform'      => 'store-a',
					'enabled'       => true,
					'affiliate_url' => 'https://example.test/store-a',
					'image_url'     => '',
				),
				array(
					'platform'      => 'store-b',
					'enabled'       => true,
					'affiliate_url' => 'https://example.test/store-b',
					'image_url'     => 'https://cdn.test/b.jpg',
				),
			),
		);
		$html      = ( new CardRenderer() )->render( $product, $platforms, array( 'image_url' => 'https://cdn.test/eyecatch.jpg' ) );
		$this->assertStringContainsString( 'https://cdn.test/b.jpg', $html );
	}

	public function test_card_image_falls_back_to_featured_image_when_no_listing_has_one(): void {
		$platforms = array( $this->orderedPlatform( 'store-a', 'ストアA', 1 ) );
		$product   = array(
			'title'        => 'テスト商品',
			'stock_status' => 'available',
			'listings'     => array(
				array(
					'platform'      => 'store-a',
					'enabled'       => true,
					'affiliate_url' => 'https://example.test/store-a',
				),
			),
		);
		$html      = ( new CardRenderer() )->render( $product, $platforms, array( 'image_url' => 'https://cdn.test/eyecatch.jpg' ) );
		$this->assertStringContainsString( 'https://cdn.test/eyecatch.jpg', $html );
	}
}
