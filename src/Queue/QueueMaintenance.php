<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductPostType;
use Affilicard\Pricing\ListingEligibility;
use Affilicard\Pricing\PriceFreshness;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Settings\GeneralSettings;
use Affilicard\Stocktake\StocktakePolicy;

/**
 * 掃引（sweep）: 公開中の商品をカーソル順に走査し、自動更新対象 listing を account 別の
 * バッチジョブ（Enqueuer::enqueueBatch）として Action Scheduler へ積む。
 * affilicard_refresh_all（全体単一 WP-Cron イベント）から呼ばれる想定。
 *
 * v3.5.0 で per-listing 投入（Enqueuer::enqueueSweep）からバッチ投入へ切り替え、
 * 併せて sweep 自体をカーソル方式の分割実行にした（spec 2026-08-25 §4）。商品数に
 * 比例して重くならないよう、1 回の呼び出しでは最大 $maxProducts 件だけを走査し、
 * 続きがあれば SweepCursor へ最後に処理した post_id を永続化して false を返す。
 * 呼び出し側（WP-Cron の開始ジョブ／AS の再投入。Task 12 で配線）が false を見て
 * 次の sweep を積み、カーソルの位置から再開する。
 *
 * ここで行うのは enqueue 時点の対象フィルタ（update_mode=auto && enabled && auto_update
 * && platform 定義が既知）・鮮度判定（PriceFreshness::needsRefetch）・棚卸し除外
 * （StocktakePolicy）のみ。バッチ内でのレート制御・異常系フォールバックは
 * BatchRefreshHandler の責務。
 *
 * 注意: ListingRefresher::refreshOne()（ハンドラが実行時に呼ぶ）は v2.4.0 で
 * update_mode/enabled のみを再チェックするが、auto_update は見ない（force enqueue との
 * 両立のため）。よって auto_update=false の listing を積まないことは、enqueue 時点の
 * このフィルタが引き続き唯一のゲートになる。
 *
 * registerRetentionFilters(): Action Scheduler の完了/失敗アクション保持期間を
 * GeneralSettings（管理画面で設定した done 時間 / failed 日数）へ連動させる。
 * AS 自身の掃除 cron（action_scheduler_run_canceller 等）がこのフィルタ値を使って
 * 古い completed/failed アクションを purge するため、reconcile（取りこぼし回収）は
 * sweep（stale listing の再 enqueue）と AS 自身の recurring-action 安全策で実質的に
 * カバーされる。
 */
final class QueueMaintenance {

	/** 最後に sweep が完走した時刻（ISO8601, UTC）を記録する option。分割実行の途中では書かない。 */
	public const OPTION_LAST_COMPLETED = 'affilicard_last_sweep_completed_at';

	/**
	 * AS の `action_scheduler_queue_runner_time_limit` フィルタが未登録のときに使う既定値
	 * （秒）。AS 自身（ActionScheduler_Abstract_QueueRunner::get_time_limit()）と同じ既定値・
	 * 同じフィルタ名を使うことで、サイトがランナーの時間予算を変更していれば
	 * batchSizeFor() の算出にもそのまま反映される（spec §4-1 Important 2）。
	 */
	private const DEFAULT_TIME_LIMIT_SECONDS = 30;

	/**
	 * バッチサイズ算出時の安全マージン（秒）。BatchRefreshHandler の既定
	 * safetyMarginSeconds と同じ値（spec §4-1 の例: 楽天 1.1s・time limit 30 秒・
	 * 安全マージン 5 秒→実効 25 秒で 22 件）。
	 */
	private const DEFAULT_SAFETY_MARGIN_SECONDS = 5;

	public function __construct(
		private ProductRepositoryInterface $repository,
		private Enqueuer $enqueuer,
		private ProviderRegistry $providerRegistry,
		private SweepCursor $cursor = new SweepCursor(),
		private StocktakePolicy $stocktake = new StocktakePolicy(),
		/**
		 * 掃引の再取得判定（PriceFreshness::needsRefetch）を表示期限より前倒しで発火
		 * させるリード秒数。呼び出し側（Plugin）が PriceFreshness::sweepLeadSeconds()
		 * で算出した値を渡す（Task 12）。既定 0 は前倒しなし（従来挙動）。
		 */
		private int $sweepLeadSeconds = 0,
		/**
		 * 1 回の sweep で積める AS pending 件数の上限（queue_depth_cap）。GeneralSettings
		 * をここで静的に読まず、呼び出し側（Plugin）が Enqueuer と同じ値を注入する
		 * （Task 12: cap の出所を Enqueuer と QueueMaintenance の 2 箇所に分散させない）。
		 */
		private int $depthCap = 500,
		/**
		 * batchSizeFor() が account の実効レート間隔（RateLimiter::effectiveIntervalMs）を
		 * 求めるために使う。final class のため Mockery でモック不可（実インスタンス＋WP
		 * 関数スタブでテストする）。
		 */
		private RateLimiter $rateLimiter = new RateLimiter()
	) {}

