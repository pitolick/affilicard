<?php
declare(strict_types=1);

namespace Affilicard\Stock;

/**
 * 商品の発売日（`YYYY-MM-DD`）から予約状態と発売日ラベルを導出する純粋ヘルパ。
 * 時刻は引数で受け取り、内部では現在時刻を読まない（テスト可能性のため）。
 */
final class ReleaseDate {

	public static function isPreorder( string $releaseDate, string $today ): bool {
		if ( ! self::isValid( $releaseDate ) || ! self::isValid( $today ) ) {
			return false;
		}
		return $today < $releaseDate;
	}

	public static function label( string $releaseDate ): string {
		if ( ! self::isValid( $releaseDate ) ) {
			return '';
		}
		$parts = array_map( 'intval', explode( '-', $releaseDate ) );
		return sprintf(
			/* translators: 1: year, 2: month, 3: day */
			(string) __( '%1$d年%2$d月%3$d日発売', 'affilicard' ),
			$parts[0],
			$parts[1],
			$parts[2]
		);
	}

	private static function isValid( string $date ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );
	}
}
