<?php
declare(strict_types=1);

namespace Affilicard\Types;

/**
 * ProductType の登録レジストリ。
 *
 * Plugin 起動時に GenericType / EbookType 等を register() する想定。
 */
final class ProductTypeRegistry {

	/**
	 * @var array<string, ProductTypeInterface>
	 */
	private array $types = array();

	public function register( ProductTypeInterface $type ): void {
		$this->types[ $type->code() ] = $type;
	}

	public function get( string $code ): ?ProductTypeInterface {
		return $this->types[ $code ] ?? null;
	}

	/**
	 * @return list<ProductTypeInterface>
	 */
	public function all(): array {
		return array_values( $this->types );
	}

	/**
	 * @return list<string>
	 */
	public function codes(): array {
		return array_keys( $this->types );
	}
}
