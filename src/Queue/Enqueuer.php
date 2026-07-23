<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Platform\PlatformDefinition;
use Affilicard\Pricing\PriceFreshness;

/**
 * Action Scheduler へのジョブ投入を一元化するラッパー。
 *
 * すべてのトリガー（手動更新・強制更新・自動作成・掃引 Cron）はこのクラス経由で
 * AS にジョブを積む。dedup は AS ネイティブの $unique=true、順序は $priority
 * （force=0 > manual=10 > sweep=20）、provider 別に group を分けてレート制御・
 * 監視をしやすくする。掃引専用の鮮度スキップと depth cap・jitter もここに集約する。
 */
final class Enqueuer {

	public const HOOK_REFRESH    = 'affilicard_refresh_listing';
	public const HOOK_AUTOCREATE = 'affilicard_autocreate';
	public const PRIORITY_FORCE  = 0;
	public const PRIORITY_MANUAL = 10;
	public const PRIORITY_SWEEP  = 20;

	public function __construct(
		private int $depthCap = 500,
		private int $maxJitterSeconds = 300
	) {}

	public function group( string $provider ): string {
		return 'affilicard-' . $provider;
	}

	/**
	 * 強制更新（ユーザーが「今すぐ更新」を明示的に押した場合）。
	 *
	 * 既存の同一ジョブを unschedule してから即時 priority 0 で積み直す。
	 */
	public function enqueueForced( int $postId, string $platform, string $provider ): void {
		$args  = array(
			'post_id'  => $postId,
			'platform' => $platform,
		);
		$group = $this->group( $provider );

		as_unschedule_all_actions( self::HOOK_REFRESH, $args, $group );
		as_schedule_single_action( time(), self::HOOK_REFRESH, $args, $group, true, self::PRIORITY_FORCE );
	}

	/**
	 * 手動更新（画面操作起点だが強制ではない通常トリガー）。
	 */
	public function enqueueManual( int $postId, string $platform, string $provider ): void {
		$args = array(
			'post_id'  => $postId,
			'platform' => $platform,
		);

		as_schedule_single_action( time(), self::HOOK_REFRESH, $args, $this->group( $provider ), true, self::PRIORITY_MANUAL );
	}

	/**
	 * 掃引 Cron 起点の更新。鮮度内（fresh）ならスキップし、depth cap 到達時も
	 * スキップする（force/manual はこのガードの対象外）。積んだら true を返す。
	 *
	 * @param array<string, mixed> $listing
	 */
	public function enqueueSweep( int $postId, string $platform, string $provider, ?PlatformDefinition $def, array $listing, int $nowTs ): bool {
		if ( ! PriceFreshness::isStale( $listing, $def, $nowTs ) ) {
			return false;
		}
		if ( $this->queueDepth() >= $this->depthCap ) {
			return false;
		}

		$args = array(
			'post_id'  => $postId,
			'platform' => $platform,
		);
		$when = time() + wp_rand( 0, $this->maxJitterSeconds );

		as_schedule_single_action( $when, self::HOOK_REFRESH, $args, $this->group( $provider ), true, self::PRIORITY_SWEEP );
		return true;
	}

	/**
	 * 自動作成（未登録商品の初回作成）。即時 priority 0 で積む。
	 */
	public function enqueueAutoCreate( string $platform, string $provider, string $externalId ): void {
		$args = array(
			'platform'    => $platform,
			'external_id' => $externalId,
		);

		as_schedule_single_action( time(), self::HOOK_AUTOCREATE, $args, $this->group( $provider ), true, self::PRIORITY_FORCE );
	}

	/**
	 * throttle/backoff によるハンドラの自己再投入。
	 *
	 * unique=false: ハンドラ実行中の自分自身が in-progress として重複判定されるため、
	 * unique=true だと backoff/throttle の再投入が必ずスキップされてしまう。単一ワーカー
	 * （AS claim による single-flight）実行中の 1 回だけ呼ばれるので false でも増殖しない。
	 */
	public function rescheduleRefresh( int $whenSec, int $postId, string $platform, string $provider ): void {
		as_schedule_single_action(
			$whenSec,
			self::HOOK_REFRESH,
			array(
				'post_id'  => $postId,
				'platform' => $platform,
			),
			$this->group( $provider ),
			false,
			self::PRIORITY_MANUAL
		);
	}

	/**
	 * throttle/backoff による AutoCreateHandler の自己再投入。
	 *
	 * unique=false: rescheduleRefresh と同様、実行中の自分自身が in-progress として
	 * 重複判定されてしまうため false（単一ワーカー実行中の 1 回だけ呼ばれるので増殖しない）。
	 */
	public function rescheduleAutoCreate( int $whenSec, string $platform, string $provider, string $externalId ): void {
		as_schedule_single_action(
			$whenSec,
			self::HOOK_AUTOCREATE,
			array(
				'platform'    => $platform,
				'external_id' => $externalId,
			),
			$this->group( $provider ),
			false,
			self::PRIORITY_FORCE
		);
	}

	/**
	 * 商品の ELIGIBLE な auto listing を enqueue する共通ヘルパー。
	 *
	 * ELIGIBLE 判定は QueueMaintenance::sweep()/PublishTrigger と同一
	 * （update_mode=auto && enabled(既定true) && auto_update(既定true) && platform 定義が既知）。
	 * `$manual` は積み方の選択のみを表す: false は force（enqueueForced・priority 0。
	 * 予約投稿の future→publish 昇格や記事公開/更新等のイベント駆動）、true は手動ボタン
	 * （enqueueManual・priority 10）。掃引（sweep）はここでは扱わない（鮮度スキップ・depth cap・
	 * jitter を伴う別経路のため enqueueSweep を直接使う）。
	 *
	 * @param array<string, mixed> $product Repository::find() の戻り
	 * @return int enqueue した listing 件数
	 */
	public function enqueueProductListings( int $postId, array $product, bool $manual ): int {
		$listings = is_array( $product['listings'] ?? null ) ? $product['listings'] : array();

		$count = 0;
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$platform = (string) ( $listing['platform'] ?? '' );
			$def      = PlatformConfig::find( $platform );
			if ( null === $def ) {
				continue;
			}

			$mode    = (string) ( $listing['update_mode'] ?? 'auto' );
			$enabled = ! isset( $listing['enabled'] ) || (bool) $listing['enabled'];
			$auto    = ! isset( $listing['auto_update'] ) || (bool) $listing['auto_update'];
			if ( 'auto' !== $mode || ! $enabled || ! $auto ) {
				continue;
			}

			if ( $manual ) {
				$this->enqueueManual( $postId, $platform, $def->provider );
			} else {
				$this->enqueueForced( $postId, $platform, $def->provider );
			}
			++$count;
		}

		return $count;
	}

	/**
	 * pending 状態の AS ジョブ件数（provider 横断）。depth cap 判定に使う。
	 *
	 * MVP は group を絞らず全 pending 件数で代用する。provider 別 group に
	 * 限定した集計が必要になったら QueueStats へ委譲する。
	 */
	public function queueDepth(): int {
		$ids = as_get_scheduled_actions(
			array(
				'status'   => 'pending',
				'per_page' => $this->depthCap + 1,
				'group'    => '',
			),
			'ids'
		);

		return is_array( $ids ) ? count( $ids ) : 0;
	}
}
