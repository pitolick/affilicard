<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\ActionSchedulerLoader;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ActionSchedulerLoaderTest extends TestCase {
	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}
	public function tearDown(): void {
		WP_Mock::tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	/**
	 * as_schedule_single_action() が既に定義済み（他プラグイン同梱の AS が先に読み込まれた等）
	 * であれば、boot() は require を行わず即座に return する。
	 *
	 * グローバル関数はプロセス内で一度定義すると取り消せないため、他テストのグローバル状態を
	 * 汚染しないよう @runInSeparateProcess で隔離プロセス実行する。
	 *
	 * @runInSeparateProcess
	 */
	public function test_boot_as_schedule_single_action定義済みならrequireせず即returnする(): void {
		// このファイルは namespace 配下のため、通常の function 宣言だと
		// Affilicard\Tests\Unit\Queue\as_schedule_single_action が定義されてしまい
		// グローバル関数を検査する function_exists('as_schedule_single_action') に反映されない。
		// eval() は現在の namespace を継承しないため、グローバルスコープへ確実に定義できる。
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			eval( 'function as_schedule_single_action() {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		// class_exists('ActionScheduler_Versions', false) が false のままであること
		// （= 実ファイルを require していないこと）で「早期 return した」ことを検証する。
		$this->assertFalse( class_exists( 'ActionScheduler_Versions', false ) );

		ActionSchedulerLoader::boot();

		$this->assertFalse(
			class_exists( 'ActionScheduler_Versions', false ),
			'as_schedule_single_action が既に定義済みなら bundle 版 AS を require してはならない'
		);
	}

	/**
	 * as_schedule_single_action() 未定義（初回ロード）なら bundle した action-scheduler.php を
	 * require する。require の結果、AS 側が定義する ActionScheduler_Versions クラスが
	 * ロードされることで「実際に require が実行された」ことを検証する。
	 *
	 * plugins_loaded 未発火（did_action()===0）の状態で require されるプロダクションの
	 * プラグイン inclusion 時点と同じ状況を bootstrap.php の did_action/doing_action スタブで再現する。
	 *
	 * @runInSeparateProcess
	 */
	public function test_boot_未定義ならbundle版action_schedulerをrequireする(): void {
		$this->assertFalse( function_exists( 'as_schedule_single_action' ) );
		$this->assertFalse( class_exists( 'ActionScheduler_Versions', false ) );

		ActionSchedulerLoader::boot();

		$this->assertTrue(
			class_exists( 'ActionScheduler_Versions', false ),
			'boot() は bundle した action-scheduler.php を require し ActionScheduler_Versions を定義するべき'
		);
	}

	public function test_path_action_schedulerのfunctions_phpを指す(): void {
		$this->assertStringEndsWith( 'action-scheduler/action-scheduler.php', ActionSchedulerLoader::path() );
	}
}
