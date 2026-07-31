<?php
/**
 * カードの見え方をローカルで確認するための素朴なレンダラ。
 *
 * WP を立ち上げずに CardRenderer だけを動かし、card.css を当てた HTML を吐く。
 * hide_media の有無を上下に並べて崩れを目視できるようにする。
 *
 *   php tools/render-preview.php > /tmp/preview.html
 *
 * @package Affilicard
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// CardRenderer が使う WP 関数の最小スタブ（エスケープは素通し。表示確認が目的）。
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $v ) { // phpcs:ignore
		return (string) $v;
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $v ) { // phpcs:ignore
		return (string) $v;
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $v ) { // phpcs:ignore
		return (string) $v;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $v ) { // phpcs:ignore
		return (string) $v;
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $v ) { // phpcs:ignore
		return (string) $v;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $v ) { // phpcs:ignore
		return is_string( $v ) ? trim( $v ) : '';
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { // phpcs:ignore
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) { // phpcs:ignore
		return $text;
	}
}
if ( ! function_exists( 'sanitize_hex_color' ) ) {
	function sanitize_hex_color( $c ) { // phpcs:ignore
		return $c;
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null ) { // phpcs:ignore
		return gmdate( $format, $timestamp ?? time() );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql' ) { // phpcs:ignore
		return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) { // phpcs:ignore
		return $d;
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n ) { // phpcs:ignore
		return number_format( (float) $n );
	}
}

use Affilicard\Platform\PlatformDefinition;
use Affilicard\Renderer\CardRenderer;

$platform = static fn( string $code, string $label, string $cta, string $brand ): PlatformDefinition =>
	new PlatformDefinition( $code, $label, 'manual', 1, true, array( 'ebook', 'generic' ), $cta, $brand, '#ffffff' );

$platforms = array(
	$platform( 'dmm-books', 'DMMブックス', 'DMMブックスで読む', '#d72d65' ),
	$platform( 'amazon-kindle', 'Amazon Kindle', 'Kindleで読む', '#ff9900' ),
	$platform( 'rakuten-kobo', '楽天Kobo', '楽天Koboで読む', '#bf0000' ),
);

$listing = static fn( string $code, ?int $price = 660 ): array => array(
	'platform'         => $code,
	'enabled'          => true,
	'affiliate_url'    => 'https://example.test/' . $code,
	'image_url'        => 'https://placehold.jp/120x180.png',
	'price'            => $price,
	'last_verified_at' => gmdate( 'c' ),
);

$cases = array(
	'複数ストア（在庫あり・あらすじと著者あり）' => array(
		'product' => array(
			'id'           => 1,
			'title'        => 'サンプル漫画（電子書籍）',
			'content'      => '架空の作品のサンプル紹介文です。あらすじの行数がカードの高さに効きます。',
			'product_type' => 'ebook',
			'stock_status' => 'available',
			'extras'       => array(
				'author'    => 'サンプル著者',
				'publisher' => 'サンプル出版',
			),
			'listings'     => array( $listing( 'dmm-books' ), $listing( 'amazon-kindle' ), $listing( 'rakuten-kobo' ) ),
		),
		'options' => array(),
	),
	'単一ストア・本文短い（汎用・カード色指定）' => array(
		'product' => array(
			'id'           => 2,
			'title'        => 'サンプル雑貨（汎用・在庫あり）',
			'content'      => '',
			'product_type' => 'generic',
			'stock_status' => 'available',
			'extras'       => array(),
			'listings'     => array( $listing( 'dmm-books' ) ),
		),
		'options' => array(
			'colors' => array(
				'card_bg'     => '#f6f7f7',
				'card_border' => '#c3c4c7',
			),
		),
	),
	'在庫切れ（CTA が出ない）'        => array(
		'product' => array(
			'id'           => 3,
			'title'        => 'サンプル（在庫切れ）',
			'content'      => '',
			'product_type' => 'generic',
			'stock_status' => 'out_of_stock',
			'extras'       => array(),
			'listings'     => array( $listing( 'dmm-books' ) ),
		),
		'options' => array(),
	),
	'取扱終了（CTA が出ない）'        => array(
		'product' => array(
			'id'           => 4,
			'title'        => 'サンプル（取扱終了）',
			'content'      => '',
			'product_type' => 'generic',
			'stock_status' => 'discontinued',
			'extras'       => array(),
			'listings'     => array( $listing( 'dmm-books' ) ),
		),
		'options' => array(),
	),
);

$css  = file_get_contents( __DIR__ . '/../assets/card.css' );
$html = '<!doctype html><meta charset="utf-8"><title>affilicard card preview</title><style>'
	. 'body{font-family:system-ui,sans-serif;background:#f0f0f1;margin:0;padding:24px}'
	. '.wrap{max-width:1080px;margin:0 auto}'
	. 'h2{font-size:15px;margin:28px 0 6px}'
	. '.pair{display:grid;grid-template-columns:1fr;gap:18px}'
	. '.col-label{font-size:12px;color:#666;margin:0 0 6px;font-weight:600}'
	. $css . '</style><div class="wrap">';

foreach ( $cases as $label => $case ) {
	$html .= '<h2>' . $label . '</h2><div class="pair">';
	foreach ( array(
		'通常表示'       => false,
		'商品画像を表示しない' => true,
	) as $col => $hide ) {
		$opts               = $case['options'];
		$opts['hide_media'] = $hide;
		$html              .= '<div class="col"><p class="col-label">' . $col . '</p>'
			. ( new CardRenderer() )->render( $case['product'], $platforms, $opts )
			. '</div>';
	}
	$html .= '</div>';
}

echo $html . '</div>';
