<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\BatchRefreshHandler;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\RateLimiter;
use Affilicard\Queue\WorkOutcome;
use Affilicard\Settings\GeneralSettings;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Enqueuer / RateLimiter は final のため直接 Mockery::mock() できない
 * （ブリーフ添付のテスト骨子はこの制約を踏まえておらず、そのままでは
 * 「The class ... is marked final」で全滅する）。tests/Unit/Queue/RefreshHandlerTest.php・
 * AutoCreateHandlerTest.php と同じ流儀で実インスタンスを組み立て、内部で呼ぶ WordPress
 * 関数（as_schedule_single_action 等）・$wpdb（RateLimiter::tryAcquire の CAS）を
 * スタブすることで「Enqueuer のどのメソッドが呼ばれたか」を検証する。
 *
 * get_option の stub は setUp() に無条件（引数無制約）で置かない。WP_Mock/Mockery は
 * 同一メソッドへの複数 shouldReceive を「後勝ち」ではなく登録順（無制約な期待値は
 * 呼び出し回数の上限が無いため以後ずっと勝ち続ける）で解決するため、setUp() に
 * 無制約スタブを置くとテスト側で後から積んだ上書き用スタブが一切効かなくなる
 * （実際に queue_paused=true の上書きが無視され RateLimiter まで到達する形で顕在化した）。
 * 各テストが必要な呼び出しだけを ->with() で明示的に stub する。
 */
