<?php
declare(strict_types=1);

namespace Affilicard\Content;

use Affilicard\Util\ScalarField;

/**
 * ストアが「成人向け」と判定した商品か（購入リンクの `adult`）。
 *
 * 商品はどのストアで売られていても同じなので、どれか 1 つのストアが成人向けと判定すれば
 * 商品全体を成人向けとみなす。無効にした listing・カードで非表示にしたストア・価格の表示期限が
 * 切れた購入リンクの判定も数える（成人向けかどうかは、表示の有無や時間で変わらないため）。
 *
 * `adult` は 3 値: '1'＝成人向け / '0'＝成人向けではない / ''＝未確認（ストアが判定を返さない）。
 * 成人向けとみなすのは '1' だけ。
 */
final class AdultDetection {

	public const YES     = '1';
	public const NO      = '0';
	public const UNKNOWN = '';

	/**
	 * 成人向けと判定したストアの platform code（重複なし・listing の並び順）。
	 *
	 * @param array<string, mixed> $product Repository::find() の戻り値形
	 * @return list<string>
	 */
	public static function detectedBy( array $product ): array {
		$listings = is_array( $product['listings'] ?? null ) ? $product['listings'] : array();
		$codes    = array();
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$offers = is_array( $listing['offers'] ?? null ) ? $listing['offers'] : array();
			foreach ( $offers as $offer ) {
				if ( is_array( $offer ) && self::YES === self::normalise( $offer['adult'] ?? '' ) ) {
					$code = ScalarField::string( $listing, 'platform' );
					if ( '' !== $code && ! in_array( $code, $codes, true ) ) {
						$codes[] = $code;
					}
					break;
				}
			}
		}
		return $codes;
	}

	/**
	 * 入力のゆれ（真偽値・数値・"true"/"false"）を 3 値に揃える。判別できない値は未確認。
	 *
	 * @param mixed $value
	 */
	public static function normalise( $value ): string {
		if ( true === $value || 1 === $value ) {
			return self::YES;
		}
		if ( false === $value || 0 === $value ) {
			return self::NO;
		}
		if ( ! is_string( $value ) ) {
			return self::UNKNOWN;
		}
		$value = strtolower( trim( $value ) );
		if ( '1' === $value || 'true' === $value ) {
			return self::YES;
		}
		if ( '0' === $value || 'false' === $value ) {
			return self::NO;
		}
		return self::UNKNOWN;
	}
}
