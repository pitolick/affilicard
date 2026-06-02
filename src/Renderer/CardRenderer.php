<?php
declare(strict_types=1);

namespace Affilicard\Renderer;

use Affilicard\Platform\PlatformDefinition;
use Affilicard\Stock\StockStatus;

/**
 * 商品データ + platform 定義から商品カードの HTML 文字列を生成する純粋なレンダラ。
 *
 * 副作用を持たず（DB/option を読まない）、入力はすべて引数で受け取る。
 * WordPress の escape 関数のみに依存する。
 */
final class CardRenderer {

	/**
	 * @param array<string, mixed>     $product   ProductRepository::find() の戻り値形
	 * @param list<PlatformDefinition> $platforms enabled な platform（displayOrder 昇順想定）
	 * @param array<string, mixed>     $options   hide_platforms / image_url / colors
	 */
	public function render( array $product, array $platforms, array $options = array() ): string {
		$by_code = array();
		foreach ( $platforms as $platform ) {
			if ( $platform instanceof PlatformDefinition ) {
				$by_code[ $platform->code ] = $platform;
			}
		}

		$hide      = isset( $options['hide_platforms'] ) && is_array( $options['hide_platforms'] ) ? array_map( 'strval', $options['hide_platforms'] ) : array();
		$image_url = isset( $options['image_url'] ) ? (string) $options['image_url'] : '';
		$colors    = isset( $options['colors'] ) && is_array( $options['colors'] ) ? $options['colors'] : array();

		$stock        = StockStatus::normalize( isset( $product['stock_status'] ) ? (string) $product['stock_status'] : null );
		$is_available = StockStatus::AVAILABLE === $stock;

		$style = $this->rootStyle( $colors );
		$html  = '<div class="affilicard-card"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>';

		if ( '' !== $image_url ) {
			$html .= '<div class="affilicard-card__media"><img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( (string) ( $product['title'] ?? '' ) ) . '" loading="lazy" /></div>';
		}

		$html .= '<div class="affilicard-card__body">';
		$html .= '<h3 class="affilicard-card__title">' . esc_html( (string) ( $product['title'] ?? '' ) ) . '</h3>';

		if ( ! $is_available ) {
			$html .= '<span class="affilicard-card__badge affilicard-card__badge--' . esc_attr( $stock ) . '">' . esc_html( StockStatus::label( $stock ) ) . '</span>';
		}

		$content = (string) ( $product['content'] ?? '' );
		if ( '' !== $content ) {
			$html .= '<div class="affilicard-card__desc">' . wp_kses_post( $content ) . '</div>';
		}

		$html .= $this->renderExtras( isset( $product['extras'] ) && is_array( $product['extras'] ) ? $product['extras'] : array() );

		if ( $is_available ) {
			$html .= $this->renderListings(
				isset( $product['listings'] ) && is_array( $product['listings'] ) ? $product['listings'] : array(),
				$by_code,
				$hide
			);
		}

		$html .= '</div></div>';
		return $html;
	}

	/**
	 * @param array<string, mixed> $colors
	 */
	private function rootStyle( array $colors ): string {
		$map   = array(
			'card_bg'     => '--affilicard-card-bg',
			'card_border' => '--affilicard-card-border',
			'cta_bg'      => '--affilicard-cta-bg',
			'cta_text'    => '--affilicard-cta-text',
		);
		$parts = array();
		foreach ( $map as $key => $var ) {
			$raw = isset( $colors[ $key ] ) ? trim( (string) $colors[ $key ] ) : '';
			if ( '' === $raw ) {
				continue;
			}
			$value = (string) sanitize_hex_color( $raw );
			if ( '' === $value ) {
				continue;
			}
			$parts[] = $var . ':' . $value;
		}
		return array() === $parts ? '' : implode( ';', $parts ) . ';';
	}

	/**
	 * @param list<array<string, mixed>> $extras
	 */
	private function renderExtras( array $extras ): string {
		$rows = '';
		foreach ( $extras as $extra ) {
			if ( ! is_array( $extra ) ) {
				continue;
			}
			$label = isset( $extra['label'] ) ? trim( (string) $extra['label'] ) : '';
			$value = isset( $extra['value'] ) ? trim( (string) $extra['value'] ) : '';
			if ( '' === $label || '' === $value ) {
				continue;
			}
			$rows .= '<div class="affilicard-card__extra"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		return '' === $rows ? '' : '<dl class="affilicard-card__extras">' . $rows . '</dl>';
	}

	/**
	 * @param list<array<string, mixed>>        $listings
	 * @param array<string, PlatformDefinition> $by_code
	 * @param list<string>                      $hide
	 */
	private function renderListings( array $listings, array $by_code, array $hide ): string {
		$items = '';
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$code = isset( $listing['platform'] ) ? (string) $listing['platform'] : '';
			if ( '' === $code || ! isset( $by_code[ $code ] ) || in_array( $code, $hide, true ) ) {
				continue;
			}
			$platform = $by_code[ $code ];
			if ( ! $platform->enabled ) {
				continue;
			}
			if ( isset( $listing['enabled'] ) && false === (bool) $listing['enabled'] ) {
				continue;
			}

			$affiliate = isset( $listing['affiliate_url'] ) ? trim( (string) $listing['affiliate_url'] ) : '';
			$regular   = isset( $listing['regular_url'] ) ? trim( (string) $listing['regular_url'] ) : '';
			$url       = '' !== $affiliate ? $affiliate : $regular;
			if ( '' === $url ) {
				continue;
			}

			$override = isset( $listing['button_label_override'] ) ? trim( (string) $listing['button_label_override'] ) : '';
			$label    = '' !== $override ? $override : $platform->buttonLabel;

			$brand = (string) sanitize_hex_color( $platform->brandColor );
			if ( '' === $brand ) {
				$brand = '#444444';
			}
			$text = (string) sanitize_hex_color( $platform->buttonTextColor );
			if ( '' === $text ) {
				$text = '#ffffff';
			}
			$btn_style = 'background:var(--affilicard-cta-bg,' . $brand . ');color:var(--affilicard-cta-text,' . $text . ');';

			$price_html = '';
			$price      = isset( $listing['price'] ) ? trim( (string) $listing['price'] ) : '';
			if ( '' !== $price ) {
				$price_html = '<span class="affilicard-card__price">' . esc_html( $price ) . '</span>';
			}

			$items .= '<li class="affilicard-card__listing">'
				. '<a class="affilicard-card__cta" href="' . esc_url( $url ) . '" target="_blank" rel="nofollow sponsored noopener" style="' . esc_attr( $btn_style ) . '">'
				. esc_html( $label ) . $price_html
				. '</a></li>';
		}
		return '' === $items ? '' : '<ul class="affilicard-card__listings">' . $items . '</ul>';
	}
}
