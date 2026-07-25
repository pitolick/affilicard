<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\RateLimiter;
use Affilicard\Queue\RefreshHandler;
use Affilicard\Queue\WorkOutcome;
use Affilicard\Settings\GeneralSettings;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class RefreshHandlerTest extends TestCase {

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

	/**
	 * RateLimiter::tryAcquire() が条件付き UPDATE で使う $wpdb を stub する
	 * （v2.4.0: 原子化した CAS のための $GLOBALS['wpdb'] モック。RateLimiterTest と同じ流儀）。
	 * add_option/wp_cache_delete も合わせて許容する。
	 *
	 * @param int $queryReturn UPDATE の影響行数（1=獲得成功／0=未獲得）。
	 */
	private function mockRateLimiterWpdb( int $queryReturn ): void {
		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $query, ...$args ) => $query );
		$wpdb->shouldReceive( 'query' )->andReturn( $queryReturn );
		$GLOBALS['wpdb'] = $wpdb;

		WP_Mock::userFunction( 'add_option' )->andReturn( true );
		WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );
	}

	/**
	 * isAutomatic=true・minRequestIntervalMs=1100 の 'rakuten' provider を登録した ProviderRegistry。
	 */
	private function registry(): ProviderRegistry {
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'minRequestIntervalMs' )->andReturn( 1100 );
		// v2.4.0: RateLimiter/throttle は account コード単位。accountCode()==='rakuten' なので
		// RateLimiter option キー（affilicard_ratelimit_rakuten）・AS group（affilicard-rakuten）は
		// この registry() の provider コードと同じ文字列のまま変わらない。
		$provider->shouldReceive( 'accountCode' )->andReturn( 'rakuten' );

		$registry = new ProviderRegistry();
		$registry->register( $provider );
		return $registry;
	}

	/**
	 * PlatformConfig::find('rakuten-kobo')->provider が 'rakuten' を返すよう
	 * affilicard_platforms option を stub する（実 defaults() の provider は 'rakuten-kobo'
	 * なので、RateLimiter option キー affilicard_ratelimit_rakuten と揃えるためスタブを使う）。
	 */
	private function stubPlatform(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'     => 'rakuten-kobo',
						'provider' => 'rakuten',
					),
				)
			);
	}

	public function test_handle_pause中はfetchせずジョブを再投入して保持する(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array( 'queue_paused' => true ) );
		$this->stubPlatform();
		WP_Mock::userFunction( 'wp_rand' )->andReturn( 0 ); // rescheduleRefresh の jitter

		// fetch は呼ばれない（消費しない）が、ジョブは reschedule で温存される
		// （不再投入だと AS がアクションを complete 扱いにしてジョブが消滅してしまう）。
		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldNotReceive( 'refreshOne' );
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				false,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 1 ); // rescheduleRefresh によるジョブ温存

		$handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );
		$handler->handle( 12, 'rakuten-kobo' );

		$this->assertConditionsMet();
	}

	/**
	 * throttle 未獲得（account contention）で cap 未満の場合: 待ちカウンタ
	 * （affilicard_throttle_waits_*）をインクリメントしつつ、従来通り reschedule で
	 * 再投入する。performWork（refreshOne）は呼ばれない。
	 */
	public function test_handle_throttle未経過かつcap未満なら待機カウンタを増やして再投入しfetchしない(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		// 実行時刻(ms)より確実に未来になる値を使い、実時計に依存せず「間隔未経過」を再現する。
		$this->mockRateLimiterWpdb( 0 ); // CAS の UPDATE が 0 行 = 未獲得
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_ratelimit_rakuten', 0 )
			->andReturn( 9999999999999 );
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( 'affilicard_throttle_waits_12_rakuten-kobo' )
			->andReturn( 5 ); // 5回待たされ済み → 今回で6回目、MAX_THROTTLE_WAITS(30)未満
		WP_Mock::userFunction( 'set_transient' )
			->once()
			->with( 'affilicard_throttle_waits_12_rakuten-kobo', 6, DAY_IN_SECONDS )
			->andReturn( true );
		WP_Mock::userFunction( 'wp_rand' )->andReturn( 0 ); // rescheduleRefresh の jitter
		WP_Mock::userFunction( 'as_schedule_single_action' )->once(); // rescheduleRefresh

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldNotReceive( 'refreshOne' );

		$handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );
		$handler->handle( 12, 'rakuten-kobo' );

		$this->assertConditionsMet();
	}

	/**
	 * throttle 未獲得（account contention）で cap（MAX_THROTTLE_WAITS）到達時: rapid な
	 * 再投入は止める（チャーン抑制）が、ジョブは失わず長い遅延（+1h）で再投入して保持する。
	 * bare complete させると AutoCreate は掃引回復が無く作成要求が永久ロストするため、共通基底
	 * では保持する（refresh も同一挙動）。カウンタは上限のまま維持し、競合は fetch 失敗では
	 * ないため例外は投げない（failed 化しない）。
	 */
	public function test_handle_cap到達時は長い遅延で再投入して保持しジョブを失わない(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		$this->mockRateLimiterWpdb( 0 ); // CAS の UPDATE が 0 行 = 未獲得
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_ratelimit_rakuten', 0 )
			->andReturn( 9999999999999 );
		// MAX_THROTTLE_WAITS(30) - 1 = 29 が記録済み → 今回の待機で 30 回目 = 上限到達。
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( 'affilicard_throttle_waits_12_rakuten-kobo' )
			->andReturn( 29 );
		// 上限維持でカウンタを 30 に据え置き（TTL 更新）。
		WP_Mock::userFunction( 'set_transient' )
			->once()
			->with( 'affilicard_throttle_waits_12_rakuten-kobo', 30, DAY_IN_SECONDS )
			->andReturn( true );
		WP_Mock::userFunction( 'delete_transient' )->never();
		// 打ち切らず、長い遅延で再投入して保持する（reschedule=as_schedule_single_action 1回）。
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()->andReturn( 888 );

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldNotReceive( 'refreshOne' );

		$handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );
		$handler->handle( 12, 'rakuten-kobo' ); // 例外を投げず、長い遅延で再投入して保持する

		$this->assertConditionsMet();
	}

	/**
	 * 今回の修正の肝: 一時失敗（TRANSIENT_FAILURE）が backoff で MAX_ATTEMPTS に達したら
	 * 例外を投げて AS の failed として記録する（従来通り）が、**give-up マーカーは絶対に立てない**。
	 * 一時障害（API 到達不可・レート制限・保存競合）で価格が 3 日間隠れ続ける元インシデントを防ぐ。
	 * give-up は恒久失敗（TERMINAL_FAILURE）経路のみが担う。
	 */
	public function test_handle_transientがリトライ上限到達でも例外は投げるがgiveupマーカーは立てない(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		$this->mockRateLimiterWpdb( 1 ); // CAS の UPDATE が 1 行 = 獲得成功（経過済）

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->once()->with( 12, 'rakuten-kobo' )->andReturn( WorkOutcome::TRANSIENT_FAILURE );

		// throttle 獲得成功 → 待機カウンタはリセットされる（listing が進捗したため）。
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_throttle_waits_12_rakuten-kobo' )
			->andReturn( true );
		// MAX_ATTEMPTS(5) - 1 = 4 が記録済み → 今回の試行で 5 回目 = 上限到達。
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( 'affilicard_refresh_attempts_12_rakuten-kobo' )
			->andReturn( 4 );
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_refresh_attempts_12_rakuten-kobo' )
			->andReturn( true );
		// 肝: 一時失敗では give-up マーカーを立てない（set_transient がそのキーで呼ばれてはならない）。
		WP_Mock::userFunction( 'set_transient' )
			->with( 'affilicard_refresh_gaveup_12_rakuten-kobo', Mockery::any(), Mockery::any() )
			->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never(); // reschedule されない（打ち切り）

		$handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );

		$thrown = null;
		try {
			$handler->handle( 12, 'rakuten-kobo' );
		} catch ( \RuntimeException $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( \RuntimeException::class, $thrown );
		$this->assertConditionsMet();
	}

	/**
	 * 恒久失敗（TERMINAL_FAILURE＝該当なし・無効 ID）は、backoff/リトライを経ず即座に
	 * onTerminalFailure で give-up マーカーを立て、**例外を投げずに complete** する
	 * （リトライしても成功しないため failed 記録は無意味）。attempts は消され、
	 * backoff の get_transient(attempts) も呼ばれない。
	 */
	public function test_handle_terminalは即giveupマーカーを立て例外を投げずcompleteする(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		$this->mockRateLimiterWpdb( 1 ); // CAS の UPDATE が 1 行 = 獲得成功（経過済）

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->once()->with( 12, 'rakuten-kobo' )->andReturn( WorkOutcome::TERMINAL_FAILURE );

		// throttle 獲得成功 → 待機カウンタはリセットされる。
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_throttle_waits_12_rakuten-kobo' )
			->andReturn( true );
		// terminal は attempts を消して complete（backoff の get_transient(attempts) は呼ばれない）。
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_refresh_attempts_12_rakuten-kobo' )
			->andReturn( true );
		WP_Mock::userFunction( 'get_transient' )
			->with( 'affilicard_refresh_attempts_12_rakuten-kobo' )
			->never();
		// onTerminalFailure が give-up マーカーを GIVEUP_COOLDOWN（3日）で立てる。
		WP_Mock::userFunction( 'set_transient' )
			->once()
			->with( 'affilicard_refresh_gaveup_12_rakuten-kobo', 1, 3 * DAY_IN_SECONDS )
			->andReturn( true );
		WP_Mock::userFunction( 'as_schedule_single_action' )->never(); // reschedule されない

		$handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );
		$handler->handle( 12, 'rakuten-kobo' ); // 例外を投げず正常終了する（complete 扱い）

		$this->assertConditionsMet();
	}

	/**
	 * MAX_ATTEMPTS 未満（打ち切り前）は例外を投げず、従来通り backoff で
	 * 自己再投入（reschedule）することを確認する。
	 */
	public function test_handle_リトライ上限未満なら例外を投げず再投入する(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		$this->mockRateLimiterWpdb( 1 ); // CAS の UPDATE が 1 行 = 獲得成功（経過済）

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->once()->with( 12, 'rakuten-kobo' )->andReturn( WorkOutcome::TRANSIENT_FAILURE );

		// throttle 獲得成功 → 待機カウンタはリセットされる（listing が進捗したため）。
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_throttle_waits_12_rakuten-kobo' )
			->andReturn( true );
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( 'affilicard_refresh_attempts_12_rakuten-kobo' )
			->andReturn( 1 ); // 1回目 → 今回で2回目、MAX_ATTEMPTS(5)未満
		WP_Mock::userFunction( 'set_transient' )
			->once()
			->with( 'affilicard_refresh_attempts_12_rakuten-kobo', 2, DAY_IN_SECONDS )
			->andReturn( true );
		WP_Mock::userFunction( 'delete_transient' )->with( 'affilicard_refresh_attempts_12_rakuten-kobo' )->never();
		WP_Mock::userFunction( 'wp_rand' )->andReturn( 0 ); // rescheduleRefresh の jitter
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				false,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 1 );

		$handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );
		$handler->handle( 12, 'rakuten-kobo' );

		$this->assertConditionsMet();
	}

	public function test_handle_取得できればrefreshOneを呼ぶ(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		$this->mockRateLimiterWpdb( 1 ); // CAS の UPDATE が 1 行 = 獲得成功（経過済）
		// throttle 獲得成功 → 待機カウンタはリセットされる（listing が進捗したため）。
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_throttle_waits_12_rakuten-kobo' )
			->andReturn( true );
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_refresh_attempts_12_rakuten-kobo' )
			->andReturn( true );

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->once()->with( 12, 'rakuten-kobo' )->andReturn( WorkOutcome::SUCCESS );
		// B: fetch 成功で give-up マーカーを delete（復旧した listing は通常周期に戻る）。
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_refresh_gaveup_12_rakuten-kobo' )
			->andReturn( true );

		$handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );
		$handler->handle( 12, 'rakuten-kobo' );

		$this->assertConditionsMet();
	}

	/**
	 * force enqueue の args は force=true を余分に持つ（Enqueuer::enqueueForced）。AS は
	 * これを positional に handle() へ渡し得るが、handle(int,string) は非可変長なので余剰
	 * 引数は無視され、force は run()/refreshOne() へ伝播しない（run 時に鮮度スキップは無く
	 * force の実行時挙動は sweep と同一＝必ず fetch なので、伝播しなくても正しい）。余分な
	 * 引数付き呼び出しでも refreshOne が postId/platform だけで呼ばれ壊れないことを担保する。
	 */
	public function test_handle_forcetrueの余分な引数を渡されてもrefreshOneはpostIdとplatformだけで呼ぶ(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		$this->mockRateLimiterWpdb( 1 ); // CAS の UPDATE が 1 行 = 獲得成功（経過済）
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_throttle_waits_12_rakuten-kobo' )
			->andReturn( true );
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_refresh_attempts_12_rakuten-kobo' )
			->andReturn( true );

		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'refreshOne' )->once()->with( 12, 'rakuten-kobo' )->andReturn( WorkOutcome::SUCCESS );
		// B: fetch 成功で give-up マーカーを delete（復旧した listing は通常周期に戻る）。
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_refresh_gaveup_12_rakuten-kobo' )
			->andReturn( true );

		$handler = new RefreshHandler( new Enqueuer(), new RateLimiter(), $refresher, $this->registry() );
		// AS が force=true を positional に渡す状況を模す（余剰引数は非可変長関数で無視される）。
		$handler->handle( 12, 'rakuten-kobo', true );

		$this->assertConditionsMet();
	}
}
