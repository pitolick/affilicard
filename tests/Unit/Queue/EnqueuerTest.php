<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Platform\PlatformDefinition;
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

	private function platform( string $code, int $ttl ): PlatformDefinition {
		return PlatformDefinition::fromArray(
			array(
				'code'          => $code,
				'priceTtlHours' => $ttl,
			)
		);
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

	public function test_enqueueForced_既存を解除し即時priority0uniqueで投入する(): void {
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
				true,           // $unique
				Enqueuer::PRIORITY_FORCE
			)
			->andReturn( 100 );

		( new Enqueuer() )->enqueueForced( 12, 'rakuten-kobo', 'rakuten' );
		$this->assertConditionsMet();
	}

	public function test_enqueueManual_即時priority10uniqueで投入する(): void {
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

	public function test_enqueueSweep_freshはスキップしfalse(): void {
		$def     = $this->platform( 'rakuten-kobo', 24 ); // priceTtlHours=24
		$now     = 1_000_000;
		$listing = array(
			'price'            => '500',
			'last_verified_at' => gmdate( 'c', $now - 3600 ),
		);
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$result = ( new Enqueuer() )->enqueueSweep( 12, 'rakuten-kobo', 'rakuten', $def, $listing, $now );
		$this->assertFalse( $result );
	}

	public function test_enqueueSweep_staleは深さ内でjitter付priority20投入しtrue(): void {
		$def     = $this->platform( 'rakuten-kobo', 24 );
		$now     = 1_000_000;
		$listing = array(
			'price'            => '500',
			'last_verified_at' => gmdate( 'c', $now - 25 * 3600 ),
		); // stale
		WP_Mock::userFunction( 'as_get_scheduled_actions' )->andReturn( array() ); // 深さ 0
		WP_Mock::userFunction( 'wp_rand' )->with( 0, 300 )->andReturn( 42 );
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
				Enqueuer::PRIORITY_SWEEP
			)->andReturn( 101 );

		$result = ( new Enqueuer() )->enqueueSweep( 12, 'rakuten-kobo', 'rakuten', $def, $listing, $now );
		$this->assertTrue( $result );
	}

	public function test_enqueueSweep_depthCap到達でスキップしfalse(): void {
		$def     = $this->platform( 'rakuten-kobo', 24 );
		$now     = 1_000_000;
		$listing = array(
			'price'            => '500',
			'last_verified_at' => gmdate( 'c', $now - 25 * 3600 ),
		); // stale
		WP_Mock::userFunction( 'as_get_scheduled_actions' )->andReturn( array( 1, 2 ) ); // 深さ 2
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$result = ( new Enqueuer( 2 ) )->enqueueSweep( 12, 'rakuten-kobo', 'rakuten', $def, $listing, $now );
		$this->assertFalse( $result );
	}

	public function test_rescheduleRefresh_指定時刻にpriority10で再投入する_uniqueはfalse(): void {
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				5000,
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

	public function test_rescheduleAutoCreate_指定時刻にpriority0で再投入する_uniqueはfalse(): void {
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				6000,
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
				'affilicard-rakuten-kobo'
			);
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				\Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten-kobo',
				true,
				Enqueuer::PRIORITY_FORCE
			)
			->andReturn( 500 );

		$count = ( new Enqueuer() )->enqueueProductListings( 12, $product, false );

		$this->assertSame( 1, $count );
	}

	public function test_enqueueProductListings_manualtrue時はeligibleなauto_listingをenqueueManualで積む(): void {
		$this->stubRakutenPlatform();
		$product = array( 'listings' => array( $this->eligibleListing() ) );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				\Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten-kobo',
				true,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 501 );

		$count = ( new Enqueuer() )->enqueueProductListings( 12, $product, true );

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

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once();
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()->andReturn( 502 );

		$count = ( new Enqueuer() )->enqueueProductListings( 12, $product, false );

		$this->assertSame( 1, $count );
	}

	/**
	 * force=true は auto_update=false の listing も eligible として積む（強制更新ボタンの既存挙動）。
	 * force=false（デフォルト）では従来通りスキップされることも併せて確認する。
	 */
	public function test_enqueueProductListings_forcetrue時はauto_updatefalseのlistingもenqueueForcedで積む(): void {
		$this->stubRakutenPlatform();
		$product = array( 'listings' => array( $this->eligibleListing( array( 'auto_update' => false ) ) ) );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once();
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				\Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten-kobo',
				true,
				Enqueuer::PRIORITY_FORCE
			)
			->andReturn( 503 );

		$count = ( new Enqueuer() )->enqueueProductListings( 12, $product, false, true );

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
	 * providerCodes を渡した場合は affilicard-{provider} group 別に pending 件数を集計して
	 * 合算する（他プラグイン等の group='' 全体件数は数えない。I1: depth cap backstop が
	 * 無関係な pending action に誤反応しないようにするための per-group 化）。
	 */
	public function test_queueDepth_providerCodes指定時はprovider別groupのpending件数を合算する(): void {
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->once()
			->with(
				array(
					'status'   => 'pending',
					'per_page' => -1,
					'group'    => 'affilicard-rakuten-kobo',
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
					'group'    => 'affilicard-dmm-ebook',
				),
				'ids'
			)
			->andReturn( array( 3 ) );

		$enqueuer = new Enqueuer( 500, 300, array( 'rakuten-kobo', 'dmm-ebook' ) );

		$this->assertSame( 3, $enqueuer->queueDepth() );
	}

	/**
	 * providerCodes 未指定（既定 array()）の場合は後方互換のため従来通り group='' の
	 * 全 pending 件数にフォールバックする。
	 */
	public function test_queueDepth_providerCodes未指定時はglobalpending件数にフォールバックする(): void {
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
}
