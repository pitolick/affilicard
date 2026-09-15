<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Platform\PlatformConfig;
use Affilicard\Pricing\FetchStatus;
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

	/**
	 * give-up マーカーが「今使う購入リンク」に効いているか。投入経路
	 * （QueueMaintenance::sweep / OfferPromotionTrigger）はこの判定だけを見る。
	 *
	 * **マーカーのキーは (post_id, platform) 単位でしか立たない。** onTerminalFailure() が
	 * 受け取る args にどの購入リンクだったかの情報が無く、キーへ offer の身元を混ぜるには
	 * 実行時にもう一度 listing を読んで選択をやり直すしかない（すでに書かれた既存キーの
	 * 面倒も見る必要がある）。代わりに、恒久失敗した購入リンク自身が持つ
	 * `fetch_status=terminal` を併せて見る——ListingRefresher は TERMINAL_FAILURE を返す
	 * 前に必ずその offer へ terminal を書き込み、保存に失敗した場合は
	 * TRANSIENT_FAILURE へ落ちてマーカー自体が立たないため、マーカーと terminal な
	 * offer は常に対で残る。
	 *
	 * これにより、恒久失敗した購入リンク A のマーカーが、繰り上げた別の購入リンク B
	 * （一度も失敗していない）まで TTL のあいだ止める事故が起きない。B が選ばれている
	 * 間は取得が走り、成功すれば onSuccess() がマーカーを消す。
	 *
	 * @param array<string, mixed> $selectedOffer OfferSelector::select() が選んだ購入リンク。
	 */
	public static function isGivenUp( int $postId, string $platform, array $selectedOffer ): bool {
		if ( ! get_transient( self::giveUpTransientKey( $postId, $platform ) ) ) {
			return false;
		}
		$status = isset( $selectedOffer['fetch_status'] ) ? (string) $selectedOffer['fetch_status'] : '';
		return FetchStatus::isTerminal( $status );
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
	 * refreshOne() が実際に fetch する件数（OfferSelector::select() の選択結果件数）。
	 * ThrottledActionHandler::run() が performWork() の**前**にこれを呼び、レート制限の
	 * 枠をこの件数に比例させて確保する。
	 *
	 * **ここと refreshOne() は listing を別々に読む（意図的に受容している競合）。**
	 * 2 回の読み取りの間に管理画面が同じ listing を保存すると、確保した枠と実際の
	 * fetch 件数がずれる。ずれは今日の選択係が 0 or 1 件しか返さないため最大 1 件で、
	 * 「枠を取ったのに fetch しない（account の次の要求が 1 間隔ぶん無駄に遅れる）」か
	 * 「枠を取らずに fetch する（1 要求が間隔より早く出る）」のどちらかに収まり、
	 * いずれも次の呼び出しで解消する。
	 *
	 * スナップショットを共有しないのは、手段がどれも割に合わないためである。
	 * ロックは refreshOne() が外部 API を叩くあいだ保持することになり有害。
	 * このハンドラはリクエストごとに 1 つ生成されて複数アクションで再利用されるので、
	 * インスタンスへ memo すると別アクションへ古い listing を配ってしまい今より悪い。
	 * 選択結果を performWork() へ引き渡す形は ThrottledActionHandler の契約
	 * （全ハンドラ共通）を変えることになる。窓はこの 2 呼び出しの間だけで外部 I/O を
	 * 挟まず、影響も上記のとおり有界なので、現状は受容する。
	 */
	protected function refreshTargetCount( array $args ): int {
		return $this->refresher->targetCount( (int) $args['post_id'], (string) $args['platform'] );
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
