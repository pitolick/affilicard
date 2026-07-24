<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\WorkOutcome;
use PHPUnit\Framework\TestCase;

/**
 * WorkOutcome（performWork の3値結果）の単体テスト。
 */
final class WorkOutcomeTest extends TestCase {

	public function test_3つのケースが存在する(): void {
		$cases = WorkOutcome::cases();
		$this->assertCount( 3, $cases );
		$this->assertContains( WorkOutcome::SUCCESS, $cases );
		$this->assertContains( WorkOutcome::TRANSIENT_FAILURE, $cases );
		$this->assertContains( WorkOutcome::TERMINAL_FAILURE, $cases );
	}

	public function test_各ケースは同一性で区別できる(): void {
		$this->assertNotSame( WorkOutcome::SUCCESS, WorkOutcome::TRANSIENT_FAILURE );
		$this->assertNotSame( WorkOutcome::TRANSIENT_FAILURE, WorkOutcome::TERMINAL_FAILURE );
		$this->assertSame( WorkOutcome::SUCCESS, WorkOutcome::SUCCESS );
	}
}
