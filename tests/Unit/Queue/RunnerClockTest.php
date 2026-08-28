<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\RunnerClock;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * RunnerClock はプロセス内の静的状態を持つ（design 参照: DB option にせず、AS ランナーと
 * 同一 PHP プロセスで完結する前提）。他のテストクラス（BatchRefreshHandlerTest 等）へ
 * static 状態が漏れて非決定的に失敗しないよう、各テストの前後で必ず null にリセットする。
 */
final class RunnerClockTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		RunnerClock::set( null );
	}

	public function tearDown(): void {
		RunnerClock::set( null );
		WP_Mock::tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	public function test_一度も記録されていなければnullを返す(): void {
		$this->assertNull( RunnerClock::startedAt() );
	}

	public function test_setで明示的に設定した値を返す(): void {
		RunnerClock::set( 1000 );

		$this->assertSame( 1000, RunnerClock::startedAt() );
	}

	public function test_setにnullを渡すと未記録状態へ戻る(): void {
		RunnerClock::set( 1000 );
		RunnerClock::set( null );

		$this->assertNull( RunnerClock::startedAt() );
	}

	/**
	 * markStarted() は呼び出し時点の現在時刻を記録する。WP_Mock は PHP 組み込みの time() を
	 * 差し替えられないため、実行前後の time() で挟んで検証する（実行はほぼ瞬時のため
	 * 決定的）。
	 */
	public function test_markStartedは現在時刻を記録する(): void {
		$before = time();
		RunnerClock::markStarted();
		$after = time();

		$started = RunnerClock::startedAt();
		$this->assertIsInt( $started );
		$this->assertGreaterThanOrEqual( $before, $started );
		$this->assertLessThanOrEqual( $after, $started );
	}

	/**
	 * register() は AS 自身が内部で恒常的に使う public フック
	 * `action_scheduler_before_process_queue`（AS のランナー起動のたびに、バッチ処理の
	 * 直前に無条件で 1 回発火する）に markStarted() をフックする。
	 */
	public function test_registerはaction_scheduler_before_process_queueにmarkStartedをフックする(): void {
		WP_Mock::expectActionAdded(
			'action_scheduler_before_process_queue',
			array( RunnerClock::class, 'markStarted' )
		);

		RunnerClock::register();

		$this->assertConditionsMet();
	}
}
