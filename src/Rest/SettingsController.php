<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\Settings\GeneralSettings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/affilicard/v1/settings` エンドポイントの実装。
 *
 * `manage_options` capability を要求する。
 */
final class SettingsController {

	public function registerRoutes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/settings',
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
	}

	public function canManageOptions(): bool {
		return (bool) current_user_can( 'manage_options' );
	}

	public function get( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( GeneralSettings::get(), 200 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$updated = GeneralSettings::update( $params );
		return new WP_REST_Response( $updated, 200 );
	}
}
