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

		$hide          = isset( $options['hide_platforms'] ) && is_array( $options['hide_platforms'] ) ? array_map( 'strval', $options['hide_platforms'] ) : array();
		$image_url     = isset( $options['image_url'] ) ? (string) $options['image_url'] : '';
		$colors        = isset( $options['colors'] ) && is_array( $options['colors'] ) ? $options['colors'] : array();
		$header_keys   = isset( $options['header_keys'] ) && is_array( $options['header_keys'] ) ? array_map( 'strval', $options['header_keys'] ) : array( 'author', 'publisher' );
		$hidden_keys   = isset( $options['hidden_keys'] ) && is_array( $options['hidden_keys'] ) ? array_map( 'strval', $options['hidden_keys'] ) : array();
		$media_label   = isset( $options['media_label'] ) ? (string) $options['media_label'] : (string) __( '商品画像', 'affilicard' );
		$cta_overrides = isset( $options['cta_label_overrides'] ) && is_array( $options['cta_label_overrides'] )
			? $options['cta_label_overrides']
			: array();

		$stock        = StockStatus::normalize( isset( $product['stock_status'] ) ? (string) $product['stock_status'] : null );
		$is_available = StockStatus::AVAILABLE === $stock;

		$extras = isset( $product['extras'] ) && is_array( $product['extras'] ) ? $product['extras'] : array();

		$style = $this->rootStyle( $colors );
		$html  = '<div class="affilicard-card"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>';

		$html .= '<div class="affilicard-card__inner">';

		// 書影カラム（画像が無ければプレースホルダ）。
		$html .= '<div class="affilicard-card__media">';
		if ( '' !== $image_url ) {
			$html .= '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( (string) ( $product['title'] ?? '' ) ) . '" loading="lazy" />';
		} else {
			$html .= '<div class="affilicard-card__media-placeholder">' . esc_html( $media_label ) . '</div>';
		}
		$html .= '</div>';

		// 本文カラム。
		$html .= '<div class="affilicard-card__body">';
		$html .= $this->renderMetaHeader( $extras, $header_keys );
		$html .= '<h3 class="affilicard-card__title">' . esc_html( (string) ( $product['title'] ?? '' ) ) . '</h3>';

		if ( ! $is_available ) {
			$html .= '<span class="affilicard-card__badge affilicard-card__badge--' . esc_attr( $stock ) . '">' . esc_html( StockStatus::label( $stock ) ) . '</span>';
		}

		$content = (string) ( $product['content'] ?? '' );
		if ( '' !== $content ) {
			$html .= '<div class="affilicard-card__desc">' . wp_kses_post( $content ) . '</div>';
		}

		$html .= $this->renderExtras( $extras, $header_keys, $hidden_keys );

		if ( $is_available ) {
			$html .= $this->renderListings(
				isset( $product['listings'] ) && is_array( $product['listings'] ) ? $product['listings'] : array(),
				$by_code,
				$hide,
				$cta_overrides
			);
		}

		$html .= '</div>'; // __body
		$html .= '</div>'; // __inner

		if ( $is_available ) {
			$html .= $this->renderTimestamp(
				isset( $product['listings'] ) && is_array( $product['listings'] ) ? $product['listings'] : array()
			);
		}

		$html .= '</div>'; // __card
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
	 * @param list<string>               $header_keys
	 * @param list<string>               $hidden_keys
	 */
	private function renderExtras( array $extras, array $header_keys, array $hidden_keys ): string {
		$excluded = array_merge( $header_keys, $hidden_keys );
		$rows     = '';
		foreach ( $extras as $extra ) {
			if ( ! is_array( $extra ) ) {
				continue;
			}
			$key = isset( $extra['key'] ) ? (string) $extra['key'] : '';
			if ( '' !== $key && in_array( $key, $excluded, true ) ) {
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
	 * header_keys に昇格した extras を書誌ヘッダ行にまとめる。
	 * author キーのみ「著」を付す（書誌表記）。
	 *
	 * @param list<array<string, mixed>> $extras
	 * @param list<string>               $header_keys
	 */
	private function renderMetaHeader( array $extras, array $header_keys ): string {
		$parts = array();
		foreach ( $header_keys as $key ) {
			$value = $this->extraValueByKey( $extras, $key );
			if ( '' === $value ) {
				continue;
			}
			// 著者キーのみ「著」を付す（書誌表記）。
			$parts[] = 'author' === $key ? esc_html( $value ) . esc_html__( ' 著', 'affilicard' ) : esc_html( $value );
		}
		if ( array() === $parts ) {
			return '';
		}
		return '<div class="affilicard-card__meta">' . implode( ' ／ ', $parts ) . '</div>';
	}

	/**
	 * @param list<array<string, mixed>> $extras
	 */
	private function extraValueByKey( array $extras, string $key ): string {
		// 同じ key が複数あれば最初のマッチを返す。
		foreach ( $extras as $extra ) {
			if ( ! is_array( $extra ) ) {
				continue;
			}
			$k = isset( $extra['key'] ) ? (string) $extra['key'] : '';
			if ( $k === $key ) {
				return isset( $extra['value'] ) ? trim( (string) $extra['value'] ) : '';
			}
		}
		return '';
	}

	/**
	 * listing 群の最新 last_fetched_at（ISO8601）から「※ YYYY年M月D日時点の価格です。…」を生成する。
	 *
	 * @param list<array<string, mixed>> $listings
	 */
	private function renderTimestamp( array $listings ): string {
		$latest = '';
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$at = isset( $listing['last_fetched_at'] ) ? trim( (string) $listing['last_fetched_at'] ) : '';
			// last_fetched_at は Cron が current_time('c') で書き込む同一 TZ 値が前提。
			// 日付プレフィックスを持つ値のみ比較対象とし、不正文字列が最新として選ばれるのを防ぐ。
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}/', $at ) && $at > $latest ) {
				$latest = $at;
			}
		}
		if ( '' === $latest || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $latest, $m ) ) {
			return '';
		}
		$date = sprintf(
			/* translators: 1: year, 2: month, 3: day */
			(string) __( '%1$d年%2$d月%3$d日', 'affilicard' ),
			(int) $m[1],
			(int) $m[2],
			(int) $m[3]
		);
		$note = sprintf(
			/* translators: %s: formatted date */
			(string) __( '※ %s時点の価格です。最新価格は各販売サイトでご確認ください。', 'affilicard' ),
			$date
		);
		return '<div class="affilicard-card__timestamp">' . esc_html( $note ) . '</div>';
	}

	/**
	 * @param list<array<string, mixed>>        $listings
	 * @param array<string, PlatformDefinition> $by_code
	 * @param list<string>                      $hide
	 * @param array<string, string>             $cta_overrides ブロック属性由来の CTA ラベル上書き（code→label）
	 */
	private function renderListings( array $listings, array $by_code, array $hide, array $cta_overrides = array() ): string {
		$rows = '';
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

			$block_override = isset( $cta_overrides[ $code ] ) ? trim( (string) $cta_overrides[ $code ] ) : '';
			$override       = isset( $listing['button_label_override'] ) ? trim( (string) $listing['button_label_override'] ) : '';
			if ( '' !== $block_override ) {
				$label = $block_override;
			} elseif ( '' !== $override ) {
				$label = $override;
			} else {
				$label = $platform->buttonLabel;
			}

			// CTA はプラットフォーム別ブランド色を維持（block 注入の --affilicard-cta-* で上書き可能）。
			$brand = (string) sanitize_hex_color( $platform->brandColor );
			if ( '' === $brand ) {
				$brand = '#444444';
			}
			$text = (string) sanitize_hex_color( $platform->buttonTextColor );
			if ( '' === $text ) {
				$text = '#ffffff';
			}
			$btn_style = 'background:var(--affilicard-cta-bg,' . $brand . ');color:var(--affilicard-cta-text,' . $text . ');';

			// 価格エリア（通常価格(取り消し線) + ¥価格 + （税込） + 割引バッジ）。
			$pricing  = '';
			$price    = isset( $listing['price'] ) ? trim( (string) $listing['price'] ) : '';
			$list_raw = isset( $listing['list_price'] ) ? trim( (string) $listing['list_price'] ) : '';

			// 通常価格(取り消し線): list_price と price が共に正の数値で list_price > price のときのみ。
			$list_num  = self::priceToNumber( $list_raw );
			$price_num = self::priceToNumber( $price );
			if ( null !== $list_num && null !== $price_num && $list_num > $price_num ) {
				$list_no_yen = (string) preg_replace( '/^[\x{00A5}\x{FFE5}\s]+/u', '', $list_raw );
				$pricing    .= '<span class="affilicard-card__list-price">¥' . esc_html( $list_no_yen ) . '</span>';
			}

			if ( '' !== $price ) {
				// 先頭の半角¥(U+00A5)/全角￥(U+FFE5)/空白のみを安全に除去（ltrim のバイト単位破壊を回避）。
				$price_no_yen = (string) preg_replace( '/^[\x{00A5}\x{FFE5}\s]+/u', '', $price );
				$pricing     .= '<span class="affilicard-card__price">¥' . esc_html( $price_no_yen ) . '</span>';
				$pricing     .= '<span class="affilicard-card__tax">' . esc_html__( '（税込）', 'affilicard' ) . '</span>';
			}
			$badge = isset( $listing['badge'] ) ? trim( (string) $listing['badge'] ) : '';
			if ( '' !== $badge ) {
				$pricing .= '<span class="affilicard-card__discount">' . esc_html( $badge ) . '</span>';
			}

			$rows .= '<li class="affilicard-card__row">'
				. '<div class="affilicard-card__platform">' . esc_html( $platform->name ) . '</div>'
				. '<div class="affilicard-card__pricing">' . $pricing . '</div>'
				. '<a class="affilicard-card__cta" href="' . esc_url( $url ) . '" target="_blank" rel="nofollow sponsored noopener" style="' . esc_attr( $btn_style ) . '">' . esc_html( $label ) . '</a>'
				. '</li>';
		}
		return '' === $rows ? '' : '<ul class="affilicard-card__listings">' . $rows . '</ul>';
	}

	/**
	 * 価格文字列を比較用の正の数値に変換する。¥/￥/カンマ/空白を除去し、
	 * is_numeric かつ 0 より大きいなら float を返す。それ以外は null。
	 */
	private static function priceToNumber( string $raw ): ?float {
		$normalized = preg_replace( '/[\x{00A5}\x{FFE5},\s]/u', '', $raw );
		if ( null === $normalized || '' === $normalized || ! is_numeric( $normalized ) ) {
			return null;
		}
		$num = (float) $normalized;
		return $num > 0 ? $num : null;
	}
}
