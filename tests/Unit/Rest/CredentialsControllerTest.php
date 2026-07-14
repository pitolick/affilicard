<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Account\AccountCredentials;
use Affilicard\Account\AccountInterface;
use Affilicard\Account\AccountRegistry;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Rest\CredentialsController;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;

/**
 * CredentialsController のテスト。
 *
 * credentials は account 単位（GET/PUT/DELETE `/accounts/{code}/credentials`）、
 * 接続テストは provider 単位（POST `/providers/{code}/test-connection`）。
 *
 * get_option/update_option/delete_option をインメモリ store で mock し、
 * AccountCredentials 経由のラウンドトリップを検証する。
 */
final class CredentialsControllerTest extends TestCase {

	/** @var array<string, string> */
	private array $store = array();

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$this->store = array();

		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $t ) => $t );
		WP_Mock::userFunction( 'wp_salt' )->andReturn( 'test-salt' );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing(
			static function ( $value ) {
				return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
		);
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );

		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( $key, $default = '' ) {
				return $this->store[ $key ] ?? $default;
			}
		);
		WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $key, $value, $autoload = null ) {
				$this->store[ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'delete_option' )->andReturnUsing(
			function ( $key ) {
				unset( $this->store[ $key ] );
				return true;
			}
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * `sample` account: text 必須 pub ＋ password 必須 sec の 1 account のみを持つ registry。
	 */
	private function accounts(): AccountRegistry {
		$reg = new AccountRegistry();
		$reg->register(
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
					);
				}
			}
		);
		return $reg;
	}

	/**
	 * `sample` account の credentials を引く fake provider（code 'sample-provider'）を 1 件持つ registry。
	 * testConnection は受け取った credentials の pub/sec をそのまま message に埋め込んで返す
	 * ので、merge の結果をテストで検証できる。
	 */
	private function providersWithFakeBoundToSample(): ProviderRegistry {
		$reg = new ProviderRegistry();
		$reg->register(
			new class() implements ProviderInterface {
				public function code(): string {
					return 'sample-provider';
				}
				public function label(): string {
					return 'Sample Provider';
				}
				public function isAutomatic(): bool {
					return true;
				}
				public function accountCode(): ?string {
					return 'sample';
				}
				public function fetch( string $externalId, array $platformConfig ): ?array {
					return null;
				}
				public function testConnection( array $credentials ): array {
					return array(
						'ok'      => true,
						'message' => 'pub=' . ( $credentials['pub'] ?? '' ) . ';sec=' . ( $credentials['sec'] ?? '' ),
					);
				}
			}
		);
		return $reg;
	}

	/**
	 * account に紐付かない（accountCode が null）fake provider を持つ registry。
	 */
	private function providersWithFakeWithoutAccount(): ProviderRegistry {
		$reg = new ProviderRegistry();
		$reg->register(
			new class() implements ProviderInterface {
				public function code(): string {
					return 'no-account-provider';
				}
				public function label(): string {
					return 'No Account Provider';
				}
				public function isAutomatic(): bool {
					return true;
				}
				public function accountCode(): ?string {
					return null;
				}
				public function fetch( string $externalId, array $platformConfig ): ?array {
					return null;
				}
				public function testConnection( array $credentials ): array {
					return array(
						'ok'      => true,
						'message' => 'keys=' . implode( ',', array_keys( $credentials ) ),
					);
				}
			}
		);
		return $reg;
	}

	/**
	 * Mockery で WP_REST_Request を組み立てる。$params には 'code'（URL パラメータ）と
	 * body フィールドを両方含める。
	 *
	 * @param array<string, mixed> $params
	 */
	private function request( array $params ): WP_REST_Request {
		$request = Mockery::mock( WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static function ( $key ) use ( $params ) {
				return $params[ $key ] ?? null;
			}
		);
		$request->shouldReceive( 'get_params' )->andReturn( $params );
		return $request;
	}

	// ─── GET /accounts/{code}/credentials ──────────────────────────────────

	/**
	 * 保存済み credentials を type-aware（text は実値・password は isSet のみ）に返す。
	 */
	public function test_getAccount_returns_type_aware_status_for_known_account(): void {
		AccountCredentials::patch(
			'sample',
			array(
				'pub' => 'PUBVAL',
				'sec' => 'SECVAL',
			)
		);

		$controller = new CredentialsController( new ProviderRegistry(), $this->accounts() );
		$response   = $controller->getAccount( $this->request( array( 'code' => 'sample' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		// text はそのまま実値、password は value を返さず isSet のみ。
		$this->assertSame( 'PUBVAL', $data['pub']['value'] );
		$this->assertTrue( $data['pub']['isSet'] );
		$this->assertSame( '', $data['sec']['value'] );
		$this->assertTrue( $data['sec']['isSet'] );
	}

	public function test_getAccount_returns_404_for_unknown_account(): void {
		$controller = new CredentialsController( new ProviderRegistry(), $this->accounts() );
		$response   = $controller->getAccount( $this->request( array( 'code' => 'unknown' ) ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'affilicard_account_not_found', $response->get_data()['code'] );
	}

	// ─── PUT /accounts/{code}/credentials ──────────────────────────────────

	/**
	 * マージ後（stored ＋ 送信値）の状態で必須検証し、欠けていれば 400 を返す。
	 */
	public function test_updateAccount_returns_400_when_required_missing_after_merge(): void {
		$controller = new CredentialsController( new ProviderRegistry(), $this->accounts() );
		$response   = $controller->updateAccount(
			$this->request(
				array(
					'code' => 'sample',
					'pub'  => 'x',
					// sec 欠。
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'affilicard_missing_required', $data['code'] );
		$this->assertContains( 'sec', $data['missing'] );
	}

	public function test_updateAccount_success_patches_and_returns_status(): void {
		$controller = new CredentialsController( new ProviderRegistry(), $this->accounts() );
		$response   = $controller->updateAccount(
			$this->request(
				array(
					'code' => 'sample',
					'pub'  => 'PUBVAL',
					'sec'  => 'SECVAL',
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'PUBVAL', $data['pub']['value'] );
		$this->assertTrue( $data['pub']['isSet'] );
		$this->assertTrue( $data['sec']['isSet'] );

		// 実際に永続化されていることを AccountCredentials 経由でも確認する。
		$this->assertSame(
			array(
				'pub' => 'PUBVAL',
				'sec' => 'SECVAL',
			),
			AccountCredentials::get( 'sample' )
		);
	}

	public function test_updateAccount_editing_one_field_does_not_400_when_other_required_already_stored(): void {
		// sec は既に保存済み。今回は pub だけを編集する。
		AccountCredentials::patch(
			'sample',
			array(
				'pub' => 'OLD_PUB',
				'sec' => 'STORED_SEC',
			)
		);

		$controller = new CredentialsController( new ProviderRegistry(), $this->accounts() );
		$response   = $controller->updateAccount(
			$this->request(
				array(
					'code' => 'sample',
					'pub'  => 'NEW_PUB',
					// sec は body に含めない。
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'NEW_PUB', $data['pub']['value'] );
		$this->assertTrue( $data['sec']['isSet'] );

		$this->assertSame(
			array(
				'pub' => 'NEW_PUB',
				'sec' => 'STORED_SEC',
			),
			AccountCredentials::get( 'sample' )
		);
	}

	public function test_updateAccount_returns_404_for_unknown_account(): void {
		$controller = new CredentialsController( new ProviderRegistry(), $this->accounts() );
		$response   = $controller->updateAccount(
			$this->request(
				array(
					'code' => 'unknown',
					'pub'  => 'x',
					'sec'  => 'y',
				)
			)
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'affilicard_account_not_found', $response->get_data()['code'] );
	}

	// ─── DELETE /accounts/{code}/credentials ───────────────────────────────

	/**
	 * DELETE で credentials を消去できる。
	 */
	public function test_deleteAccount_removes_credentials(): void {
		AccountCredentials::patch(
			'sample',
			array(
				'pub' => 'PUBVAL',
				'sec' => 'SECVAL',
			)
		);

		$controller = new CredentialsController( new ProviderRegistry(), $this->accounts() );
		$response   = $controller->deleteAccount( $this->request( array( 'code' => 'sample' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['pub']['isSet'] );
		$this->assertFalse( $data['sec']['isSet'] );

		$this->assertSame( array(), AccountCredentials::get( 'sample' ) );
	}

	public function test_deleteAccount_returns_404_for_unknown_account(): void {
		$controller = new CredentialsController( new ProviderRegistry(), $this->accounts() );
		$response   = $controller->deleteAccount( $this->request( array( 'code' => 'unknown' ) ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'affilicard_account_not_found', $response->get_data()['code'] );
	}

	// ─── POST /providers/{code}/test-connection ────────────────────────────

	/**
	 * 未登録 provider への test-connection は 404 を返す。
	 */
	public function test_testConnection_returns_404_for_unknown_provider(): void {
		$controller = new CredentialsController( new ProviderRegistry(), $this->accounts() );
		$response   = $controller->testConnection( $this->request( array( 'code' => 'unknown-provider' ) ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'affilicard_provider_not_found', $response->get_data()['code'] );
	}

	public function test_testConnection_merges_submitted_body_over_stored_account_credentials(): void {
		// account には pub/sec 両方が保存済み。
		AccountCredentials::patch(
			'sample',
			array(
				'pub' => 'stored-pub',
				'sec' => 'stored-sec',
			)
		);

		$controller = new CredentialsController( $this->providersWithFakeBoundToSample(), $this->accounts() );
		// body では pub だけ上書きする。sec は保存済みの値が使われるはず。
		$response = $controller->testConnection(
			$this->request(
				array(
					'code' => 'sample-provider',
					'pub'  => 'override-pub',
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'pub=override-pub;sec=stored-sec', $data['message'] );
	}

	public function test_testConnection_provider_without_account_uses_only_submitted_values(): void {
		$controller = new CredentialsController( $this->providersWithFakeWithoutAccount(), $this->accounts() );
		$response   = $controller->testConnection(
			$this->request(
				array(
					'code'  => 'no-account-provider',
					'token' => 'abc',
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'keys=token', $data['message'] );
	}
}
