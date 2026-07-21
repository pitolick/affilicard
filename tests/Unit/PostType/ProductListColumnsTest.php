<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\PostType;

use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductListColumns;
use Affilicard\PostType\ProductPostType;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProductListColumnsTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);
		WP_Mock::userFunction( 'esc_attr__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);
		WP_Mock::userFunction( 'esc_attr' )
			->andReturnUsing(
				static function ( $text ) {
					return (string) $text;
				}
			);
		WP_Mock::userFunction( 'esc_html' )
			->andReturnUsing(
				static function ( $text ) {
					return (string) $text;
				}
			);
		// 日付整形（Y-m-d H:i）用。UTC 固定（gmdate と同じ基準）で PHP 実行環境の
		// デフォルトタイムゾーン設定に依存しないようにする（CardRendererTest と同じ手法）。
		WP_Mock::userFunction( 'wp_date' )
			->andReturnUsing(
				static function ( $format, $timestamp = null ) {
					return gmdate( (string) $format, null !== $timestamp ? (int) $timestamp : time() );
				}
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_addColumn_inserts_fallback_column_right_after_title(): void {
		$columns = array(
			'cb'     => '<input />',
			'title'  => 'タイトル',
			'author' => '著者',
			'date'   => '日付',
		);

		$result = ProductListColumns::addColumn( $columns );

		$keys = array_keys( $result );
		$this->assertSame(
			array( 'cb', 'title', ProductListColumns::COLUMN_KEY, ProductListColumns::COLUMN_LAST_VERIFIED, 'author', 'date' ),
			$keys
		);
		$this->assertSame( 'Fallback', $result[ ProductListColumns::COLUMN_KEY ] );
		$this->assertSame( '最終更新', $result[ ProductListColumns::COLUMN_LAST_VERIFIED ] );
	}

	public function test_renderColumn_echoes_warning_icon_when_listings_have_fallback(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 123, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'      => 'dmm-books',
						'affiliate_url' => '',
						'regular_url'   => 'https://example.com/product',
					),
				)
			);

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 123 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'dashicons-warning', $output );
		$this->assertStringContainsString( 'フォールバック', $output );
	}

	public function test_renderColumn_echoes_em_dash_when_no_fallback(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 456, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'      => 'dmm-books',
						'affiliate_url' => 'https://aff.example.com/abc',
						'regular_url'   => 'https://example.com/product',
					),
				)
			);

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 456 );
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'dashicons-warning', $output );
		$this->assertStringContainsString( '—', $output );
	}

	public function test_renderColumn_echoes_price_hidden_warning_when_price_unverified(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 321, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'      => 'rakuten-kobo',
						'price'         => '693',
						'affiliate_url' => 'https://hb.afl.rakuten.co.jp/hgc/x/',
						'regular_url'   => 'https://books.rakuten.co.jp/rk/x/',
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'          => 'rakuten-kobo',
						'priceTtlHours' => 24,
					),
				)
			);

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 321 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'dashicons-warning', $output );
		$this->assertStringContainsString( '価格が未確認/期限切れのためカードで非表示です', $output );
	}

	public function test_renderColumn_last_verified_shows_max_timestamp_across_listings(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 111, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'         => 'dmm-books',
						'last_verified_at' => '2026-07-20T10:00:00+00:00',
					),
					array(
						'platform'         => 'rakuten-kobo',
						'last_verified_at' => '2026-07-21T03:15:00+00:00',
					),
				)
			);

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_LAST_VERIFIED, 111 );
		$output = (string) ob_get_clean();

		$this->assertSame( '2026-07-21 03:15', $output );
	}

	public function test_renderColumn_last_verified_echoes_em_dash_when_no_timestamps(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 222, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'dmm-books',
					),
					array(
						'platform'         => 'rakuten-kobo',
						'last_verified_at' => '',
					),
				)
			);

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_LAST_VERIFIED, 222 );
		$output = (string) ob_get_clean();

		$this->assertSame( '<span aria-hidden="true">—</span>', $output );
	}

	public function test_renderColumn_returns_early_for_unrelated_column(): void {
		ob_start();
		ProductListColumns::renderColumn( 'some-other-column', 789 );
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}
}
