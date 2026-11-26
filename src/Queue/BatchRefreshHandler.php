<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Provider\BatchFetchingProvider;
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

	/**
	 * まとめ取得で結果が手元にある listing 1 件の所要見込み（秒）。HTTP は走らず、
	 * 保存（ロック＋post meta 書き込み）だけなので小さく見積もる。
	 */
	private const PREFETCHED_ITEM_SECONDS = 2;

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

		// まとめ取得できる Provider（設計 2026-11-24 §4-3）。結果はこのジョブの中だけで使う。
		$batchProvider = $this->batchProviderFor( $account );
		if ( null !== $batchProvider ) {
			$batchProvider->clearPrefetched();
		}
		try {
			$this->processItems( $account, $items, $deadline, $intervalMs, $perItemSeconds, $batchProvider );
		} finally {
			if ( null !== $batchProvider ) {
				$batchProvider->clearPrefetched();
			}
		}
	}

	/**
	 * @param list<array{post_id:int, platform:string}> $items
	 */
	private function processItems( string $account, array $items, JobDeadline $deadline, int $intervalMs, int $perItemSeconds, ?BatchFetchingProvider $batchProvider ): void {
		// 前進保証（spec §4-1 Ruling 6）: このジョブでまだ 1 件も処理していない状態で
		// perItemSeconds が期限枠（timeLimitSeconds - safetyMarginSeconds）を超えていると、
		// 毎回 index 0 で期限チェックに弾かれ「1件も処理せず積み直す」を無限に繰り返す
		// （throttle_overrides に上限が無く、管理画面から容易に踏める）。まだ何も処理して
		// いない最初の 1 件だけは、期限を賄えなくても強制的に試みる。最悪 perItemSeconds
		// ぶん time limit を超えるが、AS は次のバッチで回復するため「1 件も処理できない」
		// より無害。2 件目以降は通常どおり期限で弾く。
		$processedAny = false;
		/** @var array<int, list<string>> $idsByIndex fetchTargetExternalIds() の結果（重複して読まないため）。 */
		$idsByIndex = array();

		foreach ( $items as $index => $item ) {
			$postId   = isset( $item['post_id'] ) ? (int) $item['post_id'] : 0;
			$platform = isset( $item['platform'] ) ? (string) $item['platform'] : '';
			if ( 0 === $postId || '' === $platform ) {
				continue;
			}

			$prefetchIds = array();
			if ( null !== $batchProvider ) {
				$ids = $this->targetIds( $idsByIndex, $index, $item );
				if ( array() !== $ids && self::allPrefetched( $batchProvider, $ids ) ) {
					// 先のまとめ取得で結果が手元にある＝外部 API を叩かない。レート枠は取らない。
					if ( $processedAny && ! $deadline->canAfford( time(), self::PREFETCHED_ITEM_SECONDS ) ) {
						$this->requeueRemaining( $account, $items, $index );
						return;
					}
					$processedAny = true;
					// この listing のためには枠を取っていない。再読込で対象が変わって外部 API を叩くことに
					// なったら、ListingRefresher が枠を取ってから叩く。
					$outcome = $this->refresher->refreshOne( $postId, $platform, true );
					if ( WorkOutcome::TRANSIENT_FAILURE === $outcome && $this->requeueIfPaused( $account, $items, $index ) ) {
						return;
					}
					$this->applyOutcome( $postId, $platform, $account, $outcome );
					continue;
				}
				if ( array() !== $ids ) {
					$prefetchIds = $this->collectPrefetchIds( $batchProvider, $items, $index, $idsByIndex );
				}
			}

			// ここから先は外部 API を呼ぶ。account を止めている（429 / 403。AccountPause）なら、
			// 残りを再開時刻までまとめて積み直す。1 件ずつのジョブへは回さない（回すと止めている間に
			// 呼び出しが膨らむ）。手元にまとめ取得の結果がある上の経路では確かめない（取得済みの
			// 結果を捨てて、再開後に取り直すことになるため）。
			if ( $this->requeueIfPaused( $account, $items, $index ) ) {
				return;
			}

			$mustAttempt = ! $processedAny;

			// ここで取るのは 1 件目の呼び出しの枠だけ。バージョン違いで 2 件目以降を叩くときは、
			// ListingRefresher が呼び出しごとに枠を取る（枠は取った時刻しか記録しないため、ここで
			// 件数ぶん見込んでも次のジョブとの間隔は守れない）。所要時間だけ件数ぶん見込む。
			$itemSeconds = $perItemSeconds * $this->httpCallsFor( $batchProvider, $idsByIndex, $index, $item, $postId, $platform );

			// 待機に入る「前に」期限を確認する。賄えないなら未処理分を積み直して終了する
			// （待機に入ってから期限を超えると AS ランナーの時間予算を食い潰したうえ、
			// 積み直しも行われずそのバッチの残りが失われる）。前進保証が働く 1 件目は
			// このゲートを素通りする。
			if ( ! $mustAttempt && ! $deadline->canAfford( time(), $itemSeconds ) ) {
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
					// ただし青天井ではない（CodeRabbit レビュー指摘）。上限には「実際の残り
					// 時間」（$deadline->remaining( time() )）を使う。$startedAt を基準にすると
					// remaining() は常に timeLimitSeconds − safetyMarginSeconds という固定値に
					// しかならず経過時間を一切反映しない（CodeRabbit レビュー再指摘）。AS ランナー
					// が先行アクションで時間をほぼ消費し終えていても 1 件目が「固定の総予算」まで
					// フルに待ってしまい、AS は待機中にランナーを終了し得るため、
					// requeueRemaining() 前に打ち切られてバッチの残りが失われる
					// （本改修が最も避けたかった取りこぼしそのもの）。
					//
					// 前進保証の趣旨は「1 件も処理できないまま積み直す状態が延々と続く」のを
					// 防ぐことであって「ランナーの残り時間を無視してよい」という意味ではない。
					// ランナーの残り時間が足りないなら待たずに積み直すのが正しい——積み直した
					// 先（次のランナー）で改めてこの item が 1 件目として試みられ、そちらは
					// フルな時間予算を持つため、前進保証そのものは失われない。
					if ( $rawWaitSec > $deadline->remaining( time() ) ) {
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
				if ( ! $mustAttempt && ! $deadline->canAfford( time(), $itemSeconds ) ) {
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

			if ( null !== $batchProvider && array() !== $prefetchIds ) {
				// 取ったレート枠 1 つで、この listing から先の最大 maxBatchSize 件ぶんをまとめて取る。
				$batchProvider->prefetch( $prefetchIds );
			}

			// 先取りに枠を使ったら、手元に結果の無い ID（1 リクエストに載らなかった 11 件目、
			// 先取りのあとに対象が変わった ID）は ListingRefresher が枠を取ってから叩く。
			$outcome = $this->refresher->refreshOne( $postId, $platform, null !== $batchProvider && array() !== $prefetchIds );
			// この listing の取得で account が止まった（429 / 403）なら、この listing を含む残りを
			// 再開時刻までまとめて積み直す。
			if ( WorkOutcome::TRANSIENT_FAILURE === $outcome && $this->requeueIfPaused( $account, $items, $index ) ) {
				return;
			}
			$this->applyOutcome( $postId, $platform, $account, $outcome );
		}
	}

	/**
	 * account を止めていれば、$fromIndex 以降の listing を再開時刻（＋ゆらぎ）にまとめて積み直して
	 * true を返す。止めていなければ何もせず false。
	 *
	 * 停止はキャッシュを使わずに読む。API の応答を待つ間に別のワーカーが止め、こちらの応答が
	 * 5xx などだった場合に、古い「停止の記録は無い」を見て 1 件ずつのジョブへ分けないため。
	 * 呼ぶのは API を呼ぶ前と一時失敗の後だけなので、DB を読む回数は API の呼び出しと同程度。
	 *
	 * @param list<array{post_id:int, platform:string}> $items
	 */
	private function requeueIfPaused( string $account, array $items, int $fromIndex ): bool {
		$until = AccountPause::until( $account, time(), true );
		if ( $until <= 0 ) {
			return false;
		}
		$this->requeueOrFallback(
			$account,
			array_slice( $items, $fromIndex ),
			$until + wp_rand( 0, Enqueuer::RESCHEDULE_JITTER_SECONDS )
		);
		return true;
	}

	private function applyOutcome( int $postId, string $platform, string $account, WorkOutcome $outcome ): void {
		if ( WorkOutcome::TRANSIENT_FAILURE === $outcome ) {
			// 一時失敗は per-listing へ落とし、既存の backoff / failed 可視化に委ねる。
			$this->enqueuer->enqueueManual( $postId, $platform, $account );
			return;
		}
		if ( WorkOutcome::TERMINAL_FAILURE === $outcome ) {
			// 恒久失敗は give-up マーカーを立てて次へ（per-listing へは落とさない）。
			set_transient(
				RefreshHandler::giveUpTransientKey( $postId, $platform ),
				1,
				3 * DAY_IN_SECONDS
			);
			return;
		}
		// SUCCESS: give-up マーカーを解除する（復旧した listing を通常周期に戻す）。
		delete_transient( RefreshHandler::giveUpTransientKey( $postId, $platform ) );
	}

	/**
	 * account の Provider がまとめ取得に対応していればそれを返す。
	 */
	private function batchProviderFor( string $account ): ?BatchFetchingProvider {
		foreach ( $this->registry->all() as $provider ) {
			if ( $provider->accountCode() === $account ) {
				return $provider instanceof BatchFetchingProvider ? $provider : null;
			}
		}
		return null;
	}

	/**
	 * @param array<int, list<string>>              $idsByIndex
	 * @param array{post_id?:int, platform?:string} $item
	 * @return list<string>
	 */
	private function targetIds( array &$idsByIndex, int $index, array $item ): array {
		if ( ! isset( $idsByIndex[ $index ] ) ) {
			$postId               = isset( $item['post_id'] ) ? (int) $item['post_id'] : 0;
			$platform             = isset( $item['platform'] ) ? (string) $item['platform'] : '';
			$idsByIndex[ $index ] = ( 0 === $postId || '' === $platform )
				? array()
				: $this->refresher->fetchTargetExternalIds( $postId, $platform );
		}
		return $idsByIndex[ $index ];
	}

	/**
	 * $fromIndex の listing から先へ、まだ取っていない外部 ID を最大 maxBatchSize 件集める。
	 * $fromIndex 自身の外部 ID は件数に関係なく必ず含める（このあと必ず fetch するため）。
	 *
	 * @param list<array{post_id:int, platform:string}> $items
	 * @param array<int, list<string>>                  $idsByIndex
	 * @return list<string>
	 */
	private function collectPrefetchIds( BatchFetchingProvider $provider, array $items, int $fromIndex, array &$idsByIndex ): array {
		$max     = max( 1, $provider->maxBatchSize() );
		$collect = array();
		foreach ( $this->targetIds( $idsByIndex, $fromIndex, $items[ $fromIndex ] ) as $id ) {
			// 1 リクエストに載る件数まで。バージョン違いがそれより多い listing の残りは、
			// ListingRefresher が 1 件ずつ枠を取って取る（prefetch() は複数のリクエストを
			// 間を空けずに送るため、ここで上限を超えて渡さない）。
			if ( count( $collect ) >= $max ) {
				break;
			}
			if ( ! $provider->hasPrefetched( $id ) ) {
				$collect[ $id ] = true;
			}
		}
		$total = count( $items );
		for ( $i = $fromIndex + 1; $i < $total; $i++ ) {
			$collected = count( $collect );
			if ( $collected >= $max ) {
				break;
			}
			$ids = $this->targetIds( $idsByIndex, $i, $items[ $i ] );
			$new = array();
			foreach ( $ids as $id ) {
				if ( ! isset( $collect[ $id ] ) && ! $provider->hasPrefetched( $id ) ) {
					$new[ $id ] = true;
				}
			}
			if ( $collected + count( $new ) > $max ) {
				// listing 単位で区切る（1 listing の外部 ID を 2 回のリクエストに割らない）。
				break;
			}
			$collect += $new;
		}
		return array_map( 'strval', array_keys( $collect ) );
	}

	/**
	 * この listing の更新で外部 API を叩く回数の見込み（所要時間の見積もりに使う）。
	 *
	 * - まとめ取得できない Provider: 価格更新の対象（バージョンごとに 1 件）の数
	 * - まとめ取得できる Provider: 先取りの 1 回 ＋ 1 リクエストに載らなかった残り
	 *
	 * @param array<int, list<string>>                $idsByIndex
	 * @param array{post_id?: int, platform?: string} $item
	 */
	private function httpCallsFor( ?BatchFetchingProvider $provider, array &$idsByIndex, int $index, array $item, int $postId, string $platform ): int {
		if ( null === $provider ) {
			return max( 1, $this->refresher->targetCount( $postId, $platform ) );
		}
		$ids = $this->targetIds( $idsByIndex, $index, $item );
		return 1 + max( 0, count( $ids ) - max( 1, $provider->maxBatchSize() ) );
	}

	/**
	 * @param list<string> $ids
	 */
	private static function allPrefetched( BatchFetchingProvider $provider, array $ids ): bool {
		foreach ( $ids as $id ) {
			if ( ! $provider->hasPrefetched( $id ) ) {
				return false;
			}
		}
		return true;
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
