<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Pricing;

use Affilicard\Pricing\FetchStatus;
use Affilicard\Pricing\LegacyOffer;
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
	 * 保存前が v3 以前の flat な listing でも身元と取得状態を拾う。
	 *
	 * 拾えないと「既知の身元」が空になり、移行前の商品を保存するたびに全 offer の
	 * 取得状態を白紙にしてしまう。
	 *
	 * **同じ platform に未知の身元を 1 件混ぜて判定する。** 「rk-1 を触らない」だけを
	 * 見ても、拾えているときと拾えていないとき（$known にこの platform が無く listing
	 * ごと素通りする）の区別が付かない——どちらも状態が残るため、実装を壊しても
	 * 落ちないテストになる。拾えていれば rk-1 だけが残り、rk-2 は未知の身元として
	 * 白紙になる。
	 */
	public function test_保存前がflatな旧形式でも身元と取得状態を拾う(): void {
		$stored = array(
			array(
				'platform'     => 'rakuten-kobo',
				'external_id'  => 'rk-1',
				'regular_url'  => 'https://example.test/a',
				'fetch_status' => FetchStatus::TERMINAL,
			),
		);

		$got = OfferStatusReset::forIdentityChanges(
			$stored,
			$this->listing(
				'rakuten-kobo',
				$this->offer( 'rk-1', 'https://example.test/a' ),
				$this->offer( 'rk-2', 'https://example.test/b' )
			)
		);

		$this->assertSame( FetchStatus::TERMINAL, $got[0]['offers'][0]['fetch_status'] );
		$this->assertSame( FetchStatus::NONE, $got[0]['offers'][1]['fetch_status'] );
	}

	/**
	 * 身元が集合に残っていても、保存前のその行が持っていた状態と違えば白紙に戻す。
	 *
	 * 取得状態は「その身元の行」が取得で得たものである。同じ身元を名乗りながら
	 * 違う状態を載せてきたということは、その状態は別の行から運ばれてきたか、
	 * 保存前の姿より古い写しである。どちらにせよ、その身元について何も語らない。
	 */
	public function test_保存前の同じ身元と違うfetch_statusは白紙に戻す(): void {
		$got = OfferStatusReset::forIdentityChanges(
			$this->listing( 'rakuten-kobo', $this->offer( 'rk-1', 'https://example.test/a', FetchStatus::TRANSIENT ) ),
			$this->listing( 'rakuten-kobo', $this->offer( 'rk-1', 'https://example.test/a', FetchStatus::TERMINAL ) )
		);

		$this->assertSame( FetchStatus::NONE, $got[0]['offers'][0]['fetch_status'] );
	}

	/**
	 * 保存しようとしている listing が flat（移行前）でも、身元が変われば白紙に戻す。
	 *
	 * 「ProductSchema が保存時に offers[] へ畳むから素通りでよい」は成り立たない——
	 * 畳み込み（{@see LegacyOffer::toOffer()}）は古い fetch_status／fetch_error を
	 * そのまま新しい offer へ運ぶ。運ばれた terminal は give-up マーカーと AND で
	 * 効くため、移行前の商品の external_id を訂正しても cooldown のあいだ再取得が
	 * 止まったままになる（まさにこのクラスが解こうとしている事故）。
	 */
	public function test_保存する側がflatでも身元が変わればfetch_statusを空にする(): void {
		$incoming = array(
			array(
				'platform'     => 'rakuten-kobo',
				'external_id'  => 'rk-fixed',
				'regular_url'  => 'https://example.test/a',
				'fetch_status' => FetchStatus::TERMINAL,
			),
		);

		$got = OfferStatusReset::forIdentityChanges(
			$this->listing( 'rakuten-kobo', $this->offer( 'rk-old', 'https://example.test/a' ) ),
			$incoming
		);

		$this->assertSame( FetchStatus::NONE, $got[0]['fetch_status'] );
	}

	/**
	 * flat な listing の旧 `fetch_error`（文言）も一緒に消す。
	 *
	 * 畳み込みは fetch_status が空なら fetch_error の文言から status を復元する
	 * （{@see LegacyOffer::toOffer()}）。fetch_status だけ空にしても、畳まれた offer に
	 * terminal が蘇って抑止が続く。
	 */
	public function test_保存する側がflatなら旧fetch_errorも消す(): void {
		$incoming = array(
			array(
				'platform'    => 'rakuten-kobo',
				'external_id' => 'rk-fixed',
				'regular_url' => 'https://example.test/a',
				// v3 以前の ListingRefresher が保存していた恒久失敗の文言。
				'fetch_error' => '該当する商品が見つかりませんでした',
			),
		);

		$got = OfferStatusReset::forIdentityChanges(
			$this->listing( 'rakuten-kobo', $this->offer( 'rk-old', 'https://example.test/a' ) ),
			$incoming
		);

		$this->assertSame( '', $got[0]['fetch_error'] );
		// 保存時に畳まれた姿（ProductSchema::sanitizeOffers と同じ写像）でも terminal が復活しない。
		$this->assertSame(
			FetchStatus::NONE,
			LegacyOffer::offersWithFallback( $got[0] )[0]['fetch_status']
		);
	}

	/**
	 * flat な保存でも、保存前のその行と違う取得状態は白紙に戻す。
	 *
	 * offers[] の経路と同じ規則を flat にも適用する（片方だけ緩いと、移行前の
	 * listing でだけ別の行から運ばれた terminal が生き残る）。
	 */
	public function test_保存する側がflatでも保存前と違うfetch_statusは白紙に戻す(): void {
		$incoming = array(
			array(
				'platform'     => 'rakuten-kobo',
				'external_id'  => 'rk-1',
				'regular_url'  => 'https://example.test/a',
				'fetch_status' => FetchStatus::TERMINAL,
			),
		);

		$got = OfferStatusReset::forIdentityChanges(
			$this->listing( 'rakuten-kobo', $this->offer( 'rk-1', 'https://example.test/a', FetchStatus::TRANSIENT ) ),
			$incoming
		);

		$this->assertSame( FetchStatus::NONE, $got[0]['fetch_status'] );
		$this->assertSame( '', $got[0]['fetch_error'] );
	}

	/**
	 * flat でも身元と取得状態が変わっていなければ触らない。
	 *
	 * 触ると廃盤 SKU の terminal が保存のたびに消え、give-up の cooldown が意味を失う。
	 */
	public function test_保存する側がflatでも身元が同じならfetch_statusを保つ(): void {
		$incoming = array(
			array(
				'platform'     => 'rakuten-kobo',
				'external_id'  => 'rk-1',
				'regular_url'  => 'https://example.test/b',
				'fetch_status' => FetchStatus::TERMINAL,
			),
		);

		$got = OfferStatusReset::forIdentityChanges(
			$this->listing( 'rakuten-kobo', $this->offer( 'rk-1', 'https://example.test/a' ) ),
			$incoming
		);

		$this->assertSame( FetchStatus::TERMINAL, $got[0]['fetch_status'] );
	}

	/**
	 * 取得結果フィールドを 1 つも持たない flat な listing（設定だけ）は素通りさせる。
	 *
	 * 畳み込みの対象にならない＝offer が生まれないので、白紙にするものが無い。
	 * ここでキーを足すと、設定だけの listing に空の取得状態が生える。
	 */
	public function test_設定だけのlistingにはキーを足さない(): void {
		$incoming = array(
			array(
				'platform'    => 'rakuten-kobo',
				'enabled'     => true,
				'update_mode' => 'auto',
			),
		);

		$got = OfferStatusReset::forIdentityChanges(
			$this->listing( 'rakuten-kobo', $this->offer( 'rk-old', 'https://example.test/a' ) ),
			$incoming
		);

		$this->assertSame( $incoming, $got );
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

	/**
	 * 別の購入リンクの身元へ打ち替えて元を消したら白紙に戻す。
	 *
	 * 身元の**集合**は変わらない（rk-b は保存前にも居た）。変わったのは行と身元の
	 * 対応である。残った行が名乗る rk-b の取得状態は、保存前の rk-b の行が持っていた
	 * もの（空）であって、打ち替え元の rk-a が得た terminal ではない。突き合わせに
	 * 状態まで含めると、集合が同じでも対応が変わったことが分かる。
	 */
	public function test_別の購入リンクの身元へ打ち替えて元を消したら白紙に戻す(): void {
		$got = OfferStatusReset::forIdentityChanges(
			$this->listing(
				'rakuten-kobo',
				$this->offer( 'rk-a', 'https://example.test/a' ),
				$this->offer( 'rk-b', 'https://example.test/b', FetchStatus::NONE )
			),
			// A を rk-b へ打ち替え、元の rk-b は削除済み（ProductSchema が重複を後勝ちで畳んだ後の姿）。
			$this->listing( 'rakuten-kobo', $this->offer( 'rk-b', 'https://example.test/a' ) )
		);

		$this->assertSame( FetchStatus::NONE, $got[0]['offers'][0]['fetch_status'] );
	}

	/**
	 * 打ち替え先の身元が保存前から同じ状態だったなら保つ。
	 *
	 * 白紙にするのは「その身元の行が持っていなかった状態」だけである。rk-b の行も
	 * terminal だったのなら、残った行が terminal を名乗ることは rk-b の SKU について
	 * 正しく、消すと廃盤 SKU への再取得を毎回焼くことになる。
	 */
	public function test_打ち替え先の身元が同じ状態を持っていたなら保つ(): void {
		$got = OfferStatusReset::forIdentityChanges(
			$this->listing(
				'rakuten-kobo',
				$this->offer( 'rk-a', 'https://example.test/a' ),
				$this->offer( 'rk-b', 'https://example.test/b' )
			),
			$this->listing( 'rakuten-kobo', $this->offer( 'rk-b', 'https://example.test/a' ) )
		);

		$this->assertSame( FetchStatus::TERMINAL, $got[0]['offers'][0]['fetch_status'] );
	}

	/**
	 * 2 つの購入リンクの external_id を入れ替えたら白紙に戻す。
	 *
	 * 入れ替えでも身元の集合は変わらないが、terminal を載せた行が名乗る身元は
	 * 保存前に terminal ではなかった rk-b である。
	 */
	public function test_2つの購入リンクの身元を入れ替えたら白紙に戻す(): void {
		$got = OfferStatusReset::forIdentityChanges(
			$this->listing(
				'rakuten-kobo',
				$this->offer( 'rk-a', 'https://example.test/a' ),
				$this->offer( 'rk-b', 'https://example.test/b', FetchStatus::NONE )
			),
			$this->listing(
				'rakuten-kobo',
				$this->offer( 'rk-b', 'https://example.test/a' ),
				$this->offer( 'rk-a', 'https://example.test/b', FetchStatus::NONE )
			)
		);

		$this->assertSame( FetchStatus::NONE, $got[0]['offers'][0]['fetch_status'] );
		$this->assertSame( FetchStatus::NONE, $got[0]['offers'][1]['fetch_status'] );
	}
}
