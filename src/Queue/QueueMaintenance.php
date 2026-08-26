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
	 * 1 バッチジョブに詰める listing 件数の既定。AS の
	 * `action_scheduler_queue_runner_time_limit`（既定 30 秒）内に account の実効レート
	 * 間隔で収まる件数から算出する（spec §4-1。楽天 1.1s・安全マージン 25 秒で 22 件）。
	 */
	private const BATCH_SIZE = 22;

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
		private int $depthCap = 500
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
	 * カーソルの扱い（spec §4-2）:
	 * - 最後の商品まで到達したら（今回の走査件数が $maxProducts 未満）カーソルを消し、
	 *   完走時刻（OPTION_LAST_COMPLETED）を記録して true を返す
	 * - `queue_depth_cap`（GeneralSettings）に達したら、そこで走査を打ち切りカーソルを
	 *   保持する。cap は「1 回の sweep で積める量の上限」であって「更新できる商品数の
	 *   天井」ではない——次回の sweep がカーソル位置から再開する
	 * - 上記以外の理由（続きがある）でも、カーソルを保持して false を返す
	 * - バッチ投入（enqueueBatch）の失敗はここでは握り潰さない（呼び出し先がログに
	 *   残す）が、投入の成否に関わらずカーソルは進捗どおりに保存される。投入に失敗
	 *   しても走査位置そのものは失われない
	 *
	 * @return bool 最後の商品まで到達したら true（完走）。false は継続あり。
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

		$buckets  = array(); // account => list<array{post_id: int, platform: string}>
		$lastSeen = $after;
		$capped   = false;

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

				$buckets[ $account ][] = array(
					'post_id'  => $id,
					'platform' => $platform,
				);

				if ( count( $buckets[ $account ] ) >= self::BATCH_SIZE ) {
					if ( 0 !== $this->enqueuer->enqueueBatch( $account, $buckets[ $account ] ) ) {
						++$depth;
					}
					$buckets[ $account ] = array();
				}
			}
		}

		// 端数を流す（BATCH_SIZE に満たなかった残り）。
		foreach ( $buckets as $account => $items ) {
			if ( array() !== $items ) {
				$this->enqueuer->enqueueBatch( $account, $items );
			}
		}

		$completed = ! $capped && count( $ids ) < $maxProducts;
		if ( $completed ) {
			$this->cursor->clear();
			update_option( self::OPTION_LAST_COMPLETED, gmdate( 'c' ), false );
			return true;
		}

		// 未完走。カーソルを保存して次の sweep に委ねる（バッチ投入に失敗しても
		// カーソルは既に走査した進捗どおりに保存されるため、次回 sweep が続きから
		// 再開できる）。
		$this->cursor->set( $lastSeen );
		return false;
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
