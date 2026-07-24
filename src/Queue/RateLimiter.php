<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * account（認証情報の共有単位。楽天/DMM 等）別のクロスプロセス throttle。直近呼び出し
 * 時刻(ms)を option に記録し、ハンドラは「間隔未経過なら fetch せず後ろ倒し」に使う
 * （AS ランナーを sleep で塞がない）。v2.4.0: レート制限は provider ではなく共有 API＝
 * account 単位でかかるため、キーを provider コードから account コードへ統一した。
 */
final class RateLimiter {

	private function optionKey( string $account ): string {
		return 'affilicard_ratelimit_' . $account;
	}

	public function effectiveIntervalMs( int $floorMs, int $overrideMs ): int {
		return $overrideMs > 0 ? max( $floorMs, $overrideMs ) : $floorMs;
	}

	/**
	 * @return array{ok: bool, next_ms: int}
	 */
	public function tryAcquire( string $account, int $intervalMs, int $nowMs ): array {
		$key  = $this->optionKey( $account );
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
