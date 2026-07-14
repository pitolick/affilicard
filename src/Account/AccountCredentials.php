<?php
declare(strict_types=1);

namespace Affilicard\Account;

use Affilicard\Util\Crypto;
use Affilicard\Util\JsonField;

/**
 * account 毎の credentials を affilicard_account_<code>_credentials に AES 暗号化して保存する。
 *
 * 値の shape は array<string, string>。GET 応答は write-only（password は value を返さない）。
 */
final class AccountCredentials {

	private const OPTION_PREFIX = 'affilicard_account_';
	private const OPTION_SUFFIX = '_credentials';

	public static function optionKey( string $accountCode ): string {
		return self::OPTION_PREFIX . $accountCode . self::OPTION_SUFFIX;
	}

	/** @return array<string, string> */
	public static function get( string $accountCode ): array {
		$raw = get_option( self::optionKey( $accountCode ), '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decrypted = Crypto::decrypt( $raw );
		if ( '' === $decrypted ) {
			return array();
		}
		$decoded = JsonField::decode( $decrypted, array() );
		$result  = array();
		foreach ( $decoded as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			$result[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}
		return $result;
	}

	/**
	 * type-aware な保存状態を返す。password は value を返さず isSet のみ、text は実値。
	 *
	 * @return array<string, array{value: string, isSet: bool}>
	 */
	public static function getStatusFor( AccountInterface $account ): array {
		$values = self::get( $account->code() );
		$status = array();
		foreach ( $account->credentialsSchema() as $field ) {
			$key            = (string) $field['key'];
			$isSecret       = ( $field['type'] ?? 'text' ) === 'password';
			$stored         = (string) ( $values[ $key ] ?? '' );
			$status[ $key ] = array(
				'value' => $isSecret ? '' : $stored,
				'isSet' => '' !== $stored,
			);
		}
		return $status;
	}

	/**
	 * PATCH: string は上書き（空文字は明示クリア）、null は維持。
	 *
	 * @param array<string, string|null> $newValues
	 */
	public static function patch( string $accountCode, array $newValues ): void {
		$current = self::get( $accountCode );
		foreach ( $newValues as $key => $value ) {
			if ( ! is_string( $key ) || null === $value ) {
				continue;
			}
			$current[ $key ] = (string) $value;
		}
		$encrypted = Crypto::encrypt( JsonField::encode( $current ) );
		update_option( self::optionKey( $accountCode ), $encrypted, false );
	}

	public static function delete( string $accountCode ): void {
		delete_option( self::optionKey( $accountCode ) );
	}
}
