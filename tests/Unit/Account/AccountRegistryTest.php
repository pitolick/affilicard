<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Account;

use Affilicard\Account\AccountInterface;
use Affilicard\Account\AccountRegistry;
use PHPUnit\Framework\TestCase;

final class AccountRegistryTest extends TestCase {

	private function fakeAccount( string $code, string $label, array $schema ): AccountInterface {
		return new class( $code, $label, $schema ) implements AccountInterface {
			public function __construct( private string $code, private string $label, private array $schema ) {}
			public function code(): string {
				return $this->code;
			}
			public function label(): string {
				return $this->label;
			}
			public function credentialsSchema(): array {
				return $this->schema;
			}
		};
	}

	public function test_register_and_get_by_code(): void {
		$registry = new AccountRegistry();
		$registry->register( $this->fakeAccount( 'sample', 'Sample', array() ) );

		$this->assertSame( 'sample', $registry->get( 'sample' )?->code() );
		$this->assertNull( $registry->get( 'missing' ) );
	}

	public function test_all_and_codes_preserve_registration_order(): void {
		$registry = new AccountRegistry();
		$registry->register( $this->fakeAccount( 'a', 'A', array() ) );
		$registry->register( $this->fakeAccount( 'b', 'B', array() ) );

		$this->assertSame( array( 'a', 'b' ), $registry->codes() );
		$this->assertCount( 2, $registry->all() );
	}
}
