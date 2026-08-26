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
			$this->requeueOrFallback( $account, $items, time() + self::PAUSE_RETRY_SECONDS );
			return;
		}

		$deadline    = new JobDeadline( time(), $this->timeLimitSeconds, $this->safetyMarginSeconds );
		$intervalMs  = $this->intervalMsFor( $account );
		$intervalSec = (int) ceil( $intervalMs / 1000 );
		// 1 件あたりの最悪所要 = レート待ち + Provider の HTTP タイムアウト（DMM/楽天とも 10 秒）。
		$perItemSeconds = $intervalSec + self::PROVIDER_TIMEOUT_SECONDS;

		// 前進保証（spec §4-1 Ruling 6）: このジョブでまだ 1 件も処理していない状態で
		// perItemSeconds が期限枠（timeLimitSeconds - safetyMarginSeconds）を超えていると、
		// 毎回 index 0 で期限チェックに弾かれ「1件も処理せず積み直す」を無限に繰り返す
		// （throttle_overrides に上限が無く、管理画面から容易に踏める）。まだ何も処理して
		// いない最初の 1 件だけは、期限を賄えなくても強制的に試みる。最悪 perItemSeconds
		// ぶん time limit を超えるが、AS は次のバッチで回復するため「1 件も処理できない」
		// より無害。2 件目以降は通常どおり期限で弾く。
		$processedAny = false;

		foreach ( $items as $index => $item ) {
			$postId   = isset( $item['post_id'] ) ? (int) $item['post_id'] : 0;
			$platform = isset( $item['platform'] ) ? (string) $item['platform'] : '';
			if ( 0 === $postId || '' === $platform ) {
				continue;
			}

			$mustAttempt = ! $processedAny;

			// 待機に入る「前に」期限を確認する。賄えないなら未処理分を積み直して終了する
			// （待機に入ってから期限を超えると AS ランナーの時間予算を食い潰したうえ、
			// 積み直しも行われずそのバッチの残りが失われる）。前進保証が働く 1 件目は
			// このゲートを素通りする。
			if ( ! $mustAttempt && ! $deadline->canAfford( time(), $perItemSeconds ) ) {
				$this->requeueRemaining( $account, $items, $index );
				return;
			}

			$processedAny = true;

			$nowMs   = (int) round( microtime( true ) * 1000 );
			$acquire = $this->limiter->tryAcquire( $account, $intervalMs, $nowMs );
			if ( ! $acquire['ok'] ) {
				$rawWaitSec = max( 0, (int) ceil( $acquire['next_ms'] / 1000 ) - time() );
				// 待機秒を残り時間でクランプする。要求した待機がクランプで減った
				// （＝そのまま待つと期限を超える）場合は、待たずに未処理分（この listing を
				// 含む）を積み直して終了する（spec §4-1）。前進保証が働く 1 件目は例外的に
				// 待つ（最悪 time limit を超えるが、AS が次のバッチで回復するため
				// 「1件も処理できない」よりまし）。
				$waitSec = $deadline->clampWait( time(), $rawWaitSec );
				if ( $waitSec < $rawWaitSec && ! $mustAttempt ) {
					$this->requeueRemaining( $account, $items, $index );
					return;
				}
				if ( $waitSec > 0 ) {
					usleep( $waitSec * 1000000 );
				}
				// 待った後に期限が賄えるか再確認する（usleep で消費した実時間を反映する）。
				if ( ! $mustAttempt && ! $deadline->canAfford( time(), $perItemSeconds ) ) {
					$this->requeueRemaining( $account, $items, $index );
					return;
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
	 * $fromIndex 以降の未処理分を新しいバッチジョブとして積み直す。jitter を付けて
	 * 同一 account の複数バッチが同一タイムスタンプへ再集結する thundering herd を避ける
	 * （Enqueuer::RESCHEDULE_JITTER_SECONDS と同じ考え方。spec §4-1 Ruling 6）。
	 *
	 * @param list<array{post_id:int, platform:string}> $items
	 */
	private function requeueRemaining( string $account, array $items, int $fromIndex ): void {
		$remaining = array_slice( $items, $fromIndex );
		$when      = time() + wp_rand( 0, Enqueuer::RESCHEDULE_JITTER_SECONDS );
		$this->requeueOrFallback( $account, $remaining, $when );
	}

	/**
	 * 自己再投入・積み直し専用の enqueueBatch ラッパー。
	 *
	 * unique=false で積む（spec §4-1 Ruling 4）。AS の unique=true は PENDING/RUNNING
	 * 双方に対して hook+group+args(JSON) の完全一致で挿入を抑止する。自己再投入・積み直しは
	 * 実行中の自分自身と account・items が一致するため、unique=true のままだと必ず抑止され
	 * 戻り値 0（未投入）のままジョブが痕跡なく消滅する。
	 *
	 * 0（投入失敗）が返った場合は握り潰さずログに残し（spec §4-3「投入失敗はログに残す」）、
	 * 対象 listing を per-listing（enqueueManual）へ個別にフォールバックする。バッチとしての
	 * 再投入に失敗しても listing 自体は失わない（spec §4-1 Ruling 4/5 レビュー対応）。
	 *
	 * @param list<array{post_id:int, platform:string}> $items
	 */
	private function requeueOrFallback( string $account, array $items, int $when ): void {
		if ( array() === $items ) {
			return;
		}

		$actionId = $this->enqueuer->enqueueBatch( $account, $items, $when, false );
		if ( 0 !== $actionId ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- バッチ再投入の失敗は AS 側で完全に不可視（run は緑のまま）になるため、運用が気づけるようログに残す（spec §4-3）。
		error_log(
			sprintf(
				'affilicard: バッチジョブの再投入に失敗しました（account=%s, items=%d件）。per-listing へ個別にフォールバックします。',
				$account,
				count( $items )
			)
		);

		foreach ( $items as $item ) {
			$postId   = isset( $item['post_id'] ) ? (int) $item['post_id'] : 0;
			$platform = isset( $item['platform'] ) ? (string) $item['platform'] : '';
			if ( 0 === $postId || '' === $platform ) {
				continue;
			}
			$this->enqueuer->enqueueManual( $postId, $platform, $account );
		}
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
