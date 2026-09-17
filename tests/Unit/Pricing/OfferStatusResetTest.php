<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Pricing;

use Affilicard\Pricing\FetchStatus;
use Affilicard\Pricing\OfferStatusReset;
use PHPUnit\Framework\TestCase;

final class OfferStatusResetTest extends TestCase {

	/**
	 * @param array<string, mixed> ...$offers
	 * @return array<int, mixed>
	 */
	private function listing( string $platform, array ...$offers ): array {
		return array(
			array(
				'platform' => $platform,
				'offers'   => array_values( $offers ),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function offer( string $externalId, string $url, string $status = FetchStatus::TERMINAL ): array {
		return array(
			'external_id'  => $externalId,
			'regular_url'  => $url,
			'fetch_status' => $status,
		);
	}

	/**
	 * 誤った external_id を訂正したら、前の身元で付いた恒久失敗を引き継がない。
	 *
	 * 引き継ぐと RefreshHandler::isGivenUp() が give-up マーカーと offer 自身の
	 * terminal を AND で見るため、訂正が cooldown のあいだ何も起こさない。
	 */
	public function test_external_idが変わった購入リンクのfetch_statusを空にする(): void {
		$got = OfferStatusReset::forIdentityChanges(
			$this->listing( 'rakuten-kobo', $this->offer( 'rk-old', 'https://example.test/a' ) ),
			$this->listing( 'rakuten-kobo', $this->offer( 'rk-fixed', 'https://example.test/a' ) )
		);

		$this->assertSame( FetchStatus::NONE, $got[0]['offers'][0]['fetch_status'] );
	}

	/** external_id を持たない購入リンクは regular_url が身元。 */
	public function test_regular_urlが変わった購入リンクのfetch_statusを空にする(): void {
		$got = OfferStatusReset::forIdentityChanges(
			$this->listing( 'rakuten-kobo', $this->offer( '', 'https://example.test/old' ) ),
			$this->listing( 'rakuten-kobo', $this->offer( '', 'https://example.test/fixed' ) )
		);

		$this->assertSame( FetchStatus::NONE, $got[0]['offers'][0]['fetch_status'] );
	}

	/**
	 * 身元が変わっていなければ触らない。
	 *
	 * ここを触ると廃盤 SKU の terminal が保存のたびに消え、give-up の cooldown が
	 * 意味を失って毎回リトライを焼く。
	 */
	public function test_身元が変わらない購入リンクのfetch_statusは保つ(): void {
		$got = OfferStatusReset::forIdentityChanges(
			$this->listing( 'rakuten-kobo', $this->offer( 'rk-1', 'https://example.test/a' ) ),
			$this->listing( 'rakuten-kobo', $this->offer( 'rk-1', 'https://example.test/b' ) )
		);

		// external_id がある限り regular_url が変わっても身元は同じ（OfferIdentity の規則）。
		$this->assertSame( FetchStatus::TERMINAL, $got[0]['offers'][0]['fetch_status'] );
	}

	/** 同じ listing の中でも、訂正された購入リンクだけを白紙にする。 */
	public function test_同じlistingの触っていない購入リンクは残す(): void {
		$got = OfferStatusReset::forIdentityChanges(
			$this->listing(
				'rakuten-kobo',
				$this->offer( 'rk-1', 'https://example.test/a' ),
				$this->offer( 'rk-2', 'https://example.test/b', FetchStatus::TRANSIENT )
			),
			$this->listing(
				'rakuten-kobo',
				$this->offer( 'rk-fixed', 'https://example.test/a' ),
				$this->offer( 'rk-2', 'https://example.test/b', FetchStatus::TRANSIENT )
			)
		);

		$this->assertSame( FetchStatus::NONE, $got[0]['offers'][0]['fetch_status'] );
		$this->assertSame( FetchStatus::TRANSIENT, $got[0]['offers'][1]['fetch_status'] );
	}

	/**
	 * 突き合わせは platform ごとに行う。
	 *
	 * external_id はストアの中でしか意味を持たないため、別 platform に同じ文字列が
	 * あっても「その listing では未知の身元」である。
	 */
	public function test_別のplatformの同名external_idは既知とみなさない(): void {
		$stored = array(
			array(
				'platform' => 'dmm-books',
				'offers'   => array( $this->offer( 'same-id', 'https://example.test/a' ) ),
			),
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array( $this->offer( 'rk-1', 'https://example.test/b' ) ),
			),
		);

		$got = OfferStatusReset::forIdentityChanges(
			$stored,
			$this->listing( 'rakuten-kobo', $this->offer( 'same-id', 'https://example.test/a' ) )
		);

		$this->assertSame( FetchStatus::NONE, $got[0]['offers'][0]['fetch_status'] );
	}

	/**
	 * 保存前に無かった platform の listing は触らない。
	 *
	 * 新規作成や listing の追加では「身元を訂正した」という出来事が起きていない。
	 * ここを触ると、取得結果を持ち込んで商品を作る外部ツールの fetch_status を
	 * 作成のたびに落とすことになる。
	 */
	public function test_保存前に無いplatformのlistingは触らない(): void {
		$incoming = $this->listing( 'rakuten-kobo', $this->offer( 'rk-1', 'https://example.test/a' ) );

		$this->assertSame( $incoming, OfferStatusReset::forIdentityChanges( array(), $incoming ) );
		$this->assertSame(
			$incoming,
			OfferStatusReset::forIdentityChanges(
				$this->listing( 'dmm-books', $this->offer( 'dmm-1', 'https://example.test/d' ) ),
				$incoming
			)
		);
	}

	/**
	 * 保存前が v3 以前の flat な listing でも身元を拾う。
	 *
	 * 拾えないと「既知の身元」が空になり、移行前の商品を保存するたびに全 offer の
	 * 取得状態を白紙にしてしまう。
	 */
	public function test_保存前がflatな旧形式でも身元を拾う(): void {
		$stored = array(
			array(
				'platform'    => 'rakuten-kobo',
				'external_id' => 'rk-1',
				'regular_url' => 'https://example.test/a',
			),
		);

		$got = OfferStatusReset::forIdentityChanges(
			$stored,
			$this->listing( 'rakuten-kobo', $this->offer( 'rk-1', 'https://example.test/a' ) )
		);

		$this->assertSame( FetchStatus::TERMINAL, $got[0]['offers'][0]['fetch_status'] );
	}

	/** offers を持たない listing（設定だけ・旧形式）はそのまま通す。 */
	public function test_offersを持たないlistingはそのまま返す(): void {
		$incoming = array(
			array(
				'platform'     => 'rakuten-kobo',
				'external_id'  => 'rk-1',
				'fetch_status' => FetchStatus::TERMINAL,
			),
			'壊れた要素',
		);

		$this->assertSame( $incoming, OfferStatusReset::forIdentityChanges( array(), $incoming ) );
	}

	/** offers の要素が配列でなくても壊れない。 */
	public function test_配列でないofferは素通りさせる(): void {
		$incoming = array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array( 'まちがい', $this->offer( 'rk-fixed', 'https://example.test/a' ) ),
			),
		);

		$got = OfferStatusReset::forIdentityChanges(
			$this->listing( 'rakuten-kobo', $this->offer( 'rk-old', 'https://example.test/a' ) ),
			$incoming
		);

		$this->assertSame( 'まちがい', $got[0]['offers'][0] );
		$this->assertSame( FetchStatus::NONE, $got[0]['offers'][1]['fetch_status'] );
	}

	/** 取得状態を持たない購入リンクには何も足さない。 */
	public function test_fetch_statusが空なら何も変えない(): void {
		$incoming = $this->listing( 'rakuten-kobo', $this->offer( 'rk-fixed', 'https://example.test/a', FetchStatus::NONE ) );

		$this->assertSame(
			$incoming,
			OfferStatusReset::forIdentityChanges(
				$this->listing( 'rakuten-kobo', $this->offer( 'rk-old', 'https://example.test/a' ) ),
				$incoming
			)
		);
	}
}
