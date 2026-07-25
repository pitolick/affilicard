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

	protected function performWork( array $args ): WorkOutcome {
		return $this->creator->create( (string) $args['platform'], (string) $args['external_id'] );
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

	/**
	 * 恒久失敗（TERMINAL_FAILURE＝fetch miss＝無効/該当なし ID）時に give-up マーカーを永続化する。
	 *
	 * externalId が恒久的に無効（AutoCreate が必ず失敗する ID）だと、Block::autoCreate は
	 * 5 分 transient ロックが切れるたびにビューで永久に再 enqueue し続ける。確定失敗を
	 * マーカーとして残し、Block::autoCreate 側でこれを見て再 enqueue を止める。
	 * option ではなく 24h TTL の transient にして、ID が後で有効化された場合に自動で再試行余地を残す。
	 *
	 * 一時失敗（TRANSIENT_FAILURE＝API 到達不可等）ではこのフックは呼ばれない＝give-up しない。
	 * 一時障害の externalId を恒久失敗として封印してしまわないための肝。
	 *
	 * @param array<string, mixed> $args
	 */
	protected function onTerminalFailure( array $args ): void {
		$platform   = isset( $args['platform'] ) ? (string) $args['platform'] : '';
		$externalId = isset( $args['external_id'] ) ? (string) $args['external_id'] : '';
		if ( '' === $platform || '' === $externalId ) {
			return;
		}
		set_transient( 'affilicard_autocreate_failed_' . $platform . '_' . $externalId, 1, DAY_IN_SECONDS );
	}

	protected function throttleWaitKey( array $args ): string {
		return 'affilicard_throttle_waits_' . $args['platform'] . '_' . $args['external_id'];
	}
}
