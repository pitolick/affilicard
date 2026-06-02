<?php
declare(strict_types=1);

namespace Affilicard\PostType;

use Affilicard\Repository\ProductRepository;
use Affilicard\Rest\ProductSchema;
use Affilicard\Util\JsonField;

/**
 * CPT 編集画面に Affilicard 商品設定のメタボックスを追加し、build/metabox.js をエンキューする。
 *
 * React metabox のデータは hidden textarea (`affilicard_data`) を通じて WP の投稿フォームと
 * 同期する。`save_post_affilicard_product` ハンドラが nonce を検証してメタを保存するため、
 * 「公開」「更新」ボタンを押すだけで商品設定も保存される。
 */
final class ProductMetaBox {

	public const NONCE_ACTION = 'affilicard_metabox';
	public const NONCE_NAME   = 'affilicard_metabox_nonce';
	public const META_BOX_ID  = 'affilicard_product_metabox';
	public const DATA_FIELD   = 'affilicard_data';

	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'addMetaBox' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueueAssets' ) );
		add_action( 'save_post_' . ProductPostType::POST_TYPE, array( self::class, 'handleSave' ), 10, 1 );
	}

	/**
	 * 投稿保存（Publish / Update）時にメタフィールドを保存する。
	 *
	 * React metabox が hidden textarea にシリアライズした JSON を読み取り、
	 * nonce + capability を検証した上で ProductRepository::saveMeta() に委譲する。
	 */
	public static function handleSave( int $post_id ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if (
			! isset( $_POST[ self::NONCE_NAME ] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_NAME ] ) ),
				self::NONCE_ACTION
			)
		) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::DATA_FIELD ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 値は JSON。フィールドごとのサニタイズは ProductSchema::sanitize* に委譲する。
		$raw     = wp_unslash( (string) $_POST[ self::DATA_FIELD ] );
		$decoded = JsonField::decode( $raw, array() );

		if ( ! is_array( $decoded ) ) {
			return;
		}

		$clean = array(
			'product_type' => sanitize_key( (string) ( $decoded['product_type'] ?? '' ) ),
			'stock_status' => (string) ( $decoded['stock_status'] ?? '' ),
			'extras'       => ProductSchema::sanitizeExtras( $decoded['extras'] ?? array() ),
			'listings'     => ProductSchema::sanitizeListings( $decoded['listings'] ?? array() ),
		);

		( new ProductRepository() )->saveMeta( $post_id, $clean );
	}

	public static function addMetaBox(): void {
		add_meta_box(
			self::META_BOX_ID,
			__( 'Affilicard 商品設定', 'affilicard' ),
			array( self::class, 'renderMetaBox' ),
			ProductPostType::POST_TYPE,
			'normal',
			'high'
		);
	}

	public static function renderMetaBox( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		echo '<div id="affilicard-metabox-root" data-post-id="' . esc_attr( (string) $post->ID ) . '"></div>';
	}

	public static function enqueueAssets( string $hook ): void {
		global $post_type, $typenow;

		$current_post_type = '';
		if ( isset( $typenow ) && '' !== (string) $typenow ) {
			$current_post_type = (string) $typenow;
		} elseif ( isset( $post_type ) && '' !== (string) $post_type ) {
			$current_post_type = (string) $post_type;
		}

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		if ( ProductPostType::POST_TYPE !== $current_post_type ) {
			return;
		}

		$asset_file = AFFILICARD_PLUGIN_DIR . 'build/metabox.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => AFFILICARD_VERSION,
			);

		wp_enqueue_script(
			'affilicard-metabox',
			AFFILICARD_PLUGIN_URL . 'build/metabox.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( 'affilicard-metabox', 'affilicard' );
	}
}
