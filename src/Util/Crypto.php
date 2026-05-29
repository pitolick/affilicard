<?php
declare(strict_types=1);

namespace Affilicard\Util;

/**
 * API キー等の機微情報を AES-256-CBC で対称暗号化するユーティリティ。
 *
 * 鍵は wp_salt('auth') を SHA-256 でハッシュした 32 バイトを使用する。
 * IV は openssl_random_pseudo_bytes(16) を都度生成し、ペイロード先頭に格納する。
 */
final class Crypto {

	private const CIPHER  = 'aes-256-cbc';
	private const IV_SIZE = 16;

	public static function encrypt( string $plaintext ): string {
		$key = self::deriveKey();
		$iv  = openssl_random_pseudo_bytes( self::IV_SIZE );

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return '';
		}

		return base64_encode( $iv . $ciphertext );
	}

	public static function decrypt( string $payload ): string {
		if ( '' === $payload ) {
			return '';
		}

		$decoded = base64_decode( $payload, true );
		if ( false === $decoded || strlen( $decoded ) <= self::IV_SIZE ) {
			return '';
		}

		$iv         = substr( $decoded, 0, self::IV_SIZE );
		$ciphertext = substr( $decoded, self::IV_SIZE );
		$key        = self::deriveKey();

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $plaintext ) {
			return '';
		}

		return $plaintext;
	}

	private static function deriveKey(): string {
		$salt = wp_salt( 'auth' );
		return hash( 'sha256', (string) $salt, true );
	}
}
