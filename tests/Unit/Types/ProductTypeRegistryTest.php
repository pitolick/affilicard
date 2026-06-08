<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Types;

use Affilicard\Types\ProductTypeInterface;
use Affilicard\Types\ProductTypeRegistry;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProductTypeRegistryTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_register_and_get_round_trip(): void {
		$registry = new ProductTypeRegistry();
		$type     = $this->makeType( 'fake' );

		$registry->register( $type );

		$this->assertSame( $type, $registry->get( 'fake' ) );
	}

	public function test_get_returns_null_for_unknown_code(): void {
		$registry = new ProductTypeRegistry();
		$this->assertNull( $registry->get( 'nonexistent' ) );
	}

	public function test_all_returns_registered_types_and_codes(): void {
		$registry = new ProductTypeRegistry();
		$a        = $this->makeType( 'aaa' );
		$b        = $this->makeType( 'bbb' );

		$registry->register( $a );
		$registry->register( $b );

		$this->assertSame( array( $a, $b ), $registry->all() );
		$this->assertSame( array( 'aaa', 'bbb' ), $registry->codes() );
	}

	private function makeType( string $code ): ProductTypeInterface {
		return new class( $code ) implements ProductTypeInterface {

			public function __construct( private string $code ) {}

			public function code(): string {
				return $this->code;
			}

			public function label(): string {
				return $this->code;
			}

			public function extrasSchema(): array {
				return array();
			}

			public function extractExtrasFromProvider( string $providerCode, array $providerRaw ): array {
				return array();
			}

			public function validateExtras( $extras ): array {
				return array();
			}

			public function cardHeaderKeys(): array {
				return array();
			}

			public function cardHiddenKeys(): array {
				return array();
			}

			public function cardMediaLabel(): string {
				return '';
			}
		};
	}
}
