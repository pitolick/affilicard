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
	 * get_option()→update_option() の read-then-write は非原子的で、2 つの AS ワーカーが
	 * 同時に同じ account/interval の枠を獲得できてしまう（レース）。ここでは option テーブルへの
	 * 条件付き UPDATE（compare-and-set）1 文で「現在値が閾値以下のときだけ更新」を行い、
	 * 影響行数（0 or 1）で獲得の成否を判定する。影響行数=1 のワーカーだけが本処理へ進める。
	 *
	 * @return array{ok: bool, next_ms: int}
	 */
	public function tryAcquire( string $account, int $intervalMs, int $nowMs ): array {
		global $wpdb;

		$key = $this->optionKey( $account );

		// option 行が無いと UPDATE は 0 行しかヒットしないため先にシードする
		// （既に存在する場合は add_option が no-op を返すだけで安全）。
		add_option( $key, '0', '', false );

		$threshold = $nowMs - $intervalMs;
		$updated   = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %d WHERE option_name = %s AND CAST(option_value AS UNSIGNED) <= %d",
				$nowMs,
				$key,
				$threshold
			)
		);
		// 生 SQL で option を書き換えたため、get_option() のオブジェクトキャッシュを
		// 破棄しないと以降の読み出しが古い値を返し続ける。
		wp_cache_delete( $key, 'options' );

		if ( 1 === $updated ) {
			return array(
				'ok'      => true,
				'next_ms' => $nowMs,
			);
		}

		$last = (int) get_option( $key, 0 );
		return array(
			'ok'      => false,
			'next_ms' => $last + $intervalMs,
		);
	}
}
