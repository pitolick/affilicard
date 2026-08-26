<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\PostType\ProductPostType;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Provider\Rakuten\RakutenProvider;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\QueueMaintenance;
use Affilicard\Queue\SweepCursor;
use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Settings\GeneralSettings;
use Affilicard\Stocktake\StocktakePolicy;
use Affilicard\Upgrade\PluginUpgrade;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * QueueMaintenance::sweep() / registerRetentionFilters() のテスト。
 *
 * Enqueuer は final class のため Mockery でモックできず、他の Queue テスト
 * （RefreshHandlerTest/AutoCreateHandlerTest）と同様に実 Enqueuer を使い、
 * Action Scheduler 関数（as_schedule_single_action 等）呼び出しの有無で
 * enqueueBatch が実質的に呼ばれたかどうかを観測する。StocktakePolicy も final の
 * ため、既定インスタンス（stocktake_enabled=false スタブで無効化）か、
 * PublicationDate 実体 + WP 関数スタブで動かす。
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

	/** SweepCursor::get() が読む option をスタブする。 */
	private function stubCursor( int $current = 0 ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( SweepCursor::OPTION_KEY, 0 )
			->andReturn( $current );
	}

	/**
	 * affilicard_general option をスタブする。既定は queue_depth_cap=500（cap に掛から
	 * ない）・stocktake_enabled=false（他テストの意図を邪魔しないよう棚卸しを無効化）。
	 *
	 * @param array<string, mixed> $overrides
	 */
	private function stubGeneralSettings( array $overrides = array() ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn(
				array_merge(
					array(
						'queue_depth_cap'   => 500,
						'stocktake_enabled' => false,
					),
					$overrides
				)
			);
	}

	/** Enqueuer::queueDepth()（accountCodes 未指定の既定経路）が読む pending 件数をスタブする。 */
	private function stubQueueDepth( int $pending = 0 ): void {
		WP_Mock::userFunction( 'as_get_scheduled_actions' )->andReturn( array_fill( 0, $pending, 1 ) );
	}

	/**
	 * posts_where フィルタの後始末（remove_filter）をスタブする。WP_Mock には
	 * remove_filter の組み込み polyfill が無いため、sweep() を呼ぶテストは全て
	 * これが必要（Ruling 1 の専用テストだけは呼び出しを厳密検証するため個別に書く）。
	 */
	private function stubFilterCleanup(): void {
		WP_Mock::userFunction( 'remove_filter' )->andReturn( true );
	}

	/** sweep 完走時の後処理（カーソル消去・完走時刻記録）を緩くスタブする。 */
	private function stubCompletion(): void {
		WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		WP_Mock::userFunction( 'update_option' )->andReturn( true );
	}

	public function test_sweep_get_postsにmaxProductsとID昇順カーソルクエリを渡す(): void {
		$this->stubCursor( 7 );
		$this->stubFilterCleanup();

		WP_Mock::userFunction( 'get_posts' )->once()->with(
			Mockery::on(
				function ( $args ) {
					return ProductPostType::POST_TYPE === $args['post_type']
						&& 'publish' === $args['post_status']
						&& 'ids' === $args['fields']
						&& 5 === $args['posts_per_page']
						&& 'ID' === $args['orderby']
						&& 'ASC' === $args['order']
						&& true === $args['no_found_rows']
						// レビュー Critical 1: これが無いと posts_where を含む句フィルタが
						// WP_Query 側で一切適用されず、カーソルが SQL に反映されない。
						&& false === $args['suppress_filters']
						// レビュー Important 3: posts_where フィルタが自クエリだけを
						// 識別するための private query var。値がカーソルと一致すること。
						&& 7 === $args['affilicard_sweep_after'];
				}
			)
		)->andReturn( array() );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );

		WP_Mock::userFunction( 'delete_option' )->once()->with( SweepCursor::OPTION_KEY );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( QueueMaintenance::OPTION_LAST_COMPLETED, Mockery::type( 'string' ), false );

		$result = ( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep( 5 );

		$this->assertTrue( $result );
		$this->assertConditionsMet();
	}

	/**
	 * Ruling 1: カーソル絞り込みを posts_where フィルタで SQL に落とし、使用後は
	 * 必ず remove_filter で外す（他のクエリに漏らさない）。
	 *
	 * Important 3: フィルタは private query var（affilicard_sweep_after）で自クエリ
	 * だけを識別する。$query がこの query var を持たない（＝他プラグインが窓内で
	 * 副次的に走らせた無関係なクエリ）場合は $where を書き換えないことも検証する。
	 */
	public function test_sweep_posts_whereフィルタでカーソル以降のみに絞り使用後に外す(): void {
		$this->stubCursor( 42 );
		$this->stubCompletion();

		// add_filter は WP_Mock の組み込み polyfill（\WP_Mock::onFilterAdded 経由）が
		// 常に使われ、WP_Mock::userFunction() では横取りできない。'posts_where' へ
		// priority=10・accepted_args=2 で Closure が登録されたことは expectFilterAdded
		// で確認する（宣言はテスト末尾で tearDown の Mockery::close() が検証する
		// intercept モックとして働く）。accepted_args=2 は $query（Important 3 の
		// クエリ識別に使う）を受け取るために必須。
		WP_Mock::expectFilterAdded( 'posts_where', Mockery::type( 'Closure' ), 10, 2 );

		// remove_filter には組み込み polyfill が無いため WP_Mock::userFunction() で
		// 横取りできる。sweep() は add_filter と remove_filter に同一の $whereCursor
		// 変数を渡すため、ここで捕捉した callback がそのまま「実際に登録された
		// クロージャ」そのものになる。
		$removedCallback = null;
		WP_Mock::userFunction( 'remove_filter' )
			->once()
			->with( 'posts_where', Mockery::type( 'Closure' ), 10 )
			->andReturnUsing(
				function ( $tag, $cb ) use ( &$removedCallback ) {
					$removedCallback = $cb;
					return true;
				}
			);

		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array() );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );

		( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep();

		$this->assertIsCallable( $removedCallback );
		$capturedCallback = $removedCallback;

		$wpdb        = Mockery::mock();
		$wpdb->posts = 'wp_posts';
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with( ' AND wp_posts.ID > %d', 42 )
			->andReturn( ' AND wp_posts.ID > 42' );
		$GLOBALS['wpdb'] = $wpdb;

		// 自クエリ（affilicard_sweep_after が sweep 時のカーソルと一致）には WHERE を足す。
		$ownQuery = Mockery::mock();
		$ownQuery->shouldReceive( 'get' )->with( 'affilicard_sweep_after', null )->andReturn( 42 );
		$this->assertSame( 'WHERE 1=1 AND wp_posts.ID > 42', $capturedCallback( 'WHERE 1=1', $ownQuery ) );

		// 他クエリ（query var を持たない＝他プラグインが窓内で副次的に走らせたクエリ）
		// には一切手を加えない（Important 3）。
		$otherQuery = Mockery::mock();
		$otherQuery->shouldReceive( 'get' )->with( 'affilicard_sweep_after', null )->andReturn( null );
		$this->assertSame( 'WHERE unrelated = 1', $capturedCallback( 'WHERE unrelated = 1', $otherQuery ) );

		unset( $GLOBALS['wpdb'] );
		$this->assertConditionsMet();
	}

	public function test_sweep_get_postsが配列以外なら何もしない(): void {
		$this->stubCursor( 0 );
		$this->stubFilterCleanup();
		WP_Mock::userFunction( 'get_posts' )->andReturn( false );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );

		WP_Mock::userFunction( 'delete_option' )->never();
		WP_Mock::userFunction( 'update_option' )->never();

		$result = ( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep();

		$this->assertTrue( $result );
		$this->assertConditionsMet();
	}

	public function test_sweep_商品が見つからない場合はスキップする(): void {
		$this->stubCursor( 0 );
		$this->stubGeneralSettings();
		$this->stubQueueDepth( 0 );
		$this->stubFilterCleanup();
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( 99 ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 99 )->andReturn( null );

		WP_Mock::userFunction( 'delete_option' )->once()->with( SweepCursor::OPTION_KEY );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( QueueMaintenance::OPTION_LAST_COMPLETED, Mockery::type( 'string' ), false );

		$result = ( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep();

		$this->assertTrue( $result );
		$this->assertConditionsMet();
	}

	public function test_sweep_公開商品のstaleな自動listingをバッチ投入する(): void {
		$this->stubCursor( 0 );
		$this->stubGeneralSettings();
		$this->stubQueueDepth( 0 );
		$this->stubFilterCleanup();
		$this->stubCompletion();
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( 12 ) );
		$this->stubRakutenPlatform();
		// B: give-up マーカー無し（get_transient=false）→ 通常通りバッチへ積む。
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

		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH_BATCH,
				array(
					'account' => 'rakuten',
					'items'   => array(
						array(
							'post_id'  => 12,
							'platform' => 'rakuten-kobo',
						),
					),
				),
				'affilicard-rakuten', // group = affilicard-{account}（v2.4.0）
				true,
				Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 200 );

		$result = ( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep();

		$this->assertTrue( $result );
		$this->assertConditionsMet();
	}

	/**
	 * last_fetched_at（最終試行時刻）が TTL 内なら、last_verified_at（成功時刻）が
	 * 古い/空・price が空（＝失敗が続いている listing）でもスキップする。
	 * これにより毎掃引で際限なく再エンキューされる perpetual retry を防ぐ。
	 */
	public function test_sweep_last_fetched_atがTTL内のlistingはバッチ投入されない(): void {
		$this->stubCursor( 0 );
		$this->stubGeneralSettings();
		$this->stubQueueDepth( 0 );
		$this->stubFilterCleanup();
		$this->stubCompletion();
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

		$result = ( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep();

		$this->assertTrue( $result );
		$this->assertConditionsMet();
	}

	/**
	 * B: give-up マーカー（RefreshHandler::giveUpTransientKey）が立っている listing は、
	 * eligibility・account 解決を満たし stale であってもバッチへ積まずスキップする。
	 * 恒久失敗（廃盤/無効 ID）listing の再取得 TTL 毎の毎周回リトライを COOLDOWN 中は抑える。
	 */
	public function test_sweep_giveup済みlistingはバッチ投入されない(): void {
		$this->stubCursor( 0 );
		$this->stubGeneralSettings();
		$this->stubQueueDepth( 0 );
		$this->stubFilterCleanup();
		$this->stubCompletion();
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

		$result = ( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep();

		$this->assertTrue( $result );
		$this->assertConditionsMet();
	}

	public function test_sweep_manualかdisabledかauto_update無効のlistingはバッチ投入しない(): void {
		$this->stubCursor( 0 );
		$this->stubGeneralSettings();
		$this->stubQueueDepth( 0 );
		$this->stubFilterCleanup();
		$this->stubCompletion();
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

		$result = ( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep();

		$this->assertTrue( $result );
		$this->assertConditionsMet();
	}

	public function test_sweep_未知platformのlistingはバッチ投入しない(): void {
		$this->stubCursor( 0 );
		$this->stubGeneralSettings();
		$this->stubQueueDepth( 0 );
		$this->stubFilterCleanup();
		$this->stubCompletion();
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

		$result = ( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep();

		$this->assertTrue( $result );
		$this->assertConditionsMet();
	}

	/** 商品数が $maxProducts に達したら、カーソルを保存して未完走（false）を返す。 */
	public function test_sweep_maxProducts上限に達したらカーソルを保存し未完走を返す(): void {
		$this->stubCursor( 0 );
		$this->stubGeneralSettings();
		$this->stubQueueDepth( 0 );
		$this->stubFilterCleanup();
		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array( 10, 11 ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->twice()->andReturn( $this->product( 0, array() ) );

		WP_Mock::userFunction( 'update_option' )->once()->with( SweepCursor::OPTION_KEY, 11, false );
		WP_Mock::userFunction( 'delete_option' )->never();

		$result = ( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep( 2 );

		$this->assertFalse( $result );
		$this->assertConditionsMet();
	}

	/** 最後の商品まで到達したら（走査件数 < maxProducts）カーソルを消し完走時刻を記録する。 */
	public function test_sweep_最後まで到達したらカーソルを消し完走時刻を記録する(): void {
		$this->stubCursor( 0 );
		$this->stubGeneralSettings();
		$this->stubQueueDepth( 0 );
		$this->stubFilterCleanup();
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( 10 ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->andReturn( $this->product( 10, array() ) );

		WP_Mock::userFunction( 'delete_option' )->once()->with( SweepCursor::OPTION_KEY );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( QueueMaintenance::OPTION_LAST_COMPLETED, Mockery::type( 'string' ), false );

		$result = ( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep( 200 );

		$this->assertTrue( $result );
		$this->assertConditionsMet();
	}

	/**
	 * 設計要点 1: queue_depth_cap に既に達している場合、商品を 1 件も処理せずカーソル
	 * を保持（前回位置のまま）して打ち切る。cap は「1 回の sweep で積む量の上限」であり
	 * 「更新できる商品数の天井」ではないため、取りこぼしにしない。
	 */
	public function test_sweep_queue_depth_capに既に達していたらカーソルを保持して打ち切る(): void {
		$this->stubCursor( 5 );
		$this->stubGeneralSettings( array( 'queue_depth_cap' => 1 ) );
		$this->stubQueueDepth( 1 ); // 開始時点で既に cap 到達済み
		$this->stubFilterCleanup();
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( 10, 11 ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );

		WP_Mock::userFunction( 'update_option' )->once()->with( SweepCursor::OPTION_KEY, 5, false );
		WP_Mock::userFunction( 'delete_option' )->never();

		$result = ( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep( 200 );

		$this->assertFalse( $result );
		$this->assertConditionsMet();
	}

	/**
	 * 設計要点 1（続き）: sweep の途中でバッチ投入が積み重なり cap に達した場合も、
	 * その時点で走査を打ち切りカーソルを保持する。BATCH_SIZE(22) 件溜めた 1 商品目
	 * 分のバッチ投入で depth が cap(1) に達し、23 商品目には手を付けない。
	 */
	public function test_sweep_バッチ投入でcapに達したら以降の商品を打ち切る(): void {
		$this->stubCursor( 0 );
		$this->stubGeneralSettings( array( 'queue_depth_cap' => 1 ) );
		$this->stubQueueDepth( 0 ); // 開始時点は空
		$this->stubFilterCleanup();
		$this->stubRakutenPlatform();
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );

		WP_Mock::userFunction( 'get_posts' )->andReturn( range( 1, 23 ) );

		$stale = gmdate( 'c', time() - 25 * 3600 );
		$repo  = Mockery::mock( ProductRepositoryInterface::class );
		foreach ( range( 1, 22 ) as $id ) {
			$repo->shouldReceive( 'find' )->once()->with( $id )->andReturn(
				$this->product(
					$id,
					array(
						array(
							'platform'        => 'rakuten-kobo',
							'enabled'         => true,
							'update_mode'     => 'auto',
							'auto_update'     => true,
							'external_id'     => 'e' . $id,
							'last_fetched_at' => $stale,
						),
					)
				)
			);
		}

		// 22 件たまったところで 1 回だけバッチ投入する（BATCH_SIZE=22）。
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()->andReturn( 555 );

		WP_Mock::userFunction( 'update_option' )->once()->with( SweepCursor::OPTION_KEY, 22, false );
		WP_Mock::userFunction( 'delete_option' )->never();

		$result = ( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep( 200 );

		$this->assertFalse( $result );
		$this->assertConditionsMet();
	}

	/**
	 * 継続ジョブ（バッチ）の投入に失敗しても（as_schedule_single_action が 0 を返しても）
	 * 走査したところまでのカーソルは保存される。カーソルが無いまま投入だけが失われると、
	 * その位置以降の商品が次の WP-Cron まで丸ごと更新されない（spec §4-2）。
	 */
	public function test_sweep_バッチ投入に失敗してもカーソルは保存される(): void {
		$this->stubCursor( 0 );
		$this->stubGeneralSettings();
		$this->stubQueueDepth( 0 );
		$this->stubFilterCleanup();
		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array( 10 ) );
		$this->stubRakutenPlatform();
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 10 )->andReturn(
			$this->product(
				10,
				array(
					array(
						'platform'        => 'rakuten-kobo',
						'enabled'         => true,
						'update_mode'     => 'auto',
						'auto_update'     => true,
						'external_id'     => 'e1',
						'last_fetched_at' => gmdate( 'c', time() - 25 * 3600 ),
					),
				)
			)
		);

		// 0 = 投入失敗（もしくは重複）。それでもカーソルは進捗どおりに保存される。
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()->andReturn( 0 );

		WP_Mock::userFunction( 'update_option' )->once()->with( SweepCursor::OPTION_KEY, 10, false );
		WP_Mock::userFunction( 'delete_option' )->never();

		// maxProducts=1 と一致させ、cap 以外の理由（続きがある）で未完走になる経路を通す。
		$result = ( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep( 1 );

		$this->assertFalse( $result );
		$this->assertConditionsMet();
	}

	/**
	 * 設計要点 5: 棚卸し対象の商品は listing 単位ではなく商品単位で丸ごと除外する
	 * （PlatformConfig::find 等、listing ループに一切到達しない）。
	 */
	public function test_sweep_棚卸し対象の商品は商品単位でスキップする(): void {
		$this->stubCursor( 0 );
		$this->stubFilterCleanup();
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( 20 ) );

		// stocktake_enabled=true・stocktake_days=1、最終掲載日は無し（get_post_meta=''）→
		// 棚卸し基準日（epoch 0）にフォールバックし、1 日を大きく過ぎているので棚卸し対象。
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn(
				array(
					'queue_depth_cap'   => 500,
					'stocktake_enabled' => true,
					'stocktake_days'    => 1,
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, '' )
			->andReturn( gmdate( 'c', 0 ) );
		WP_Mock::userFunction( 'get_post_meta' )->andReturn( '' );
		$this->stubQueueDepth( 0 );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 20 )->andReturn(
			$this->product(
				20,
				array(
					array(
						'platform'        => 'rakuten-kobo',
						'enabled'         => true,
						'update_mode'     => 'auto',
						'auto_update'     => true,
						'external_id'     => 'e1',
						'last_fetched_at' => gmdate( 'c', time() - 25 * 3600 ),
					),
				)
			)
		);

		// PlatformConfig::find に到達しない＝listing ループへ入っていないことの傍証。
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		WP_Mock::userFunction( 'delete_option' )->once()->with( SweepCursor::OPTION_KEY );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( QueueMaintenance::OPTION_LAST_COMPLETED, Mockery::type( 'string' ), false );

		$result = ( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep();

		$this->assertTrue( $result );
		$this->assertConditionsMet();
	}

	/**
	 * Ruling 2: QueueMaintenance のコンストラクタが $sweepLeadSeconds を受け取り、
	 * PriceFreshness::needsRefetch に渡す。既定 0（前倒しなし）では TTL 内の listing は
	 * 再取得されない。
	 */
	public function test_sweepLeadSecondsが既定0のときは表示TTLどおりに判定する(): void {
		$this->stubCursor( 0 );
		$this->stubGeneralSettings();
		$this->stubQueueDepth( 0 );
		$this->stubFilterCleanup();
		$this->stubCompletion();
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( 30 ) );
		$this->stubRakutenPlatform(); // priceTtlHours=24 → ttl=86400
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );

		// age=85000s。lead=0 のときの閾値は ttl=86400 のため、まだ再取得不要。
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 30 )->andReturn(
			$this->product(
				30,
				array(
					array(
						'platform'        => 'rakuten-kobo',
						'enabled'         => true,
						'update_mode'     => 'auto',
						'auto_update'     => true,
						'external_id'     => 'e1',
						'last_fetched_at' => gmdate( 'c', time() - 85000 ),
					),
				)
			)
		);

		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$result = ( new QueueMaintenance( $repo, new Enqueuer(), $this->registry(), new SweepCursor() ) )->sweep();

		$this->assertTrue( $result );
		$this->assertConditionsMet();
	}

	/**
	 * Ruling 2（続き）: $sweepLeadSeconds を明示的に渡すと、同じ age でも表示 TTL より
	 * 前倒しで再取得対象になる（PriceFreshness::needsRefetch の閾値が下がるため）。
	 */
	public function test_sweepLeadSecondsを渡すと表示期限より前倒しで再取得対象になる(): void {
		$this->stubCursor( 0 );
		$this->stubGeneralSettings();
		$this->stubQueueDepth( 0 );
		$this->stubFilterCleanup();
		$this->stubCompletion();
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( 31 ) );
		$this->stubRakutenPlatform();
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );

		// 同じ age=85000s でも lead=3600 を渡すと閾値が 82800 に下がり再取得対象になる。
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 31 )->andReturn(
			$this->product(
				31,
				array(
					array(
						'platform'        => 'rakuten-kobo',
						'enabled'         => true,
						'update_mode'     => 'auto',
						'auto_update'     => true,
						'external_id'     => 'e1',
						'last_fetched_at' => gmdate( 'c', time() - 85000 ),
					),
				)
			)
		);

		WP_Mock::userFunction( 'as_schedule_single_action' )->once()->andReturn( 900 );

		$maintenance = new QueueMaintenance(
			$repo,
			new Enqueuer(),
			$this->registry(),
			new SweepCursor(),
			new StocktakePolicy(),
			3600
		);

		$this->assertTrue( $maintenance->sweep() );
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
