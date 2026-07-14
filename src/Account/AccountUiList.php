<?php
declare(strict_types=1);

namespace Affilicard\Account;

/**
 * 設定画面(React)へ渡す account UI リストを組み立てる。credentialsSchema の SSOT。
 */
final class AccountUiList {

	/**
	 * @return list<array{code: string, label: string, credentialsSchema: list<array{key: string, label: string, type: string, required: bool}>, isConfigured: bool}>
	 */
	public static function build( AccountRegistry $registry ): array {
		$list = array();
		foreach ( $registry->all() as $account ) {
			$list[] = array(
				'code'              => $account->code(),
				'label'             => $account->label(),
				'credentialsSchema' => $account->credentialsSchema(),
				'isConfigured'      => self::isConfigured( $account ),
			);
		}
		return $list;
	}

	/**
	 * required な credentialsSchema フィールドがすべて非空値で保存済みかどうか。
	 */
	private static function isConfigured( AccountInterface $account ): bool {
		$stored = AccountCredentials::get( $account->code() );
		foreach ( $account->credentialsSchema() as $field ) {
			if ( empty( $field['required'] ) ) {
				continue;
			}
			$key = (string) $field['key'];
			if ( '' === (string) ( $stored[ $key ] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}
}
