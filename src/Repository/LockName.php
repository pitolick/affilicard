<?php

declare(strict_types=1);

namespace Affilicard\Repository;

/**
 * MySQL の名前付きロック（GET_LOCK）の名前を組み立てる。
 *
 * **名前付きロックは接続単位ではなく MySQL サーバ全体で共有される。** 共有ホスティングの
 * ように 1 つの MySQL に複数の WordPress が同居していると、サイトを区別しない名前は
 * 別サイトの処理を待たせてしまう（同じ理由でテーブル prefix 違いのマルチサイトも衝突する）。
 * DB 名と prefix のハッシュを必ず挟む。
 *
 * 名前は 64 バイト以内に収めること（それを超える名前の挙動は MySQL のバージョン依存）。
 */
final class LockName {

	/** サイトを区別する 8 文字のハッシュ。 */
	public static function siteHash(): string {
		global $wpdb;

		$dbname = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		$prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';

		return substr( md5( $dbname . '|' . $prefix ), 0, 8 );
	}

	/**
	 * `<prefix>_<サイト>_<suffix>` 形式のロック名。
	 *
	 * $suffix は呼び出し側が 64 バイトに収まるよう調整すること（外部由来の文字列は
	 * ハッシュへ畳んでから渡す）。
	 */
	public static function build( string $prefix, string $suffix ): string {
		return $prefix . '_' . self::siteHash() . '_' . $suffix;
	}
}
