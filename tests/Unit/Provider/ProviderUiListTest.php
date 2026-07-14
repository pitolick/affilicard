<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider;

use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Provider\ProviderUiList;
use PHPUnit\Framework\TestCase;

final class ProviderUiListTest extends TestCase {

	private function provider( string $code, string $label, bool $auto, ?string $account ): ProviderInterface {
		return new class( $code, $label, $auto, $account ) implements ProviderInterface {
			public function __construct(
				private string $code,
				private string $label,
				private bool $auto,
				private ?string $account
			) {}
			public function code(): string {
				return $this->code;
			}
			public function label(): string {
				return $this->label;
			}
			public function isAutomatic(): bool {
				return $this->auto;
			}
			public function accountCode(): ?string {
				return $this->account;
			}
			public function fetch( string $externalId, array $platformConfig ): ?array {
				return null;
			}
			public function testConnection( array $credentials ): array {
				return array(
					'ok'      => true,
					'message' => '',
				);
			}
		};
	}

	public function test_build_includes_account_code(): void {
		$registry = new ProviderRegistry();
		$registry->register( $this->provider( 'manual', '手動入力', false, null ) );
		$registry->register( $this->provider( 'rakuten-kobo', '楽天Kobo', true, 'rakuten' ) );

		$list = ProviderUiList::build( $registry );

		$this->assertSame( 'manual', $list[0]['code'] );
		$this->assertNull( $list[0]['accountCode'] );
		$this->assertFalse( $list[0]['isAutomatic'] );
		$this->assertSame( 'rakuten', $list[1]['accountCode'] );
		$this->assertTrue( $list[1]['isAutomatic'] );
	}
}
