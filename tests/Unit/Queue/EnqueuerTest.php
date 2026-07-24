<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Platform\PlatformDefinition;
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
	 * 掃引の再取得判定は last_fetched_at（最終試行時刻）基準。last_verified_at が
	 * 古い/空・price が空（＝失敗が続いている listing）でも、直近の試行
	 * （last_fetched_at）が TTL 内ならスキップする（毎掃引の連打を防ぐ）。
	 */
	public function test_enqueueSweep_last_fetched_atがTTL内はlast_verified_atや価格に関わらずスキップしfalse(): void {
		$def     = $this->platform( 'rakuten-kobo', 24 ); // priceTtlHours=24
		$now     = 1_000_000;
		$listing = array(
			'price'            => '', // 失敗続きで価格未確定
			'last_verified_at' => '', // 一度も成功していない
			'last_fetched_at'  => gmdate( 'c', $now - 3600 ), // 直近の試行はTTL内
		);
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$result = ( new Enqueuer() )->enqueueSweep( 12, 'rakuten-kobo', 'rakuten', $def, $listing, $now );
		$this->assertFalse( $result );
	}

	/**
	 * 掃引リード（sweepLeadSeconds）を持つ Enqueuer は、表示 TTL（24h）内でも期限より手前で
	 * 再取得を発火する。20h 前の listing は lead=5h（しきい値 24-5=19h）なら投入対象になる
	 * （リード無しなら 20h < 24h でスキップ＝別テストで担保）。表示 TTL は変えず、価格が期限に
	 * 達する前に再確認を終わらせるための機構。
	 */
	public function test_enqueueSweep_リード付きは表示TTL内でも期限前に再取得投入する(): void {
		$def     = $this->platform( 'rakuten-kobo', 24 );
		$now     = 1_000_000;
		$listing = array(
			'price'            => '500',
			'last_verified_at' => gmdate( 'c', $now - 20 * 3600 ),
			'last_fetched_at'  => gmdate( 'c', $now - 20 * 3600 ), // 20h 前（TTL 24h 内だが lead しきい値 19h 超）
		);
		WP_Mock::userFunction( 'as_get_scheduled_actions' )->andReturn( array() );
		WP_Mock::userFunction( 'wp_rand' )->with( 0, 300 )->andReturn( 0 );
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
			)->andReturn( 202 );

		$enqueuer = new Enqueuer( 500, 300, array(), $this->registryWithRakuten(), 5 * 3600 );
		$this->assertTrue(
			$enqueuer->enqueueSweep( 12, 'rakuten-kobo', 'rakuten', $def, $listing, $now )
		);
	}

	public function test_enqueueSweep_last_fetched_atがTTL超過は深さ内でjitter付priority20投入しtrue(): void {
		$def     = $this->platform( 'rakuten-kobo', 24 );
		$now     = 1_000_000;
		$listing = array(
			'price'            => '',
			'last_verified_at' => '',
			'last_fetched_at'  => gmdate( 'c', $now - 25 * 3600 ),
		); // 直近の試行がTTL超過
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

	/**
	 * last_fetched_at が無い（初回・移行直後のデータ等）listing は常に再取得対象。
	 */
	public function test_enqueueSweep_last_fetched_at欠落は投入対象でtrue(): void {
		$def     = $this->platform( 'rakuten-kobo', 24 );
		$now     = 1_000_000;
		$listing = array( 'price' => '500' ); // last_fetched_at 無し
		WP_Mock::userFunction( 'as_get_scheduled_actions' )->andReturn( array() ); // 深さ 0
		WP_Mock::userFunction( 'wp_rand' )->with( 0, 300 )->andReturn( 0 );
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()->andReturn( 101 );

		$result = ( new Enqueuer() )->enqueueSweep( 12, 'rakuten-kobo', 'rakuten', $def, $listing, $now );
		$this->assertTrue( $result );
	}

	/**
	 * enqueueSweep は listing 毎に queueDepth() を再クエリせず、インスタンス内で
	 * memoize した深さを使う（O(N) の as_get_scheduled_actions クエリ回避）。
	 * enqueue 成功のたびに memo をインクリメントするので、cap 到達判定は
	 * sweep 内で引き続き正しく効く。
	 */
	public function test_enqueueSweep_深さは初回のみクエリしmemoの増分でcapを守る(): void {
		$def     = $this->platform( 'rakuten-kobo', 24 );
		$now     = 1_000_000;
		$listing = array(
			'price'           => '500',
			'last_fetched_at' => gmdate( 'c', $now - 25 * 3600 ),
		); // 直近の試行がTTL超過

		// 深さクエリは 1 回だけ（listing 3件を捌いても再クエリしない）。既存 pending=1。
		WP_Mock::userFunction( 'as_get_scheduled_actions' )->once()->andReturn( array( 1 ) );
		WP_Mock::userFunction( 'wp_rand' )->with( 0, 300 )->andReturn( 0 );
		// cap=2・既存深さ=1 → 1件目は積める（memo は 2 に増分）。2・3件目は cap 到達でスキップ。
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()->andReturn( 200 );

		$enqueuer = new Enqueuer( 2 );

		$result1 = $enqueuer->enqueueSweep( 1, 'rakuten-kobo', 'rakuten', $def, $listing, $now );
		$result2 = $enqueuer->enqueueSweep( 2, 'rakuten-kobo', 'rakuten', $def, $listing, $now );
		$result3 = $enqueuer->enqueueSweep( 3, 'rakuten-kobo', 'rakuten', $def, $listing, $now );

		$this->assertTrue( $result1 );
		$this->assertFalse( $result2 );
		$this->assertFalse( $result3 );
		$this->assertConditionsMet();
	}

	public function test_enqueueSweep_depthCap到達でスキップしfalse(): void {
		$def     = $this->platform( 'rakuten-kobo', 24 );
		$now     = 1_000_000;
		$listing = array(
			'price'           => '500',
			'last_fetched_at' => gmdate( 'c', $now - 25 * 3600 ),
		); // 直近の試行がTTL超過
		WP_Mock::userFunction( 'as_get_scheduled_actions' )->andReturn( array( 1, 2 ) ); // 深さ 2
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$result = ( new Enqueuer( 2 ) )->enqueueSweep( 12, 'rakuten-kobo', 'rakuten', $def, $listing, $now );
		$this->assertFalse( $result );
	}

	/**
	 * v2.4.0 症状1/3（thundering herd）対策: 自己再投入は jitter 無しだと同一 account を
	 * 奪い合う listing 群が寸分違わず同一タイムスタンプへ再集結してしまうため、
	 * enqueueSweep と同様に wp_rand(0, RESCHEDULE_JITTER_SECONDS) を $whenSec に加算する。
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

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
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
}
