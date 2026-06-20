<?php
declare(strict_types=1);

namespace Affilicard\Renderer;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Plugin;
use Affilicard\Types\ProductTypeRegistry;

/**
 * 商品配列＋ブロック属性から商品カード HTML を組み立てる共有サービス。
 *
 * フロント Block::render（publish ガード付き）と REST プレビュー
 * （status 非依存・認証済み）が同一の描画経路を共有するために抽出。
 * enqueue/echo の副作用は持たず、HTML 文字列のみ返す。
 */
final class CardHtmlBuilder {

	private static ?ProductTypeRegistry $typeRegistry = null;

	/**
	 * @param array<string, mixed> $product    ProductRepository::find() の戻り値形
	 * @param array<string, mixed> $attributes ブロック属性
	 */
	public function build( array $product, array $attributes ): string {
		$platforms = array_values(
			array_filter(
				PlatformConfig::all(),
				static fn( $platform ): bool => $platform->enabled
			)
		);

		$known_codes = array_map(
			static fn( $platform ): string => (string) $platform->code,
			$platforms
		);

		$hide_platforms = isset( $attributes['hidePlatforms'] ) && is_array( $attributes['hidePlatforms'] )
			? $attributes['hidePlatforms']
			: array();

		$cta_overrides = $this->sanitizeCtaOverrides(
			isset( $attributes['ctaLabelOverrides'] ) && is_array( $attributes['ctaLabelOverrides'] )
				? $attributes['ctaLabelOverrides']
				: array(),
			$known_codes
		);

		$type        = self::typeRegistry()->get( isset( $product['product_type'] ) ? (string) $product['product_type'] : '' );
		$header_keys = null !== $type ? $type->cardHeaderKeys() : array( 'author', 'publisher' );
		$hidden_keys = null !== $type ? $type->cardHiddenKeys() : array();
		$media_label = null !== $type ? $type->cardMediaLabel() : (string) __( '商品画像', 'affilicard' );

		$options = array(
			'hide_platforms'      => $hide_platforms,
			'image_url'           => $this->featuredImageUrl( (int) ( $product['id'] ?? 0 ) ),
			'colors'              => array(
				'card_bg'     => isset( $attributes['cardBgColor'] ) ? (string) $attributes['cardBgColor'] : '',
				'card_border' => isset( $attributes['cardBorderColor'] ) ? (string) $attributes['cardBorderColor'] : '',
				'cta_bg'      => isset( $attributes['ctaBgColor'] ) ? (string) $attributes['ctaBgColor'] : '',
				'cta_text'    => isset( $attributes['ctaTextColor'] ) ? (string) $attributes['ctaTextColor'] : '',
			),
			'header_keys'         => $header_keys,
			'hidden_keys'         => $hidden_keys,
			'media_label'         => $media_label,
			'cta_label_overrides' => $cta_overrides,
		);

		return ( new CardRenderer() )->render( $product, $platforms, $options );
	}

	/**
	 * @param array<string, mixed> $raw         code => label（任意の混入を含む）
	 * @param list<string>         $known_codes 既知 platform code
	 * @return array<string, string>
	 */
	public function sanitizeCtaOverrides( array $raw, array $known_codes ): array {
		$clean = array();
		foreach ( $raw as $code => $label ) {
			$code = (string) $code;
			if ( ! in_array( $code, $known_codes, true ) ) {
				continue;
			}
			$value = sanitize_text_field( is_string( $label ) ? $label : '' );
			if ( '' === $value ) {
				continue;
			}
			$clean[ $code ] = $value;
		}
		return $clean;
	}

	private static function typeRegistry(): ProductTypeRegistry {
		if ( null === self::$typeRegistry ) {
			self::$typeRegistry = Plugin::buildProductTypeRegistry();
		}
		return self::$typeRegistry;
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
