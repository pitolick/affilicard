<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Provider\ProviderRegistry;
use Affilicard\Settings\GeneralSettings;

/**
 * throttle 付き AS ハンドラの共通骨格。
 * pause ゲート → provider 解決 → account 解決（v2.4.0: RateLimiter/throttle は provider
 * ではなく共有 API＝account 単位）→ RateLimiter（未経過なら本処理せず後ろ倒し）→
 * performWork → 失敗は backoff 再投入。
 * サブクラスは providerCodeFor / performWork / reschedule / attemptKey を実装する。
 */
abstract class ThrottledActionHandler {

	protected const MAX_ATTEMPTS = 5;

	/**
	 * throttle 未獲得（account contention）での再投入回数の上限。attempts/backoff は
	 * performWork が実際に呼ばれた（＝失敗した）回数しか数えないため、同一 account を
	 * 複数 listing が奪い合う状況では、負け続ける listing が永遠に performWork に到達
	 * できず attempts が一切増えない＝failed に絶対到達しない（症状2）。この安全弁で
	 * 「待たされ過ぎている」listing を検知し、これ以上再投入せず cron sweep に譲る。
	 */
	protected const MAX_THROTTLE_WAITS = 30;

	/** pause 中に温存したジョブを再チェックするまでの待機秒数（10分）。 */
	protected const PAUSE_RETRY_SECONDS = 600;

	public function __construct(
		protected RateLimiter $limiter,
		protected ProviderRegistry $registry
	) {}

	/** args から provider コードを解決（不明なら null）。 */
	abstract protected function providerCodeFor( array $args ): ?string;

	/**
	 * 本処理（fetch/create）。結果を WorkOutcome で返す。
	 * SUCCESS＝成功/対象なし・TRANSIENT_FAILURE＝一時失敗（リトライ）・TERMINAL_FAILURE＝恒久失敗（即 give-up）。
	 */
	abstract protected function performWork( array $args ): WorkOutcome;

	/** 自分自身を $whenSec に再投入（unique=false）。 */
	abstract protected function reschedule( int $whenSec, array $args ): void;

	/** backoff 試行回数を記録する transient キー。 */
	abstract protected function attemptKey( array $args ): string;

	/** throttle 未獲得での再投入待ち回数を記録する transient キー（attemptKey と別カウンタ）。 */
	abstract protected function throttleWaitKey( array $args ): string;

	/**
	 * 恒久失敗（TERMINAL_FAILURE＝該当なし・無効 ID 等、リトライしても成功しない）時に 1 度だけ
	 * 呼ぶフック。base は no-op。サブクラスが override して give-up マーカーの永続化等を行う。
	 *
	 * v2.4.0 の再設計により give-up はこの terminal 経路のみが担う。一時失敗（TRANSIENT_FAILURE）は
	 * backoff で MAX_ATTEMPTS に達しても failed 化するだけで、このフックは呼ばれない（give-up しない）。
	 * これにより一時障害で価格が長時間隠れ続ける問題を防ぐ。
	 *
	 * @param array<string, mixed> $args
	 */
	protected function onTerminalFailure( array $args ): void {
		// no-op（サブクラスが override する）。
	}

	/**
	 * 成功（SUCCESS）時に 1 度だけ呼ぶフック。base は no-op。サブクラスが override して
	 * give-up マーカーの解除（復旧した listing/ID を通常周期に戻す）等を行う。
	 *
	 * @param array<string, mixed> $args
	 */
	protected function onSuccess( array $args ): void {
		// no-op（サブクラスが override する）。
	}

	/**
	 * @param array<string, mixed> $args
	 */
	protected function run( array $args ): void {
		if ( GeneralSettings::isQueuePaused() ) {
			// bare return だと AS がこのアクションを complete 扱いにしてジョブが消滅する
			// （force/autocreate は掃引による再エンキューが無いため恒久ロスト）。
			// スケジュールは残し、復旧後に処理する契約を守るため自己再投入して温存する。
			$this->reschedule( time() + self::PAUSE_RETRY_SECONDS, $args );
			return;
		}
		$providerCode = $this->providerCodeFor( $args );
		if ( null === $providerCode ) {
			return;
		}
		$provider = $this->registry->get( $providerCode );
		if ( null === $provider || ! $provider->isAutomatic() ) {
			return;
		}
		// v2.4.0: RateLimiter/throttle は provider ではなく共有 API＝account 単位でかける
		// （認証画面と一致）。accountCode() が null（理論上は手動系のみだが isAutomatic()
		// で既に弾いているため通常到達しない）の場合は provider コードへフォールバックする。
		$account = $provider->accountCode() ?? $providerCode;

		$interval = $this->limiter->effectiveIntervalMs(
			$provider->minRequestIntervalMs(),
			GeneralSettings::throttleOverrideMs( $account )
		);
		$nowMs    = (int) round( microtime( true ) * 1000 );
		$acquire  = $this->limiter->tryAcquire( $account, $interval, $nowMs );
		if ( ! $acquire['ok'] ) {
			$this->throttleWait( $args, (int) ceil( $acquire['next_ms'] / 1000 ) );
			return;
		}

		// account を獲得できた＝競合待ちから抜けて進捗した。待機カウンタをリセットする。
		delete_transient( $this->throttleWaitKey( $args ) );

		$outcome = $this->performWork( $args );
		if ( WorkOutcome::SUCCESS === $outcome ) {
			// 成功/対象なし: attempts を消し、give-up マーカー解除フックを呼ぶ。
			delete_transient( $this->attemptKey( $args ) );
			$this->onSuccess( $args );
			return;
		}
		if ( WorkOutcome::TERMINAL_FAILURE === $outcome ) {
			// 恒久失敗: リトライ無意味。attempts を消して give-up マーカーを立て、そのまま complete
			// する（backoff せず・例外も投げない）。failed 記録は無意味なため作らない。
			delete_transient( $this->attemptKey( $args ) );
			$this->onTerminalFailure( $args );
			return;
		}
		// 一時失敗（TRANSIENT_FAILURE）: backoff でリトライ。MAX_ATTEMPTS 到達時は failed 化するが
		// give-up マーカーは立てない（backoff は onTerminalFailure を呼ばない）。
		$this->backoff( $args );
	}

