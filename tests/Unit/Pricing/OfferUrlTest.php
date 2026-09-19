<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Pricing;

use Affilicard\Pricing\OfferUrl;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * 「どの URL を出すか」の規則を 1 箇所へ集めた OfferUrl のテスト。
 *
 * ここで固定したいのは ctaHref()（カードの実物）と isRegularUrlFallback()
 * （ダッシュボードの件数・商品一覧の警告列）が**必ず同じ答えを返す**ことである。
 * 以前は後者 2 つが素の空判定を持っており、affiliate_url が不正で regular_url が
 * 正当な offer——カードは regular_url で描画している＝正真正銘のフォールバック中
 * ——を「フォールバックではない」と数えていた。
 */
final class OfferUrlTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		// 実 WordPress の esc_url_raw() は javascript:/data: 等の危険スキームを排除して
		// 空文字を返す（CardRendererTest と同じ stub）。
		WP_Mock::userFunction( 'esc_url_raw' )
			->andReturnUsing(
				static function ( $value ) {
					$value = is_scalar( $value ) ? (string) $value : '';
					if ( 1 === preg_match( '/^\s*(javascript|data|vbscript)\s*:/i', $value ) ) {
						return '';
					}
					return $value;
				}
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_アフィリURLがあればそれを出す(): void {
		$offer = array(
			'affiliate_url' => 'https://example.test/a?aff=1',
			'regular_url'   => 'https://example.test/a',
		);

		$this->assertSame( 'https://example.test/a?aff=1', OfferUrl::ctaHref( $offer ) );
		$this->assertFalse( OfferUrl::isRegularUrlFallback( $offer ) );
	}

	public function test_アフィリURLが空なら通常URLへ倒れフォールバックと数える(): void {
		$offer = array(
			'affiliate_url' => '',
			'regular_url'   => 'https://example.test/a',
		);

		$this->assertSame( 'https://example.test/a', OfferUrl::ctaHref( $offer ) );
		$this->assertTrue( OfferUrl::isRegularUrlFallback( $offer ) );
	}

	/**
	 * 不正な affiliate_url は「無い」と同じ——カードは regular_url を出す。
	 *
	 * 件数と警告列が素の空判定を持っていたころは、この offer だけカードの実物と
	 * 答えが食い違っていた（カードはフォールバック中なのに「フォールバックではない」）。
	 */
	public function test_不正なアフィリURLは通常URLへ倒れフォールバックと数える(): void {
		$offer = array(
			'affiliate_url' => 'javascript:alert(1)',
			'regular_url'   => 'https://example.test/a',
		);

		$this->assertSame( 'https://example.test/a', OfferUrl::ctaHref( $offer ) );
		$this->assertTrue( OfferUrl::isRegularUrlFallback( $offer ) );
	}

	/**
	 * 通常 URL も不正なら出せる URL が 1 つも無い——フォールバックですらない。
	 *
	 * この offer はカード側でも表示対象から外れる（CardRenderer::visibleListings()）。
	 */
	public function test_通常URLも不正なら出せるURLが無くフォールバックでもない(): void {
		$offer = array(
			'affiliate_url' => '',
			'regular_url'   => 'javascript:alert(1)',
		);

		$this->assertSame( '', OfferUrl::ctaHref( $offer ) );
		$this->assertFalse( OfferUrl::isRegularUrlFallback( $offer ) );
	}

	public function test_URLを1つも持たない購入リンクはフォールバックではない(): void {
		$this->assertSame( '', OfferUrl::ctaHref( array() ) );
		$this->assertFalse( OfferUrl::isRegularUrlFallback( array() ) );
	}

	/** URL の前後の空白は落として判定する。 */
	public function test_前後の空白は落として判定する(): void {
		$offer = array(
			'affiliate_url' => '   ',
			'regular_url'   => '  https://example.test/a  ',
		);

		$this->assertSame( 'https://example.test/a', OfferUrl::ctaHref( $offer ) );
		$this->assertTrue( OfferUrl::isRegularUrlFallback( $offer ) );
	}

	/**
	 * 非スカラーは「値なし」として扱う（ScalarField::string と同じ規則）。
	 *
	 * `(string)` で直にキャストすると「Array to string conversion」の警告のうえ
	 * `'Array'` になり、esc_url_raw() がそれを実在しない URL へ仕立ててしまう。
	 */
	public function test_非スカラーのURLは値なしとして扱う(): void {
		$offer = array(
			'affiliate_url' => array( 'https://example.test/a?aff=1' ),
			'regular_url'   => 'https://example.test/a',
		);

		$this->assertSame( 'https://example.test/a', OfferUrl::ctaHref( $offer ) );
		$this->assertTrue( OfferUrl::isRegularUrlFallback( $offer ) );
	}
}
