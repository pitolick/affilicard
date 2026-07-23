<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Block;

use Affilicard\Block\Block;
use Affilicard\Queue\Enqueuer;
use Affilicard\Repository\ProductRepository;
use Affilicard\Repository\ProductRepositoryInterface;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class BlockTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::passthruFunction( 'esc_html' );
		WP_Mock::passthruFunction( 'esc_attr' );
		WP_Mock::passthruFunction( 'esc_url' );
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'sanitize_hex_color', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'wp_enqueue_style', array( 'return' => true ) );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * get_post / get_post_meta をモックし、find() が解決可能な投稿を返すようにする。
	 */
	private function mockFoundPost( int $id, string $title ): void {
		$post = (object) array(
			'ID'            => $id,
			'post_type'     => 'affilicard_product',
			'post_title'    => $title,
			'post_content'  => '',
			'post_status'   => 'publish',
			'post_modified' => '2026-06-01 00:00:00',
		);
		WP_Mock::userFunction( 'get_post', array( 'return' => $post ) );
		WP_Mock::userFunction( 'get_post_meta', array( 'return' => '' ) );
	}

	public function test_render_resolves_by_product_id(): void {
		$this->mockFoundPost( 7, '解決された商品' );
		WP_Mock::userFunction( 'get_option', array( 'return' => array() ) );
		WP_Mock::userFunction( 'get_post_thumbnail_id', array( 'return' => 0 ) );
		WP_Mock::userFunction( 'current_time', array( 'return' => '2026-06-29' ) );

		$block = new Block( new ProductRepository(), new Enqueuer() );
		$html  = $block->render( array( 'productId' => 7 ) );

		$this->assertStringContainsString( '解決された商品', $html );
	}

	public function test_render_returns_empty_for_non_published_product(): void {
		// 下書き/非公開商品は公開フロントに描画しない。
		$post = (object) array(
			'ID'            => 7,
			'post_type'     => 'affilicard_product',
			'post_title'    => '下書き商品',
			'post_content'  => '',
			'post_status'   => 'draft',
			'post_modified' => '2026-06-01 00:00:00',
		);
		WP_Mock::userFunction( 'get_post', array( 'return' => $post ) );
		WP_Mock::userFunction( 'get_post_meta', array( 'return' => '' ) );

		$block = new Block( new ProductRepository(), new Enqueuer() );

		$this->assertSame( '', $block->render( array( 'productId' => 7 ) ) );
	}

	public function test_render_resolves_by_slug_when_no_id(): void {
		WP_Mock::userFunction( 'get_posts', array( 'return' => array( 7 ) ) );
		$this->mockFoundPost( 7, '解決された商品' );
		WP_Mock::userFunction( 'get_option', array( 'return' => array() ) );
		WP_Mock::userFunction( 'get_post_thumbnail_id', array( 'return' => 0 ) );
		WP_Mock::userFunction( 'current_time', array( 'return' => '2026-06-29' ) );

		$block = new Block( new ProductRepository(), new Enqueuer() );
		$html  = $block->render( array( 'slug' => 'my-slug' ) );

		$this->assertStringContainsString( '解決された商品', $html );
	}

	public function test_render_resolves_by_external_id_and_platform(): void {
		WP_Mock::userFunction( 'get_posts', array( 'return' => array( 7 ) ) );
		$this->mockFoundPost( 7, '解決された商品' );
		WP_Mock::userFunction( 'get_option', array( 'return' => array() ) );
		WP_Mock::userFunction( 'get_post_thumbnail_id', array( 'return' => 0 ) );
		WP_Mock::userFunction( 'current_time', array( 'return' => '2026-06-29' ) );

		$block = new Block( new ProductRepository(), new Enqueuer() );
		$html  = $block->render(
			array(
				'externalId' => '56869',
				'platform'   => 'dmm-books',
			)
		);

		$this->assertStringContainsString( '解決された商品', $html );
	}

	public function test_render_returns_empty_when_unresolved(): void {
		$block = new Block( new ProductRepository(), new Enqueuer() );
		$this->assertSame( '', $block->render( array() ) );
	}

	public function test_render_returns_empty_when_product_not_found(): void {
		WP_Mock::userFunction( 'get_post', array( 'return' => null ) );

		$block = new Block( new ProductRepository(), new Enqueuer() );
		$this->assertSame( '', $block->render( array( 'productId' => 999 ) ) );
	}

	public function test_render_passes_color_attributes_as_css_vars(): void {
		$this->mockFoundPost( 7, '解決された商品' );
		WP_Mock::userFunction( 'get_option', array( 'return' => array() ) );
		WP_Mock::userFunction( 'get_post_thumbnail_id', array( 'return' => 0 ) );
		WP_Mock::userFunction( 'current_time', array( 'return' => '2026-06-29' ) );

		$block = new Block( new ProductRepository(), new Enqueuer() );
		$html  = $block->render(
			array(
				'productId'  => 7,
				'ctaBgColor' => '#abcdef',
			)
		);

		$this->assertStringContainsString( '--affilicard-cta-bg:#abcdef', $html );
	}

	public function test_register_registers_block_type(): void {
		WP_Mock::userFunction( 'wp_register_script', array( 'return' => true ) );
		WP_Mock::userFunction( 'wp_register_style', array( 'return' => true ) );
		WP_Mock::userFunction( 'wp_set_script_translations', array( 'return' => true ) );
		WP_Mock::userFunction( 'register_block_type', array( 'times' => 1 ) );

		$block = new Block( new ProductRepository(), new Enqueuer() );
		$block->register();

		$this->assertConditionsMet();
	}

	public function test_render_未登録ブロックはautoCreateをenqueueしてカードを出さない(): void {
		// findByExternalId=null → get_transient(lock)=false → enqueueAutoCreate 1回（=as_schedule_single_action）
		// ＆ set_transient → render は ''（このビューではカードを描画しない＝§3-6）。
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'findByExternalId' )->with( 'rakuten-kobo', 'X123' )->andReturn( null );
		$repo->shouldNotReceive( 'save' );
		$repo->shouldNotReceive( 'find' );

		WP_Mock::userFunction( 'get_option' )->andReturn(
			array(
				array(
					'code'            => 'rakuten-kobo',
					'name'            => '楽天Kobo',
					'provider'        => 'rakuten-kobo',
					'displayOrder'    => 3,
					'enabled'         => true,
					'applicableTypes' => array( 'ebook' ),
					'buttonLabel'     => '',
					'brandColor'      => '',
					'buttonTextColor' => '',
				),
			)
		);

		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( 'affilicard_autocreate_rakuten-kobo_X123' )
			->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )
			->once()
			->with( 'affilicard_autocreate_rakuten-kobo_X123', 1, 5 * MINUTE_IN_SECONDS )
			->andReturn( true );
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_AUTOCREATE,
				array(
					'platform'    => 'rakuten-kobo',
					'external_id' => 'X123',
				),
				'affilicard-rakuten-kobo',
				true,
				Enqueuer::PRIORITY_FORCE
			)
			->andReturn( 999 );

		$block = new Block( $repo, new Enqueuer() );
		$html  = $block->render(
			array(
				'externalId' => 'X123',
				'platform'   => 'rakuten-kobo',
			)
		);

		$this->assertSame( '', $html );
	}

	public function test_render_ebook_isbn_not_visible(): void {
		// product_type='ebook' の商品を Block::render したとき、
		// EbookType::cardHiddenKeys()=['isbn'] が hidden_keys に渡り ISBN がカードに出ないことを検証する。
		$post = (object) array(
			'ID'            => 42,
			'post_type'     => 'affilicard_product',
			'post_title'    => '架空の電子書籍',
			'post_content'  => '',
			'post_status'   => 'publish',
			'post_modified' => '2026-06-01 00:00:00',
		);
		WP_Mock::userFunction( 'get_post', array( 'return' => $post ) );

		$isbn_json = json_encode(
			array(
				array(
					'key'   => 'isbn',
					'label' => 'ISBN',
					'value' => '978-4-00-000000-0',
				),
			)
		);

		WP_Mock::userFunction(
			'get_post_meta',
			array(
				'return_callback' => static function ( $id, $key, $single ) use ( $isbn_json ) {
					if ( \Affilicard\PostType\ProductPostType::META_PRODUCT_TYPE === $key ) {
						return 'ebook';
					}
					if ( \Affilicard\PostType\ProductPostType::META_EXTRAS === $key ) {
						return $isbn_json;
					}
					return '';
				},
			)
		);

		WP_Mock::userFunction( 'get_option', array( 'return' => array() ) );
		WP_Mock::userFunction( 'get_post_thumbnail_id', array( 'return' => 0 ) );
		WP_Mock::userFunction( 'current_time', array( 'return' => '2026-06-29' ) );

		$block = new Block( new ProductRepository(), new Enqueuer() );
		$html  = $block->render( array( 'productId' => 42 ) );

		// ISBN 値がカード本体に出力されないこと。
		$this->assertStringNotContainsString( '978-4-00-000000-0', $html );
		// ISBN ラベルがメタヘッダにも出ないこと（ヘッダは author/publisher のみ）。
		$this->assertStringNotContainsString( '>ISBN<', $html );
	}

	public function test_render_releases_lock_when_platform_undefined(): void {
		// PlatformConfig::find() が null（未知の platform code）のとき、enqueue できないため
		// 5 分ロックを即解放する（delete_transient が呼ばれる）。as_schedule_single_action は呼ばれない。
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'findByExternalId' )->with( 'dmm-books', 'ext-9' )->andReturn( null );
		$repo->shouldNotReceive( 'find' );

		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->once()->andReturn( true );
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_autocreate_dmm-books_ext-9' )
			->andReturn( true );

		$block = new Block( $repo, new Enqueuer() );
		$html  = $block->render(
			array(
				'externalId' => 'ext-9',
				'platform'   => 'dmm-books',
			)
		);

		$this->assertSame( '', $html );
	}

	public function test_render_skips_autocreate_when_locked(): void {
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'findByExternalId' )->andReturn( null );
		$repo->shouldNotReceive( 'save' );
		WP_Mock::userFunction( 'get_transient' )->andReturn( true );

		$block = new Block( $repo, new Enqueuer() );
		$this->assertSame(
			'',
			$block->render(
				array(
					'externalId' => 'ext-9',
					'platform'   => 'dmm-books',
				)
			)
		);
	}
}
