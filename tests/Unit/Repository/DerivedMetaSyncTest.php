<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Repository;

use Affilicard\Repository\DerivedMetaSync;
use Affilicard\Repository\ProductLockUnavailable;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class DerivedMetaSyncTest extends TestCase {

	/**
	 * GET_LOCK の戻り値（1＝取得成功／0＝タイムアウト）。
	 *
	 * **テストの中で $wpdb モックを作り直すのではなく、ここから読む。** WP_Mock も
	 * Mockery も先に登録した設定を保持するため、テストごとに登録し直す書き方は
	 * 無言で効かないことがある。
	 *
	 * @var int
	 */
	private int $lockResult = 1;

	/**
	 * as_schedule_single_action() へ渡された引数（1 呼び出し 1 要素）。
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $scheduled = array();

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$this->lockResult = 1;
		$this->scheduled  = array();

		$wpdb = Mockery::mock();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $query ) => $query );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( fn() => (string) $this->lockResult );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb;

		// syncDerivedMeta() が触る meta 操作。ここでの関心は「同期できたか」ではなく
		// 「できなかったときに再投入するか」なので、最小限の受け皿で足りる。
		WP_Mock::userFunction( 'get_post_meta' )->andReturn( array() );
		WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );
		WP_Mock::userFunction( 'add_post_meta' )->andReturn( true );
		WP_Mock::userFunction( 'delete_post_meta' )->andReturn( true );

		WP_Mock::userFunction( 'as_schedule_single_action' )
			->andReturnUsing(
				function ( ...$args ) {
					$this->scheduled[] = $args;
					return 4242;
				}
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		unset( $GLOBALS['wpdb'] );
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * 同期できたら再投入しない。
	 */
	public function test_afterRestSaveは同期できたら再投入しない(): void {
		$this->lockResult = 1;

		DerivedMetaSync::afterRestSave( 5 );

		$this->assertSame( array(), $this->scheduled );
	}

	/**
	 * 同期できなければ 2 回目を積む（rest_after_insert 自体が 1 回目）。
	 *
	 * ここが「黙って消えない」の要である。syncDerivedMeta() がミラーを作り直さずに
	 * 戻ったことを呼び出し側が無視すると、ミラーが listings と食い違ったまま放置され、
	 * 自動作成が既存商品を見落として重複を作る。
	 */
	public function test_afterRestSaveは同期できなければ2回目を積む(): void {
		$this->lockResult = 0;

		DerivedMetaSync::afterRestSave( 7 );

		$this->assertCount( 1, $this->scheduled );
		$args = $this->scheduled[0];
		$this->assertSame( DerivedMetaSync::HOOK, $args[1] );
		$this->assertSame(
			array(
				'post_id' => 7,
				'attempt' => 2,
			),
			$args[2]
		);
		$this->assertSame( DerivedMetaSync::GROUP, $args[3] );
		$this->assertTrue( $args[4] );
	}

	/**
	 * 再試行アクションも、同期できなければ次の回を積む。
	 */
	public function test_runは同期できなければ次の回を積む(): void {
		$this->lockResult = 0;

		DerivedMetaSync::run( 7, 2 );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame(
			array(
				'post_id' => 7,
				'attempt' => 3,
			),
			$this->scheduled[0][2]
		);
	}

	/**
	 * 同期できたら次を積まない（積み続けない）。
	 */
	public function test_runは同期できたら次を積まない(): void {
		$this->lockResult = 1;

		DerivedMetaSync::run( 7, 2 );

		$this->assertSame( array(), $this->scheduled );
	}

	/**
	 * 上限まで試してだめなら例外を投げる（Action Scheduler が failed として記録する）。
	 *
	 * 無限に積み直すと、握ったまま返さないセッションのような故障が「永遠に予定されて
	 * いるが永遠に終わらないアクション」として隠れてしまう。諦めたことも見える形にする。
	 *
	 * **メッセージまで固定する。** 型だけだと、モック不足で出た
	 * `Mockery\Exception\NoMatchingExpectationException`（これも RuntimeException を
	 * 継承する）でテストが通ってしまう。
	 */
	public function test_runは上限に達したら例外を投げて積み直さない(): void {
		$this->lockResult = 0;

		$caught = null;
		try {
			DerivedMetaSync::run( 7, DerivedMetaSync::MAX_ATTEMPTS );
		} catch ( ProductLockUnavailable $e ) {
			$caught = $e;
		}

		$this->assertInstanceOf( ProductLockUnavailable::class, $caught );
		$this->assertSame(
			sprintf(
				'affilicard: 商品 %1$d の extid ミラー同期を %2$d 回試みてロックを取得できなかった。',
				7,
				DerivedMetaSync::MAX_ATTEMPTS
			),
			$caught->getMessage()
		);
		$this->assertSame( 7, $caught->postId() );
		$this->assertSame( array(), $this->scheduled );
	}

	/**
	 * 投稿 ID が無効なら何もしない（AS の args が壊れていても巻き込まれない）。
	 */
	public function test_runは投稿IDが無効なら何もしない(): void {
		$this->lockResult = 0;

		DerivedMetaSync::run( 0, 1 );

		$this->assertSame( array(), $this->scheduled );
	}

	/**
	 * ハンドラを配線する。配線が無いと積んだアクションは AS 上に滞留して実行されない。
	 */
	public function test_registerは再試行アクションのハンドラを配線する(): void {
		WP_Mock::expectActionAdded(
			DerivedMetaSync::HOOK,
			array( DerivedMetaSync::class, 'run' ),
			10,
			2
		);

		DerivedMetaSync::register();

		$this->assertConditionsMet();
	}
}
