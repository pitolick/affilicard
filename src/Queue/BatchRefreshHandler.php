<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Settings\GeneralSettings;

/**
 * affilicard_refresh_batch のハンドラ。
 *
 * 1 ジョブが複数 listing を担当し、ジョブ内でレート間隔を守りながら順次 fetch する。
 * 枠が取れないときは「待たずに再投入」ではなく「待つ」——これが per-listing 方式で
 * 空振り 90% を生んでいた原因（spec §2-1）。待ち時間は 1 秒前後と短く、待たない
 * コストの方が桁違いに大きいことが実測で判明している。
 *
 * 失敗した listing だけを既存の per-listing ジョブへ落とし、backoff / give-up /
 * failed 可視化という異常系の機構をそのまま活かす（spec §4-1）。
 */
final class BatchRefreshHandler {

	/** pause 中に温存したジョブを再チェックするまでの待機秒数（10分。ThrottledActionHandler と同じ）。 */
	private const PAUSE_RETRY_SECONDS = 600;

	/**
	 * 1 件あたりの Provider HTTP タイムアウト実測値（秒）。DmmProvider.php・RakutenClient.php
	 * とも timeout => 10 を設定済み。1 件の最悪所要時間は「レート待ち + この値」として
	 * 期限判定に用いる（spec §4-1）。
	 */
	private const PROVIDER_TIMEOUT_SECONDS = 10;

	public function __construct(
		private Enqueuer $enqueuer,
		private RateLimiter $limiter,
		private ListingRefresher $refresher,
		private ProviderRegistry $registry,
		private int $timeLimitSeconds = 30,
		private int $safetyMarginSeconds = 5
	) {}

	/**
	 * @param array{account?: string, items?: list<array{post_id:int, platform:string}>} $args
	 */
	public function handle( array $args ): void {
		$account = isset( $args['account'] ) ? (string) $args['account'] : '';
		$items   = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
		if ( '' === $account || array() === $items ) {
			return;
		}

		// pause 中はジョブを失わずに温存する（bare return だと AS が complete 扱いにしてジョブが消滅する）。
		if ( GeneralSettings::isQueuePaused() ) {
			$this->enqueuer->enqueueBatch( $account, $items, time() + self::PAUSE_RETRY_SECONDS );
			return;
		}

		$deadline    = new JobDeadline( time(), $this->timeLimitSeconds, $this->safetyMarginSeconds );
		$intervalMs  = $this->intervalMsFor( $account );
		$intervalSec = (int) ceil( $intervalMs / 1000 );
		// 1 件あたりの最悪所要 = レート待ち + Provider の HTTP タイムアウト（DMM/楽天とも 10 秒）。
		$perItemSeconds = $intervalSec + self::PROVIDER_TIMEOUT_SECONDS;

		foreach ( $items as $index => $item ) {
			$postId   = isset( $item['post_id'] ) ? (int) $item['post_id'] : 0;
			$platform = isset( $item['platform'] ) ? (string) $item['platform'] : '';
			if ( 0 === $postId || '' === $platform ) {
				continue;
			}

			// 待機に入る「前に」期限を確認する。賄えないなら未処理分を積み直して終了する
			// （待機に入ってから期限を超えると AS ランナーの時間予算を食い潰したうえ、
			// 積み直しも行われずそのバッチの残りが失われる）。
			if ( ! $deadline->canAfford( time(), $perItemSeconds ) ) {
				$this->requeueRemaining( $account, $items, $index );
				return;
			}

			$nowMs   = (int) round( microtime( true ) * 1000 );
			$acquire = $this->limiter->tryAcquire( $account, $intervalMs, $nowMs );
			if ( ! $acquire['ok'] ) {
				$waitSec = max( 0, (int) ceil( $acquire['next_ms'] / 1000 ) - time() );
				// 待機秒を残り時間でクランプする。クランプに掛かった（＝待つと期限を超える）場合は
				// 待たずに未処理分（この listing を含む）を積み直して終了する。
				$waitSec = $deadline->clampWait( time(), $waitSec );
				if ( 0 === $waitSec && ! $deadline->canAfford( time(), $perItemSeconds ) ) {
					$this->requeueRemaining( $account, $items, $index );
					return;
				}
				if ( $waitSec > 0 ) {
					usleep( $waitSec * 1000000 );
				}
				$nowMs   = (int) round( microtime( true ) * 1000 );
				$acquire = $this->limiter->tryAcquire( $account, $intervalMs, $nowMs );
				if ( ! $acquire['ok'] ) {
					// 他ワーカーに取られた。この listing は per-listing へ委ねる。
					$this->enqueuer->enqueueManual( $postId, $platform, $account );
					continue;
				}
			}

			$outcome = $this->refresher->refreshOne( $postId, $platform );
			if ( WorkOutcome::TRANSIENT_FAILURE === $outcome ) {
				// 一時失敗は per-listing へ落とし、既存の backoff / failed 可視化に委ねる。
				$this->enqueuer->enqueueManual( $postId, $platform, $account );
				continue;
			}
			if ( WorkOutcome::TERMINAL_FAILURE === $outcome ) {
				// 恒久失敗は give-up マーカーを立てて次へ（per-listing へは落とさない）。
				set_transient(
					RefreshHandler::giveUpTransientKey( $postId, $platform ),
					1,
					3 * DAY_IN_SECONDS
				);
				continue;
			}
			// SUCCESS: give-up マーカーを解除する（復旧した listing を通常周期に戻す）。
			delete_transient( RefreshHandler::giveUpTransientKey( $postId, $platform ) );
		}
	}

	/**
	 * $fromIndex 以降の未処理分を新しいバッチジョブとして積み直す。
	 *
	 * @param list<array{post_id:int, platform:string}> $items
	 */
	private function requeueRemaining( string $account, array $items, int $fromIndex ): void {
		$remaining = array_slice( $items, $fromIndex );
		if ( array() === $remaining ) {
			return;
		}
		$this->enqueuer->enqueueBatch( $account, $remaining, time() );
	}

	private function intervalMsFor( string $account ): int {
		$floorMs = 0;
		foreach ( $this->registry->all() as $provider ) {
			if ( $provider->accountCode() === $account ) {
				$floorMs = $provider->minRequestIntervalMs();
				break;
			}
		}
		return $this->limiter->effectiveIntervalMs( $floorMs, GeneralSettings::throttleOverrideMs( $account ) );
	}
}
