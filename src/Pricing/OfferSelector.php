<?php
declare(strict_types=1);

namespace Affilicard\Pricing;

use Affilicard\Util\ScalarField;

/**
 * listing の中からどの購入リンク（offer）を使うかを決める唯一の場所。
 *
 * 描画（CardRenderer）も価格更新（ListingRefresher・QueueMaintenance）も
 * この結果だけを見る。ボタンと書影が別の offer を指すといったズレを
 * 構造的に起こさないための集約である。
 */
final class OfferSelector {

	/** display_order の既定値。小さいほど優先。 */
	public const DEFAULT_ORDER = 100;

	/**
	 * 使う購入リンクを返す。
	 *
	 * **常に配列を返す。** 現時点では 0 件または 1 件しか返さないが、将来
	 * 「同一 platform の複数リンクを同時に見せる」に進むときは、この関数が
	 * 返す件数を増やすだけで描画も更新も追随する。レート制限の枠取りも
	 * 返り値の件数に比例させてあるため、表示を増やして制限を超える事故が起きない。
	 *
	 * @param array<int, mixed> $offers
	 * @return list<array<string, mixed>>
	 */
	public static function select( array $offers, bool $fallbackEnabled ): array {
		$sorted = self::sorted( $offers );
		if ( array() === $sorted ) {
			return array();
		}

		if ( ! $fallbackEnabled ) {
			return array( $sorted[0] );
		}

		foreach ( $sorted as $offer ) {
			$status = ScalarField::string( $offer, 'fetch_status' );
			if ( ! FetchStatus::isTerminal( $status ) ) {
				return array( $offer );
			}
		}

		// 全件が terminal。非破壊に倒し、先頭を返す（価格は PriceFreshness が隠す）。
		return array( $sorted[0] );
	}

	/**
	 * バージョン（`variant_label`）ごとに使う購入リンクを 1 件ずつ返す（カードの描画用）。
	 *
	 * 同じ商品が通常版・特装版、字幕版・吹替版のように**別の商品ページ**で
	 * 売られているとき、カードにはそれぞれを並べたい。一方で listing の offers は
	 * 「優先順位つきの代わりのリンク」（期間限定版が消えたら通常版へ切り替える）にも
	 * 使われており、こちらは 1 件だけを見せる。両者を `variant_label` で分ける:
	 *
	 * - 同じ `variant_label`（空文字どうしも同じ）の offers は 1 つのグループで、
	 *   その中から {@see self::select()} の規則で 1 件を選ぶ（代わりのリンクの動きはそのまま）
	 * - グループの並びは、表示順で最初に現れた offer の位置で決める
	 *
	 * `variant_label` を誰も持たない listing は 1 グループになり、{@see self::select()} と
	 * 同じ結果（0 件または 1 件）になる。
	 *
	 * **価格更新はまだこちらを使わない。** 更新キューは (商品, platform) 単位で 1 件を
	 * 取りに行く作りのため、2 つ目以降のバージョンは自動更新の対象にならない
	 * （spec 2026-11-26-offer-variant-label-design.md §5）。
	 *
	 * @param array<int, mixed> $offers
	 * @return list<array<string, mixed>>
	 */
	public static function selectPerVariant( array $offers, bool $fallbackEnabled ): array {
		$groups = array();
		foreach ( self::sorted( $offers ) as $offer ) {
			$groups[ self::variantKey( $offer ) ][] = $offer;
		}

		$out = array();
		foreach ( $groups as $group ) {
			// グループの中は既に表示順なので、select() の並べ替えは同じ順を保つ（安定ソート）。
			foreach ( self::select( $group, $fallbackEnabled ) as $offer ) {
				$out[] = $offer;
			}
		}
		return $out;
	}

	/**
	 * offer のバージョン名（前後の空白は除く）。無ければ空文字。
	 *
	 * @param array<string, mixed> $offer
	 */
	public static function variantLabel( array $offer ): string {
		return trim( ScalarField::string( $offer, 'variant_label' ) );
	}

	/**
	 * グループ分けのキー。PHP の配列キーは数字の文字列を整数に変えるため、接頭辞を付けて
	 * 文字列のまま扱う（「1」と「01」を別のバージョンとして保つ）。
	 *
	 * @param array<string, mixed> $offer
	 */
	private static function variantKey( array $offer ): string {
		return 'v:' . self::variantLabel( $offer );
	}

	/**
	 * display_order 昇順・同値は出現順（安定ソート）。
	 *
	 * PHP の usort は同値の順序を保証しないため、出現位置をタイブレークに使う。
	 *
	 * @param array<int, mixed> $offers
	 * @return list<array<string, mixed>>
	 */
	private static function sorted( array $offers ): array {
		$indexed = array();
		$i       = 0;
		foreach ( $offers as $offer ) {
			if ( ! is_array( $offer ) ) {
				continue;
			}
			$order     = self::normaliseOrder( $offer['display_order'] ?? null );
			$indexed[] = array( $order, $i, $offer );
			++$i;
		}

		usort(
			$indexed,
			static function ( array $a, array $b ): int {
				return $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0];
			}
		);

		return array_map( static fn( array $row ): array => $row[2], $indexed );
	}

	/**
	 * display_order を整数へ揃える唯一の場所。
	 *
	 * **不正値は最優先（0）ではなく既定値へ倒す。** `(int)` で畳むと空文字も
	 * 非数値文字列も 0 になり、打ち間違いや壊れた入力が「いちばん先に表示される
	 * 購入リンク」に化ける。並び順の指定が読めないなら、指定が無いときと同じ
	 * 扱い（DEFAULT_ORDER）にするのが安全側である。
	 *
	 * 小数は切り捨てる（`(int)` と同じ）。JS 側（ListingsEditor の
	 * offerDisplayOrder）はこの規則の写しなので、変えるときは両方直すこと。
	 *
	 * @param mixed $raw
	 */
	public static function normaliseOrder( $raw ): int {
		if ( is_int( $raw ) ) {
			return $raw;
		}
		if ( is_float( $raw ) ) {
			return is_finite( $raw ) ? (int) $raw : self::DEFAULT_ORDER;
		}
		if ( is_string( $raw ) && is_numeric( trim( $raw ) ) ) {
			$trimmed = trim( $raw );
			// **桁あふれを整数化しない。** `'1e9999'` は is_numeric() を通るが float に
			// すると INF になり、INF の `(int)` キャストは未定義（PHP の実測は 0）＝
			// いちばん先に表示される購入リンクに化ける。有限でなければ「読めなかった」
			// 側へ倒す（JS 側の offerDisplayOrder も Number.isFinite() で同じ判断をする）。
			return is_finite( (float) $trimmed ) ? (int) $trimmed : self::DEFAULT_ORDER;
		}
		return self::DEFAULT_ORDER;
	}
}
