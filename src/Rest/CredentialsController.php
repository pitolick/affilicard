<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\Account\AccountCredentials;
use Affilicard\Account\AccountRegistry;
use Affilicard\Provider\ProviderRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * 認証情報 REST。credentials は account 単位（GET/PUT/DELETE）、接続テストは provider 単位（POST）。
 */
final class CredentialsController {

	public function __construct(
		private ProviderRegistry $providers,
		private AccountRegistry $accounts
	) {}

	public function registerRoutes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/accounts/(?P<code>[a-z0-9-]+)/credentials',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'getAccount' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'updateAccount' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'deleteAccount' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/providers/(?P<code>[a-z0-9-]+)/test-connection',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'testConnection' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
			)
		);
	}

	public function canManageOptions(): bool {
		return (bool) current_user_can( 'manage_options' );
	}

	public function getAccount( WP_REST_Request $request ): WP_REST_Response {
		$account = $this->accounts->get( (string) $request->get_param( 'code' ) );
		if ( null === $account ) {
			return $this->accountNotFound();
		}
		return new WP_REST_Response( AccountCredentials::getStatusFor( $account ), 200 );
	}

	public function updateAccount( WP_REST_Request $request ): WP_REST_Response {
		$account = $this->accounts->get( (string) $request->get_param( 'code' ) );
		if ( null === $account ) {
			return $this->accountNotFound();
		}

		$values = $this->submittedValues( $request );

		// マージ後状態で required 検証。
		$merged  = array_merge( AccountCredentials::get( $account->code() ), $values );
		$missing = array();
		foreach ( $account->credentialsSchema() as $field ) {
			if ( ! empty( $field['required'] ) && '' === (string) ( $merged[ $field['key'] ] ?? '' ) ) {
				$missing[] = (string) $field['key'];
			}
		}
		if ( array() !== $missing ) {
			return new WP_REST_Response(
				array(
					'code'    => 'affilicard_missing_required',
					'message' => __( '必須項目が未入力です。', 'affilicard' ),
					'missing' => $missing,
				),
				400
			);
		}

		AccountCredentials::patch( $account->code(), $values );
		return new WP_REST_Response( AccountCredentials::getStatusFor( $account ), 200 );
	}

	public function deleteAccount( WP_REST_Request $request ): WP_REST_Response {
		$account = $this->accounts->get( (string) $request->get_param( 'code' ) );
		if ( null === $account ) {
			return $this->accountNotFound();
		}
		AccountCredentials::delete( $account->code() );
		return new WP_REST_Response( AccountCredentials::getStatusFor( $account ), 200 );
	}

	public function testConnection( WP_REST_Request $request ): WP_REST_Response {
		$provider = $this->providers->get( (string) $request->get_param( 'code' ) );
		if ( null === $provider ) {
			return $this->providerNotFound();
		}

		$accountCode = $provider->accountCode();
		$stored      = null === $accountCode ? array() : AccountCredentials::get( $accountCode );
		$merged      = array_merge( $stored, $this->submittedValues( $request ) );

		$result = $provider->testConnection( $merged );
		return new WP_REST_Response(
			array(
				'ok'      => (bool) ( $result['ok'] ?? false ),
				'message' => (string) ( $result['message'] ?? '' ),
			),
			200
		);
	}

	/**
	 * リクエスト body から credentials の文字列マップを取り出す（code は除外）。
	 *
	 * @return array<string, string>
	 */
	private function submittedValues( WP_REST_Request $request ): array {
		$params = $request->get_params();
		if ( ! is_array( $params ) ) {
			return array();
		}
		// 'code' は route の {code} 予約パラメータのため除外(同名の credential フィールドは扱わない)。
		unset( $params['code'] );
		$values = array();
		foreach ( $params as $key => $value ) {
			if ( is_string( $key ) && null !== $value ) {
				$values[ $key ] = (string) $value;
			}
		}
		return $values;
	}

	private function accountNotFound(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code'    => 'affilicard_account_not_found',
				'message' => __( '指定されたアカウントが見つかりません。', 'affilicard' ),
			),
			404
		);
	}

	private function providerNotFound(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code'    => 'affilicard_provider_not_found',
				'message' => __( '指定された Provider が見つかりません。', 'affilicard' ),
			),
			404
		);
	}
}
