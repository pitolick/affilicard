<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderRegistry;

/**
 * affilicard_refresh_listing アクションのハンドラ。ThrottledActionHandler の骨格に
 * 「refreshOne で fetch＋反映」を差し込む。
 */
final class RefreshHandler extends ThrottledActionHandler {

	/**
	 * give-up マーカー transient の生存期間。恒久的に解決できない外部 ID（廃盤・無効 ID）の
	 * listing は再取得 TTL（約19-24h）毎に4回 completed＋1回 failed のリトライを毎周回繰り返す。
	 * terminal failure（MAX_ATTEMPTS 到達）後はこの期間だけ掃引でスキップし、毎周回リトライを
	 * 実質的に減らす（再取得 TTL より十分長く取る。API レート予算も節約）。
	 */
	private const GIVEUP_COOLDOWN = 3 * DAY_IN_SECONDS;

	/**
	 * give-up マーカー transient のキー。QueueMaintenance::sweep() が掃引スキップ判定に、
	 * RefreshHandler が set/delete に共有する。
	 */
	public static function giveUpTransientKey( int $postId, string $platform ): string {
		return 'affilicard_refresh_gaveup_' . $postId . '_' . $platform;
	}

	public function __construct(
		private Enqueuer $enqueuer,
		RateLimiter $limiter,
		private ListingRefresher $refresher,
		ProviderRegistry $registry
	) {
		parent::__construct( $limiter, $registry );
	}

	public function handle( int $postId, string $platform ): void {
		$this->run(
			array(
				'post_id'  => $postId,
				'platform' => $platform,
			)
		);
	}

	protected function providerCodeFor( array $args ): ?string {
		$definition = PlatformConfig::find( (string) $args['platform'] );
		return null !== $definition ? $definition->provider : null;
	}

	protected function performWork( array $args ): WorkOutcome {
		// give-up マーカーの set/delete は onTerminalFailure/onSuccess フックに集約する
		// （run() が outcome を見て呼び分ける）。ここでは refreshOne の結果をそのまま返す。
		return $this->refresher->refreshOne( (int) $args['post_id'], (string) $args['platform'] );
	}

	/**
	 * 恒久失敗（TERMINAL_FAILURE＝廃盤/無効 ID で恒久的に解決できない）時に give-up マーカーを
	 * 永続化する。QueueMaintenance::sweep() が GIVEUP_COOLDOWN の間この listing をスキップし、
	 * 再取得 TTL 毎の毎周回リトライ（API 浪費・completed チャーン）を抑える。
	 *
	 * 一時失敗（TRANSIENT_FAILURE＝API 障害・レート制限・保存競合）ではこのフックは呼ばれない
	 * ＝give-up しない。一時障害で価格が長時間隠れ続ける元インシデントを防ぐための肝。
	 *
	 * @param array<string, mixed> $args
	 */
	protected function onTerminalFailure( array $args ): void {
		set_transient(
			self::giveUpTransientKey( (int) $args['post_id'], (string) $args['platform'] ),
			1,
			self::GIVEUP_COOLDOWN
		);
	}

	/**
	 * fetch 成功で give-up マーカーを消す（外部 ID が復旧した listing を通常周期に戻す）。
	 *
	 * @param array<string, mixed> $args
	 */
	protected function onSuccess( array $args ): void {
		delete_transient( self::giveUpTransientKey( (int) $args['post_id'], (string) $args['platform'] ) );
	}

	protected function reschedule( int $whenSec, array $args ): void {
		$providerCode = $this->providerCodeFor( $args );
		if ( null === $providerCode ) {
			return;
		}
		// v2.4.0: 再投入の group も account コード単位（run() の throttle キーと揃える）。
		$account = $this->registry->get( $providerCode )?->accountCode() ?? $providerCode;
		$this->enqueuer->rescheduleRefresh( $whenSec, (int) $args['post_id'], (string) $args['platform'], $account );
	}

	protected function attemptKey( array $args ): string {
		return 'affilicard_refresh_attempts_' . $args['post_id'] . '_' . $args['platform'];
	}

	protected function throttleWaitKey( array $args ): string {
		return 'affilicard_throttle_waits_' . $args['post_id'] . '_' . $args['platform'];
	}
}
