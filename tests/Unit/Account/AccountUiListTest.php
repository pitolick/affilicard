<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Account;

use Affilicard\Account\AccountInterface;
use Affilicard\Account\AccountRegistry;
use Affilicard\Account\AccountUiList;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class AccountUiListTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $t ) => $t );
		WP_Mock::userFunction( 'wp_salt' )->andReturn( 'test-salt' );
		WP_Mock::userFunction( 'wp_json_encode' )
			->andReturnUsing(
				static function ( $value ) {
					return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				}
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, array{key: string, label: string, type: string, required: bool}> $schema
	 */
	private function account( string $code, array $schema ): AccountInterface {
		return new class( $code, $schema ) implements AccountInterface {
			public function __construct( private string $code, private array $schema ) {}
			public function code(): string {
				return $this->code;
			}
			public function label(): string {
				return 'Sample';
			}
			public function credentialsSchema(): array {
				return $this->schema;
			}
		};
	}

	public function test_build_maps_accounts_in_order(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( '' );

		$registry = new AccountRegistry();
		$registry->register(
			$this->account(
				'sample',
				array(
					array(
						'key'      => 'k',
						'label'    => 'K',
						'type'     => 'password',
						'required' => true,
					),
				)
			)
		);

		$list = AccountUiList::build( $registry );

		$this->assertCount( 1, $list );
		$this->assertSame( 'sample', $list[0]['code'] );
		$this->assertSame( 'Sample', $list[0]['label'] );
		$this->assertSame( 'k', $list[0]['credentialsSchema'][0]['key'] );
	}

	public function test_isConfigured_true_when_all_required_fields_stored(): void {
		$store = array();
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static function ( $key, $default = '' ) use ( &$store ) {
				return $store[ $key ] ?? $default;
			}
		);
		WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);

		$account = $this->account(
			'configured',
			array(
				array(
					'key'      => 'access_key',
					'label'    => 'Access Key',
					'type'     => 'password',
					'required' => true,
				),
			)
		);

		\Affilicard\Account\AccountCredentials::patch(
			'configured',
			array( 'access_key' => 'stored-value' )
		);

		$registry = new AccountRegistry();
		$registry->register( $account );

		$list = AccountUiList::build( $registry );

		$this->assertTrue( $list[0]['isConfigured'] );
	}

	public function test_isConfigured_false_when_required_field_unset(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( '' );

		$registry = new AccountRegistry();
		$registry->register(
			$this->account(
				'unset',
				array(
					array(
						'key'      => 'access_key',
						'label'    => 'Access Key',
						'type'     => 'password',
						'required' => true,
					),
				)
			)
		);

		$list = AccountUiList::build( $registry );

		$this->assertFalse( $list[0]['isConfigured'] );
	}

	public function test_build_does_not_leak_stored_credential_values_into_payload(): void {
		$store = array();
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static function ( $key, $default = '' ) use ( &$store ) {
				return $store[ $key ] ?? $default;
			}
		);
		WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);

		$account = $this->account(
			'secret_test',
			array(
				array(
					'key'      => 'api_key',
					'label'    => 'API Key',
					'type'     => 'password',
					'required' => true,
				),
			)
		);

		// Store a distinctive sentinel value that should NOT appear in output.
		\Affilicard\Account\AccountCredentials::patch(
			'secret_test',
			array( 'api_key' => 'SENTINEL_SECRET_VALUE_XYZ' )
		);

		$registry = new AccountRegistry();
		$registry->register( $account );

		$list = AccountUiList::build( $registry );

		// The sentinel value must never appear anywhere in the payload.
		$encoded = json_encode( $list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$this->assertStringNotContainsString( 'SENTINEL_SECRET_VALUE_XYZ', $encoded );

		// Verify that build() DID read the stored credentials (isConfigured is true).
		// This ensures the negative assertion above is meaningful—build() read the secret
		// but correctly did not leak it.
		$this->assertTrue( $list[0]['isConfigured'] );
	}
}