	/**
	 * Ruling 8: 次に sweep() を呼んでも前進できるか。sweep() 内部の cap チェックは
	 * ループ先頭で 1 度だけ計算した depth（$this->enqueuer->queueDepth()）を使うため、
	 * それが既に depthCap 以上であれば、最初の商品に到達する前に必ず打ち切られ
	 * カーソルが 1 件も進まないまま false を返す（このメソッドと sweep() 冒頭の
	 * cap チェックはロジックを同期させておくこと）。
	 *
	 * pending のバッチ深さが cap に張り付いた状態（例: キュー一時停止中に
	 * BatchRefreshHandler が pause 温存の自己再投入を繰り返し、pending が cap を
	 * 下回らない）で呼び出し側が sweep() を即時に呼び直し続けると、cursor が
	 * 一切進まないまま get_posts / as_get_scheduled_actions の空振りクエリだけが
	 * 無限に繰り返される（completed アクションのチャーン。spec が消そうとした
	 * 症状そのもの）。呼び出し側はこれが true の間、sweep() を呼ばずに遅延して
	 * 再投入すること（Task 12・Ruling 8）。
	 */
	public function queueAtCapacity(): bool {
		return $this->enqueuer->queueDepth() >= $this->depthCap;
	}

	/**
	 * 公開商品をカーソル順に $maxProducts 件走査し、対象 listing を account 別の
	 * バッチジョブとして積む。
	 *
	 * カーソルの扱い（spec §4-2・レビュー Major 1/2 反映後）:
	 * - 最後の商品まで到達し（今回の走査件数が $maxProducts 未満）、かつ走査した
	 *   バッチが 1 件も失われていなければ、カーソルを消し完走時刻
	 *   （OPTION_LAST_COMPLETED）を記録して true を返す
	 * - `queue_depth_cap`（GeneralSettings）に達したら、そこで走査を打ち切りカーソルを
	 *   保持する。cap は「1 回の sweep で積める量の上限」であって「更新できる商品数の
	 *   天井」ではない——次回の sweep がカーソル位置から再開する
	 * - 上記以外の理由（続きがある）でも、カーソルを保持して false を返す
	 * - バッチ投入（`Enqueuer::enqueueBatch()`）が 0 を返しても、それが unique 重複
	 *   （既に pending があり作業は失われていない）なのか投入失敗（作業が失われる）
	 *   なのかを `Enqueuer::hasScheduledBatch()` で判別する（spec §4-3）。**重複は
	 *   無視してよいが、投入失敗は「最後まで到達した」とは扱わない**——その
	 *   バケットの最初の商品の手前へカーソルを巻き戻し、false を返す。これにより
	 *   次の sweep（false 時は即時に再スケジュールされる）が同じ listing を
	 *   再度対象にできる（巻き戻さずに完走扱いにすると、次の定期 sweep まで
	 *   その listing が更新されなくなる）
	 * - 端数バケット（BATCH_SIZE に満たず走査末尾で流すもの）も、投入前に
	 *   `queue_depth_cap` の残り容量を確認する。残り容量が無いバケットは投入を
	 *   試みず、同様にカーソルを巻き戻す（完全バッチの cap チェックと同じ扱いに揃える）
	 *
	 * @return bool 最後の商品まで到達し、かつすべてのバッチが確定的に投入（成功
	 *         または既存 pending との重複）されていれば true（完走）。false は継続あり。
	 */
	public function sweep( int $maxProducts = 200 ): bool {
		$after = $this->cursor->get();

		// カーソルより後ろの ID だけを取る。PHP 側で filter すると取得済みの先頭に
		// カーソル以前の商品が含まれるぶん 1 回に進む件数が目減りし、カーソルが末尾に
		// 近づくほど 1 回あたり 0〜数件しか進まなくなる。posts_where で SQL に落とし、
		// posts_per_page => $maxProducts が「カーソル以降の $maxProducts 件」を意味する
		// ようにする。
		//
		// - 'suppress_filters' => false が無いと WP_Query::get_posts() は
		// posts_where を含む句フィルタを一切適用しない（get_posts() 自身の既定値は
		// suppress_filters=true）。これが無いとフィルタは実クエリで一度も呼ばれず、
		// カーソルが SQL に反映されないまま同じ先頭 $maxProducts 件を無限に
		// 再走査してしまう（レビュー Critical 1）。
		// - 'affilicard_sweep_after' を private query var として渡し、フィルタ側で
		// $query->get() が一致するときだけ WHERE を書き換える。$where だけを見て
		// 無条件に足すと、remove_filter までの短い窓の間に他プラグインが副次的に
		// 走らせた無関係な WP_Query の結果まで黙って削ってしまう（レビュー Important 3）。
		// - 他のクエリに漏れると重大な副作用になるため、使用後は必ず remove_filter で
		// 外す（get_posts が例外を投げても外れるよう try/finally）。
		$whereCursor = static function ( string $where, $query ) use ( $after ): string {
			if ( $after !== $query->get( 'affilicard_sweep_after', null ) ) {
				return $where;
			}
			global $wpdb;
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
		};

		add_filter( 'posts_where', $whereCursor, 10, 2 );
		try {
			$ids = get_posts(
				array(
					'post_type'              => ProductPostType::POST_TYPE,
					'post_status'            => 'publish',
					'fields'                 => 'ids',
					'posts_per_page'         => $maxProducts,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'suppress_filters'       => false,
					'affilicard_sweep_after' => $after,
				)
			);
		} finally {
			remove_filter( 'posts_where', $whereCursor, 10 );
		}

		if ( ! is_array( $ids ) ) {
			return true;
		}

		$now = time();

		// queue_depth_cap の判定に使う現在の pending 件数。listing 毎に
		// as_get_scheduled_actions を叩くと O(N) クエリになるため、1 度だけクエリして
		// 以降はこの sweep 内で新規に積んだ分だけローカルに加算する
		// （Enqueuer::currentDepth() と同じ考え方）。走査対象が無ければクエリ自体を省く。
		$cap   = 0;
		$depth = 0;
		if ( array() !== $ids ) {
			$cap   = $this->depthCap;
			$depth = $this->enqueuer->queueDepth();
		}

		$buckets      = array(); // account => list<array{post_id: int, platform: string}>
		$bucketStarts = array(); // account => 現在の未flushバケットに最初に加わった post_id
		$batchSizes   = array(); // account => algo で算出したバッチサイズ（sweep 内でメモ化）
		$lastSeen     = $after;
		$capped       = false;
		// レビュー Major 1/2: 「作業が失われた」最初の post_id（投入失敗、または
		// queue_depth_cap 不足で投入を試みられなかった端数バケット）。null のままなら
		// 走査した範囲はすべて確定的に投入済み（成功 or 既存 pending との重複）。
		$firstUnconfirmedId = null;

		foreach ( $ids as $id ) {
			if ( $depth >= $cap ) {
				// cap 到達。この商品以降は未処理のまま次回へ持ち越す
				// （lastSeen を進めないので、次の sweep がここから再開する）。
				$capped = true;
				break;
			}

			$id       = (int) $id;
			$lastSeen = $id;

			$product = $this->repository->find( $id );
			if ( null === $product || ! is_array( $product['listings'] ?? null ) ) {
				continue;
			}

			// 棚卸し対象の商品はここで丸ごと除外する（listing 単位ではなく商品単位）。
			if ( $this->stocktake->isRetired( $id, $now ) ) {
				continue;
			}

			foreach ( $product['listings'] as $listing ) {
				if ( ! is_array( $listing ) ) {
					continue;
				}
				$platform = (string) ( $listing['platform'] ?? '' );
				$def      = PlatformConfig::find( $platform );
				if ( null === $def ) {
					continue;
				}

				// auto listing のみ（update_mode=auto・enabled・auto_update）。
				if ( ! ListingEligibility::isAutoEligible( $listing ) ) {
					continue;
				}

				// account が解決できない（provider 未登録、または手動系で accountCode()
				// が null）listing は積まない。
				$account = $this->providerRegistry->get( $def->provider )?->accountCode();
				if ( null === $account ) {
					continue;
				}

				// B: give-up マーカーが立つ listing（terminal failure 済み＝廃盤/無効 ID）は
				// GIVEUP_COOLDOWN の間スキップする。
				if ( get_transient( RefreshHandler::giveUpTransientKey( $id, $platform ) ) ) {
					continue;
				}

				// 直近の試行から TTL（リード分だけ前倒し）を経過していない listing は
				// まだ積まない（perpetual retry の抑止）。
				if ( ! PriceFreshness::needsRefetch( $listing, $def, $now, $this->sweepLeadSeconds ) ) {
					continue;
				}

				if ( array() === ( $buckets[ $account ] ?? array() ) ) {
					// この account のバケットが空から埋まり始める瞬間の post_id を記録する。
					// 投入失敗/容量不足でこのバケットが失われたとき、カーソルをこの手前へ
					// 巻き戻すために使う（レビュー Major 1/2）。
					$bucketStarts[ $account ] = $id;
				}

				$buckets[ $account ][] = array(
					'post_id'  => $id,
					'platform' => $platform,
				);

				if ( ! isset( $batchSizes[ $account ] ) ) {
					$batchSizes[ $account ] = $this->batchSizeFor( $account );
				}

				if ( count( $buckets[ $account ] ) >= $batchSizes[ $account ] ) {
					$this->flushBucket( $account, $buckets[ $account ], $bucketStarts[ $account ], $depth, $firstUnconfirmedId );
					$buckets[ $account ] = array();
				}
			}
		}

		// 端数を流す（バッチサイズに満たなかった残り）。完全バッチ（上のループ内）と
		// 異なり、ここは cap を再確認していなかった（レビュー Major 2）。残り容量が
		// 無ければ投入を試みず、次の sweep に委ねる（$firstUnconfirmedId 経由）。
		foreach ( $buckets as $account => $items ) {
			if ( array() === $items ) {
				continue;
			}

			if ( $depth >= $cap ) {
				$firstUnconfirmedId = null === $firstUnconfirmedId
					? $bucketStarts[ $account ]
					: min( $firstUnconfirmedId, $bucketStarts[ $account ] );
				continue;
			}

			$this->flushBucket( $account, $items, $bucketStarts[ $account ], $depth, $firstUnconfirmedId );
		}

		if ( null !== $firstUnconfirmedId ) {
			// 作業が失われた最初の商品の手前へカーソルを巻き戻す。次の sweep が
			// そこから再走査し、投入できなかった listing を積み直す（レビュー Major 1/2）。
			$lastSeen = $firstUnconfirmedId - 1;
		}

		$completed = ! $capped && null === $firstUnconfirmedId && count( $ids ) < $maxProducts;
		if ( $completed ) {
			$this->cursor->clear();
			update_option( self::OPTION_LAST_COMPLETED, gmdate( 'c' ), false );
			return true;
		}

		// 未完走。カーソルを保存して次の sweep に委ねる。通常は走査した進捗どおりの
		// 位置だが、バッチ投入に失敗していた場合は上で「作業が失われた最初の商品の
		// 手前」まで巻き戻した値になっており、次回 sweep がそこから積み直す。
		$this->cursor->set( $lastSeen );
		return false;
	}

