<?php
declare(strict_types=1);

namespace Affilicard\PostType;

/**
 * CPT `affilicard_product` の最小限の登録。
 *
 * Phase 4a-1 で meta key 定数 / capability 詳細を拡張する。
 */
final class ProductPostType {

	public const POST_TYPE = 'affilicard_product';

	public const META_PRODUCT_TYPE   = 'affilicard_product_type';
	public const META_STOCK_STATUS   = 'affilicard_stock_status';
	public const META_EXTRAS         = 'affilicard_extras';
	public const META_LISTINGS       = 'affilicard_listings';
	public const META_SCHEMA_VERSION = 'affilicard_schema_version';
	public const META_EXTID_PREFIX   = 'affilicard_extid_';

	/**
	 * プラットフォーム別の外部 ID を保存する meta キーを生成する。
	 *
	 * 例: externalIdMetaKey('dmm-books') => 'affilicard_extid_dmm-books'
	 */
	public static function externalIdMetaKey( string $platform_code ): string {
		return self::META_EXTID_PREFIX . $platform_code;
	}

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( '商品カード', 'affilicard' ),
					'singular_name' => __( '商品カード', 'affilicard' ),
					'menu_name'     => __( 'Affilicard', 'affilicard' ),
					'add_new'       => __( '新規追加', 'affilicard' ),
					'add_new_item'  => __( '商品カードを新規追加', 'affilicard' ),
					'edit_item'     => __( '商品カードを編集', 'affilicard' ),
					'all_items'     => __( '商品カード一覧', 'affilicard' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'show_in_rest'    => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'thumbnail', 'author' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'menu_icon'       => 'dashicons-products',
			)
		);
	}
}
