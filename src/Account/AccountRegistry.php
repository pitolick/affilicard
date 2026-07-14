<?php
declare(strict_types=1);

namespace Affilicard\Account;

/**
 * Account の登録レジストリ。Plugin::buildAccountRegistry() で rakuten / dmm を register する。
 */
final class AccountRegistry {

	/** @var array<string, AccountInterface> */
	private array $accounts = array();

	public function register( AccountInterface $account ): void {
		$this->accounts[ $account->code() ] = $account;
	}

	public function get( string $code ): ?AccountInterface {
		return $this->accounts[ $code ] ?? null;
	}

	/** @return list<AccountInterface> */
	public function all(): array {
		return array_values( $this->accounts );
	}

	/** @return list<string> */
	public function codes(): array {
		return array_keys( $this->accounts );
	}
}
