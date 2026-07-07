<?php
declare(strict_types=1);

namespace Affilicard\PostType;

use Affilicard\Rest\ProductSchema;

/**
 * 商品 CPT の post meta を REST（Gutenberg core-data）に露出させる登録。
 *
 * listings/extras はネイティブ配列メタ（type=array）として保存する。
 * custom-fields support 追加後、useEntityProp の配列メタが REST に露出して保存可能になった。
 * フィールド形状の権威は ProductSchema::sanitize* に置く。
 * 未認証/低権限への露出は auth_callback(edit_posts)＋ProductRestController で防ぐ。
 */
final class ProductMeta {

	public static function register(): void {
		$auth = static function (): bool {
			return current_user_can( 'edit_posts' );
		};

		$object_array_schema = array(
			'schema' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
			),
		);

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
				'type'              => 'array',
				'single'            => true,
				'default'           => array(),
				'show_in_rest'      => $object_array_schema,
				'auth_callback'     => $auth,
				'sanitize_callback' => static function ( $value ) {
					return ProductSchema::sanitizeListings( $value );
				},
			)
		);

		register_post_meta(
			ProductPostType::POST_TYPE,
			ProductPostType::META_EXTRAS,
			array(
				'type'              => 'array',
				'single'            => true,
				'default'           => array(),
				'show_in_rest'      => $object_array_schema,
				'auth_callback'     => $auth,
				'sanitize_callback' => static function ( $value ) {
					return ProductSchema::sanitizeExtras( $value );
				},
			)
		);

		register_post_meta(
			ProductPostType::POST_TYPE,
			ProductPostType::META_RELEASE_DATE,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'auth_callback'     => $auth,
				'sanitize_callback' => static function ( $value ) {
					return ProductSchema::sanitizeReleaseDate( $value );
				},
			)
		);

		register_post_meta(
			ProductPostType::POST_TYPE,
			ProductPostType::META_MASK_BLUR,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'auth_callback'     => $auth,
				'sanitize_callback' => static function ( $value ) {
					return (bool) $value;
				},
			)
		);

		register_post_meta(
			ProductPostType::POST_TYPE,
			ProductPostType::META_MASK_R18,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'auth_callback'     => $auth,
				'sanitize_callback' => static function ( $value ) {
					return (bool) $value;
				},
			)
		);

		register_post_meta(
			ProductPostType::POST_TYPE,
			ProductPostType::META_MASK_LABEL,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'auth_callback'     => $auth,
				'sanitize_callback' => static function ( $value ) {
					return sanitize_text_field( (string) $value );
				},
			)
		);
	}
}
