<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Account;

use Affilicard\Account\AccountCredentials;
use Affilicard\Account\AccountInterface;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class AccountCredentialsTest extends TestCase {

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

	private function account( array $schema ): AccountInterface {
		return new class( $schema ) implements AccountInterface {
			public function __construct( private array $schema ) {}
			public function code(): string {
				return 'sample';
			}
			public function label(): string {
				return 'Sample';
			}
			public function credentialsSchema(): array {
				return $this->schema;
			}
		};
	}

	public function test_option_key_uses_account_prefix(): void {
		$this->assertSame(
			'affilicard_account_rakuten_credentials',
			AccountCredentials::optionKey( 'rakuten' )
		);
	}

	public function test_patch_then_get_status_for_roundtrip(): void {
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
			array(
				array(
					'key'      => 'pub',
					'label'    => 'Pub',
					'type'     => 'text',
					'required' => true,
				),
				array(
					'key'      => 'sec',
					'label'    => 'Sec',
					'type'     => 'password',
					'required' => true,
				),
			)
		);

		AccountCredentials::patch(
			'sample',
			array(
				'pub' => 'api_pub',
				'sec' => 'secret123',
			)
		);

		$status = AccountCredentials::getStatusFor( $account );
		$this->assertSame( 'api_pub', $status['pub']['value'] );   // text は実値
		$this->assertTrue( $status['pub']['isSet'] );
		$this->assertSame( '', $status['sec']['value'] );          // password は withhold
		$this->assertTrue( $status['sec']['isSet'] );
	}
}