	/**
	 * 1 account 分のバケットを投入する。成功したら $depth を増やす。
	 *
	 * `enqueueBatch()` が 0 を返した場合、`Enqueuer::hasScheduledBatch()` で
	 * 「unique 重複でスキップ（作業は失われていない）」なのか「投入失敗（作業が
	 * 失われる）」なのかを判別する（spec §4-3。戻り値だけでは区別できない）。
	 * 失敗の場合だけログに残し（AS 側では完全に不可視になるため）、
	 * $firstUnconfirmedId をこのバケットの開始 post_id 未満に更新する
	 * （呼び出し側がこれを見てカーソルを巻き戻す）。
	 *
	 * @param list<array{post_id: int, platform: string}> $items
	 */
	private function flushBucket( string $account, array $items, int $bucketStart, int &$depth, ?int &$firstUnconfirmedId ): void {
		$actionId = $this->enqueuer->enqueueBatch( $account, $items );
		if ( 0 !== $actionId ) {
			++$depth;
			return;
		}

		if ( $this->enqueuer->hasScheduledBatch( $account, $items ) ) {
			// 既にキューにある（unique 重複）。作業は失われていないため何もしない
			// （depth もこれ以上消費しない＝cap の枠を無駄に使わない）。
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 掃引のバッチ投入失敗は AS 側で完全に不可視（run は緑のまま）になるため、運用が気づけるようログに残す（spec §4-3）。
		error_log(
			sprintf(
				'affilicard: 掃引のバッチ投入に失敗しました（account=%s, items=%d件）。カーソルを未投入商品の手前へ巻き戻し、次回の掃引で再試行します。',
				$account,
				count( $items )
			)
		);

		$firstUnconfirmedId = null === $firstUnconfirmedId ? $bucketStart : min( $firstUnconfirmedId, $bucketStart );
	}

	/**
	 * account 単位のバッチサイズ。AS の `action_scheduler_queue_runner_time_limit`
	 * フィルタ（既定 30 秒。AS 自身も同じフィルタを適用する。
	 * ActionScheduler_Abstract_QueueRunner::get_time_limit()）から安全マージンを引いた
	 * 実効秒数を、account の実効レート間隔（RateLimiter::effectiveIntervalMs）で割って
	 * 算出する（spec §4-1 Important 2。楽天 1.1s・time limit 30 秒・安全マージン 5 秒→
	 * 実効 25 秒で 22 件）。
	 *
	 * 算出結果は `affilicard_refresh_batch_size` フィルタで上書き可能にする（spec §8-2:
	 * 実環境の fetch 所要時間を測って安全マージンを調整する運用を、コードリリース無しで
	 * 行えるようにするため）。最終値は 1 件未満にならないようクランプする（0 だと
	 * バッチが永久に flush されず listing が積みっぱなしになる）。
	 */
	private function batchSizeFor( string $account ): int {
		$timeLimit  = (int) apply_filters( 'action_scheduler_queue_runner_time_limit', self::DEFAULT_TIME_LIMIT_SECONDS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- AS 自身が定義・適用する既存フィルタを読むだけで、affilicard がここで新しく hook を定義しているわけではない。
		$intervalMs = $this->intervalMsFor( $account );

		$effectiveSeconds = max( 0, $timeLimit - self::DEFAULT_SAFETY_MARGIN_SECONDS );
		$intervalSeconds  = $intervalMs / 1000;
		$size             = $intervalSeconds > 0 ? (int) floor( $effectiveSeconds / $intervalSeconds ) : $effectiveSeconds;
		$size             = max( 1, $size );

		return max( 1, (int) apply_filters( 'affilicard_refresh_batch_size', $size, $account ) );
	}

	/**
	 * account の実効レート間隔（ms）。BatchRefreshHandler::intervalMsFor() と同じロジック
	 * （providerRegistry で accountCode() 一致の provider を探し minRequestIntervalMs を
	 * 求め、GeneralSettings::throttleOverrideMs で上書き）。
	 */
	private function intervalMsFor( string $account ): int {
		$floorMs = 0;
		foreach ( $this->providerRegistry->all() as $provider ) {
			if ( $provider->accountCode() === $account ) {
				$floorMs = $provider->minRequestIntervalMs();
				break;
			}
		}
		return $this->rateLimiter->effectiveIntervalMs( $floorMs, GeneralSettings::throttleOverrideMs( $account ) );
	}

	/**
	 * Action Scheduler の completed/failed アクション保持期間フィルタを
	 * GeneralSettings の値に連動させる。
	 */
	public static function registerRetentionFilters(): void {
		add_filter( 'action_scheduler_retention_period', array( self::class, 'doneRetentionSeconds' ) );
		add_filter( 'action_scheduler_retention_period_for_failed', array( self::class, 'failedRetentionSeconds' ) );
	}

	/** completed アクションの保持期間（秒）。 */
	public static function doneRetentionSeconds(): int {
		return GeneralSettings::retentionDoneHours() * HOUR_IN_SECONDS;
	}

	/** failed アクションの保持期間（秒）。 */
	public static function failedRetentionSeconds(): int {
		return GeneralSettings::retentionFailedDays() * DAY_IN_SECONDS;
	}
}
