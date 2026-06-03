<?php
declare(strict_types=1);

namespace Affilicard\Rest;

/**
 * REST API ルートの集約登録。
 *
 * 各サブコントローラの registerRoutes($namespace) を呼び出すだけで、
 * 個別のロジックはそれぞれに委譲する。
 */
final class RestController {

	public const NAMESPACE = 'affilicard/v1';

	public function __construct(
		private ProductsController $products,
		private SettingsController $settings,
		private PlatformsController $platforms,
		private CredentialsController $credentials,
		private RefreshController $refresh
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		$this->products->registerRoutes( self::NAMESPACE );
		$this->settings->registerRoutes( self::NAMESPACE );
		$this->platforms->registerRoutes( self::NAMESPACE );
		$this->credentials->registerRoutes( self::NAMESPACE );
		$this->refresh->registerRoutes( self::NAMESPACE );
	}
}
