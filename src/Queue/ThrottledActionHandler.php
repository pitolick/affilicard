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

	/** 本処理（fetch/create）。成功で true。 */
	abstract protected function performWork( array $args ): bool;

	/** 自分自身を $whenSec に再投入（unique=false）。 */
	abstract protected function reschedule( int $whenSec, array $args ): void;

	/** backoff 試行回数を記録する transient キー。 */
	abstract protected function attemptKey( array $args ): string;

	/** throttle 未獲得での再投入待ち回数を記録する transient キー（attemptKey と別カウンタ）。 */
	abstract protected function throttleWaitKey( array $args ): string;

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

		if ( $this->performWork( $args ) ) {
			delete_transient( $this->attemptKey( $args ) );
			return;
		}
		$this->backoff( $args );
	}

	/**
	 * throttle 未獲得（account contention）での再投入。同一 account を奪い合う listing が
	 * 勝者以外いつまでも performWork に到達できず、attempts が増えない＝backoff/failed 化
	 * が機能しない（症状2）ことへの安全弁。待ち回数が MAX_THROTTLE_WAITS に達したら、
	 * これ以上再投入せず打ち切る（＝このアクションはそのまま complete する。cron sweep が
	 * needsRefetch のクールダウン経過後に改めて拾う）。競合は fetch 失敗ではないため、
	 * backoff() と異なり例外は投げない（failed 化しない）。
	 *
	 * @param array<string, mixed> $args
	 */
	private function throttleWait( array $args, int $whenSec ): void {
		$key   = $this->throttleWaitKey( $args );
		$waits = (int) get_transient( $key ) + 1;
		if ( $waits >= self::MAX_THROTTLE_WAITS ) {
			delete_transient( $key );
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
