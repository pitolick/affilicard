<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\RateLimiter;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class RateLimiterTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	public function test_effectiveIntervalMs_下限と上書きの大きい方(): void {
		$rl = new RateLimiter();
		$this->assertSame( 1100, $rl->effectiveIntervalMs( 1100, 0 ) );    // override 無し
		$this->assertSame( 2000, $rl->effectiveIntervalMs( 1100, 2000 ) ); // 遅い側に上書き
		$this->assertSame( 1100, $rl->effectiveIntervalMs( 1100, 500 ) );  // 下限より速い上書きは無効
	}

	public function test_tryAcquire_経過済みならokでlastを更新する(): void {
		WP_Mock::userFunction( 'get_option' )->with( 'affilicard_ratelimit_rakuten', 0 )->andReturn( 1000 );
		WP_Mock::userFunction( 'update_option' )->once()->with( 'affilicard_ratelimit_rakuten', 3000, false )->andReturn( true );

		$out = ( new RateLimiter() )->tryAcquire( 'rakuten', 1100, 3000 );
		$this->assertTrue( $out['ok'] );
	}

	public function test_tryAcquire_未経過ならngでnext_msを返す(): void {
		WP_Mock::userFunction( 'get_option' )->with( 'affilicard_ratelimit_rakuten', 0 )->andReturn( 1000 );
		WP_Mock::userFunction( 'update_option' )->never();

		$out = ( new RateLimiter() )->tryAcquire( 'rakuten', 1100, 1500 ); // 1500-1000=500 < 1100
		$this->assertFalse( $out['ok'] );
		$this->assertSame( 2100, $out['next_ms'] ); // 1000 + 1100
	}
}
