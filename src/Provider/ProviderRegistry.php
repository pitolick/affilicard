<?php
declare(strict_types=1);

namespace Affilicard\Provider;

/**
 * Provider の登録レジストリ。
 *
 * Plugin 起動時に ManualProvider / DmmProvider 等を register() する想定。
 */
final class ProviderRegistry {

	/**
	 * @var array<string, ProviderInterface>
	 */
	private array $providers = array();

	public function register( ProviderInterface $provider ): void {
		$this->providers[ $provider->code() ] = $provider;
	}

	public function get( string $code ): ?ProviderInterface {
		return $this->providers[ $code ] ?? null;
	}

	/**
	 * @return list<ProviderInterface>
	 */
	public function all(): array {
		return array_values( $this->providers );
	}

	/**
	 * @return list<string>
	 */
	public function codes(): array {
		return array_keys( $this->providers );
	}
}
