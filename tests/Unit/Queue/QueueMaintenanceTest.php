<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\PostType\ProductPostType;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Provider\Rakuten\RakutenProvider;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\QueueMaintenance;
use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Settings\GeneralSettings;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * QueueMaintenance::sweep() / registerRetentionFilters() のテスト。
 *
 * Enqueuer は final class のため Mockery でモックできず、他の Queue テスト
 * （RefreshHandlerTest/AutoCreateHandlerTest）と同様に実 Enqueuer を使い、
 * Action Scheduler 関数（as_schedule_single_action 等）呼び出しの有無で
 * enqueueSweep が実質的に呼ばれたかどうかを観測する。
 */
final class QueueMaintenanceTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/** affilicard_platforms option を rakuten-kobo 1件で stub する（priceTtlHours=24）。 */
	private function stubRakutenPlatform(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( \Affilicard\Platform\PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'          => 'rakuten-kobo',
						'name'          => '楽天Kobo',
						'provider'      => 'rakuten-kobo',
						'displayOrder'  => 3,
						'enabled'       => true,
						'priceTtlHours' => 24,
					),
				)
			);
	}

	/** RakutenProvider（code='rakuten-kobo', accountCode='rakuten'）を登録した ProviderRegistry。 */
	private function registry(): ProviderRegistry {
		$registry = new ProviderRegistry();
		$registry->register( new RakutenProvider() );
		return $registry;
	}

	/** @param list<array<string, mixed>> $listings */
	private function product( int $id, array $listings ): array {
		return array(
			'id'           => $id,
			'title'        => '対象作品',
			'content'      => '',
			'status'       => 'publish',
			'product_type' => 'generic',
			'stock_status' => 'available',
			'extras'       => array(),
			'listings'     => $listings,
		);
	}

	public function test_sweep_公開商品idを取得する(): void {
		WP_Mock::userFunction( 'get_posts' )->once()->with(
			Mockery::on(
				function ( $args ) {
					return ProductPostType::POST_TYPE === $args['post_type']
						&& 'publish' === $args['post_status']
						&& 'ids' === $args['fields']
						&& -1 === $args['posts_per_page']
						&& true === $args['no_found_rows'];
				}
			)
		)->andReturn( array() );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );

		( new QueueMaintenance( $repo, new Enqueuer(), $this->registry() ) )->sweep();

		$this->assertConditionsMet();
	}

	public function test_sweep_get_postsが配列以外なら何もしない(): void {
		WP_Mock::userFunction( 'get_posts' )->andReturn( false );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );

		( new QueueMaintenance( $repo, new Enqueuer(), $this->registry() ) )->sweep();

		$this->assertConditionsMet();
	}

	public function test_sweep_商品が見つからない場合はスキップする(): void {
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( 99 ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 99 )->andReturn( null );

		( new QueueMaintenance( $repo, new Enqueuer(), $this->registry() ) )->sweep();

		$this->assertConditionsMet();
	}

	public function test_sweep_公開商品のstaleな自動listingをenqueueSweepする(): void {
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( 12 ) );
		$this->stubRakutenPlatform();
		// B: give-up マーカー無し（get_transient=false）→ 通常通り enqueueSweep する。
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'        => 'rakuten-kobo',
						'enabled'         => true,
						'update_mode'     => 'auto',
						'auto_update'     => true,
						'external_id'     => 'deadbeef01',
						'price'           => '500',
						'last_fetched_at' => gmdate( 'c', time() - 25 * 3600 ), // 直近の試行がTTL超過（TTL=24h）
					),
				)
			)
		);

		WP_Mock::userFunction( 'as_get_scheduled_actions' )->andReturn( array() ); // depth 0
		WP_Mock::userFunction( 'wp_rand' )->with( 0, 300 )->andReturn( 0 );
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten', // group = affilicard-{account}（v2.4.0）
				true,
				Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 200 );

		( new QueueMaintenance( $repo, new Enqueuer(), $this->registry() ) )->sweep();

		$this->assertConditionsMet();
	}

	/**
	 * last_fetched_at（最終試行時刻）が TTL 内なら、last_verified_at（成功時刻）が
	 * 古い/空・price が空（＝失敗が続いている listing）でもスキップする。
	 * これにより毎掃引で際限なく再エンキューされる perpetual retry を防ぐ。
	 */
	public function test_sweep_last_fetched_atがTTL内のlistingはenqueueSweepでスキップされAS未呼び出し(): void {
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( 15 ) );
		$this->stubRakutenPlatform();
		// B: give-up マーカー無し（get_transient=false）。鮮度スキップの前に give-up 判定が走る。
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 15 )->andReturn(
			$this->product(
				15,
				array(
					array(
						'platform'         => 'rakuten-kobo',
						'enabled'          => true,
						'update_mode'      => 'auto',
						'auto_update'      => true,
						'external_id'      => 'deadbeef02',
						'price'            => '',
						'last_verified_at' => '',
						'last_fetched_at'  => gmdate( 'c', time() - 3600 ), // 直近の試行はTTL内（TTL=24h）
					),
				)
			)
		);

		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		( new QueueMaintenance( $repo, new Enqueuer(), $this->registry() ) )->sweep();

		$this->assertConditionsMet();
	}

	/**
	 * B: give-up マーカー（RefreshHandler::giveUpTransientKey）が立っている listing は、
	 * eligibility・account 解決を満たし stale であっても enqueueSweep せずスキップする。
	 * 恒久失敗（廃盤/無効 ID）listing の再取得 TTL 毎の毎周回リトライを COOLDOWN 中は抑える。
	 */
	public function test_sweep_giveup済みlistingはenqueueSweepせずAS未呼び出し(): void {
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( 16 ) );
		$this->stubRakutenPlatform();

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 16 )->andReturn(
			$this->product(
				16,
				array(
					array(
						'platform'        => 'rakuten-kobo',
						'enabled'         => true,
						'update_mode'     => 'auto',
						'auto_update'     => true,
						'external_id'     => 'deadbeef03',
						'price'           => '',
						'last_fetched_at' => gmdate( 'c', time() - 25 * 3600 ), // stale だが give-up 中
					),
				)
			)
		);

		// give-up マーカーが立っている（truthy）→ 掃引はこの listing をスキップする。
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( 'affilicard_refresh_gaveup_16_rakuten-kobo' )
			->andReturn( 1 );
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();
		WP_Mock::userFunction( 'as_get_scheduled_actions' )->never();

		( new QueueMaintenance( $repo, new Enqueuer(), $this->registry() ) )->sweep();

		$this->assertConditionsMet();
	}

	public function test_sweep_manualかdisabledかauto_update無効のlistingはenqueueSweepしない(): void {
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( 13 ) );
		$this->stubRakutenPlatform();

		$stale = gmdate( 'c', time() - 25 * 3600 );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 13 )->andReturn(
			$this->product(
				13,
				array(
					array(
						'platform'         => 'rakuten-kobo',
						'enabled'          => true,
						'update_mode'      => 'manual', // manual → 対象外
						'auto_update'      => true,
						'external_id'      => 'e1',
						'last_verified_at' => $stale,
					),
					array(
						'platform'         => 'rakuten-kobo',
						'enabled'          => false, // disabled → 対象外
						'update_mode'      => 'auto',
						'auto_update'      => true,
						'external_id'      => 'e2',
						'last_verified_at' => $stale,
					),
					array(
						'platform'         => 'rakuten-kobo',
						'enabled'          => true,
						'update_mode'      => 'auto',
						'auto_update'      => false, // auto_update off → 対象外
						'external_id'      => 'e3',
						'last_verified_at' => $stale,
					),
				)
			)
		);

		WP_Mock::userFunction( 'as_schedule_single_action' )->never();
		WP_Mock::userFunction( 'as_get_scheduled_actions' )->never();

		( new QueueMaintenance( $repo, new Enqueuer(), $this->registry() ) )->sweep();

		$this->assertConditionsMet();
	}

	public function test_sweep_未知platformのlistingはenqueueSweepしない(): void {
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( 14 ) );
		WP_Mock::userFunction( 'get_option' )
			->with( \Affilicard\Platform\PlatformConfig::OPTION_KEY, array() )
			->andReturn( array() ); // platform 定義なし → find() は null

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 14 )->andReturn(
			$this->product(
				14,
				array(
					array(
						'platform'         => 'unknown-platform',
						'enabled'          => true,
						'update_mode'      => 'auto',
						'auto_update'      => true,
						'external_id'      => 'e1',
						'last_verified_at' => gmdate( 'c', time() - 25 * 3600 ),
					),
				)
			)
		);

		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		( new QueueMaintenance( $repo, new Enqueuer(), $this->registry() ) )->sweep();

		$this->assertConditionsMet();
	}

	/** affilicard_general option を retention 値でスタブする。 */
	private function stubRetention( int $doneHours, int $failedDays ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn(
				array(
					'retention_done_hours'  => $doneHours,
					'retention_failed_days' => $failedDays,
				)
			);
	}

	public function test_registerRetentionFilters_completedとfailedの両フィルタを登録する(): void {
		WP_Mock::expectFilterAdded(
			'action_scheduler_retention_period',
			array( QueueMaintenance::class, 'doneRetentionSeconds' )
		);
		WP_Mock::expectFilterAdded(
			'action_scheduler_retention_period_for_failed',
			array( QueueMaintenance::class, 'failedRetentionSeconds' )
		);

		QueueMaintenance::registerRetentionFilters();

		$this->assertConditionsMet();
	}

	public function test_doneRetentionSeconds_retention_done_hoursを秒に変換して返す(): void {
		$this->stubRetention( 48, 7 );

		$this->assertSame( 48 * HOUR_IN_SECONDS, QueueMaintenance::doneRetentionSeconds() );
	}

	public function test_failedRetentionSeconds_retention_failed_daysを秒に変換して返す(): void {
		$this->stubRetention( 24, 10 );

		$this->assertSame( 10 * DAY_IN_SECONDS, QueueMaintenance::failedRetentionSeconds() );
	}
}
