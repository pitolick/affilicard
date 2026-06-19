<?php
declare(strict_types=1);

namespace Affilicard\PostType;

/**
 * CPT 編集画面に build/metabox.js（サイドバープラグイン）をエンキューする。
 *
 * データの保存は @wordpress/core-data 経由の REST API で行うため、
 * クラシックメタボックス（add_meta_box / $_POST 保存）は廃止。
 */
final class ProductMetaBox {

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueueAssets' ) );
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
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style(
			'affilicard-admin-metabox',
			AFFILICARD_PLUGIN_URL . 'assets/admin-metabox.css',
			array( 'wp-components' ),
			AFFILICARD_VERSION
		);
	}
}
