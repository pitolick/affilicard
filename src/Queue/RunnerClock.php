<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * Action Scheduler ランナーの起動時刻を記録する。
 *
 * `BatchRefreshHandler` の `JobDeadline` は「そのジョブ自身の開始時刻」ではなく
 * 「AS ランナー全体の残り時間」を見て期限判定しなければならない（CodeRabbit
 * レビュー・design doc 2026-08-25 追記）。AS は 1 回のランナー起動（`run()`）で
 * 複数アクションを連続実行し、実行時間の計測はランナー生成時刻
 * （`ActionScheduler_Abstract_QueueRunner::$created_time`）から行う
 * （`get_execution_time()` 参照）。しかし `$created_time` は private・
 * `get_execution_time()`/`get_time_limit()`/`time_likely_to_be_exceeded()` は
 * いずれも protected で、AS は「ランナーの残り時間」を取得する public API を
 * 公開していない（vendor/woocommerce/action-scheduler/classes/abstracts/
 * ActionScheduler_Abstract_QueueRunner.php で確認済み）。
 *
 * 代替として、AS が自身のコアクラスから恒常的に利用している public フック
 * `action_scheduler_before_process_queue`（`ActionScheduler_QueueRunner::run()` /
 * `ActionScheduler_WPCLI_QueueRunner::run()` の両方で、バッチ処理ループに入る
 * 「直前」に無条件で 1 回だけ発火する。AS 自身も
 * `ActionScheduler_RecurringActionScheduler::schedule_recurring_scheduler_hook` や
 * `ActionScheduler_wpCommentLogger::disable_comment_counting` の配線にこのフックを
 * 使っており、実装詳細ではなく安定した拡張ポイントである）を使い、ランナーの
 * 処理開始時刻を記録する。`$created_time` そのものではないが、コンストラクタから
 * このフックの発火までに行われる処理（メモリ/時間制限の引き上げ等）はごく短時間で、
 * 実用上は同一ランナー起動の開始時刻として扱ってよい。
 *
 * プロセス内の静的状態として保持する（DB option にしない）。AS ランナーとその中で
 * 実行される affilicard のジョブは同一 PHP プロセス内で同期的に実行されるため、
 * リクエストを跨いだ永続化は不要かつ、毎分の WP-Cron ごとに DB 書き込みを増やす
 * だけの無駄になる（本 spec が削ろうとしている無駄そのもの）。
 */
final class RunnerClock {

	private static ?int $startedAt = null;

	/**
	 * `action_scheduler_before_process_queue` にフックし、ランナー起動のたびに
	 * 開始時刻を記録する。Plugin::bootInstance() で一度だけ呼ぶ想定。
	 */
	public static function register(): void {
		add_action( 'action_scheduler_before_process_queue', array( self::class, 'markStarted' ) );
	}

	/** `action_scheduler_before_process_queue` のハンドラ。 */
	public static function markStarted(): void {
		self::set( time() );
	}

	/**
	 * 記録値を明示的に設定する。テスト、および `markStarted()` からの内部呼び出し用。
	 */
	public static function set( ?int $startedAt ): void {
		self::$startedAt = $startedAt;
	}

	/**
	 * AS ランナーの起動時刻（unix timestamp）。フックが一度も発火していないプロセス
	 * （AS を介さずハンドラを直接呼ぶユニットテスト等）では null を返す——呼び出し側は
	 * 自身の開始時刻へフォールバックすること。
	 */
	public static function startedAt(): ?int {
		return self::$startedAt;
	}
}
