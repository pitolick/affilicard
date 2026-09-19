<?php

declare(strict_types=1);

namespace Affilicard\Util;

/**
 * 外部由来の配列から「文字列フィールド」を安全に取り出す。
 *
 * REST の本文や postmeta はネストした配列・オブジェクトを含みうる。それを
 * `(string)` でキャストすると、配列は警告を出したうえで "Array" という値になり、
 * `__toString` を持たないオブジェクトでは致命的エラーになる。前者は
 * 「でたらめな身元・URL が保存される」形で沈黙するため、弾かずに通す方が危険である
 * （身元が "Array" の offer は再同定も生死判定もできない）。
 *
 * 非スカラーは「値なし」と同じ空文字に倒す。スカラーの扱いは従来どおり
 * （bool の "1"／数値の文字列化を含め `(string)` と同じ）。
 */
final class ScalarField {

	/**
	 * @param array<string, mixed> $source
	 */
	public static function string( array $source, string $key ): string {
		return isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? (string) $source[ $key ] : '';
	}
}
