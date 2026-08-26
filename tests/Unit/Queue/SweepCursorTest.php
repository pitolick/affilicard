<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\SweepCursor;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class SweepCursorTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	public function test_未設定なら0を返す(): void {
		WP_Mock::userFunction( 'get_option' )->with( SweepCursor::OPTION_KEY, 0 )->andReturn( 0 );

		$this->assertSame( 0, ( new SweepCursor() )->get() );
	}

	public function test_set_は_autoload_無しで保存する(): void {
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( SweepCursor::OPTION_KEY, 42, false );

		( new SweepCursor() )->set( 42 );

		$this->assertConditionsMet();
	}

	public function test_clear_は_option_を削除する(): void {
		WP_Mock::userFunction( 'delete_option' )->once()->with( SweepCursor::OPTION_KEY );

		( new SweepCursor() )->clear();

		$this->assertConditionsMet();
	}
}
