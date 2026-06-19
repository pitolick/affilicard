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
				'labels'                => array(
					'name'          => __( '商品カード', 'affilicard' ),
					'singular_name' => __( '商品カード', 'affilicard' ),
					'menu_name'     => __( 'Affilicard', 'affilicard' ),
					'add_new'       => __( '新規追加', 'affilicard' ),
					'add_new_item'  => __( '商品カードを新規追加', 'affilicard' ),
					'edit_item'     => __( '商品カードを編集', 'affilicard' ),
					'all_items'     => __( '商品カード一覧', 'affilicard' ),
				),
				'public'                => false,
				'show_ui'               => true,
				'show_in_menu'          => true,
				// Gutenberg 有効化に show_in_rest=true が必須。ただしコア WP_REST_Posts_Controller は
				// publish 投稿を未認証でも read 可能にするため（check_read_permission が publish で無条件 true）、
				// 未認証の商品カタログ露出を防ぐ目的で rest_controller_class に ProductRestController を指定し
				// read 系 permission を edit_posts 必須に上書きする。機密 meta（listings/extras/stock）は
				// register_post_meta 未使用で元から REST 非露出。未認証 read 拒否は rest-read-hardening.spec.js で固定。
				'show_in_rest'          => true,
				'rest_controller_class' => \Affilicard\Rest\ProductRestController::class,
				'capability_type'       => 'post',
				'map_meta_cap'          => true,
				// 'custom-fields' は register_post_meta(show_in_rest) を REST 応答の
				// `meta` フィールドとして露出させるために必須。これが無いと Gutenberg の
				// useEntityProp が meta を保存/読込できない（生の Custom Fields パネルは
				// エディタ設定でオプトイン時のみ表示され、既定では非表示）。
				'supports'              => array( 'title', 'editor', 'thumbnail', 'author', 'custom-fields' ),
				'has_archive'           => false,
				'rewrite'               => false,
				'menu_icon'             => 'dashicons-products',
			)
		);
	}
}
