<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderCredentials;
use Affilicard\Provider\ProviderRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/affilicard/v1/platforms/{code}/credentials` および
 * `/affilicard/v1/platforms/{code}/test-connection` の実装。
 *
 * URL の {code} は platform code（dmm-books 等）。内部で platform → provider に解決する。
 */
final class CredentialsController {

	public function __construct( private ProviderRegistry $providers ) {}

	public function registerRoutes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/platforms/(?P<code>[a-z0-9-]+)/credentials',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/platforms/(?P<code>[a-z0-9-]+)/test-connection',
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

	public function get( WP_REST_Request $request ): WP_REST_Response {
		$platform_code = (string) $request->get_param( 'code' );
		$provider_code = $this->resolveProviderCode( $platform_code );
		if ( null === $provider_code ) {
			return $this->platformNotFound();
		}

		return new WP_REST_Response( ProviderCredentials::getMasked( $provider_code ), 200 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response {
		$platform_code = (string) $request->get_param( 'code' );
		$provider_code = $this->resolveProviderCode( $platform_code );
		if ( null === $provider_code ) {
			return $this->platformNotFound();
		}

		$params = $request->get_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		// `code` URL パラメータは除外（credentials 本体のみ patch）。
		unset( $params['code'] );

		$values = array();
		foreach ( $params as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			if ( null === $value ) {
				$values[ $key ] = null;
				continue;
			}
			$values[ $key ] = (string) $value;
		}

		ProviderCredentials::patch( $provider_code, $values );

		return new WP_REST_Response( ProviderCredentials::getMasked( $provider_code ), 200 );
	}

	public function testConnection( WP_REST_Request $request ): WP_REST_Response {
		$platform_code = (string) $request->get_param( 'code' );
		$platform      = PlatformConfig::find( $platform_code );
		if ( null === $platform ) {
			return $this->platformNotFound();
		}

		$provider = $this->providers->get( $platform->provider );
		if ( null === $provider ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( '対応する Provider が見つかりません。', 'affilicard' ),
				),
				404
			);
		}

		$credentials = ProviderCredentials::get( $platform->provider );

		$result = $provider->testConnection( $credentials );

		return new WP_REST_Response(
			array(
				'ok'      => (bool) ( $result['ok'] ?? false ),
				'message' => (string) ( $result['message'] ?? '' ),
			),
			200
		);
	}

	private function resolveProviderCode( string $platformCode ): ?string {
		$platform = PlatformConfig::find( $platformCode );
		if ( null === $platform ) {
			return null;
		}
		return $platform->provider;
	}

	private function platformNotFound(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code'    => 'affilicard_platform_not_found',
				'message' => __( '指定されたプラットフォームが見つかりません。', 'affilicard' ),
			),
			404
		);
	}
}
