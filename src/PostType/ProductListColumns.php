<?php
declare(strict_types=1);

namespace Affilicard\PostType;

use Affilicard\Util\JsonField;

/**
 * CPT 一覧画面に「Fallback」カラムを追加する。
 *
 * Listing の `affiliate_url` が空かつ `regular_url` が非空の場合、警告アイコンを表示する。
 */
final class ProductListColumns {

	public const COLUMN_KEY = 'affilicard_fallback';

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
				$new[ self::COLUMN_KEY ] = __( 'Fallback', 'affilicard' );
			}
		}
		return $new;
	}

	public static function renderColumn( string $column_key, int $post_id ): void {
		if ( self::COLUMN_KEY !== $column_key ) {
			return;
		}

		$raw      = (string) get_post_meta( $post_id, ProductPostType::META_LISTINGS, true );
		$listings = '' === $raw ? array() : JsonField::decode( $raw );

		$has_fallback = false;
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$affiliate = isset( $listing['affiliate_url'] ) ? (string) $listing['affiliate_url'] : '';
			$regular   = isset( $listing['regular_url'] ) ? (string) $listing['regular_url'] : '';
			if ( '' === $affiliate && '' !== $regular ) {
				$has_fallback = true;
				break;
			}
		}

		if ( $has_fallback ) {
			echo '<span class="dashicons dashicons-warning" style="color:#dba617" title="' . esc_attr__( 'アフィリエイト URL 未設定、通常 URL にフォールバック中', 'affilicard' ) . '"></span>';
		} else {
			echo '<span aria-hidden="true">—</span>';
		}
	}
}
