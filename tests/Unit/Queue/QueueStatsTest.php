<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\QueueStats;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class QueueStatsTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	public function test_forProvider_status別件数を返す(): void {
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-rakuten-kobo',
					'status'   => 'pending',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array( 1, 2, 3 ) );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-rakuten-kobo',
					'status'   => 'in-progress',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array( 4 ) );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-rakuten-kobo',
					'status'   => 'failed',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array() );

		$out = ( new QueueStats( array( 'rakuten-kobo' ) ) )->forProvider( 'rakuten-kobo' );
		$this->assertSame(
			array(
				'pending'     => 3,
				'in_progress' => 1,
				'failed'      => 0,
			),
			$out
		);
	}

	public function test_summary_全providerのstatus別件数を返す(): void {
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-rakuten-kobo',
					'status'   => 'pending',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array( 1, 2 ) );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-rakuten-kobo',
					'status'   => 'in-progress',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array() );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-rakuten-kobo',
					'status'   => 'failed',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array( 9 ) );

		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-dmm-ebook',
					'status'   => 'pending',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array( 5, 6, 7, 8 ) );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-dmm-ebook',
					'status'   => 'in-progress',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array( 10 ) );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-dmm-ebook',
					'status'   => 'failed',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array() );

		$out = ( new QueueStats( array( 'rakuten-kobo', 'dmm-ebook' ) ) )->summary();
		$this->assertSame(
			array(
				'rakuten-kobo' => array(
					'pending'     => 2,
					'in_progress' => 0,
					'failed'      => 1,
				),
				'dmm-ebook'    => array(
					'pending'     => 4,
					'in_progress' => 1,
					'failed'      => 0,
				),
			),
			$out
		);
	}

	public function test_depth_全providerのpending合算を返す(): void {
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-rakuten-kobo',
					'status'   => 'pending',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array( 1, 2 ) );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-rakuten-kobo',
					'status'   => 'in-progress',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array() );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-rakuten-kobo',
					'status'   => 'failed',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array() );

		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-dmm-ebook',
					'status'   => 'pending',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array( 5, 6, 7, 8 ) );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-dmm-ebook',
					'status'   => 'in-progress',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array() );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-dmm-ebook',
					'status'   => 'failed',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array() );

		$depth = ( new QueueStats( array( 'rakuten-kobo', 'dmm-ebook' ) ) )->depth();
		$this->assertSame( 6, $depth );
	}

	public function test_forProvider_未知のproviderでもgroup名を組み立てて問い合わせる(): void {
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-unknown',
					'status'   => 'pending',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array() );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-unknown',
					'status'   => 'in-progress',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array() );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => 'affilicard-unknown',
					'status'   => 'failed',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array() );

		$out = ( new QueueStats( array() ) )->forProvider( 'unknown' );
		$this->assertSame(
			array(
				'pending'     => 0,
				'in_progress' => 0,
				'failed'      => 0,
			),
			$out
		);
	}
}
