<?php
declare(strict_types=1);

namespace Affilicard\PostType;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Pricing\PriceFreshness;
use Affilicard\Util\JsonField;

/**
 * CPT 一覧画面に「Fallback」カラムを追加する。
 *
 * Listing の `affiliate_url` が空かつ `regular_url` が非空の場合、警告アイコンを表示する。
 * また、`price` を保持しているが `PriceFreshness::isPriceDisplayable()` が false（未確認/期限切れ）の
 * 場合も、カード上で価格が非表示になる旨の警告アイコンを表示する。
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
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$affiliate = isset( $listing['affiliate_url'] ) ? (string) $listing['affiliate_url'] : '';
			$regular   = isset( $listing['regular_url'] ) ? (string) $listing['regular_url'] : '';
			if ( '' === $affiliate && '' !== $regular ) {
				$has_fallback = true;
			}

			$price = isset( $listing['price'] ) ? trim( (string) $listing['price'] ) : '';
			if ( '' !== $price ) {
				$platform = PlatformConfig::find( (string) ( $listing['platform'] ?? '' ) );
				if ( ! PriceFreshness::isPriceDisplayable( $listing, $platform, $now_ts ) ) {
					$has_hidden_price = true;
				}
			}
		}

		if ( $has_hidden_price ) {
			echo '<span class="dashicons dashicons-warning" style="color:#d63638" title="' . esc_attr__( '価格が未確認/期限切れのためカードで非表示です', 'affilicard' ) . '"></span> ';
		}
		if ( $has_fallback ) {
			echo '<span class="dashicons dashicons-warning" style="color:#dba617" title="' . esc_attr__( 'アフィリエイト URL 未設定、通常 URL にフォールバック中', 'affilicard' ) . '"></span>';
		}
		if ( ! $has_hidden_price && ! $has_fallback ) {
			echo '<span aria-hidden="true">—</span>';
		}
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
