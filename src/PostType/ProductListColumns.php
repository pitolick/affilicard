<?php
declare(strict_types=1);

namespace Affilicard\PostType;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Pricing\PriceFreshness;
use Affilicard\Queue\Enqueuer;
use Affilicard\Util\JsonField;

/**
 * CPT 一覧画面に「Fallback」カラムを追加する。
 *
 * Listing の `affiliate_url` が空かつ `regular_url` が非空の場合、警告アイコンを表示する。
 * また、`price` を保持しているが `PriceFreshness::isPriceDisplayable()` が false（未確認/期限切れ）の
 * 場合も、カード上で価格が非表示になる旨の警告アイコンを表示する。
 *
 * 警告が出ている listing については、Action Scheduler の pending 状態（再取得ジョブが
 * 既にキュー投入済みか）と `fetch_error`（直近の取得失敗理由）を warning アイコンの
 * title 属性に付記する（Task 18）。`fetch_error` は provider 由来の外部文字列のため、
 * `wp_strip_all_tags()` によるタグ除去＋長さ制限（200文字）でサニタイズしたうえで、
 * 出力直前に `esc_attr()` で最終エスケープする二重防御を行う（spec §9-3）。
 */
final class ProductListColumns {

	public const COLUMN_KEY           = 'affilicard_fallback';
	public const COLUMN_LAST_VERIFIED = 'affilicard_last_verified';

