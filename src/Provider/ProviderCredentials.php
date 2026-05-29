<?php
declare(strict_types=1);

namespace Affilicard\Provider;

use Affilicard\Util\Crypto;
use Affilicard\Util\JsonField;

/**
 * Provider 毎の credentials を `affilicard_provider_<code>_credentials` オプションに
 * AES-256-CBC 暗号化して保存する。
 *
 * 値の shape は `array<string, string>`（例: ['api_id' => '...', 'affiliate_id' => '...']）。
 */
final class ProviderCredentials {

	private const OPTION_PREFIX = 'affilicard_provider_';
	private const OPTION_SUFFIX = '_credentials';

	public static function optionKey( string $providerCode ): string {
		return self::OPTION_PREFIX . $providerCode . self::OPTION_SUFFIX;
	}

	/**
	 * 復号した credentials を返す。任意箇所で失敗したら空配列。
	 *
	 * @return array<string, string>
	 */
	public static function get( string $providerCode ): array {
		$raw = get_option( self::optionKey( $providerCode ), '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decrypted = Crypto::decrypt( $raw );
		if ( '' === $decrypted ) {
			return array();
		}

		$decoded = JsonField::decode( $decrypted, array() );

		$result = array();
		foreach ( $decoded as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			$result[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}
		return $result;
	}

	/**
	 * 表示用にマスクした credentials を返す。
	 *
	 * - 空文字はそのまま空文字
	 * - 1 文字の場合は `*`
	 * - 2 文字以上は末尾 2 文字を残し、残りを `*` で置換
	 *
	 * @return array<string, string>
	 */
	public static function getMasked( string $providerCode ): array {
		$values = self::get( $providerCode );
		$masked = array();
		foreach ( $values as $key => $value ) {
			$masked[ $key ] = self::maskValue( $value );
		}
		return $masked;
	}

	/**
	 * PATCH セマンティクス:
	 *   - null   → そのキーは触らない（既存値を維持）
	 *   - string → 上書き（空文字は明示的クリア）
	 *
	 * @param array<string, string|null> $newValues
	 */
	public static function patch( string $providerCode, array $newValues ): void {
		$current = self::get( $providerCode );

		foreach ( $newValues as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			if ( null === $value ) {
				continue;
			}
			$current[ $key ] = (string) $value;
		}

		$payload   = JsonField::encode( $current );
		$encrypted = Crypto::encrypt( $payload );

		update_option( self::optionKey( $providerCode ), $encrypted, false );
	}

	public static function delete( string $providerCode ): void {
		delete_option( self::optionKey( $providerCode ) );
	}

	private static function maskValue( string $value ): string {
		$length = strlen( $value );
		if ( 0 === $length ) {
			return '';
		}
		if ( 1 === $length ) {
			return '*';
		}
		if ( 2 === $length ) {
			return '**';
		}
		return str_repeat( '*', $length - 2 ) . substr( $value, -2 );
	}
}
