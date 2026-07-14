<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Account;

use Affilicard\Account\AccountInterface;
use Affilicard\Account\AccountRegistry;
use Affilicard\Account\AccountUiList;
use PHPUnit\Framework\TestCase;

final class AccountUiListTest extends TestCase {

	public function test_build_maps_accounts_in_order(): void {
		$registry = new AccountRegistry();
		$registry->register(
			new class() implements AccountInterface {
				public function code(): string {
					return 'sample';
				}
				public function label(): string {
					return 'Sample';
				}
				public function credentialsSchema(): array {
					return array(
						array(
							'key'      => 'k',
							'label'    => 'K',
							'type'     => 'password',
							'required' => true,
						),
					);
				}
			}
		);

		$list = AccountUiList::build( $registry );

		$this->assertCount( 1, $list );
		$this->assertSame( 'sample', $list[0]['code'] );
		$this->assertSame( 'Sample', $list[0]['label'] );
		$this->assertSame( 'k', $list[0]['credentialsSchema'][0]['key'] );
	}
}
