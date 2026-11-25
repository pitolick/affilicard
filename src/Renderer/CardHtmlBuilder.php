<?php
declare(strict_types=1);

namespace Affilicard\Renderer;

use Affilicard\Content\AdultDetection;
use Affilicard\Platform\PlatformConfig;
use Affilicard\Plugin;
use Affilicard\Provider\DisplayPolicy;
use Affilicard\Provider\DisplayPolicyProvider;
use Affilicard\Provider\ProviderRegistry;
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

	private static ?ProviderRegistry $providerRegistry = null;

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

		$only_platforms = $this->sanitizeOnlyPlatforms(
			isset( $attributes['onlyPlatforms'] ) && is_array( $attributes['onlyPlatforms'] )
				? $attributes['onlyPlatforms']
				: array(),
			$known_codes
		);

		$cta_overrides = $this->sanitizeCtaOverrides(
			isset( $attributes['ctaLabelOverrides'] ) && is_array( $attributes['ctaLabelOverrides'] )
				? $attributes['ctaLabelOverrides']
				: array(),
			$known_codes
		);

		$mask = $this->resolveMask( $attributes, $product );

		$type         = self::typeRegistry()->get( isset( $product['product_type'] ) ? (string) $product['product_type'] : '' );
		$header_keys  = null !== $type ? $type->cardHeaderKeys() : array( 'author', 'publisher' );
		$hidden_keys  = null !== $type ? $type->cardHiddenKeys() : array();
		$media_label  = null !== $type ? $type->cardMediaLabel() : (string) __( '商品画像', 'affilicard' );
		$media_aspect = null !== $type ? $type->cardMediaAspectRatio() : '1 / 1';

		$release_date = isset( $product['release_date'] ) ? (string) $product['release_date'] : '';
		$today        = (string) current_time( 'Y-m-d' );
		$is_preorder  = \Affilicard\Stock\ReleaseDate::isPreorder( $release_date, $today );

		$options = array(
			'hide_platforms'       => $hide_platforms,
			'only_platforms'       => $only_platforms,
			'image_url'            => $this->featuredImageUrl( (int) ( $product['id'] ?? 0 ) ),
			'colors'               => array(
				'card_bg'     => isset( $attributes['cardBgColor'] ) ? (string) $attributes['cardBgColor'] : '',
				'card_border' => isset( $attributes['cardBorderColor'] ) ? (string) $attributes['cardBorderColor'] : '',
				'cta_bg'      => isset( $attributes['ctaBgColor'] ) ? (string) $attributes['ctaBgColor'] : '',
				'cta_text'    => isset( $attributes['ctaTextColor'] ) ? (string) $attributes['ctaTextColor'] : '',
			),
			'header_keys'          => $header_keys,
			'hidden_keys'          => $hidden_keys,
			'media_label'          => $media_label,
			'media_aspect_ratio'   => $media_aspect,
			'cta_label_overrides'  => $cta_overrides,
			'is_preorder'          => $is_preorder,
			'release_date_label'   => $is_preorder ? \Affilicard\Stock\ReleaseDate::label( $release_date ) : '',
			'mask_blur'            => $mask['blur'],
			'mask_r18'             => $mask['r18'],
			'mask_label'           => $mask['label'],
			// ブロック属性で明示されていればそれを、無ければグローバル設定を使う（マスクと同じ優先順）。
			'hide_media'           => array_key_exists( 'hideMedia', $attributes ) && null !== $attributes['hideMedia']
				? (bool) $attributes['hideMedia']
				: \Affilicard\Settings\GeneralSettings::hidesProductImages(),
			// CardRenderer は option を読まない（純粋レンダラの制約）ため、ここで解決して渡す。
			'fallback_on_terminal' => \Affilicard\Settings\GeneralSettings::fallbackOnTerminal(),
			'display_policies'     => self::displayPolicies( $platforms ),
		);

		return ( new CardRenderer() )->render( $product, $platforms, $options );
	}

	/**
	 * @param array<int|string, mixed> $raw         任意混入を含む platform code 配列
	 * @param list<string>             $known_codes 既知 platform code
	 * @return list<string>
	 */
	public function sanitizeOnlyPlatforms( array $raw, array $known_codes ): array {
		$clean = array();
		foreach ( $raw as $code ) {
			if ( ! is_string( $code ) ) {
				continue;
			}
			if ( in_array( $code, $known_codes, true ) && ! in_array( $code, $clean, true ) ) {
				$clean[] = $code;
			}
		}
		return $clean;
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

	/**
	 * マスク設定をブロック属性優先・商品 meta 継承で解決する（属性ごと独立）。
	 * boolean はブロック属性が存在し null でなければ採用、maskLabel は
	 * 未定義＝継承／定義済み（空文字含む）＝上書き。
	 *
	 * R18 は「手動の設定（ブロック → 商品）」に「ストアの成人向け判定」を足す。判定に従うかは
	 * ブロックの storeR18（未指定ならサイト設定）で決める。
	 *
	 * @param array<string, mixed> $attributes ブロック属性
	 * @param array<string, mixed> $product    Repository::find() の戻り値形
	 * @return array{blur: bool, r18: bool, label: string}
	 */
	public function resolveMask( array $attributes, array $product ): array {
		$blur = array_key_exists( 'maskBlur', $attributes ) && null !== $attributes['maskBlur']
			? (bool) $attributes['maskBlur']
			: (bool) ( $product['mask_blur'] ?? false );
		$r18  = array_key_exists( 'maskR18', $attributes ) && null !== $attributes['maskR18']
			? (bool) $attributes['maskR18']
			: (bool) ( $product['mask_r18'] ?? false );
		// ストアの成人向け判定。手動の R18 とは別に足し合わせる（手動の OFF では外れない）。
		// 外すのはブロックの storeR18=false か、サイト設定（GeneralSettings::followsStoreAdultFlag）。
		if ( ! $r18 && array() !== AdultDetection::detectedBy( $product ) ) {
			$r18 = array_key_exists( 'storeR18', $attributes ) && null !== $attributes['storeR18']
				? (bool) $attributes['storeR18']
				: \Affilicard\Settings\GeneralSettings::followsStoreAdultFlag();
		}
		$label = array_key_exists( 'maskLabel', $attributes ) && null !== $attributes['maskLabel']
			? (string) $attributes['maskLabel']
			: (string) ( $product['mask_label'] ?? '' );
		return array(
			'blur'  => $blur,
			'r18'   => $r18,
			'label' => $label,
		);
	}

	/**
	 * platform code => そのストアの Provider が申告した表示の決まり（設計 2026-11-24 §5）。
	 *
	 * platform の現在の Provider で決め、それが申告しなければ自動取得の候補（eligibleProvider）で決める。
	 * 自動取得から手動入力へ戻した platform にも、API で取った書影が残っているため
	 * （Amazon の書影は API 経由でしか使えず、表示期限はそのまま適用する必要がある）。
	 *
	 * @param list<\Affilicard\Platform\PlatformDefinition> $platforms
	 * @return array<string, DisplayPolicy>
	 */
	private static function displayPolicies( array $platforms ): array {
		if ( null === self::$providerRegistry ) {
			self::$providerRegistry = Plugin::buildProviderRegistry();
		}
		$policies = array();
		foreach ( $platforms as $platform ) {
			foreach ( array( $platform->provider, $platform->eligibleProvider ) as $code ) {
				$provider = '' !== $code ? self::$providerRegistry->get( $code ) : null;
				if ( $provider instanceof DisplayPolicyProvider ) {
					$policies[ $platform->code ] = $provider->displayPolicy();
					break;
				}
			}
		}
		return $policies;
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
