<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Pricing;

use Affilicard\Pricing\LegacyOffer;
use Affilicard\Pricing\OfferSelector;
use PHPUnit\Framework\TestCase;

final class LegacyOfferTest extends TestCase {

	/**
	 * 非スカラーの入力を文字列へキャストしない。
	 *
	 * REST の本文は入れ子の配列を含みうる。`(string)` でキャストすると配列は
	 * 警告のうえ "Array" になり、身元が "Array" の購入リンクが保存される。
	 * そうなると再同定も生死判定もできず、静かに壊れたデータが残る。
	 */
	public function test_配列のexternal_idやregular_urlは空として扱う(): void {
		$offer = LegacyOffer::toOffer(
			array(
				'external_id' => array( 'r-1' ),
				'regular_url' => array( 'https://example.test/a' ),
				'price'       => array( '693' ),
			)
		);

		$this->assertSame( '', $offer['external_id'] );
		$this->assertSame( '', $offer['regular_url'] );
		$this->assertSame( '', $offer['price'] );
	}

	/** 非スカラーしか無い listing は「取得結果を持たない」と判定する。 */
	public function test_配列しか持たない_listing_は取得結果なしと判定する(): void {
		$this->assertFalse(
			LegacyOffer::hasFlatFetchFields(
				array(
					'external_id' => array( 'r-1' ),
					'regular_url' => array( 'https://example.test/a' ),
				)
			)
		);
	}

	/** 非スカラーだけの flat listing からは購入リンクを合成しない。 */
	public function test_配列しか持たない_listing_からは購入リンクを作らない(): void {
		$this->assertSame(
			array(),
			LegacyOffer::offersWithFallback( array( 'external_id' => array( 'r-1' ) ) )
		);
	}

	/** スカラーの扱いは従来どおり（数値も文字列化する）。 */
	public function test_スカラーは従来どおり文字列化する(): void {
		$offer = LegacyOffer::toOffer(
			array(
				'external_id' => 'r-1',
				'price'       => 693,
			)
		);

		$this->assertSame( 'r-1', $offer['external_id'] );
		$this->assertSame( '693', $offer['price'] );
	}

	/**
	 * 取得状態も他のフィールドと同じく非スカラーを捨てる。
	 *
	 * `(string)` で直にキャストすると「Array to string conversion」の警告を出したうえで
	 * `'Array'` という存在しない取得状態が offer に入り、後段の `FetchStatus::normalise()`
	 * が畳むため、理由の書かれない警告アイコンだけが残る。
	 */
	public function test_配列のfetch_statusやfetch_errorは取得状態を作らない(): void {
		$offer = LegacyOffer::toOffer(
			array(
				'external_id'  => 'r-1',
				'fetch_status' => array( 'terminal' ),
				'fetch_error'  => array( '該当する商品が見つかりませんでした' ),
			)
		);

		$this->assertSame( '', $offer['fetch_status'] );
	}

	/**
	 * 読めない display_order は既定値へ倒す（OfferSelector::normaliseOrder と同じ規則）。
	 *
	 * ここだけ `(int)` で畳むと、v3 の flat listing で読めない値のとき PHP は 0
	 * （＝最優先）、編集画面は 100 として扱う。しかも移行後も 0 のまま保存され、
	 * 後から追加した購入リンクより常に先へ来てしまう。
	 */
	public function test_読めないdisplay_orderは既定値へ倒す(): void {
		foreach ( array(
			'（空文字）' => '',
			'非数値'   => 'あ',
			'数字始まり' => '12abc',
		) as $label => $raw ) {
			$offer = LegacyOffer::toOffer(
				array(
					'external_id'   => 'x',
					'display_order' => $raw,
				)
			);
			$this->assertSame(
				OfferSelector::DEFAULT_ORDER,
				$offer['display_order'],
				sprintf( '%s を既定値へ倒していない', $label )
			);
		}
	}

	/** 数値文字列は従来どおり読む。 */
	public function test_数値文字列のdisplay_orderは整数として読む(): void {
		$offer = LegacyOffer::toOffer(
			array(
				'external_id'   => 'x',
				'display_order' => '12',
			)
		);
		$this->assertSame( 12, $offer['display_order'] );
	}
}
