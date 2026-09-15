<?php
declare(strict_types=1);

namespace Affilicard\Pricing;

use Affilicard\Util\ScalarField;

/**
 * 選択済みの offer が「どの URL を出すか」を決める唯一の場所。
 *
 * **この規則を持つ場所を 1 箇所に固定するためのクラスである。** 同じ判断が
 * 3 箇所で必要になる:
 *
 * 1. 描画（{@see \Affilicard\Renderer\CardRenderer}）——CTA の href と、そもそも
 *    その listing を表示するかどうか
 * 2. ダッシュボードの件数（{@see \Affilicard\Repository\ProductRepository::hasFallbackListing()}）
 * 3. 商品一覧の警告列（{@see \Affilicard\PostType\ProductListColumns::renderFallbackColumn()}）
 *
 * 3 箇所が別々に判定を書くと、**運用に見せている数字とカードの実物がずれる**。
 * 実際に 2 と 3 は `'' === $affiliate` という素の空判定だけを持っていたため、
 * `affiliate_url` が不正（`javascript:` 等の危険スキーム）で `regular_url` が
 * 正当な offer は、カードが regular_url で描画している（＝正真正銘の
 * フォールバック中）のに、件数にも警告列にも「フォールバックではない」として
 * 扱われていた。
 *
 * **検証は `esc_url_raw()` で行う。** 出力直前の `esc_url()` だけに頼ると、
 * 不正な affiliate_url をそのまま採用したうえで最後に空文字へ落とされ、使える
 * regular_url があるのに `href=""` の壊れた CTA になる。offers[] 経路のデータは
 * 保存時に {@see \Affilicard\Rest\ProductSchema::sanitizeOffers()} が既に
 * `esc_url_raw()` を通しているため通常は無意味だが、v3 以前の flat な listing は
 * {@see LegacyOffer::offersWithFallback()} が保存時のサニタイズを経ていない生の
 * post meta をそのままここへ渡す。
 */
final class OfferUrl {

	/**
	 * 購入リンクの href を決める。アフィリエイト URL が無ければ通常 URL へ倒れる。
	 *
	 * 「無い」は**検証後**の空を意味する（危険スキーム等で `esc_url_raw()` が空に
	 * 落とした URL も「無い」）。空文字を返したら出せる URL が 1 つも無い。
	 *
	 * @param array<string, mixed> $offer
	 */
	public static function ctaHref( array $offer ): string {
		$affiliate = self::validated( $offer, 'affiliate_url' );
		if ( '' !== $affiliate ) {
			return $affiliate;
		}
		return self::validated( $offer, 'regular_url' );
	}

	/**
	 * この offer が「アフィリエイト URL が無いため素の商品 URL を出している」状態か。
	 *
	 * {@see self::ctaHref()} と同じ検証を通すので、**答えは必ずカードの実物と一致する**。
	 * 出せる URL が 1 つも無い offer（両方とも空・不正）は false——フォールバックでは
	 * なく、そもそも表示されない。
	 *
	 * @param array<string, mixed> $offer
	 */
	public static function isRegularUrlFallback( array $offer ): bool {
		return '' === self::validated( $offer, 'affiliate_url' )
			&& '' !== self::validated( $offer, 'regular_url' );
	}

	/**
	 * offer の URL フィールドを読んで検証する。
	 *
	 * 値は {@see ScalarField::string()} で読む（配列・オブジェクトは「値なし」）。
	 * `(string)` で直にキャストすると、配列が「Array to string conversion」の警告の
	 * うえ `'Array'` になり、`esc_url_raw()` がそれを `http://Array` という実在しない
	 * URL へ仕立ててしまう。
	 *
	 * @param array<string, mixed> $offer
	 */
	private static function validated( array $offer, string $key ): string {
		return (string) esc_url_raw( trim( ScalarField::string( $offer, $key ) ) );
	}
}
