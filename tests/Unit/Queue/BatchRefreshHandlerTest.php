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
		// unique=false: 実行中の自分自身と account・items が一致するため、true のままだと
		// AS の unique 判定（PENDING/RUNNING 双方が対象）に抑止され戻り値 0 のまま
		// ジョブが痕跡なく消滅する（spec §4-1 Ruling 4）。
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
				false,
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
	 * 前進保証（spec §4-1 Ruling 6）:「そのジョブでまだ1件も処理していない場合は、
	 * 期限を賄えなくても最低1件は試みる」。timeLimitSeconds=safetyMarginSeconds として
	 * JobDeadline::remaining() を常に 0 にし、perItemSeconds（12秒）を一切賄えない状況を
	 * 作る。1件目（まだ何も処理していない）は期限チェックを素通りして強制的に試み
	 * refreshOne が実行されるが、2件目（1件目で processedAny=true になった後）は
	 * 通常どおり期限で弾かれ、jitter 付き・unique=false で積み直される。
	 */
	public function test_1件も処理していなければ期限が賄えなくても最初の1件は試みる(): void {
		$this->stubGeneralSettings();
		$this->mockRateLimiterWpdb( 1 ); // 1件目は即座に CAS 獲得成功（待たない）。

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->once()->with( 1, 'rakuten-kobo' )->andReturn( WorkOutcome::SUCCESS );
		$refresher->shouldReceive( 'refreshOne' )->with( 2, 'rakuten-kobo' )->never();

		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_refresh_gaveup_1_rakuten-kobo' )
			->andReturn( true );

		WP_Mock::userFunction( 'wp_rand' )->andReturn( 0 ); // requeueRemaining の jitter。
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
							'post_id'  => 2,
							'platform' => 'rakuten-kobo',
						),
					),
				),
				'affilicard-rakuten',
				false,
				Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 7 );

		// timeLimitSeconds === safetyMarginSeconds → JobDeadline::remaining() は常に 0。
		// perItemSeconds（rakuten 1100ms→2秒 + Provider タイムアウト10秒=12秒）を
		// 一切賄えないが、1件目は前進保証でゲートを素通りする。
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
	 * spec §4-1 Ruling 5:「クランプに掛かった（要求した待機が残り時間で切り詰められた）
	 * 場合は、待たずに未処理分を積み直して終了する」。1件目（rakuten 1100ms interval で
	 * 即座に CAS 獲得成功・待機なし）で processedAny=true にしたうえで、2件目は
	 * remaining=13秒（outer ゲート canAfford(12) はぎりぎり通過）に対し next_ms を
	 * 約50秒先に設定し、要求した待機（約50秒）が clampWait で 13秒まで切り詰められる
	 * 状況を作る。切り詰められた＝要求より減った場合は usleep を一切行わず即座に
	 * 積み直すため、実待機なしで決定的に検証できる。
	 */
	public function test_クランプで待機が切り詰められたら待たずに積み直す(): void {
		$this->stubGeneralSettings();
		$nowMs = (int) round( microtime( true ) * 1000 );
		$this->mockRateLimiterWpdb( 1, 0 ); // 1件目は獲得成功、2件目は未獲得。
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_ratelimit_rakuten', 0 )
			->andReturn( $nowMs + 48900 ); // next_ms ≈ 今+50秒 → raw waitSec は remaining(13秒) を大きく上回る。

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->once()->with( 1, 'rakuten-kobo' )->andReturn( WorkOutcome::SUCCESS );
		$refresher->shouldReceive( 'refreshOne' )->with( 2, 'rakuten-kobo' )->never();

		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_refresh_gaveup_1_rakuten-kobo' )
			->andReturn( true );

		WP_Mock::userFunction( 'wp_rand' )->andReturn( 0 ); // requeueRemaining の jitter。
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH_BATCH,
				array(
					'account' => 'rakuten',
					'items'   => array(
						array(
							'post_id'  => 2,
							'platform' => 'rakuten-kobo',
						),
					),
				),
				'affilicard-rakuten',
				false,
				Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 8 );

		// remaining=13秒（timeLimit13/margin0）。2件目は outer ゲート（perItemSeconds=12）は
		// 通過するが、実際に計算される待機（約50秒）はクランプで13秒まで切り詰められる＝
		// 要求より減る＝待たずに積み直す。
		$handler = new BatchRefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry(), 13, 0 );

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
	 * 本改修の主目的である「ジョブ内で待って枠を取り、fetch する」経路（waitSec > 0 →
	 * usleep → 2回目の tryAcquire で取得成功 → refreshOne 実行）を検証する。next_ms を
	 * CAS 呼び出し直前の実時刻より約1秒先に設定し、実待機を1秒未満に抑えつつ決定的に
	 * 「待ってから取得できる」経路を再現する。
	 */
	public function test_レート枠が一時的に取れないときは待って再取得しrefreshOneを実行する(): void {
		$this->stubGeneralSettings();
		$nowMs = (int) round( microtime( true ) * 1000 );
		$this->mockRateLimiterWpdb( 0, 1 ); // 1回目は未獲得（待つ）、2回目は獲得成功。
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_ratelimit_rakuten', 0 )
			->andReturn( $nowMs - 1100 + 1000 ); // next_ms ≈ 今+1秒 → waitSec は小さい正の値。

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->once()->with( 9, 'rakuten-kobo' )->andReturn( WorkOutcome::SUCCESS );

		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_refresh_gaveup_9_rakuten-kobo' )
			->andReturn( true );

		WP_Mock::userFunction( 'as_schedule_single_action' )->never();
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();

		$handler = new BatchRefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry(), 30, 5 );

		$handler->handle(
			$this->args(
				array(
					'post_id'  => 9,
					'platform' => 'rakuten-kobo',
				)
			)
		);

		$this->assertConditionsMet();
	}

	/**
	 * TERMINAL_FAILURE（恒久失敗）は give-up マーカーを立てて次へ進む（per-listing へは
	 * 落とさない）。これまでのテストは SUCCESS/TRANSIENT_FAILURE のみで一度も
	 * TERMINAL_FAILURE 経路を通していなかった。
	 */
	public function test_TERMINAL_FAILUREはgiveupマーカーを立てて次へ進む(): void {
		$this->stubGeneralSettings();
		$this->mockRateLimiterWpdb( 1 ); // 即座に CAS 獲得成功（待たない）。

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->once()->with( 3, 'rakuten-kobo' )->andReturn( WorkOutcome::TERMINAL_FAILURE );

		WP_Mock::userFunction( 'set_transient' )
			->once()
			->with( 'affilicard_refresh_gaveup_3_rakuten-kobo', 1, 3 * DAY_IN_SECONDS )
			->andReturn( true );
		WP_Mock::userFunction( 'delete_transient' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();

		$handler = new BatchRefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry(), 30, 5 );

		$handler->handle(
			$this->args(
				array(
					'post_id'  => 3,
					'platform' => 'rakuten-kobo',
				)
			)
		);

		$this->assertConditionsMet();
	}

	/**
	 * Critical 2（spec §4-3）: enqueueBatch（unique=false での自己再投入・積み直し）が
	 * 0（投入失敗）を返した場合、握り潰さず対象 listing を per-listing（enqueueManual）へ
	 * 個別にフォールバックする。pause 経路（requeueOrFallback の最も単純な入口）で
	 * enqueueBatch 自体の投入が失敗した状況を再現する。
	 */
	public function test_バッチ再投入が失敗したらper_listingへ個別にフォールバックする(): void {
		$this->stubGeneralSettings( array( 'queue_paused' => true ) );

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->never();

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
				false,
				Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 0 ); // 投入失敗。

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->twice()->andReturn( true );
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 1,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 101 );
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
			->andReturn( 102 );

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
