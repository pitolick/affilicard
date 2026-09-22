<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Pricing;

use Affilicard\Pricing\FetchStatus;
use Affilicard\Pricing\OfferSelector;
use PHPUnit\Framework\TestCase;

final class OfferSelectorTest extends TestCase {

	/** @return array<string, mixed> */
	private function offer( int $order, string $id, string $status = FetchStatus::NONE ): array {
		return array(
			'display_order' => $order,
			'external_id'   => $id,
			'regular_url'   => 'https://example.test/' . $id,
			'fetch_status'  => $status,
		);
	}

	public function test_空なら空配列を返す(): void {
		$this->assertSame( array(), OfferSelector::select( array(), true ) );
	}

	public function test_表示順が小さいものを選ぶ(): void {
		$offers = array( $this->offer( 100, 'b' ), $this->offer( 10, 'a' ) );
		$got    = OfferSelector::select( $offers, false );
		$this->assertCount( 1, $got );
		$this->assertSame( 'a', $got[0]['external_id'] );
	}

	public function test_同値なら配列の出現順を保つ(): void {
		$offers = array( $this->offer( 10, 'first' ), $this->offer( 10, 'second' ) );
		$got    = OfferSelector::select( $offers, false );
		$this->assertSame( 'first', $got[0]['external_id'] );
	}

	public function test_display_order未指定は100として扱う(): void {
		$offers = array(
			array(
				'external_id' => 'default',
				'regular_url' => 'https://example.test/d',
			),
			$this->offer( 10, 'explicit' ),
		);
		$got    = OfferSelector::select( $offers, false );
		$this->assertSame( 'explicit', $got[0]['external_id'] );
	}

	/**
	 * 管理画面（src/Admin/components/ListingsEditor.jsx の offerDisplayOrder）は
	 * この規則を JS へ写している。ここは「PHP 側が何を返すか」の固定であり、
	 * tests/js/components/ListingsEditor.test.jsx の同名ケースと対になっている。
	 * どちらかを変えるときは必ず両方直すこと。
	 */
	/**
	 * 読めない display_order は「指定なし」と同じ扱い（既定値）にする。
	 *
	 * `(int)` で畳むと空文字も非数値文字列も 0 になり、打ち間違いや壊れた入力が
	 * 「いちばん先に表示される購入リンク」に化ける。並び順の指定が読めないなら、
	 * 指定が無いときと同じ扱いにするのが安全側である。
	 */
	public function test_display_orderが空文字なら既定値として扱う(): void {
		$offers = array(
			$this->offer( 10, 'ten' ),
			array(
				'display_order' => '',
				'external_id'   => 'empty',
				'regular_url'   => 'https://example.test/e',
			),
		);
		$got    = OfferSelector::select( $offers, false );
		$this->assertSame( 'ten', $got[0]['external_id'] );
	}

	public function test_display_orderが数字でない文字列なら既定値として扱う(): void {
		$offers = array(
			$this->offer( 10, 'ten' ),
			array(
				'display_order' => 'あ',
				'external_id'   => 'bogus',
				'regular_url'   => 'https://example.test/b',
			),
		);
		$got    = OfferSelector::select( $offers, false );
		$this->assertSame( 'ten', $got[0]['external_id'] );
	}

	/** 数字で始まるだけの文字列も「読めない」に倒す（先頭の数値を拾わない）。 */
	public function test_display_orderが数字で始まるだけの文字列なら既定値として扱う(): void {
		$offers = array(
			$this->offer( 50, 'fifty' ),
			array(
				'display_order' => '12abc',
				'external_id'   => 'twelve',
				'regular_url'   => 'https://example.test/t',
			),
		);
		$got    = OfferSelector::select( $offers, false );
		$this->assertSame( 'fifty', $got[0]['external_id'] );
	}

