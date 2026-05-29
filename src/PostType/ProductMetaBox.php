<?php
declare(strict_types=1);

namespace Affilicard\PostType;

/**
 * CPT 編集画面に Affilicard 商品設定のメタボックスを追加し、build/metabox.js をエンキューする。
 */
final class ProductMetaBox {

	public const NONCE_ACTION = 'affilicard_metabox';
	public const NONCE_NAME   = 'affilicard_metabox_nonce';
	public const META_BOX_ID  = 'affilicard_product_metabox';

	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'addMetaBox' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueueAssets' ) );
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
