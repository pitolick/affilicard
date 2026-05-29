<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\Settings\PlatformsSettings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/affilicard/v1/platforms` エンドポイントの実装。
 *
 * `manage_options` capability を要求する。
 */
final class PlatformsController {

	public function registerRoutes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/platforms',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list' ),
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

	public function list( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( PlatformsSettings::all(), 200 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response {
		$raw = $request->get_param( 'platforms' );
		if ( ! is_array( $raw ) ) {
			// 値が直接配列で渡されたケースにも対応する。
			$params = $request->get_params();
			$raw    = isset( $params['platforms'] ) && is_array( $params['platforms'] ) ? $params['platforms'] : array();
		}

		$updated = PlatformsSettings::update( $raw );
		return new WP_REST_Response( $updated, 200 );
	}
}
