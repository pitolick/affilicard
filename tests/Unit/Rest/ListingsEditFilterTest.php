<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\PostType\ProductPostType;
use Affilicard\Pricing\FetchStatus;
use Affilicard\Rest\ListingsEditFilter;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;

final class ListingsEditFilterTest extends TestCase {

	/**
	 * get_post_meta( META_LISTINGS ) が返す「保存前の listings」。
	 *
	 * **テストごとに userFunction を登録し直しても切り替わらない。** WP_Mock は
	 * 同じ関数名について最初の期待だけを保持するため、値はここから読む。
	 *
	 * @var array<int, mixed>
	 */
	private array $storedListings = array();

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( 'get_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key = '', $single = false ) {
					return ProductPostType::META_LISTINGS === $key ? $this->storedListings : array();
				}
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $offer
	 * @return array<int, mixed>
	 */
	private function listings( array ...$offer ): array {
		return array(
			array(
				'platform' => 'rakuten-kobo',
				'offers'   => array_values( $offer ),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function offer( string $externalId, string $status = FetchStatus::TERMINAL ): array {
		return array(
			'external_id'  => $externalId,
			'regular_url'  => 'https://example.test/a',
			'fetch_status' => $status,
		);
	}

	private function request( ?array $listings ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wp/v2/affilicard_product/5' );
		if ( null !== $listings ) {
			$request->set_param( 'meta', array( ProductPostType::META_LISTINGS => $listings ) );
		}
		return $request;
	}

	/**
	 * ブロックエディタ（core-data）の保存経路でも、身元を訂正した購入リンクの
	 * 取得状態が白紙に戻る。
	 */
	public function test_external_idを訂正したらリクエストのmetaを白紙に戻す(): void {
		$this->storedListings = $this->listings( $this->offer( 'rk-old' ) );

		$request  = $this->request( $this->listings( $this->offer( 'rk-fixed' ) ) );
		$prepared = (object) array( 'ID' => 5 );

		$this->assertSame( $prepared, ListingsEditFilter::resetEditedOfferStatus( $prepared, $request ) );

		$meta = $request->get_param( 'meta' );
		$this->assertSame(
			FetchStatus::NONE,
			$meta[ ProductPostType::META_LISTINGS ][0]['offers'][0]['fetch_status']
		);
	}

	/** 身元が変わっていなければ触らない。 */
	public function test_身元が変わらなければmetaを書き換えない(): void {
		$this->storedListings = $this->listings( $this->offer( 'rk-1' ) );

		$incoming = $this->listings( $this->offer( 'rk-1' ) );
		$request  = $this->request( $incoming );

		ListingsEditFilter::resetEditedOfferStatus( (object) array( 'ID' => 5 ), $request );

		$meta = $request->get_param( 'meta' );
		$this->assertSame( $incoming, $meta[ ProductPostType::META_LISTINGS ] );
	}

	/** 新規作成（prepared に ID が無い）では「訂正」が起きていないので何もしない。 */
	public function test_新規作成では何もしない(): void {
		$this->storedListings = array();

		$incoming = $this->listings( $this->offer( 'rk-new' ) );
		$request  = $this->request( $incoming );

		ListingsEditFilter::resetEditedOfferStatus( (object) array( 'post_title' => 'A' ), $request );

		$meta = $request->get_param( 'meta' );
		$this->assertSame( $incoming, $meta[ ProductPostType::META_LISTINGS ] );
	}

	/** listings を送らない保存（本文だけの更新など）では meta を作らない。 */
	public function test_listingsを送らない保存ではmetaを触らない(): void {
		$this->storedListings = $this->listings( $this->offer( 'rk-1' ) );

		$request = $this->request( null );

		ListingsEditFilter::resetEditedOfferStatus( (object) array( 'ID' => 5 ), $request );

		$this->assertNull( $request->get_param( 'meta' ) );
	}
}
