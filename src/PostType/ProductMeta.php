<?php
declare(strict_types=1);

namespace Affilicard\PostType;

use Affilicard\Rest\ProductSchema;
use Affilicard\Util\JsonField;

/**
 * 商品 CPT の post meta を REST（Gutenberg core-data）に露出させる登録。
 *
 * listings/extras は JSON 文字列として保存する（type=string）。
 * useEntityProp のオブジェクト配列メタが保存時に空配列化される既知制限（#55283）を回避するため、
 * JS 側で JSON.stringify/parse し、PHP 側で JsonField で decode/encode する。
 * フィールド形状の権威は ProductSchema::sanitize* に置く。
 * 未認証/低権限への露出は auth_callback(edit_posts)＋ProductRestController で防ぐ。
 */
final class ProductMeta {

	public static function register(): void {
		$auth = static function (): bool {
			return current_user_can( 'edit_posts' );
		};

		register_post_meta(
			ProductPostType::POST_TYPE,
			ProductPostType::META_PRODUCT_TYPE,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => 'generic',
				'show_in_rest'      => true,
				'auth_callback'     => $auth,
				'sanitize_callback' => static function ( $value ) {
					return sanitize_key( (string) $value );
				},
			)
		);

		register_post_meta(
			ProductPostType::POST_TYPE,
			ProductPostType::META_STOCK_STATUS,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => 'available',
				'show_in_rest'      => true,
				'auth_callback'     => $auth,
				'sanitize_callback' => static function ( $value ) {
					return sanitize_text_field( (string) $value );
				},
			)
		);

		register_post_meta(
			ProductPostType::POST_TYPE,
			ProductPostType::META_LISTINGS,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'auth_callback'     => $auth,
				'sanitize_callback' => static function ( $value ) {
					return JsonField::encode( ProductSchema::sanitizeListings( JsonField::decode( (string) $value, array() ) ) );
				},
			)
		);

		register_post_meta(
			ProductPostType::POST_TYPE,
			ProductPostType::META_EXTRAS,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'auth_callback'     => $auth,
				'sanitize_callback' => static function ( $value ) {
					return JsonField::encode( ProductSchema::sanitizeExtras( JsonField::decode( (string) $value, array() ) ) );
				},
			)
		);
	}
}
