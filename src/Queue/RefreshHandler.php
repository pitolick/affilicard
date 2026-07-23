<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderRegistry;

/**
 * affilicard_refresh_listing アクションのハンドラ。ThrottledActionHandler の骨格に
 * 「refreshOne で fetch＋反映」を差し込む。
 */
final class RefreshHandler extends ThrottledActionHandler {

	public function __construct(
		private Enqueuer $enqueuer,
		RateLimiter $limiter,
		private ListingRefresher $refresher,
		ProviderRegistry $registry
	) {
		parent::__construct( $limiter, $registry );
	}

	public function handle( int $postId, string $platform ): void {
		$this->run(
			array(
				'post_id'  => $postId,
				'platform' => $platform,
			)
		);
	}

	protected function providerCodeFor( array $args ): ?string {
		$definition = PlatformConfig::find( (string) $args['platform'] );
		return null !== $definition ? $definition->provider : null;
	}

	protected function performWork( array $args ): bool {
		return $this->refresher->refreshOne( (int) $args['post_id'], (string) $args['platform'] );
	}

	protected function reschedule( int $whenSec, array $args ): void {
		$providerCode = $this->providerCodeFor( $args );
		if ( null !== $providerCode ) {
			$this->enqueuer->rescheduleRefresh( $whenSec, (int) $args['post_id'], (string) $args['platform'], $providerCode );
		}
	}

	protected function attemptKey( array $args ): string {
		return 'affilicard_refresh_attempts_' . $args['post_id'] . '_' . $args['platform'];
	}
}
