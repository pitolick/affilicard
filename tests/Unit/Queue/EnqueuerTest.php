<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ManualProvider;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Provider\Rakuten\RakutenProvider;
use Affilicard\Queue\Enqueuer;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class EnqueuerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	/** affilicard_platforms option を rakuten-kobo 1件で stub する。 */
	private function stubRakutenPlatform(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'         => 'rakuten-kobo',
						'name'         => '楽天Kobo',
						'provider'     => 'rakuten-kobo',
						'displayOrder' => 3,
						'enabled'      => true,
					),
				)
			);
	}

	/** affilicard_platforms option を provider='manual' の platform 1件で stub する。 */
	private function stubManualPlatform(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'         => 'amazon-kindle',
						'name'         => 'Amazon Kindle',
						'provider'     => 'manual',
						'displayOrder' => 5,
						'enabled'      => true,
					),
				)
			);
	}

	/** RakutenProvider（code='rakuten-kobo', accountCode='rakuten'）を登録した ProviderRegistry。 */
	private function registryWithRakuten(): ProviderRegistry {
		$registry = new ProviderRegistry();
		$registry->register( new RakutenProvider() );
		return $registry;
	}

	/** ManualProvider（accountCode=null）を登録した ProviderRegistry。 */
	private function registryWithManual(): ProviderRegistry {
		$registry = new ProviderRegistry();
		$registry->register( new ManualProvider() );
		return $registry;
	}

	/**
	 * enqueueProductListings のテスト用: 実 provider レジストリ（rakuten-kobo→rakuten）を
	 * 注入した Enqueuer。account 解決を伴う本番の enqueueProductListings 経路を再現する。
	 */
	private function enqueuerWithRakuten(): Enqueuer {
		return new Enqueuer( 500, 300, array(), $this->registryWithRakuten() );
	}

	/** @param array<string, mixed> $overrides */
	private function eligibleListing( array $overrides = array() ): array {
		return array_merge(
			array(
				'platform'    => 'rakuten-kobo',
				'enabled'     => true,
				'update_mode' => 'auto',
				'auto_update' => true,
				'external_id' => 'deadbeef01',
				'price'       => '500',
			),
			$overrides
		);
	}

	/**
	 * force は base args（sweep/manual と同一）ではなく force=true 付き args で積む。
	 * sweep/manual の in-progress ジョブ（同一 base args・unique=true）に unique 吸収されて
	 * ドロップされるのを防ぐため。base args と force args の両方を unschedule してから
	 * force args を priority 0・unique=true で積む。
	 */
	public function test_enqueueForced_base_forceの両方を解除しforce付きargsでpriority0uniqueで投入する(): void {
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once()
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten'
			);
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once()
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
					'force'    => true,
				),
				'affilicard-rakuten'
			);
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				\Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
					'force'    => true,
				),
				'affilicard-rakuten',
				true,           // $unique
				Enqueuer::PRIORITY_FORCE
			)
			->andReturn( 100 );

		( new Enqueuer() )->enqueueForced( 12, 'rakuten-kobo', 'rakuten' );
		$this->assertConditionsMet();
	}

	public function test_enqueueManual_即時priority10uniqueで投入する(): void {
		// pending sweep（同一 base args）を繰り上げるため、投入前に base args を unschedule する。
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once()
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 34,
					'platform' => 'amazon-kindle',
				),
				'affilicard-amazon'
			);
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				\Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 34,
					'platform' => 'amazon-kindle',
				),
				'affilicard-amazon',
				true,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 101 );

		( new Enqueuer() )->enqueueManual( 34, 'amazon-kindle', 'amazon' );
		$this->assertConditionsMet();
	}

	public function test_enqueueAutoCreate_即時priority0uniqueでplatformとexternal_idを投入する(): void {
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				\Mockery::type( 'int' ),
				Enqueuer::HOOK_AUTOCREATE,
				array(
					'platform'    => 'rakuten-kobo',
					'external_id' => 'ext-001',
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_FORCE
			)
			->andReturn( 102 );

		( new Enqueuer() )->enqueueAutoCreate( 'rakuten-kobo', 'rakuten', 'ext-001' );
		$this->assertConditionsMet();
	}

	/**
	 * Task 12 Ruling 3: 掃引トリガーは args 無し・group='affilicard-sweep'・
	 * priority=PRIORITY_SWEEP で積む。$unique の既定は true（WP-Cron からの開始トリガーが
	 * 多重起動しないようにする）。
	 */
	public function test_enqueueSweepTrigger_既定はunique_trueでaffilicard_sweepを積む(): void {
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				\Mockery::type( 'int' ),
				Enqueuer::HOOK_SWEEP,
				array(),
				'affilicard-sweep',
				true,
				Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 303 );

		$this->assertSame( 303, ( new Enqueuer() )->enqueueSweepTrigger() );
		$this->assertConditionsMet();
	}

	/**
	 * QueueMaintenance::sweep() が false（未完走）を返したときの継続トリガーは
	 * unique=false で積む必要がある——実行中の自分自身が in-progress として
	 * unique 判定に一致するため、true のままだと必ず抑止されジョブが消滅する
	 * （rescheduleRefresh 等の自己再投入と同じ理由）。
	 */
	public function test_enqueueSweepTrigger_unique_falseを明示指定できる(): void {
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				\Mockery::type( 'int' ),
				Enqueuer::HOOK_SWEEP,
				array(),
				'affilicard-sweep',
				false,
				Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 0 );

		$this->assertSame( 0, ( new Enqueuer() )->enqueueSweepTrigger( false ) );
		$this->assertConditionsMet();
	}

	/**
	 * Task 12・Ruling 8: $when > 0 を渡すと time() ではなくその時刻に積む。
	 * pause 中／queue_depth_cap に張り付いた状態での遅延再投入に使う。
	 */
	public function test_enqueueSweepTrigger_whenを渡すとその時刻に積む(): void {
		$when = time() + 600;
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				$when,
				Enqueuer::HOOK_SWEEP,
				array(),
				'affilicard-sweep',
				false,
				Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 0 );

		( new Enqueuer() )->enqueueSweepTrigger( false, $when );
		$this->assertConditionsMet();
	}

	/**
	 * v2.4.0 症状1/3（thundering herd）対策: 自己再投入は jitter 無しだと同一 account を
	 * 奪い合う listing 群が寸分違わず同一タイムスタンプへ再集結してしまうため、
	 * wp_rand(0, RESCHEDULE_JITTER_SECONDS) を $whenSec に加算する。
	 * wp_rand を固定値にモックし、加算後の時刻で呼ばれることを厳密に検証する。
	 */
	public function test_rescheduleRefresh_jitterを加算した時刻にpriority10で再投入する_uniqueはfalse(): void {
		WP_Mock::userFunction( 'wp_rand' )
			->once()
			->with( 0, Enqueuer::RESCHEDULE_JITTER_SECONDS )
			->andReturn( 37 );
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				5000 + 37,
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				false, // $unique: 自己再投入は実行中の自分自身が in-progress 重複となるため false
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 1 );

		( new Enqueuer() )->rescheduleRefresh( 5000, 12, 'rakuten-kobo', 'rakuten' );
		$this->assertConditionsMet();
	}

	public function test_rescheduleAutoCreate_jitterを加算した時刻にpriority0で再投入する_uniqueはfalse(): void {
		WP_Mock::userFunction( 'wp_rand' )
			->once()
			->with( 0, Enqueuer::RESCHEDULE_JITTER_SECONDS )
			->andReturn( 12 );
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				6000 + 12,
				Enqueuer::HOOK_AUTOCREATE,
				array(
					'platform'    => 'rakuten-kobo',
					'external_id' => 'ext-001',
				),
				'affilicard-rakuten',
				false, // $unique: 自己再投入は実行中の自分自身が in-progress 重複となるため false
				Enqueuer::PRIORITY_FORCE
			)
			->andReturn( 1 );

		( new Enqueuer() )->rescheduleAutoCreate( 6000, 'rakuten-kobo', 'rakuten', 'ext-001' );
		$this->assertConditionsMet();
	}

	/**
	 * enqueueProductListings のテスト（onTransitionPostStatus/RefreshController 共用ヘルパー）。
	 */
	public function test_enqueueProductListings_manualfalse時はeligibleなauto_listingをenqueueForcedで積む(): void {
		$this->stubRakutenPlatform();
		$product = array( 'listings' => array( $this->eligibleListing() ) );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once()
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten'
			);
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once()
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
					'force'    => true,
				),
				'affilicard-rakuten'
			);
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				\Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
					'force'    => true,
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_FORCE
			)
			->andReturn( 500 );

		$count = $this->enqueuerWithRakuten()->enqueueProductListings( 12, $product, false );

		$this->assertSame( 1, $count );
	}

	public function test_enqueueProductListings_manualtrue時はeligibleなauto_listingをenqueueManualで積む(): void {
		$this->stubRakutenPlatform();
		$product = array( 'listings' => array( $this->eligibleListing() ) );

		// enqueueManual は pending sweep を繰り上げるため base args を unschedule してから積む。
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once()
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten'
			);
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				\Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 501 );

		$count = $this->enqueuerWithRakuten()->enqueueProductListings( 12, $product, true );

		$this->assertSame( 1, $count );
	}

	public function test_enqueueProductListings_manualかdisabledかauto_update無効のlistingは積まない(): void {
		$this->stubRakutenPlatform();
		$product = array(
			'listings' => array(
				$this->eligibleListing( array( 'update_mode' => 'manual' ) ),
				$this->eligibleListing( array( 'enabled' => false ) ),
				$this->eligibleListing( array( 'auto_update' => false ) ),
			),
		);

		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$count = ( new Enqueuer() )->enqueueProductListings( 12, $product, false );

		$this->assertSame( 0, $count );
	}

	public function test_enqueueProductListings_未知platformのlistingは積まない(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn( array() ); // platform 定義なし → find() は null

		$product = array( 'listings' => array( $this->eligibleListing( array( 'platform' => 'unknown-platform' ) ) ) );

		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$count = ( new Enqueuer() )->enqueueProductListings( 12, $product, false );

		$this->assertSame( 0, $count );
	}

	/**
	 * v2.4.0: provider→account 解決に失敗する（provider が手動系で accountCode()===null）
	 * listing は enqueue できないため積まない（「対応する自動 Provider が無い」ケース）。
	 */
	public function test_enqueueProductListings_accountが解決できないmanual系listingは積まない(): void {
		$this->stubManualPlatform();
		$product = array( 'listings' => array( $this->eligibleListing( array( 'platform' => 'amazon-kindle' ) ) ) );

		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$count = ( new Enqueuer( 500, 300, array(), $this->registryWithManual() ) )->enqueueProductListings( 12, $product, false );

		$this->assertSame( 0, $count );
	}

	public function test_enqueueProductListings_listingsが無い場合は0を返す(): void {
		$count = ( new Enqueuer() )->enqueueProductListings( 12, array(), false );

		$this->assertSame( 0, $count );
	}

	public function test_enqueueProductListings_複数listingsのうちeligible件数のみ返す(): void {
		$this->stubRakutenPlatform();
		$product = array(
			'listings' => array(
				$this->eligibleListing(),
				$this->eligibleListing( array( 'update_mode' => 'manual' ) ),
			),
		);

		// enqueueForced は base args と force args の 2 回 unschedule する。
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->twice();
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()->andReturn( 502 );

		$count = $this->enqueuerWithRakuten()->enqueueProductListings( 12, $product, false );

		$this->assertSame( 1, $count );
	}

	/**
	 * force=true は auto_update=false の listing も eligible として積む（強制更新ボタンの既存挙動）。
	 * force=false（デフォルト）では従来通りスキップされることも併せて確認する。
	 */
	public function test_enqueueProductListings_forcetrue時はauto_updatefalseのlistingもenqueueForcedで積む(): void {
		$this->stubRakutenPlatform();
		$product = array( 'listings' => array( $this->eligibleListing( array( 'auto_update' => false ) ) ) );

		// enqueueForced は base args と force args の 2 回 unschedule する。
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->twice();
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				\Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
					'force'    => true,
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_FORCE
			)
			->andReturn( 503 );

		$count = $this->enqueuerWithRakuten()->enqueueProductListings( 12, $product, false, true );

		$this->assertSame( 1, $count );
	}

	public function test_enqueueProductListings_forceデフォルトfalseではauto_updatefalseのlistingは積まない(): void {
		$this->stubRakutenPlatform();
		$product = array( 'listings' => array( $this->eligibleListing( array( 'auto_update' => false ) ) ) );

		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$count = ( new Enqueuer() )->enqueueProductListings( 12, $product, false );

		$this->assertSame( 0, $count );
	}

	public function test_queueDepth_pendingのids件数を返す(): void {
		WP_Mock::userFunction( 'as_get_scheduled_actions' )->once()
			->with(
				array(
					'status'   => 'pending',
					'per_page' => 501,
					'group'    => '',
				),
				'ids'
			)
			->andReturn( array( 1, 2, 3 ) );

		$this->assertSame( 3, ( new Enqueuer() )->queueDepth() );
	}

	/**
	 * accountCodes を渡した場合は affilicard-{account} group 別に pending 件数を集計して
	 * 合算する（他プラグイン等の group='' 全体件数は数えない。I1: depth cap backstop が
	 * 無関係な pending action に誤反応しないようにするための per-group 化）。
	 */
	public function test_queueDepth_accountCodes指定時はaccount別groupのpending件数を合算する(): void {
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->once()
			->with(
				array(
					'status'   => 'pending',
					'per_page' => -1,
					'group'    => 'affilicard-rakuten',
				),
				'ids'
			)
			->andReturn( array( 1, 2 ) );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->once()
			->with(
				array(
					'status'   => 'pending',
					'per_page' => -1,
					'group'    => 'affilicard-dmm',
				),
				'ids'
			)
			->andReturn( array( 3 ) );

		$enqueuer = new Enqueuer( 500, 300, array( 'rakuten', 'dmm' ) );

		$this->assertSame( 3, $enqueuer->queueDepth() );
	}

	/**
	 * accountCodes 未指定（既定 array()）の場合は後方互換のため従来通り group='' の
	 * 全 pending 件数にフォールバックする。
	 */
	public function test_queueDepth_accountCodes未指定時はglobalpending件数にフォールバックする(): void {
		WP_Mock::userFunction( 'as_get_scheduled_actions' )->once()
			->with(
				array(
					'status'   => 'pending',
					'per_page' => 501,
					'group'    => '',
				),
				'ids'
			)
			->andReturn( array( 1, 2, 3, 4 ) );

		$this->assertSame( 4, ( new Enqueuer() )->queueDepth() );
	}

	public function test_enqueueBatch_は_account_group_と_sweep_優先度で1件のジョブを積む(): void {
		$captured = null;
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->andReturnUsing(
				function ( $when, $hook, $args, $group, $unique, $priority ) use ( &$captured ) {
					$captured = compact( 'when', 'hook', 'args', 'group', 'unique', 'priority' );
					return 4242;
				}
			);

		$enqueuer = new Enqueuer();
		$items    = array(
			array(
				'post_id'  => 11,
				'platform' => 'rakuten-kobo',
			),
			array(
				'post_id'  => 12,
				'platform' => 'rakuten-kobo',
			),
		);

		// $when は固定値を渡して素通しされることを確かめる（既定 0 のときの time() は
		// 実行時刻に依存して固定できないため、明示値で契約を固定する）。
		$when = 1735689600;

		$actionId = $enqueuer->enqueueBatch( 'rakuten', $items, $when );

		$this->assertSame( 4242, $actionId );
		$this->assertSame( $when, $captured['when'] );
		$this->assertSame( Enqueuer::HOOK_REFRESH_BATCH, $captured['hook'] );
		$this->assertSame( 'affilicard-rakuten', $captured['group'] );
		$this->assertSame( Enqueuer::PRIORITY_SWEEP, $captured['priority'] );
		$this->assertTrue( $captured['unique'] );
		$this->assertSame( 'rakuten', $captured['args']['account'] );
		// 件数だけでなく順序を含めて完全一致であることを固定する（ハンドラは
		// items の並び順に処理し、requeueRemaining() が index で切り出すため）。
		$this->assertSame( $items, $captured['args']['items'] );
	}

	public function test_enqueueBatch_は_items_が空なら何も積まず0を返す(): void {
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$enqueuer = new Enqueuer();

		$this->assertSame( 0, $enqueuer->enqueueBatch( 'rakuten', array() ) );
	}

	/**
	 * ハンドラの自己再投入・積み直し用に unique=false を明示指定できることを確認する
	 * （spec §4-1 Ruling 4）。AS の unique=true は PENDING/RUNNING 双方に対して
	 * hook+group+args(JSON) の完全一致で挿入を抑止するため、実行中の自分自身と
	 * account・items が一致する自己再投入では常に抑止され、ジョブが痕跡なく消滅する。
	 */
	public function test_enqueueBatch_はunique_falseを明示できる(): void {
		$captured = null;
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->andReturnUsing(
				function ( $when, $hook, $args, $group, $unique, $priority ) use ( &$captured ) {
					$captured = compact( 'unique' );
					return 9999;
				}
			);

		$enqueuer = new Enqueuer();
		$items    = array(
			array(
				'post_id'  => 21,
				'platform' => 'rakuten-kobo',
			),
		);

		$actionId = $enqueuer->enqueueBatch( 'rakuten', $items, 0, false );

		$this->assertSame( 9999, $actionId );
		$this->assertFalse( $captured['unique'] );
	}

	/**
	 * レビュー Major 1（spec §4-3）: `enqueueBatch()` の戻り値 0 は「unique 重複で
	 * スキップ」と「投入失敗」のどちらもあり得るため区別できない。
	 * `hasScheduledBatch()` は `enqueueBatch()` と同一の hook/args/group で
	 * `as_has_scheduled_action()` を呼び、呼び出し側（QueueMaintenance::sweep()）が
	 * 両者を判別できるようにする。
	 */
	public function test_hasScheduledBatch_はenqueueBatchと同一のhook_args_groupで問い合わせる(): void {
		$captured = null;
		WP_Mock::userFunction( 'as_has_scheduled_action' )
			->once()
			->andReturnUsing(
				function ( $hook, $args, $group ) use ( &$captured ) {
					$captured = compact( 'hook', 'args', 'group' );
					return true;
				}
			);

		$enqueuer = new Enqueuer();
		$items    = array(
			array(
				'post_id'  => 11,
				'platform' => 'rakuten-kobo',
			),
		);

		$this->assertTrue( $enqueuer->hasScheduledBatch( 'rakuten', $items ) );
		$this->assertSame( Enqueuer::HOOK_REFRESH_BATCH, $captured['hook'] );
		$this->assertSame( 'affilicard-rakuten', $captured['group'] );
		$this->assertSame( 'rakuten', $captured['args']['account'] );
		$this->assertSame( $items, $captured['args']['items'] );
	}

	public function test_hasScheduledBatch_はas_has_scheduled_actionがfalseならfalseを返す(): void {
		WP_Mock::userFunction( 'as_has_scheduled_action' )->once()->andReturn( false );

		$enqueuer = new Enqueuer();

		$this->assertFalse(
			$enqueuer->hasScheduledBatch(
				'rakuten',
				array(
					array(
						'post_id'  => 1,
						'platform' => 'rakuten-kobo',
					),
				)
			)
		);
	}
}