	/**
	 * throttle 未獲得（account contention）での再投入。同一 account を奪い合うジョブが
	 * 短時間に密集すると rapid な再投入（completed アクションのチャーン。症状1）や、勝者以外
	 * が performWork に到達できない状況が起こり得る。掃引ジョブは Enqueuer の決定的
	 * スタガリングで実効レート間隔ぶん分散させて衝突自体を根本回避しているため、この待機は
	 * 稀にしか発生しない安全弁。
	 *
	 * MAX_THROTTLE_WAITS に達したら rapid な再投入は止める（チャーン抑制）が、**ジョブは
	 * 失わない**。ここで bare return すると AS がアクションを complete 扱いにしてジョブが消滅し、
	 * 特に AutoCreate は掃引による回復経路が無いため作成要求が永久ロストする。よって上限到達時は
	 * 長い遅延（+1h）で低頻度に再投入して保持する。カウンタは上限のまま維持（TTL 更新）し、
	 * 混雑が続く限り 1h 毎の再投入に留める。account 獲得成功時に run() がカウンタを消すので、
	 * 混雑解消後は通常の処理へ戻る。競合は fetch 失敗ではないため例外は投げない（failed 化しない）。
	 *
	 * @param array<string, mixed> $args
	 */
	private function throttleWait( array $args, int $whenSec ): void {
		$key   = $this->throttleWaitKey( $args );
		$waits = (int) get_transient( $key ) + 1;
		if ( $waits >= self::MAX_THROTTLE_WAITS ) {
			// rapid な再投入は打ち切るが、ジョブは失わず長い遅延で保持する（AutoCreate は
			// 掃引回復が無いため complete させると永久ロストする）。カウンタは上限維持。
			set_transient( $key, self::MAX_THROTTLE_WAITS, DAY_IN_SECONDS );
			$this->reschedule( max( $whenSec, time() + HOUR_IN_SECONDS ), $args );
			return;
		}
		set_transient( $key, $waits, DAY_IN_SECONDS );
		$this->reschedule( $whenSec, $args );
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private function backoff( array $args ): void {
		$key      = $this->attemptKey( $args );
		$attempts = (int) get_transient( $key ) + 1;
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			delete_transient( $key );
			// 一時失敗（transient）のリトライ上限到達。give-up マーカーは立てない（onTerminalFailure は
			// 呼ばない）。恒久失敗と違い、後で API が回復すれば成功し得るため封印しない。
			// 打ち切り。bare return だと AS がこのアクションを complete 扱いにしてしまい、
			// 失敗が可視化されない・パネルの failed 件数/「失敗を再試行」が機能しなくなる。
			// AS のランナーはアクションコールバックを try/catch しており、投げられた例外を
			// failed アクションとして記録する（catch した Throwable のメッセージ付きで記録）
			// ため、例外を投げて意図的に failed 化する。fetch_error は listing 側に
			// refreshOne が既に記録済み（Fallback 列で可視化）で、こちらは AS 側の記録。
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- HTML 出力ではなく AS の内部ログ（action_scheduler_logs.message）に保存される例外メッセージ。$args は post_id（int）/platform（既知 platform コード）のみで外部入力を含まない。
				'affilicard: 価格更新のリトライ上限に達しました (' . implode( ',', array_map( 'strval', $args ) ) . ')'
			);
		}
		set_transient( $key, $attempts, DAY_IN_SECONDS );
		$delay = min( 3600, (int) pow( 2, $attempts ) * 60 ); // 指数バックオフ・上限 1h クランプ
		$this->reschedule( time() + $delay, $args );
	}
}
