<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\AutoCreate\ProductAutoCreator;
use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderRegistry;

/**
 * affilicard_autocreate アクションのハンドラ。ThrottledActionHandler の骨格に
 * 「ProductAutoCreator::create で商品生成」を差し込む（AutoCreate の AS 非同期化・§3-6）。
 */
final class AutoCreateHandler extends ThrottledActionHandler {

	public function __construct(
		private Enqueuer $enqueuer,
		RateLimiter $limiter,
		private ProductAutoCreator $creator,
		ProviderRegistry $registry
	) {
		parent::__construct( $limiter, $registry );
	}

	public function handle( string $platform, string $externalId ): void {
		$this->run(
			array(
				'platform'    => $platform,
				'external_id' => $externalId,
			)
		);
	}

	protected function providerCodeFor( array $args ): ?string {
		$definition = PlatformConfig::find( (string) $args['platform'] );
		return null !== $definition ? $definition->provider : null;
	}

	protected function performWork( array $args ): bool {
		return null !== $this->creator->create( (string) $args['platform'], (string) $args['external_id'] );
	}

	protected function reschedule( int $whenSec, array $args ): void {
		$providerCode = $this->providerCodeFor( $args );
		if ( null === $providerCode ) {
			return;
		}
		// v2.4.0: 再投入の group も account コード単位（run() の throttle キーと揃える）。
		$account = $this->registry->get( $providerCode )?->accountCode() ?? $providerCode;
		$this->enqueuer->rescheduleAutoCreate( $whenSec, (string) $args['platform'], $account, (string) $args['external_id'] );
	}

	protected function attemptKey( array $args ): string {
		return 'affilicard_autocreate_attempts_' . $args['platform'] . '_' . $args['external_id'];
	}

	protected function throttleWaitKey( array $args ): string {
		return 'affilicard_throttle_waits_' . $args['platform'] . '_' . $args['external_id'];
	}
}
