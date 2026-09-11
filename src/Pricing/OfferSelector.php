<?php
declare(strict_types=1);

namespace Affilicard\Pricing;

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
			$status = isset( $offer['fetch_status'] ) ? (string) $offer['fetch_status'] : FetchStatus::NONE;
			if ( ! FetchStatus::isTerminal( $status ) ) {
				return array( $offer );
			}
		}

		// 全件が terminal。非破壊に倒し、先頭を返す（価格は PriceFreshness が隠す）。
		return array( $sorted[0] );
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
			$order     = isset( $offer['display_order'] ) ? (int) $offer['display_order'] : self::DEFAULT_ORDER;
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
}
