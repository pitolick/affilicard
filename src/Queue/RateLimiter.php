<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * provider 別のクロスプロセス throttle。直近呼び出し時刻(ms)を option に記録し、
 * ハンドラは「間隔未経過なら fetch せず後ろ倒し」に使う（AS ランナーを sleep で塞がない）。
 */
final class RateLimiter {

	private function optionKey( string $provider ): string {
		return 'affilicard_ratelimit_' . $provider;
	}

	public function effectiveIntervalMs( int $floorMs, int $overrideMs ): int {
		return $overrideMs > 0 ? max( $floorMs, $overrideMs ) : $floorMs;
	}

	/**
	 * @return array{ok: bool, next_ms: int}
	 */
	public function tryAcquire( string $provider, int $intervalMs, int $nowMs ): array {
		$key  = $this->optionKey( $provider );
		$last = (int) get_option( $key, 0 );
		if ( $nowMs - $last >= $intervalMs ) {
			update_option( $key, $nowMs, false );
			return array(
				'ok'      => true,
				'next_ms' => $nowMs,
			);
		}
		return array(
			'ok'      => false,
			'next_ms' => $last + $intervalMs,
		);
	}
}
