<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Provider\ProviderRegistry;
use Affilicard\Settings\GeneralSettings;

/**
 * throttle 付き AS ハンドラの共通骨格。
 * pause ゲート → provider 解決 → RateLimiter（未経過なら本処理せず後ろ倒し）→ performWork → 失敗は backoff 再投入。
 * サブクラスは providerCodeFor / performWork / reschedule / attemptKey を実装する。
 */
abstract class ThrottledActionHandler {

	protected const MAX_ATTEMPTS = 5;

	public function __construct(
		protected RateLimiter $limiter,
		protected ProviderRegistry $registry
	) {}

	/** args から provider コードを解決（不明なら null）。 */
	abstract protected function providerCodeFor( array $args ): ?string;

	/** 本処理（fetch/create）。成功で true。 */
	abstract protected function performWork( array $args ): bool;

	/** 自分自身を $whenSec に再投入（unique=false）。 */
	abstract protected function reschedule( int $whenSec, array $args ): void;

	/** backoff 試行回数を記録する transient キー。 */
	abstract protected function attemptKey( array $args ): string;

	/**
	 * @param array<string, mixed> $args
	 */
	protected function run( array $args ): void {
		if ( GeneralSettings::isQueuePaused() ) {
			return;
		}
		$providerCode = $this->providerCodeFor( $args );
		if ( null === $providerCode ) {
			return;
		}
		$provider = $this->registry->get( $providerCode );
		if ( null === $provider || ! $provider->isAutomatic() ) {
			return;
		}

		$interval = $this->limiter->effectiveIntervalMs(
			$provider->minRequestIntervalMs(),
			GeneralSettings::throttleOverrideMs( $providerCode )
		);
		$nowMs    = (int) round( microtime( true ) * 1000 );
		$acquire  = $this->limiter->tryAcquire( $providerCode, $interval, $nowMs );
		if ( ! $acquire['ok'] ) {
			$this->reschedule( (int) ceil( $acquire['next_ms'] / 1000 ), $args );
			return;
		}

		if ( $this->performWork( $args ) ) {
			delete_transient( $this->attemptKey( $args ) );
			return;
		}
		$this->backoff( $args );
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private function backoff( array $args ): void {
		$key      = $this->attemptKey( $args );
		$attempts = (int) get_transient( $key ) + 1;
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			delete_transient( $key );
			return; // 打ち切り（failed）。fetch_error は listing に記録済み・Fallback 列で可視化。
		}
		set_transient( $key, $attempts, DAY_IN_SECONDS );
		$delay = min( 3600, (int) pow( 2, $attempts ) * 60 ); // 指数バックオフ・上限 1h クランプ
		$this->reschedule( time() + $delay, $args );
	}
}
