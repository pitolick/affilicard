<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Pricing;

use Affilicard\Pricing\OfferIdentity;
use PHPUnit\Framework\TestCase;

/**
 * 購入リンク（offer）の身元を決める OfferIdentity のテスト。
 *
 * この関数は「取得結果をどの offer へ書き戻すか」を決める。でたらめな身元が
 * 出ると、別の購入リンクへ価格を書き込む／どれにも一致せず書き戻しが落ちる、の
 * どちらかになる。ここで固定したいのは **非スカラーを身元にしない** ことである。
 *
 * WP 関数を一切呼ばないため WP_Mock は使わない（素の PHPUnit\TestCase）。
 */
final class OfferIdentityTest extends TestCase {

	public function test_external_idがあればそれを身元にする(): void {
		$this->assertSame(
			'external_id:abc123',
			OfferIdentity::of(
				array(
					'external_id' => 'abc123',
					'regular_url' => 'https://example.test/a',
				)
			)
		);
	}

	public function test_external_idが空なら_regular_urlを身元にする(): void {
		$this->assertSame(
			'regular_url:https://example.test/a',
			OfferIdentity::of(
				array(
					'external_id' => '',
					'regular_url' => 'https://example.test/a',
				)
			)
		);
	}

	public function test_どちらも無ければ空のregular_url身元になる(): void {
		$this->assertSame( 'regular_url:', OfferIdentity::of( array() ) );
	}

	/**
	 * 非スカラーの external_id は「値なし」に倒し、regular_url へ落とす。
	 *
	 * `(string)` で直にキャストすると配列は「Array to string conversion」警告のうえ
	 * `'Array'` になる。その瞬間、身元を持たない offer 同士が全員
	 * `external_id:Array` という**同一の身元**を名乗ることになり、
	 * ListingRefresher の書き戻しが最初に一致した別の購入リンクへ価格を書き込む。
	 */
	public function test_非スカラーのexternal_idは値なしとして扱う(): void {
		$this->assertSame(
			'regular_url:https://example.test/a',
			OfferIdentity::of(
				array(
					'external_id' => array( 'abc123' ),
					'regular_url' => 'https://example.test/a',
				)
			)
		);
	}

	/** 非スカラーの regular_url も「値なし」に倒す（'Array' を身元にしない）。 */
	public function test_非スカラーのregular_urlは値なしとして扱う(): void {
		$this->assertSame(
			'regular_url:',
			OfferIdentity::of(
				array(
					'regular_url' => array( 'https://example.test/a' ),
				)
			)
		);
	}

	/**
	 * `__toString` を持たないオブジェクトでも致命的エラーにならない。
	 *
	 * `(string) $object` は Error を投げる。身元の算出は listing の走査中に呼ばれるため、
	 * 1 件の壊れた offer が価格更新全体を落とすことになる。
	 */
	public function test_オブジェクトのフィールドでも致命的エラーにならない(): void {
		$this->assertSame(
			'regular_url:',
			OfferIdentity::of(
				array(
					'external_id' => new \stdClass(),
					'regular_url' => new \stdClass(),
				)
			)
		);
	}
}
