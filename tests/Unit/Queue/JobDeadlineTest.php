<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\JobDeadline;
use PHPUnit\Framework\TestCase;

final class JobDeadlineTest extends TestCase {

	public function test_remaining_は_安全マージンを差し引いた残り秒を返す(): void {
		$deadline = new JobDeadline( 1000, 30, 5 );

		// 期限 = 1000 + 30 - 5 = 1025
		$this->assertSame( 25, $deadline->remaining( 1000 ) );
		$this->assertSame( 5, $deadline->remaining( 1020 ) );
	}

	public function test_remaining_は_期限超過で0未満にならない(): void {
		$deadline = new JobDeadline( 1000, 30, 5 );

		$this->assertSame( 0, $deadline->remaining( 1025 ) );
		$this->assertSame( 0, $deadline->remaining( 9999 ) );
	}

	public function test_canAfford_は_必要秒を賄えるときだけ真(): void {
		$deadline = new JobDeadline( 1000, 30, 5 );

		$this->assertTrue( $deadline->canAfford( 1000, 25 ) );
		$this->assertFalse( $deadline->canAfford( 1000, 26 ) );
		$this->assertFalse( $deadline->canAfford( 1025, 1 ) );
	}

	public function test_clampWait_は_残り時間を超える待機を切り詰める(): void {
		$deadline = new JobDeadline( 1000, 30, 5 );

		$this->assertSame( 3, $deadline->clampWait( 1000, 3 ) );
		$this->assertSame( 25, $deadline->clampWait( 1000, 60 ) );
		$this->assertSame( 0, $deadline->clampWait( 1025, 60 ) );
	}
}
