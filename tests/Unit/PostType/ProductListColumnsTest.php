<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\PostType;

use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductListColumns;
use Affilicard\PostType\ProductPostType;
use Affilicard\Queue\Enqueuer;
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
		// fetch_error サニタイズ（spec §9-3 二重防御の1段目）の実体を模した stub。
		// 実 wp_strip_all_tags と同様、タグは除去するがタグ内テキストはそのまま残す。
		WP_Mock::userFunction( 'wp_strip_all_tags' )
			->andReturnUsing(
				static function ( $text ) {
					return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $text ) );
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
		$this->assertSame( '最終同期', $result[ ProductListColumns::COLUMN_LAST_VERIFIED ] );
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
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'     => 'dmm-books',
						'provider' => 'dmm-ebook',
					),
				)
			);
		WP_Mock::userFunction( 'as_has_scheduled_action' )
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 123,
					'platform' => 'dmm-books',
				),
				'affilicard-dmm-ebook'
			)
			->andReturn( false );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 123 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'dashicons-warning', $output );
		$this->assertStringContainsString( 'フォールバック', $output );
		$this->assertStringNotContainsString( '更新待ち', $output );
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
		WP_Mock::userFunction( 'as_has_scheduled_action' )
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 321,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-manual'
			)
			->andReturn( false );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 321 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'dashicons-warning', $output );
		$this->assertStringContainsString( '価格が未確認/期限切れのためカードで非表示です', $output );
		$this->assertStringNotContainsString( '更新待ち', $output );
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

	/**
	 * Task 18: Fallback 列にキュー状態を連携。
	 *
	 * as_has_scheduled_action が true（pending なジョブが Enqueuer::HOOK_REFRESH /
	 * post_id・platform / "affilicard-{provider}" group で見つかる）場合、警告アイコンの
	 * title に「更新待ち」が含まれること。呼び出し引数（hook・args・group）も検証する。
	 */
	public function test_renderColumn_fallback_title_includes_pending_note_when_queue_job_scheduled(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 555, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'      => 'dmm-books',
						'affiliate_url' => '',
						'regular_url'   => 'https://example.com/product',
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'     => 'dmm-books',
						'provider' => 'dmm-ebook',
					),
				)
			);
		WP_Mock::userFunction( 'as_has_scheduled_action' )
			->once()
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 555,
					'platform' => 'dmm-books',
				),
				'affilicard-dmm-ebook'
			)
			->andReturn( true );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 555 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'dashicons-warning', $output );
		$this->assertStringContainsString( '更新待ち', $output );
	}

	/**
	 * Task 18 / spec §9-3 二重防御の証拠テスト。
	 *
	 * fetch_error は provider 由来の外部文字列のため、HTML/script が混入していても
	 * 1) wp_strip_all_tags によるタグ除去、2) esc_attr による最終エスケープ、の二段構えで
	 * 生のまま出力に混入しないことを検証する。タグは除去されるがタグ内テキスト自体は
	 * サニタイズ後も残る（strip_tags の仕様どおり）ため、タグそのもの（`<script>`）が
	 * 出力に存在しないことをもって「実行可能なマークアップとして生存していない」ことを確認する。
	 */
	public function test_renderColumn_fallback_title_strips_script_tag_from_fetch_error_and_escapes_output(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 666, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'      => 'dmm-books',
						'affiliate_url' => '',
						'regular_url'   => 'https://example.com/product',
						'fetch_error'   => 'API接続エラー: <script>alert(1)</script>',
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'     => 'dmm-books',
						'provider' => 'dmm-ebook',
					),
				)
			);
		WP_Mock::userFunction( 'as_has_scheduled_action' )
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 666,
					'platform' => 'dmm-books',
				),
				'affilicard-dmm-ebook'
			)
			->andReturn( false );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 666 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'dashicons-warning', $output );
		$this->assertStringContainsString( '失敗理由', $output );
		$this->assertStringContainsString( 'API接続エラー', $output );
		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringNotContainsString( '</script>', $output );
	}

	/**
	 * Task 18 / spec §9-3 二重防御の2段目（長さ制限）。
	 *
	 * 極端に長い fetch_error（200文字超）は切り詰められ、末尾の内容が出力に現れないこと。
	 */
	public function test_renderColumn_fallback_title_truncates_long_fetch_error(): void {
		$long_error = str_repeat( 'あ', 250 ) . 'TAIL_MARKER_MUST_BE_TRUNCATED';

		WP_Mock::userFunction( 'get_post_meta' )
			->with( 777, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'      => 'dmm-books',
						'affiliate_url' => '',
						'regular_url'   => 'https://example.com/product',
						'fetch_error'   => $long_error,
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'     => 'dmm-books',
						'provider' => 'dmm-ebook',
					),
				)
			);
		WP_Mock::userFunction( 'as_has_scheduled_action' )
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 777,
					'platform' => 'dmm-books',
				),
				'affilicard-dmm-ebook'
			)
			->andReturn( false );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 777 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '失敗理由', $output );
		$this->assertStringNotContainsString( 'TAIL_MARKER_MUST_BE_TRUNCATED', $output );
	}
}
