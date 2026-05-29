<?php
declare(strict_types=1);

namespace Affilicard\Schema;

/**
 * CPT meta スキーマのバージョン管理用 value object。
 *
 * 後続 Phase でマイグレーションが必要になった際に compare() を利用する。
 */
final class SchemaVersion {

	public const CURRENT = '1';

	public static function current(): string {
		return self::CURRENT;
	}

	/**
	 * 現在のバージョンと引数のバージョンを比較する。
	 *
	 * PHP の version_compare に揃え、戻り値は -1 / 0 / 1。
	 */
	public static function compare( string $other ): int {
		return version_compare( self::CURRENT, $other );
	}
}
