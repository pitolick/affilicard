<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\BatchFetchingProvider;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Settings\GeneralSettings;

/**
 * account 単位のバッチジョブの件数を決める。
 *
 * 掃引（QueueMaintenance::sweep）と手動更新（RefreshController）が同じ計算を使う。
 * 2 か所に書くと、片方だけ直したときにジョブの大きさが食い違うため 1 か所に置く。
 */
final class BatchSizer {

	/**
	 * AS の `action_scheduler_queue_runner_time_limit` フィルタが未登録のときに使う既定値
	 * （秒）。AS 自身（ActionScheduler_Abstract_QueueRunner::get_time_limit()）と同じ既定値・
	 * 同じフィルタ名を使うことで、サイトがランナーの時間予算を変更していればバッチサイズの
	 * 算出にもそのまま反映される（spec §4-1 Important 2）。
	 */
	public const DEFAULT_TIME_LIMIT_SECONDS = 30;

	/**
	 * バッチサイズ算出時の安全マージン（秒）。BatchRefreshHandler の既定
	 * safetyMarginSeconds と同じ値（spec §4-1 の例: 楽天 1.1s・time limit 30 秒・
	 * 安全マージン 5 秒→実効 25 秒で 22 件）。
	 */
	public const DEFAULT_SAFETY_MARGIN_SECONDS = 5;

	/**
	 * バッチジョブの引数（`{"account":…,"items":[…]}` の JSON）の長さの上限（文字数）。
	 *
	 * Action Scheduler の DB ストアは、JSON にした引数が 8,000 文字を超えるジョブを登録しない
	 * （ActionScheduler_DBStore::$max_args_length。例外は as_schedule_single_action() の中で
	 * 握り潰され、error_log に 1 行残るだけで 0 が返る）。まとめ取得（10 件/リクエスト）で
	 * 件数が 10 倍になると 220 件 ≒ 9,700 文字になり、ジョブが黙って消えていた。
	 * 余裕を持たせて 7,000 文字に収める。
	 */
	public const MAX_ARGS_JSON_LENGTH = 7000;

	/** 設定に platform が 1 つも無いときに仮定する platform code の長さ。 */
	private const FALLBACK_PLATFORM_CODE_LENGTH = 32;

	public function __construct(
		private ProviderRegistry $registry,
		/**
		 * account の実効レート間隔（RateLimiter::effectiveIntervalMs）を求めるために使う。
		 * final class のため Mockery でモック不可（実インスタンス＋WP 関数スタブでテストする）。
		 */
		private RateLimiter $rateLimiter = new RateLimiter()
	) {}

	/**
	 * account 単位のバッチサイズ。AS のランナーの時間予算から安全マージンを引いた実効秒数を、
	 * account の実効レート間隔で割って算出する（spec §4-1 Important 2。楽天 1.1s・time limit
	 * 30 秒・安全マージン 5 秒→実効 25 秒で 22 件）。まとめ取得できる Provider は、レート枠
	 * 1 つで最大 maxBatchSize() 件を取るので、その倍率を掛ける（設計 2026-11-24 §4-3）。
	 *
	 * 算出結果は `affilicard_refresh_batch_size` フィルタで上書き可能にする（spec §8-2:
	 * 実環境の fetch 所要時間を測って安全マージンを調整する運用を、コードリリース無しで
	 * 行えるようにするため）。最終値は 1 件未満にならないようクランプする（0 だと
	 * バッチが永久に flush されず listing が積みっぱなしになる）。
	 */
	public function sizeFor( string $account ): int {
		$timeLimit  = (int) apply_filters( 'action_scheduler_queue_runner_time_limit', self::DEFAULT_TIME_LIMIT_SECONDS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- AS 自身が定義・適用する既存フィルタを読むだけで、affilicard がここで新しく hook を定義しているわけではない。
		$intervalMs = $this->intervalMsFor( $account );

		$effectiveSeconds = max( 0, $timeLimit - self::DEFAULT_SAFETY_MARGIN_SECONDS );
		$intervalSeconds  = $intervalMs / 1000;
		$size             = $intervalSeconds > 0 ? (int) floor( $effectiveSeconds / $intervalSeconds ) : $effectiveSeconds;
		$size             = max( 1, $size );
		$provider         = $this->providerFor( $account );
		if ( $provider instanceof BatchFetchingProvider ) {
			$size *= max( 1, $provider->maxBatchSize() );
		}

		$size = max( 1, (int) apply_filters( 'affilicard_refresh_batch_size', $size, $account ) );

		// フィルタで大きくされても、ジョブを登録できる長さには必ず収める。
		return min( $size, self::maxItemsForArgs( $account ) );
	}

	/**
	 * ジョブの引数が MAX_ARGS_JSON_LENGTH に収まる最大件数。
	 *
	 * 1 件の長さは最悪値で見積もる: post_id は PHP の整数の最大桁、platform は設定にある
	 * platform code のうち最も長いもの（account を問わない。掃引と手動更新のどちらの items も
	 * 収まるように）。
	 */
	public static function maxItemsForArgs( string $account ): int {
		$longest = 0;
		foreach ( PlatformConfig::all() as $platform ) {
			$longest = max( $longest, strlen( $platform->code ) );
		}
		if ( 0 === $longest ) {
			$longest = self::FALLBACK_PLATFORM_CODE_LENGTH;
		}

		$item     = strlen(
			(string) json_encode(
				array(
					'post_id'  => PHP_INT_MAX,
					'platform' => str_repeat( 'x', $longest ),
				)
			)
		) + 1; // 区切りのカンマ。
		$envelope = strlen(
			(string) json_encode(
				array(
					'account' => $account,
					'items'   => array(),
				)
			)
		);

		return max( 1, intdiv( self::MAX_ARGS_JSON_LENGTH - $envelope, $item ) );
	}

	/**
	 * この account の Provider が 1 リクエストで複数商品を取れるか。
	 */
	public function isBatchFetching( string $account ): bool {
		return $this->providerFor( $account ) instanceof BatchFetchingProvider;
	}

	/**
	 * account の実効レート間隔（ms）。BatchRefreshHandler::intervalMsFor() と同じロジック
	 * （accountCode() 一致の provider の minRequestIntervalMs を GeneralSettings::throttleOverrideMs で上書き）。
	 */
	private function intervalMsFor( string $account ): int {
		$provider = $this->providerFor( $account );
		$floorMs  = null !== $provider ? $provider->minRequestIntervalMs() : 0;
		return $this->rateLimiter->effectiveIntervalMs( $floorMs, GeneralSettings::throttleOverrideMs( $account ) );
	}

	private function providerFor( string $account ): ?ProviderInterface {
		foreach ( $this->registry->all() as $provider ) {
			if ( $provider->accountCode() === $account ) {
				return $provider;
			}
		}
		return null;
	}
}
