<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Pricing;

use Affilicard\Pricing\FetchStatus;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class FetchStatusTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $t ) => $t );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_terminalだけがisTerminalで真になる(): void {
		$this->assertTrue( FetchStatus::isTerminal( FetchStatus::TERMINAL ) );
		$this->assertFalse( FetchStatus::isTerminal( FetchStatus::TRANSIENT ) );
		$this->assertFalse( FetchStatus::isTerminal( FetchStatus::UNSUPPORTED ) );
		$this->assertFalse( FetchStatus::isTerminal( FetchStatus::NONE ) );
	}

	public function test_未知の値はisTerminalで偽になる(): void {
		// 想定外の値で購入リンクを飛ばすのは危険side。飛ばさない側へ倒す。
		$this->assertFalse( FetchStatus::isTerminal( 'とつぜんの値' ) );
	}

	public function test_成功は文言を返さない(): void {
		$this->assertSame( '', FetchStatus::label( FetchStatus::NONE ) );
	}

	public function test_各状態に文言がある(): void {
		$this->assertSame( '自動取得の対象外です', FetchStatus::label( FetchStatus::UNSUPPORTED ) );
		$this->assertSame( '一時的に取得できませんでした', FetchStatus::label( FetchStatus::TRANSIENT ) );
		$this->assertSame( '商品が見つかりません', FetchStatus::label( FetchStatus::TERMINAL ) );
	}

	public function test_旧文言をコードへ写像する(): void {
		$this->assertSame( FetchStatus::UNSUPPORTED, FetchStatus::fromLegacyMessage( '対応する自動 Provider がありません' ) );
		$this->assertSame( FetchStatus::TERMINAL, FetchStatus::fromLegacyMessage( '該当する商品が見つかりませんでした' ) );
		$this->assertSame( FetchStatus::TRANSIENT, FetchStatus::fromLegacyMessage( '価格情報の取得に失敗しました' ) );
		$this->assertSame( FetchStatus::NONE, FetchStatus::fromLegacyMessage( '' ) );
	}

	public function test_未知の旧文言はtransientへ倒す(): void {
		// 恒久と誤認して購入リンクを飛ばすより、飛ばさない側が安全。
		$this->assertSame( FetchStatus::TRANSIENT, FetchStatus::fromLegacyMessage( '手で書き換えられた文言' ) );
	}
}
