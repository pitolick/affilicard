<?php
declare(strict_types=1);

namespace Affilicard\Util;

/**
 * 投稿 meta に JSON 文字列として保存するための防御的 encode/decode ユーティリティ。
 */
final class JsonField {

	/**
	 * @param array<string, mixed>|array<int, mixed> $value
	 */
	public static function encode( array $value ): string {
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			$json = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return is_string( $json ) ? $json : '';
	}

	/**
	 * 不正 JSON / 非配列 JSON はデフォルト値を返す。
	 *
	 * @param array<string, mixed>|array<int, mixed> $default
	 * @return array<string, mixed>|array<int, mixed>
	 */
	public static function decode( string $json, array $default = array() ): array {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return $default;
		}
		return $decoded;
	}
}
