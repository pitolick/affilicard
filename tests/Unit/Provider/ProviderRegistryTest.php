<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider;

use Affilicard\Provider\FetchResult;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProviderRegistryTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_register_and_get_round_trip(): void {
		$registry = new ProviderRegistry();
		$provider = $this->makeProvider( 'foo' );

		$registry->register( $provider );

		$this->assertSame( $provider, $registry->get( 'foo' ) );
	}

	public function test_all_and_codes_return_registered_providers(): void {
		$registry = new ProviderRegistry();
		$a        = $this->makeProvider( 'a-code' );
		$b        = $this->makeProvider( 'b-code' );

		$registry->register( $a );
		$registry->register( $b );

		$this->assertSame( array( $a, $b ), $registry->all() );
		$this->assertSame( array( 'a-code', 'b-code' ), $registry->codes() );
	}

	public function test_get_returns_null_for_unknown_code(): void {
		$registry = new ProviderRegistry();
		$this->assertNull( $registry->get( 'nope' ) );
	}

	private function makeProvider( string $code ): ProviderInterface {
		return new class( $code ) implements ProviderInterface {
			public function __construct( private string $code ) {}
			public function code(): string {
				return $this->code;
			}
			public function label(): string {
				return 'label-' . $this->code;
			}
			public function isAutomatic(): bool {
				return false;
			}
			public function accountCode(): ?string {
				return null;
			}
			public function fetch( string $externalId, array $platformConfig ): FetchResult {
				return FetchResult::error();
			}
			public function testConnection( array $credentials ): array {
				return array(
					'ok'      => true,
					'message' => '',
				);
			}
			public function minRequestIntervalMs(): int {
				return 0;
			}
		};
	}
}
