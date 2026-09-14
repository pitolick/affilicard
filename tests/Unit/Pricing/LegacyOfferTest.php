<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Pricing;

use Affilicard\Pricing\LegacyOffer;
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
}
