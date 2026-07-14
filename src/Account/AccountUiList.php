<?php
declare(strict_types=1);

namespace Affilicard\Account;

/**
 * 設定画面(React)へ渡す account UI リストを組み立てる。credentialsSchema の SSOT。
 */
final class AccountUiList {

	/**
	 * @return list<array{code: string, label: string, credentialsSchema: list<array{key: string, label: string, type: string, required: bool}>}>
	 */
	public static function build( AccountRegistry $registry ): array {
		$list = array();
		foreach ( $registry->all() as $account ) {
			$list[] = array(
				'code'              => $account->code(),
				'label'             => $account->label(),
				'credentialsSchema' => $account->credentialsSchema(),
			);
		}
		return $list;
	}
}
