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