	public static function register(): void {
		$hook_post_type = ProductPostType::POST_TYPE;
		add_filter( "manage_{$hook_post_type}_posts_columns", array( self::class, 'addColumn' ) );
		add_action( "manage_{$hook_post_type}_posts_custom_column", array( self::class, 'renderColumn' ), 10, 2 );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function addColumn( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new[ self::COLUMN_KEY ]           = __( 'Fallback', 'affilicard' );
				$new[ self::COLUMN_LAST_VERIFIED ] = __( '最終同期', 'affilicard' );
			}
		}
		return $new;
	}

	public static function renderColumn( string $column_key, int $post_id ): void {
		switch ( $column_key ) {
			case self::COLUMN_KEY:
				self::renderFallbackColumn( $post_id );
				return;
			case self::COLUMN_LAST_VERIFIED:
				self::renderLastVerifiedColumn( $post_id );
				return;
			default:
				return;
		}
	}

	private static function renderFallbackColumn( int $post_id ): void {
		$listings_raw = get_post_meta( $post_id, ProductPostType::META_LISTINGS, true );
		$listings     = is_array( $listings_raw ) ? $listings_raw : ( is_string( $listings_raw ) ? JsonField::decode( $listings_raw, array() ) : array() );

		$now_ts           = time();
		$has_fallback     = false;
		$has_hidden_price = false;
		$has_pending      = false;
		$error_notes      = array();
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$platform_code = isset( $listing['platform'] ) ? (string) $listing['platform'] : '';
			$affiliate     = isset( $listing['affiliate_url'] ) ? (string) $listing['affiliate_url'] : '';
			$regular       = isset( $listing['regular_url'] ) ? (string) $listing['regular_url'] : '';
			$is_fallback   = ( '' === $affiliate && '' !== $regular );
			if ( $is_fallback ) {
				$has_fallback = true;
			}

			$definition      = null;
			$is_price_hidden = false;
			$price           = isset( $listing['price'] ) ? trim( (string) $listing['price'] ) : '';
			if ( '' !== $price ) {
				$definition = PlatformConfig::find( $platform_code );
				if ( ! PriceFreshness::isPriceDisplayable( $listing, $definition, $now_ts ) ) {
					$has_hidden_price = true;
					$is_price_hidden  = true;
				}
			}

			// キュー状態/失敗理由の問い合わせは、既に警告が出ている listing に限定する
			// （警告の無い listing まで毎回 Action Scheduler に問い合わせるのは無駄なため）。
			if ( ! $is_fallback && ! $is_price_hidden ) {
				continue;
			}

			if ( '' !== $platform_code ) {
				if ( null === $definition ) {
					$definition = PlatformConfig::find( $platform_code );
				}
				$provider = null !== $definition ? $definition->provider : 'manual';
				// v2.4.0: キューの group も account コード単位（Enqueuer と揃える）。
				// account が解決できない（provider 未登録・手動系）場合は provider コードへ
				// フォールバックする（該当 group には実際のジョブが積まれないため常に false になる）。
				$account = \Affilicard\Plugin::buildProviderRegistry()->get( $provider )?->accountCode() ?? $provider;
				$group   = 'affilicard-' . $account;
				$args    = array(
					'post_id'  => $post_id,
					'platform' => $platform_code,
				);
				if ( as_has_scheduled_action( Enqueuer::HOOK_REFRESH, $args, $group ) ) {
					$has_pending = true;
				}
			}

			$raw_error = isset( $listing['fetch_error'] ) ? trim( (string) $listing['fetch_error'] ) : '';
			if ( '' !== $raw_error ) {
				$clean = self::sanitizeFetchError( $raw_error );
				if ( '' !== $clean && ! in_array( $clean, $error_notes, true ) ) {
					$error_notes[] = $clean;
				}
			}
		}

		$queue_note = self::buildQueueNote( $has_pending, $error_notes );

		if ( $has_hidden_price ) {
			echo '<span class="dashicons dashicons-warning" style="color:#d63638" title="' . esc_attr( __( '価格が未確認/期限切れのためカードで非表示です', 'affilicard' ) . $queue_note ) . '"></span> ';
		}
		if ( $has_fallback ) {
			echo '<span class="dashicons dashicons-warning" style="color:#dba617" title="' . esc_attr( __( 'アフィリエイト URL 未設定、通常 URL にフォールバック中', 'affilicard' ) . $queue_note ) . '"></span>';
		}
		if ( ! $has_hidden_price && ! $has_fallback ) {
			echo '<span aria-hidden="true">—</span>';
		}
	}

	/**
	 * pending 状態と fetch_error から、警告アイコンの title に付記する追加テキストを組み立てる。
	 *
	 * 戻り値はエスケープ前のプレーンテキスト（呼び出し側で esc_attr() を必ず通すこと）。
	 *
	 * @param list<string> $error_notes サニタイズ済みの fetch_error（listing 単位で重複除去済み）
	 */
	private static function buildQueueNote( bool $has_pending, array $error_notes ): string {
		$parts = array();
		if ( $has_pending ) {
			$parts[] = __( '更新待ち（キュー投入済み）', 'affilicard' );
		}
		if ( array() !== $error_notes ) {
			$parts[] = sprintf(
				/* translators: %s: サニタイズ済みの取得失敗理由（複数ある場合は " / " 区切り） */
				__( '失敗理由: %s', 'affilicard' ),
				implode( ' / ', $error_notes )
			);
		}
		if ( array() === $parts ) {
			return '';
		}
		return ' / ' . implode( ' / ', $parts );
	}

	/**
	 * provider 由来の `fetch_error` を二重にサニタイズする（spec §9-3）。
	 *
	 * 1) `wp_strip_all_tags()` でタグを除去する（`<script>` 等が HTML として生存しない）。
	 * 2) 200 文字に切り詰める（肥大化・表示崩れ防止）。
	 *
	 * 最終エスケープ（`esc_attr()`）は呼び出し側（title 属性への出力直前）で行う。
	 */
	private static function sanitizeFetchError( string $raw ): string {
		$stripped = wp_strip_all_tags( $raw );
		return mb_substr( trim( $stripped ), 0, 200 );
	}

	/**
	 * 各 listing の `last_verified_at`（UTC ISO8601）のうち最新（MAX）を `wp_date()` でサイトの
	 * タイムゾーン/ロケールに整形して表示する。1件も無ければ Fallback カラムと同じ em dash。
	 */
	private static function renderLastVerifiedColumn( int $post_id ): void {
		$listings_raw = get_post_meta( $post_id, ProductPostType::META_LISTINGS, true );
		$listings     = is_array( $listings_raw ) ? $listings_raw : ( is_string( $listings_raw ) ? JsonField::decode( $listings_raw, array() ) : array() );

		$max_ts = 0;
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$at = isset( $listing['last_verified_at'] ) ? trim( (string) $listing['last_verified_at'] ) : '';
			if ( '' === $at ) {
				continue;
			}
			$ts = strtotime( $at );
			if ( false !== $ts && $ts > $max_ts ) {
				$max_ts = $ts;
			}
		}

		if ( $max_ts > 0 ) {
			echo esc_html( wp_date( 'Y-m-d H:i', $max_ts ) );
			return;
		}
		echo '<span aria-hidden="true">—</span>';
	}
}
