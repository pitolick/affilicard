<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\AutoCreate\ProductAutoCreator;
use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\FetchResult;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\AutoCreateHandler;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\RateLimiter;
use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Settings\GeneralSettings;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * ProductAutoCreator は final のため直接 Mockery::mock() できない。
 * Task 8 の RefreshHandlerTest（ListingRefresher を直接モック）と異なり、
 * ProductAutoCreator 自身は実インスタンスを組み立て、その依存（ProviderRegistry
 * の中の provider・ProductRepositoryInterface）をモックすることで
 * 「creator->create が呼ばれたか」を fetch/save の呼び出し有無で検証する
 * （ProductAutoCreatorTest.php と同じ流儀）。
 */
final class AutoCreateHandlerTest extends TestCase {

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
	 * isAutomatic=true・minRequestIntervalMs=1100 の 'rakuten' provider モック。
	 * AutoCreateHandler（ThrottledActionHandler 側の provider 判定）と
	 * ProductAutoCreator（実際の fetch）の両方から同一インスタンスを参照させる。
	 */
	private function provider(): ProviderInterface {
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'minRequestIntervalMs' )->andReturn( 1100 );
		// v2.4.0: RateLimiter/throttle は account コード単位。accountCode()==='rakuten' なので
		// RateLimiter option キー（affilicard_ratelimit_rakuten）・AS group（affilicard-rakuten）は
		// この provider() の provider コードと同じ文字列のまま変わらない。
		$provider->shouldReceive( 'accountCode' )->andReturn( 'rakuten' );
		return $provider;
	}

	private function registry( ProviderInterface $provider ): ProviderRegistry {
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

	public function test_handle_pause中はcreateせずジョブを再投入して保持する(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array( 'queue_paused' => true ) );
		$this->stubPlatform();
		WP_Mock::userFunction( 'wp_rand' )->andReturn( 0 ); // rescheduleAutoCreate の jitter

		$provider = $this->provider();
		$provider->shouldNotReceive( 'fetch' );
		$registry = $this->registry( $provider );

		// creator->create が呼ばれれば必ず save に到達するはずなので、
		// 呼ばれないことを save 不発火で検証する（ProductAutoCreator は final で直接モック不可）。
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'save' );
		$creator = new ProductAutoCreator( $registry, $repo );

		// fetch/create は呼ばれない（消費しない）が、ジョブは reschedule で温存される
		// （不再投入だと AS がアクションを complete 扱いにしてジョブが消滅してしまう）。
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_AUTOCREATE,
				array(
					'platform'    => 'rakuten-kobo',
					'external_id' => 'ext-001',
				),
				'affilicard-rakuten',
				false,
				Enqueuer::PRIORITY_FORCE
			)
			->andReturn( 1 ); // rescheduleAutoCreate によるジョブ温存

		$handler = new AutoCreateHandler( new Enqueuer(), new RateLimiter(), $creator, $registry );
		$handler->handle( 'rakuten-kobo', 'ext-001' );

		$this->assertConditionsMet();
	}

	/**
	 * throttle 未獲得（account contention）で cap 未満の場合: 待ちカウンタ
	 * （affilicard_throttle_waits_*）をインクリメントしつつ、従来通り reschedule で
	 * 再投入する。performWork（create/fetch）は呼ばれない。
	 */
	public function test_handle_throttle未経過かつcap未満なら待機カウンタを増やして再投入しcreateしない(): void {
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
			->with( 'affilicard_throttle_waits_rakuten-kobo_ext-001' )
			->andReturn( 5 ); // 5回待たされ済み → 今回で6回目、MAX_THROTTLE_WAITS(30)未満
		WP_Mock::userFunction( 'set_transient' )
			->once()
			->with( 'affilicard_throttle_waits_rakuten-kobo_ext-001', 6, DAY_IN_SECONDS )
			->andReturn( true );
		WP_Mock::userFunction( 'wp_rand' )->andReturn( 0 ); // rescheduleAutoCreate の jitter
		WP_Mock::userFunction( 'as_schedule_single_action' )->once(); // rescheduleAutoCreate

		$provider = $this->provider();
		$provider->shouldNotReceive( 'fetch' );
		$registry = $this->registry( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'save' );
		$creator = new ProductAutoCreator( $registry, $repo );

		$handler = new AutoCreateHandler( new Enqueuer(), new RateLimiter(), $creator, $registry );
		$handler->handle( 'rakuten-kobo', 'ext-001' );

		$this->assertConditionsMet();
	}

	/**
	 * throttle 未獲得（account contention）で cap（MAX_THROTTLE_WAITS）到達時: rapid な
	 * 再投入は止める（チャーン抑制）が、**ジョブは失わない**。bare complete させると
	 * AutoCreate は掃引回復が無く作成要求が永久ロストするため、長い遅延（+1h）で再投入して
	 * 保持し、カウンタは上限のまま維持する（競合は fetch 失敗ではないため例外は投げない）。
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
			->with( 'affilicard_throttle_waits_rakuten-kobo_ext-001' )
			->andReturn( 29 );
		// 上限維持でカウンタを 30 に据え置き（TTL 更新）。
		WP_Mock::userFunction( 'set_transient' )
			->once()
			->with( 'affilicard_throttle_waits_rakuten-kobo_ext-001', 30, DAY_IN_SECONDS )
			->andReturn( true );
		WP_Mock::userFunction( 'delete_transient' )->never();
		// 打ち切らず、長い遅延で再投入して保持する（reschedule=as_schedule_single_action 1回）。
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()->andReturn( 777 );

		$provider = $this->provider();
		$provider->shouldNotReceive( 'fetch' );
		$registry = $this->registry( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'save' );
		$creator = new ProductAutoCreator( $registry, $repo );

		$handler = new AutoCreateHandler( new Enqueuer(), new RateLimiter(), $creator, $registry );
		$handler->handle( 'rakuten-kobo', 'ext-001' ); // 例外を投げず、長い遅延で再投入して保持する

		$this->assertConditionsMet();
	}

	public function test_handle_取得できればcreateを呼ぶ(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		$this->mockRateLimiterWpdb( 1 ); // CAS の UPDATE が 1 行 = 獲得成功（経過済）
		// throttle 獲得成功 → 待機カウンタはリセットされる（listing が進捗したため）。
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_throttle_waits_rakuten-kobo_ext-001' )
			->andReturn( true );
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_autocreate_attempts_rakuten-kobo_ext-001' )
			->andReturn( true );

		$provider = $this->provider();
		$provider->shouldReceive( 'fetch' )
			->once()
			->with( 'ext-001', Mockery::type( 'array' ) )
			->andReturn(
				FetchResult::hit(
					array(
						'title'           => '架空のサンプル作品',
						'price'           => '600',
						'list_price'      => '1000',
						'badge'           => '',
						'image_url'       => 'https://example.test/i.jpg',
						'regular_url'     => 'https://example.test/r',
						'affiliate_url'   => 'https://example.test/a',
						'platform_extras' => array(),
					)
				)
			);
		$registry = $this->registry( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'save' )->once()->andReturn( 55 );
		$creator = new ProductAutoCreator( $registry, $repo );

		$handler = new AutoCreateHandler( new Enqueuer(), new RateLimiter(), $creator, $registry );
		$handler->handle( 'rakuten-kobo', 'ext-001' );

		$this->assertConditionsMet();
	}

	/**
	 * 恒久失敗（TERMINAL_FAILURE＝fetch miss＝無効/該当なし ID）時、AutoCreateHandler::onTerminalFailure
	 * が give-up マーカー（affilicard_autocreate_failed_{platform}_{externalId}）を 24h TTL で立て、
	 * backoff/リトライを経ず**例外を投げずに complete** する。Block::autoCreate はこのマーカーを見て
	 * 恒久失敗 ID の再 enqueue を止める。attempts は消され、backoff の get_transient(attempts) は呼ばれない。
	 */
	public function test_handle_terminalは即giveupマーカーを立て例外を投げずcompleteする(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		$this->mockRateLimiterWpdb( 1 ); // throttle 獲得成功（経過済）→ performWork に到達
		// throttle 獲得で待機カウンタはリセットされる。
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_throttle_waits_rakuten-kobo_ext-001' )
			->andReturn( true );

		// fetch miss（該当なし・無効 ID）→ create が TERMINAL_FAILURE → 即 give-up・complete。
		$provider = $this->provider();
		$provider->shouldReceive( 'fetch' )->once()->andReturn( FetchResult::miss() );
		$registry = $this->registry( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'save' );
		$creator = new ProductAutoCreator( $registry, $repo );

		// terminal は attempts を消して complete（backoff の get_transient(attempts) は呼ばれない）。
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_autocreate_attempts_rakuten-kobo_ext-001' )
			->andReturn( true );
		WP_Mock::userFunction( 'get_transient' )
			->with( 'affilicard_autocreate_attempts_rakuten-kobo_ext-001' )
			->never();
		// onTerminalFailure が give-up マーカーを 24h TTL で立てる。
		WP_Mock::userFunction( 'set_transient' )
			->once()
			->with( 'affilicard_autocreate_failed_rakuten-kobo_ext-001', 1, DAY_IN_SECONDS )
			->andReturn( true );
		WP_Mock::userFunction( 'as_schedule_single_action' )->never(); // reschedule されない

		$handler = new AutoCreateHandler( new Enqueuer(), new RateLimiter(), $creator, $registry );
		$handler->handle( 'rakuten-kobo', 'ext-001' ); // 例外を投げず正常終了する（complete 扱い）

		$this->assertConditionsMet();
	}

	/**
	 * 一時失敗（TRANSIENT_FAILURE＝fetch error＝API 到達不可）が MAX_ATTEMPTS に達したら例外は投げるが、
	 * **give-up マーカーは立てない**。一時障害の externalId を恒久失敗として封印してしまわないための肝。
	 */
	public function test_handle_transientがリトライ上限到達でも例外は投げるがgiveupマーカーは立てない(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		$this->mockRateLimiterWpdb( 1 ); // throttle 獲得成功（経過済）→ performWork に到達
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_throttle_waits_rakuten-kobo_ext-001' )
			->andReturn( true );

		// fetch error（API 到達不可）→ create が TRANSIENT_FAILURE → backoff。
		$provider = $this->provider();
		$provider->shouldReceive( 'fetch' )->once()->andReturn( FetchResult::error() );
		$registry = $this->registry( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'save' );
		$creator = new ProductAutoCreator( $registry, $repo );

		// attempts が既に MAX_ATTEMPTS-1(=4) → 今回で 5 に達し打ち切り。
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( 'affilicard_autocreate_attempts_rakuten-kobo_ext-001' )
			->andReturn( 4 );
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_autocreate_attempts_rakuten-kobo_ext-001' )
			->andReturn( true );
		// 肝: 一時失敗では give-up マーカーを立てない。
		WP_Mock::userFunction( 'set_transient' )
			->with( 'affilicard_autocreate_failed_rakuten-kobo_ext-001', Mockery::any(), Mockery::any() )
			->never();

		$handler = new AutoCreateHandler( new Enqueuer(), new RateLimiter(), $creator, $registry );

		$this->expectException( \RuntimeException::class );
		$handler->handle( 'rakuten-kobo', 'ext-001' );
	}
}
