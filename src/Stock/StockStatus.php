<?php
declare(strict_types=1);

namespace Affilicard\Stock;

/**
 * CPT meta `affilicard_stock_status` の取りうる値を表す value object。
 */
final class StockStatus {

	public const AVAILABLE     = 'available';
	public const OUT_OF_STOCK  = 'out_of_stock';
	public const DISCONTINUED  = 'discontinued';

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::AVAILABLE,
			self::OUT_OF_STOCK,
			self::DISCONTINUED,
		);
	}

	public static function isValid( string $value ): bool {
		return in_array( $value, self::all(), true );
	}

	/**
	 * 不正値・null は AVAILABLE にフォールバックする。
	 */
	public static function normalize( ?string $value ): string {
		if ( null === $value || ! self::isValid( $value ) ) {
			return self::AVAILABLE;
		}
		return $value;
	}

	public static function label( string $value ): string {
		$normalized = self::normalize( $value );
		switch ( $normalized ) {
			case self::OUT_OF_STOCK:
				return __( '在庫切れ', 'affilicard' );
			case self::DISCONTINUED:
				return __( '取扱終了', 'affilicard' );
			case self::AVAILABLE:
			default:
				return __( '販売中', 'affilicard' );
		}
	}
}