	/**
	 * 桁あふれする数値文字列も「読めない」に倒す。
	 *
	 * `'1e9999'` は is_numeric() を通るが float にすると INF になり、INF の `(int)`
	 * キャストは未定義（実測 0）＝最優先に化ける。JS 側（ListingsEditor の
	 * offerDisplayOrder）は Number.isFinite() で既定値へ倒しており、規則の写しを
	 * 保つためにもここで弾く。
	 */
	public function test_display_orderが桁あふれする数値文字列なら既定値として扱う(): void {
		$offers = array(
			$this->offer( 50, 'fifty' ),
			array(
				'display_order' => '1e9999',
				'external_id'   => 'huge',
				'regular_url'   => 'https://example.test/h',
			),
		);
		$got    = OfferSelector::select( $offers, false );
		$this->assertSame( 'fifty', $got[0]['external_id'] );
	}

	/** 有限でない float（INF / NAN）も既定値へ倒す。 */
	public function test_display_orderが有限でないfloatなら既定値として扱う(): void {
		$this->assertSame( OfferSelector::DEFAULT_ORDER, OfferSelector::normaliseOrder( INF ) );
		$this->assertSame( OfferSelector::DEFAULT_ORDER, OfferSelector::normaliseOrder( -INF ) );
		$this->assertSame( OfferSelector::DEFAULT_ORDER, OfferSelector::normaliseOrder( NAN ) );
		$this->assertSame( OfferSelector::DEFAULT_ORDER, OfferSelector::normaliseOrder( '1e9999' ) );
	}

	/** 数値文字列は従来どおり読む。 */
	public function test_display_orderが数値文字列なら整数として読む(): void {
		$offers = array(
			$this->offer( 50, 'fifty' ),
			array(
				'display_order' => '12',
				'external_id'   => 'twelve',
				'regular_url'   => 'https://example.test/t',
			),
		);
		$got    = OfferSelector::select( $offers, false );
		$this->assertSame( 'twelve', $got[0]['external_id'] );
	}

	public function test_設定OFFならterminalでも先頭を返す(): void {
		$offers = array( $this->offer( 10, 'dead', FetchStatus::TERMINAL ), $this->offer( 100, 'alive' ) );
		$got    = OfferSelector::select( $offers, false );
		$this->assertSame( 'dead', $got[0]['external_id'] );
	}

	public function test_設定ONならterminalを飛ばす(): void {
		$offers = array( $this->offer( 10, 'dead', FetchStatus::TERMINAL ), $this->offer( 100, 'alive' ) );
		$got    = OfferSelector::select( $offers, true );
		$this->assertSame( 'alive', $got[0]['external_id'] );
	}

	public function test_設定ONでもtransientとunsupportedは飛ばさない(): void {
		// 商品は存在している。切り替えると復旧時に戻る往復が起きる。
		$offers = array( $this->offer( 10, 'busy', FetchStatus::TRANSIENT ), $this->offer( 100, 'alive' ) );
		$this->assertSame( 'busy', OfferSelector::select( $offers, true )[0]['external_id'] );

		$offers = array( $this->offer( 10, 'manual', FetchStatus::UNSUPPORTED ), $this->offer( 100, 'alive' ) );
		$this->assertSame( 'manual', OfferSelector::select( $offers, true )[0]['external_id'] );
	}

	public function test_全件terminalなら先頭を返す(): void {
		// 非破壊。リンクは出したまま価格だけ PriceFreshness が隠す。
		$offers = array( $this->offer( 10, 'a', FetchStatus::TERMINAL ), $this->offer( 100, 'b', FetchStatus::TERMINAL ) );
		$got    = OfferSelector::select( $offers, true );
		$this->assertSame( 'a', $got[0]['external_id'] );
	}

	public function test_配列でない要素は無視する(): void {
		$offers = array( 'こわれた値', $this->offer( 10, 'ok' ) );
		$got    = OfferSelector::select( $offers, false );
		$this->assertCount( 1, $got );
		$this->assertSame( 'ok', $got[0]['external_id'] );
	}
}
