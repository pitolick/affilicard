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
		/**
		 * time limit の既定値（秒）。実際に使う値は handle() が
		 * `action_scheduler_queue_runner_time_limit` フィルタ（AS 自身も同じフィルタを
		 * 適用する。ActionScheduler_Abstract_QueueRunner::get_time_limit()）を通して
		 * 決める——この値はフィルタが登録されていない場合の既定にすぎない（spec §4-1
		 * Important 2）。
		 */
		private int $timeLimitSeconds = 30,
		private int $safetyMarginSeconds = 5
	) {}

	/**
	 * @param array{account?: string, items?: list<array{post_id:int, platform:string}>} $args
	 */
	public function handle( array $args ): void {
		$account = isset( $args['account'] ) ? (string) $args['account'] : '';
		// array_values() でキーを 0 始まりの連番へ正規化する。以降の foreach で得る $index を
		// requeueRemaining() が array_slice( $items, $fromIndex ) の「オフセット（先頭からの
		// 位置）」として使うため、キーが連番でないとズレる（CodeRabbit レビュー指摘）。
		// 正規の投入経路（Enqueuer::enqueueBatch）は投入前に array_values() 済みの items しか
		// 積まないため通常は連番だが、handle() は AS フックの境界（外部入力の受け口）なので
		// ここでも防御的に正規化する。
		$items = isset( $args['items'] ) && is_array( $args['items'] ) ? array_values( $args['items'] ) : array();
		if ( '' === $account || array() === $items ) {
			return;
		}

		// pause 中はジョブを失わずに温存する（bare return だと AS が complete 扱いにしてジョブが消滅する）。
		if ( GeneralSettings::isQueuePaused() ) {
			$this->requeueOrFallback( $account, $items, time() + self::PAUSE_RETRY_SECONDS );
			return;
		}

		// AS ランナーの時間予算は `action_scheduler_queue_runner_time_limit` フィルタで
		// サイト側から調整され得る。AS 自身（get_time_limit()）と同じフィルタを読むことで、
		// ランナーが伸縮されても JobDeadline の期限判定がそれに追従する（spec §4-1 Important 2）。
		$timeLimit = (int) apply_filters( 'action_scheduler_queue_runner_time_limit', $this->timeLimitSeconds ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- AS 自身が定義・適用する既存フィルタを読むだけで、affilicard がここで新しく hook を定義しているわけではない。

		// 期限は「このジョブ自身の開始時刻」ではなく「AS ランナー全体の開始時刻」から起算する。
		// AS はランナー生成時刻（ActionScheduler_Abstract_QueueRunner::$created_time）から実行
		// 時間を計測し、1 回のランナー起動で複数アクションを連続実行する。このジョブ自身の
		// 開始時刻を起点にすると、先行アクションが消費した時間を無視して残り時間を過大評価
		// してしまい、usleep() や fetch が AS ランナー全体の時間予算を超えて、未処理分の
		// 積み直し前にランナーごと打ち切られる（＝バッチの残りが失われる）おそれがある
		// （CodeRabbit レビュー指摘）。$created_time は private・関連メソッド
		// （get_execution_time()/get_time_limit()/time_likely_to_be_exceeded()）はいずれも
		// protected で、AS は残り時間を取得する public API を公開していない
		// （vendor/woocommerce/action-scheduler で確認済み）。代わりに AS 自身が内部で使う
		// public フック `action_scheduler_before_process_queue` でランナー起動時刻を捕捉する
		// （RunnerClock::register() 参照）。このジョブが AS を介さず直接呼ばれた場合
		// （フックが一度も発火していない。主にユニットテスト）は自身の開始時刻へ
		// フォールバックする（従来の挙動）。
		$startedAt   = RunnerClock::startedAt() ?? time();
		$deadline    = new JobDeadline( $startedAt, $timeLimit, $this->safetyMarginSeconds );
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
				if ( $mustAttempt ) {
					// 前進保証が働く 1 件目はクランプを経ず生の待機秒（$rawWaitSec）で待つ
					// ——1 件目は期限ゲート（canAfford）自体を素通りして必ず試みる設計
					// なので、待機だけクランプされると待ち足りずに枠を取れないまま2回目の
					// tryAcquire に進み、結局 per-listing へ落ちて「1件も処理できないまま
					// 積み直す」を防ぐという前進保証の目的が達成できない。
					//
					// ただし青天井ではない（CodeRabbit レビュー指摘）。throttle_overrides に
					// 大きい値（例: 20000ms）が設定されていると $rawWaitSec が数十秒に達し得て、
					// AS ランナーの時間予算を丸ごと占有し後続アクションを食い潰してしまう。
					// 上限は「このジョブ自身の開始時刻（$startedAt）を基準に評価した総予算」
					// （$deadline->remaining( $startedAt )）——"now" 基準の remaining() を
					// 上限に使うと、他の item が先に消費した分でこの上限自体が 0 になり得て
					// 前進保証そのものが機能しなくなる（直前に直した Minor と同じ症状を
					// 再発させる）ため、経過時間の影響を受けない $startedAt を基準にする。
					// 上限を超える場合は待たずに「枠が空く近く」へ積み直す——積み直した先で
					// 改めてこの item が 1 件目として試みられるため、前進保証そのものは保たれる。
					if ( $rawWaitSec > $deadline->remaining( $startedAt ) ) {
						$this->requeueRemaining( $account, $items, $index );
						return;
					}
					$waitSec = $rawWaitSec;
				} else {
					// 待機秒を残り時間でクランプする。要求した待機がクランプで減った
					// （＝そのまま待つと期限を超える）場合は、待たずに未処理分（この
					// listing を含む）を積み直して終了する（spec §4-1）。
					$waitSec = $deadline->clampWait( time(), $rawWaitSec );
					if ( $waitSec < $rawWaitSec ) {
						$this->requeueRemaining( $account, $items, $index );
						return;
					}
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
