<?php
declare(strict_types=1);

namespace Affilicard\Provider;

/**
 * 設定画面(React)のドロップダウンへ渡す provider UI リスト。schema は持たず accountCode を持つ。
 */
final class ProviderUiList {

	/**
	 * @return list<array{code: string, label: string, isAutomatic: bool, accountCode: string|null}>
	 */
	public static function build( ProviderRegistry $registry ): array {
		$list = array();
		foreach ( $registry->all() as $provider ) {
			$list[] = array(
				'code'        => $provider->code(),
				'label'       => $provider->label(),
				'isAutomatic' => $provider->isAutomatic(),
				'accountCode' => $provider->accountCode(),
			);
		}
		return $list;
	}
}
