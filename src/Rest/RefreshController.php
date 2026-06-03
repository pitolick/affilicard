<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\Cron\ListingRefresher;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/affilicard/v1/refresh` — 価格更新の手動トリガー（manage_options）。
 *
 * platform 未指定なら全公開商品、指定なら該当 platform の listing のみ refresh する。
 * force=true なら auto_update=false の listing も更新する。
 */
final class RefreshController {

	public function __construct( private ListingRefresher $refresher ) {}

	public function registerRoutes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/refresh',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
			)
		);
	}

	public function canManageOptions(): bool {
		return (bool) current_user_can( 'manage_options' );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$platform = (string) $request->get_param( 'platform' );
		$force    = (bool) $request->get_param( 'force' );
		if ( '' === $platform ) {
			$this->refresher->run( $force );
			$scope = 'all';
		} else {
			$this->refresher->runForPlatform( $platform, $force );
			$scope = $platform;
		}
		return new WP_REST_Response(
			array(
				'ok'    => true,
				'scope' => $scope,
				'force' => $force,
			),
			200
		);
	}
}
