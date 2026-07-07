<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Rest\CardPreviewController;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;

final class CardPreviewControllerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$this->stubCommonWpFunctions();
		// featured image → get_post_thumbnail_id を 0（サムネイルなし）で返す
		WP_Mock::userFunction( 'get_post_thumbnail_id' )->andReturn( 0 );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * CardHtmlBuilder 経由で呼ばれる WP 関数の共通 stub。
	 * setUp() と、サムネイルありに差し替えるマスク系テストの双方から呼ばれる。
	 */
	private function stubCommonWpFunctions(): void {
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn( $v ) => is_string( $v ) ? trim( $v ) : ''
		);
		WP_Mock::userFunction( 'sanitize_hex_color', array( 'return_arg' => 0 ) );
		WP_Mock::passthruFunction( 'esc_html' );
		WP_Mock::passthruFunction( 'esc_attr' );
		WP_Mock::passthruFunction( 'esc_url' );
		// PlatformConfig::all() → get_option を空配列で返す
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		// build() が呼ぶ current_time を固定（発売日なし商品は is_preorder=false のまま）
		WP_Mock::userFunction( 'current_time', array( 'return' => '2026-06-29' ) );
	}

	/**
	 * テスト用に WP_REST_Request stub へパラメータを設定するヘルパ。
	 *
	 * @param array<string, mixed> $params
	 */
	private function makeRequest( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/affilicard/v1/products/' . ( $params['id'] ?? 0 ) . '/card-preview' );
		foreach ( $params as $key => $value ) {
			$request->set_param( (string) $key, $value );
		}
		return $request;
	}

	public function test_preview_loads_draft_product_and_returns_html(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );

		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'find' )->with( 42 )->willReturn(
			array(
				'id'           => 42,
				'title'        => '下書き商品',
				'content'      => '',
				'status'       => 'draft',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'extras'       => array(),
				'listings'     => array(),
				'modified'     => '',
			)
		);

		$controller = new CardPreviewController( $repository );
		$request    = $this->makeRequest( array( 'id' => 42 ) );

		$response = $controller->preview( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'html', $data );
		$this->assertIsString( $data['html'] );
	}

	public function test_preview_returns_404_when_product_missing(): void {
		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'find' )->willReturn( null );

		$controller = new CardPreviewController( $repository );
		$response   = $controller->preview( $this->makeRequest( array( 'id' => 999 ) ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_preview_passes_hide_platforms_and_colors_to_builder(): void {
		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'find' )->willReturn(
			array(
				'id'           => 1,
				'title'        => 'テスト商品',
				'content'      => '',
				'status'       => 'publish',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'extras'       => array(),
				'listings'     => array(),
				'modified'     => '',
			)
		);

		$controller = new CardPreviewController( $repository );
		$request    = $this->makeRequest(
			array(
				'id'              => 1,
				'hidePlatforms'   => array( 'dmm-books' ),
				'ctaBgColor'      => '#ff0000',
				'ctaTextColor'    => '#ffffff',
				'cardBgColor'     => '#fafafa',
				'cardBorderColor' => '#cccccc',
			)
		);

		$response = $controller->preview( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'html', $data );
	}

	public function test_preview_passes_only_platforms_to_builder(): void {
		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'find' )->willReturn(
			array(
				'id'           => 1,
				'title'        => 'テスト商品',
				'content'      => '',
				'status'       => 'publish',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'extras'       => array(),
				'listings'     => array(),
				'modified'     => '',
			)
		);

		$controller = new CardPreviewController( $repository );
		$request    = $this->makeRequest(
			array(
				'id'            => 1,
				'onlyPlatforms' => array( 'dmm-books' ),
			)
		);

		$response = $controller->preview( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'html', $response->get_data() );
	}

	public function test_preview_guards_only_platforms_non_array(): void {
		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'find' )->willReturn(
			array(
				'id'           => 3,
				'title'        => '商品C',
				'content'      => '',
				'status'       => 'draft',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'extras'       => array(),
				'listings'     => array(),
				'modified'     => '',
			)
		);

		$controller = new CardPreviewController( $repository );
		// onlyPlatforms に非配列を渡しても 200 で返ること（空配列として処理）
		$request = $this->makeRequest(
			array(
				'id'            => 3,
				'onlyPlatforms' => 'not-an-array',
			)
		);

		$response = $controller->preview( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'html', $response->get_data() );
	}

	public function test_preview_guards_hide_platforms_non_array(): void {
		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'find' )->willReturn(
			array(
				'id'           => 2,
				'title'        => '商品B',
				'content'      => '',
				'status'       => 'draft',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'extras'       => array(),
				'listings'     => array(),
				'modified'     => '',
			)
		);

		$controller = new CardPreviewController( $repository );
		// hidePlatforms に非配列を渡しても 200 で返ること（空配列として処理）
		$request = $this->makeRequest(
			array(
				'id'            => 2,
				'hidePlatforms' => 'not-an-array',
			)
		);

		$response = $controller->preview( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_preview_passes_mask_attributes_when_present(): void {
		// マスクの重ね表示は image_url が空でないときのみ描画されるため、
		// このテストだけ WP 関数スタブをリセットしてサムネイルありに差し替える
		// （setUp() の get_post_thumbnail_id=0 は他テストの前提のため変更しない）。
		WP_Mock::setUp();
		$this->stubCommonWpFunctions();
		WP_Mock::userFunction( 'get_post_thumbnail_id' )->andReturn( 321 );
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->andReturn( 'https://example.com/cover.jpg' );

		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'find' )->willReturn(
			array(
				'id'           => 7,
				'title'        => 'マスク商品',
				'content'      => '',
				'status'       => 'publish',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'extras'       => array(),
				'listings'     => array(),
				'modified'     => '',
				// 商品側 meta: r18=true（マスク要）・独自ラベル保持。
				// maskR18 を未送信のまま preview してもこの値が継承されることを確認する。
				// mask_r18=true は CardRenderer 側で mask_blur を強制するため、
				// このテストは maskR18 継承／maskLabel 上書きの検証に専念する
				// （maskBlur 単独 passthrough の検証は次の
				// test_preview_mask_blur_alone_forces_masked_overlay で分離する）。
				'mask_blur'    => false,
				'mask_r18'     => true,
				'mask_label'   => '元ラベル',
			)
		);

		$controller = new CardPreviewController( $repository );
		$request    = $this->makeRequest(
			array(
				'id'        => 7,
				'maskBlur'  => '1',
				'maskLabel' => '注意',
				// maskR18 は未送信のまま（resolveMask の継承を確認する）。
			)
		);

		$response = $controller->preview( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertStringContainsString( 'affilicard-card__cover--masked', $data['html'] );
		// maskLabel は送信値で上書きされる。
		$this->assertStringContainsString( '注意', $data['html'] );
		$this->assertStringNotContainsString( '元ラベル', $data['html'] );
		// maskR18 は未送信 → 商品 meta の true を継承し R18 バッジが出る。
		// バッジの図案（aria-label 等）は変わり得るため、安定した class で presence を固定する。
		$this->assertStringContainsString( 'affilicard-card__cover-badge', $data['html'] );
	}

	public function test_preview_mask_blur_alone_forces_masked_overlay(): void {
		// product 側 mask_blur/mask_r18 を両方 false にし、request の maskBlur だけを
		// 送信することで、masked オーバーレイが maskR18 継承ではなく maskBlur
		// passthrough 経由でのみ出ることを固定する
		// （このアサーションは CardPreviewController::preview の maskBlur ブロックを
		// 削除すると RED になることを確認済み）。
		WP_Mock::setUp();
		$this->stubCommonWpFunctions();
		WP_Mock::userFunction( 'get_post_thumbnail_id' )->andReturn( 321 );
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->andReturn( 'https://example.com/cover.jpg' );

		$repository = $this->createMock( ProductRepositoryInterface::class );
		$repository->method( 'find' )->willReturn(
			array(
				'id'           => 8,
				'title'        => 'ぼかし単独商品',
				'content'      => '',
				'status'       => 'publish',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'extras'       => array(),
				'listings'     => array(),
				'modified'     => '',
				// 商品側 meta は両方 false。masked オーバーレイが出るとしたら
				// request の maskBlur passthrough 以外に原因がないようにする。
				'mask_blur'    => false,
				'mask_r18'     => false,
				'mask_label'   => '',
			)
		);

		$controller = new CardPreviewController( $repository );
		$request    = $this->makeRequest(
			array(
				'id'       => 8,
				'maskBlur' => '1',
				// maskR18 / maskLabel は未送信。
			)
		);

		$response = $controller->preview( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertStringContainsString( 'affilicard-card__cover--masked', $data['html'] );
		// maskR18 は未送信・product も false なので 18+ バッジは出ない。
		$this->assertStringNotContainsString( 'aria-label="18+"', $data['html'] );
	}

	public function test_can_edit_posts_delegates_to_current_user_can(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts' )
			->andReturn( true );

		$repository = $this->createMock( ProductRepositoryInterface::class );
		$controller = new CardPreviewController( $repository );

		$this->assertTrue( $controller->canEditPosts() );
	}
}
