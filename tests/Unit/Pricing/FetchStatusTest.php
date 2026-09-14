<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Pricing;

use Affilicard\Pricing\FetchStatus;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class FetchStatusTest extends TestCase {

	/**
	 * 現在ロケールの訳語。原文 => 訳文。
	 *
	 * **ここに置くのは意図的である。** WP_Mock::userFunction() は同じ関数名を
	 * 再登録しても最初の期待が残るため、個別テストで __ を差し替えても黙って
	 * 無視される。切り替えたい値はプロパティ経由で渡す。
	 *
	 * @var array<string, string>
	 */
	private array $translations = array();

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$this->translations = array();
		WP_Mock::userFunction( '__' )->andReturnUsing(
			fn( $t ) => $this->translations[ $t ] ?? $t
		);
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

	/**
	 * 翻訳済みの旧 fetch_error でも恒久失敗として拾う。
	 *
	 * v3 は __() の戻り値を保存していたため、affilicard の翻訳を入れているサイトでは
	 * 日本語リテラルと一致しない。リテラルだけを見ると TERMINAL が TRANSIENT に化け、
	 * fallback_on_terminal が ON のとき消滅した購入リンクを選び続ける。
	 */
	public function test_翻訳済みの恒久失敗メッセージもTERMINALとして扱う(): void {
		$this->translations['該当する商品が見つかりませんでした'] = 'No matching product was found';

		$this->assertSame(
			FetchStatus::TERMINAL,
			FetchStatus::fromLegacyMessage( 'No matching product was found' )
		);
	}

	/**
	 * 翻訳済みの「自動取得の対象外」も UNSUPPORTED として拾う。
	 *
	 * JS 側（src/Admin/components/ListingsEditor.jsx の
	 * fetchStatusFromLegacyMessage()）に同じ規則を二重に持たせているため、
	 * 片方だけ訳語を拾う状態にならないよう両言語で同じケースを固定する。
	 * 対応する JS のテストは tests/js/components/ListingsEditor.test.jsx。
	 */
	public function test_翻訳済みの対象外メッセージもUNSUPPORTEDとして扱う(): void {
		$this->translations['対応する自動 Provider がありません'] = 'No automatic provider is available';

		$this->assertSame(
			FetchStatus::UNSUPPORTED,
			FetchStatus::fromLegacyMessage( 'No automatic provider is available' )
		);
	}

	/** 訳語を足しても、翻訳前に保存された日本語リテラルは従来どおり写る。 */
	public function test_訳語があっても日本語リテラルは従来どおり写る(): void {
		$this->translations['該当する商品が見つかりませんでした'] = 'No matching product was found';

		$this->assertSame(
			FetchStatus::TERMINAL,
			FetchStatus::fromLegacyMessage( '該当する商品が見つかりませんでした' )
		);
	}
}
