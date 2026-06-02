<?php
declare(strict_types=1);

namespace Affilicard\Block;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Renderer\CardRenderer;
use Affilicard\Repository\ProductRepository;

/**
 * Gutenberg block `affilicard/product-card` の登録とサーバサイド render。
 *
 * block.json を単一の属性情報源とし、script/style は build/ 出力を handles 経由で
 * 手動 register する（explicit multi-entry build を変更しないため）。
 */
final class Block {

	private const SCRIPT_HANDLE       = 'affilicard-block';
	private const STYLE_HANDLE        = 'affilicard-card';
	private const EDITOR_STYLE_HANDLE = 'affilicard-block-editor';

	public function __construct( private ProductRepository $repository ) {}

	public static function register_hook(): void {
		$instance = new self( new ProductRepository() );
		add_action( 'init', array( $instance, 'register' ) );
	}

	public function register(): void {
		$asset_file = AFFILICARD_PLUGIN_DIR . 'build/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => AFFILICARD_VERSION,
			);

		wp_register_script(
			self::SCRIPT_HANDLE,
			AFFILICARD_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( self::SCRIPT_HANDLE, 'affilicard' );

		wp_register_style(
			self::STYLE_HANDLE,
			AFFILICARD_PLUGIN_URL . 'assets/card.css',
			array(),
			AFFILICARD_VERSION
		);
		wp_register_style(
			self::EDITOR_STYLE_HANDLE,
			AFFILICARD_PLUGIN_URL . 'assets/block-editor.css',
			array( self::STYLE_HANDLE ),
			AFFILICARD_VERSION
		);

		register_block_type(
			AFFILICARD_PLUGIN_DIR . 'src/Block/block.json',
			array(
				'render_callback'       => array( $this, 'render' ),
				'editor_script_handles' => array( self::SCRIPT_HANDLE ),
				'style_handles'         => array( self::STYLE_HANDLE ),
				'editor_style_handles'  => array( self::EDITOR_STYLE_HANDLE ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render( array $attributes ): string {
		$product = $this->resolveProduct( $attributes );
		if ( null === $product ) {
			return '';
		}

		// 公開フロントでは published 商品のみ描画する（Repository は admin/REST 向けに
		// post_status='any' で検索するため、下書き/非公開/ゴミ箱の漏洩をここで防ぐ）。
		$status = isset( $product['status'] ) ? (string) $product['status'] : '';
		if ( 'publish' !== $status ) {
			return '';
		}

		$platforms = PlatformConfig::all();
		$platforms = array_values(
			array_filter(
				$platforms,
				static function ( $platform ): bool {
					return $platform->enabled;
				}
			)
		);

		$hide_platforms = isset( $attributes['hidePlatforms'] ) && is_array( $attributes['hidePlatforms'] )
			? $attributes['hidePlatforms']
			: array();
		$options        = array(
			'hide_platforms' => $hide_platforms,
			'image_url'      => $this->featuredImageUrl( (int) ( $product['id'] ?? 0 ) ),
			'colors'         => array(
				'card_bg'     => isset( $attributes['cardBgColor'] ) ? (string) $attributes['cardBgColor'] : '',
				'card_border' => isset( $attributes['cardBorderColor'] ) ? (string) $attributes['cardBorderColor'] : '',
				'cta_bg'      => isset( $attributes['ctaBgColor'] ) ? (string) $attributes['ctaBgColor'] : '',
				'cta_text'    => isset( $attributes['ctaTextColor'] ) ? (string) $attributes['ctaTextColor'] : '',
			),
		);

		wp_enqueue_style( self::STYLE_HANDLE );

		return ( new CardRenderer() )->render( $product, $platforms, $options );
	}

	/**
	 * @param array<string, mixed> $attributes
	 * @return array<string, mixed>|null
	 */
	private function resolveProduct( array $attributes ): ?array {
		$product_id = isset( $attributes['productId'] ) ? (int) $attributes['productId'] : 0;
		if ( $product_id > 0 ) {
			return $this->repository->find( $product_id );
		}

		$slug = isset( $attributes['slug'] ) ? trim( (string) $attributes['slug'] ) : '';
		if ( '' !== $slug ) {
			return $this->repository->findBySlug( $slug );
		}

		$external_id = isset( $attributes['externalId'] ) ? trim( (string) $attributes['externalId'] ) : '';
		$platform    = isset( $attributes['platform'] ) ? trim( (string) $attributes['platform'] ) : '';
		if ( '' !== $external_id && '' !== $platform ) {
			return $this->repository->findByExternalId( $platform, $external_id );
		}

		return null;
	}

	private function featuredImageUrl( int $postId ): string {
		if ( $postId <= 0 ) {
			return '';
		}
		$thumb_id = (int) get_post_thumbnail_id( $postId );
		if ( $thumb_id <= 0 ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $thumb_id, 'medium' );
		return is_string( $url ) ? $url : '';
	}
}
