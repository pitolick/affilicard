<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

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
}