final class BatchRefreshHandlerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		if ( isset( $GLOBALS['wpdb'] ) ) {
			unset( $GLOBALS['wpdb'] );
		}
		Mockery::close();
		parent::tearDown();
	}

	private function args( array ...$items ): array {
		return array(
			'account' => 'rakuten',
			'items'   => $items,
		);
	}

	/**
	 * affilicard_general option を stub する。GeneralSettings::isQueuePaused() /
	 * throttleOverrideMs() は同じ get_option( OPTION_KEY, array() ) 呼び出しを共有するため、
	 * 1 回の登録で両方をカバーする（呼び出し回数無制限）。
	 *
	 * @param array<string, mixed> $stored
	 */
	private function stubGeneralSettings( array $stored = array() ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( $stored );
	}

	/**
	 * isAutomatic=true・minRequestIntervalMs=1100・accountCode='rakuten' の provider を
	 * 登録した ProviderRegistry。tests/Unit/Queue/RefreshHandlerTest.php:56 の同名ヘルパと
	 * 同じ実装をコピーしたもの。
	 */
	private function registry(): ProviderRegistry {
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'minRequestIntervalMs' )->andReturn( 1100 );
		$provider->shouldReceive( 'accountCode' )->andReturn( 'rakuten' );

		$registry = new ProviderRegistry();
		$registry->register( $provider );
		return $registry;
	}

	/**
	 * RateLimiter::tryAcquire() が条件付き UPDATE で使う $wpdb を stub する
	 * （RefreshHandlerTest::mockRateLimiterWpdb と同じ流儀）。add_option/wp_cache_delete も
	 * 合わせて許容する。
	 *
	 * @param int ...$queryReturns UPDATE の影響行数を呼び出し順に指定する（1=獲得成功／0=未獲得）。
	 *            複数渡すと呼び出しごとに順に返す（最後の値は以降の呼び出しでも繰り返す）。
	 */
	private function mockRateLimiterWpdb( int ...$queryReturns ): void {
		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $query, ...$args ) => $query );
		$wpdb->shouldReceive( 'query' )->andReturn( ...$queryReturns );
		$GLOBALS['wpdb'] = $wpdb;

		WP_Mock::userFunction( 'add_option' )->andReturn( true );
		WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );
	}

	public function test_全件成功ならper_listingへ落とさない(): void {
		$this->stubGeneralSettings();
		$this->mockRateLimiterWpdb( 1, 1 ); // 2件とも CAS 獲得成功（待たない）。

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->twice()->andReturn( WorkOutcome::SUCCESS );

		// SUCCESS ごとに give-up マーカーを解除する。
		WP_Mock::userFunction( 'delete_transient' )
			->twice()
			->with( Mockery::pattern( '/^affilicard_refresh_gaveup_[12]_rakuten-kobo$/' ) )
			->andReturn( true );

		// per-listing（HOOK_REFRESH）にも自己再投入（HOOK_REFRESH_BATCH）にも一切落ちない。
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();

		$handler = new BatchRefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry(), 30, 5 );

		$handler->handle(
			$this->args(
				array(
					'post_id'  => 1,
					'platform' => 'rakuten-kobo',
				),
				array(
					'post_id'  => 2,
					'platform' => 'rakuten-kobo',
				)
			)
		);

		$this->assertConditionsMet();
	}

	public function test_一時失敗したlistingだけがper_listingへ落ちる(): void {
		$this->stubGeneralSettings();
		$this->mockRateLimiterWpdb( 1, 1 ); // 2件とも CAS 獲得成功（待たない）。

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->with( 1, 'rakuten-kobo' )->andReturn( WorkOutcome::SUCCESS );
		$refresher->shouldReceive( 'refreshOne' )->with( 2, 'rakuten-kobo' )->andReturn( WorkOutcome::TRANSIENT_FAILURE );

		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_refresh_gaveup_1_rakuten-kobo' )
			->andReturn( true );

		// TRANSIENT_FAILURE は Enqueuer::enqueueManual 経由で per-listing（HOOK_REFRESH）へ
		// unschedule→即時 priority MANUAL で積み直される。
		WP_Mock::userFunction( 'as_unschedule_all_actions' )
			->once()
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 2,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten'
			)
			->andReturn( true );
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 2,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 99 );

		$handler = new BatchRefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry(), 30, 5 );

		$handler->handle(
			$this->args(
				array(
					'post_id'  => 1,
					'platform' => 'rakuten-kobo',
				),
				array(
					'post_id'  => 2,
					'platform' => 'rakuten-kobo',
				)
			)
		);

		$this->assertConditionsMet();
	}

	public function test_pause中はfetchせずジョブを温存する(): void {
		$this->stubGeneralSettings( array( 'queue_paused' => true ) );

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->never();

		// 自己再投入（Enqueuer::enqueueBatch → HOOK_REFRESH_BATCH）で温存する。
		// bare return だと AS がこのアクションを complete 扱いにしてジョブが消滅してしまう。
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH_BATCH,
				array(
					'account' => 'rakuten',
					'items'   => array(
						array(
							'post_id'  => 1,
							'platform' => 'rakuten-kobo',
						),
					),
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 42 );

		$handler = new BatchRefreshHandler(
			new Enqueuer(),
			new RateLimiter(),
			$refresher,
			$this->registry(),
			30,
			5
		);

		$handler->handle(
			$this->args(
				array(
					'post_id'  => 1,
					'platform' => 'rakuten-kobo',
				)
			)
		);

		$this->assertConditionsMet();
	}

	/**
	 * 設計要点1・2:「期限チェックは待機の前」「期限が近いとき未処理分が積み直される」。
	 * timeLimitSeconds=safetyMarginSeconds として deadline を即時ゼロにし、1件目の
	 * canAfford() チェックが最初から失敗する状況を作る。RateLimiter::tryAcquire() や
	 * ListingRefresher::refreshOne() には一切到達せず（＝待機に入る前に弾かれる）、
	 * 全件が新しいバッチジョブとして即時積み直される。
	 */
	public function test_期限に間に合わない場合は待たずに未処理分を積み直す(): void {
		$this->stubGeneralSettings();
		// tryAcquire/refreshOne どちらにも到達しないため、どちらもモック不要（呼ばれたら
		// Mockery が未期待の呼び出しとして失敗させる）。
		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->never();

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never(); // enqueueManual は呼ばれない。
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH_BATCH,
				array(
					'account' => 'rakuten',
					'items'   => array(
						array(
							'post_id'  => 1,
							'platform' => 'rakuten-kobo',
						),
						array(
							'post_id'  => 2,
							'platform' => 'rakuten-kobo',
						),
					),
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 7 );

		// timeLimitSeconds === safetyMarginSeconds → JobDeadline::remaining() は常に 0。
		// perItemSeconds（rakuten 1100ms→2秒 + Provider タイムアウト10秒=12秒）を
		// 一切賄えないため、1件目のループ冒頭で即座に積み直して終了する。
		$handler = new BatchRefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry(), 5, 5 );

		$handler->handle(
			$this->args(
				array(
					'post_id'  => 1,
					'platform' => 'rakuten-kobo',
				),
				array(
					'post_id'  => 2,
					'platform' => 'rakuten-kobo',
				)
			)
		);

		$this->assertConditionsMet();
	}

	/**
	 * 設計要点2の後半（クランプに関連する分岐）:「レート枠が取れない → 待つ →
	 * 再取得してもなお取れない」場合は per-listing（enqueueManual）へ委ねる。
	 *
	 * `next_ms` を CAS 呼び出し直前の実時刻とほぼ同時刻に設定し、計算される待機秒
	 * （clampWait を通した後の値）を実質ゼロに保つことで、実時間を消費せず
	 * 「待機してもなお枠が空かない」経路を決定的に再現する（クランプそのものは
	 * JobDeadline::clampWait の純粋ロジックとして Task 2 側で既に検証済み。ここでは
	 * ハンドラがその戻り値を見て usleep をスキップし、期限に余裕があるにもかかわらず
	 * 待機が 0 に切り詰められた状況でも再試行 → per-listing フォールバックへ正しく
	 * つなぐことを検証する）。
	 */
	public function test_待っても枠が取れなければper_listingへ委ねる(): void {
		$this->stubGeneralSettings();
		// CAS が2回とも0行（未獲得）。get_option の "last" は「ほぼ今」を返すため、
		// 計算される待機秒は 0（またはごく短い正の値）に収まり、実待機がほぼ発生しない。
		$nowMs = (int) round( microtime( true ) * 1000 );
		$this->mockRateLimiterWpdb( 0, 0 );
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_ratelimit_rakuten', 0 )
			->andReturn( $nowMs - 1100 ); // next_ms = last + intervalMs(1100) ≈ 今。

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->never();

		WP_Mock::userFunction( 'as_unschedule_all_actions' )
			->once()
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 5,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten'
			)
			->andReturn( true );
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 5,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 1 );

		// 期限には十分な余裕がある（remaining=25秒 >> perItemSeconds=12秒）。
		// つまり「期限が近い」からではなく「レート枠がふさがっている」からの
		// フォールバックであることを確認する。
		$handler = new BatchRefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry(), 30, 5 );

		$handler->handle(
			$this->args(
				array(
					'post_id'  => 5,
					'platform' => 'rakuten-kobo',
				)
			)
		);

		$this->assertConditionsMet();
	}
}
