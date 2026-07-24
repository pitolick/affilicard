<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Provider\Rakuten\RakutenProvider;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\PublishTrigger;
use Affilicard\Repository\ProductRepositoryInterface;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_Post;

/**
 * PublishTrigger のテスト。
 *
 * Enqueuer は final class のため Mockery でモックできず、他の Queue テスト
 * （QueueMaintenanceTest 等）と同様に実 Enqueuer を使い、Action Scheduler 関数
 * （as_schedule_single_action 等）呼び出しの有無で enqueueForced が実質的に
 * 呼ばれたかどうかを観測する。
 */
final class PublishTriggerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/** affilicard_platforms option を rakuten-kobo 1件で stub する。 */
	private function stubRakutenPlatform(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'         => 'rakuten-kobo',
						'name'         => '楽天Kobo',
						'provider'     => 'rakuten-kobo',
						'displayOrder' => 3,
						'enabled'      => true,
					),
				)
			);
	}

	/** RakutenProvider（code='rakuten-kobo', accountCode='rakuten'）を登録した ProviderRegistry。 */
	private function registry(): ProviderRegistry {
		$registry = new ProviderRegistry();
		$registry->register( new RakutenProvider() );
		return $registry;
	}

	/** @param list<array<string, mixed>> $listings */
	private function product( int $id, array $listings = array() ): array {
		return array(
			'id'           => $id,
			'title'        => '対象作品',
			'content'      => '',
			'status'       => 'publish',
			'product_type' => 'generic',
			'stock_status' => 'available',
			'extras'       => array(),
			'listings'     => $listings,
		);
	}

	/** @param array<string, mixed> $overrides */
	private function eligibleListing( array $overrides = array() ): array {
		return array_merge(
			array(
				'platform'    => 'rakuten-kobo',
				'enabled'     => true,
				'update_mode' => 'auto',
				'auto_update' => true,
				'external_id' => 'deadbeef01',
				'price'       => '500',
			),
			$overrides
		);
	}

	private function post( array $data ): WP_Post {
		return new WP_Post( $data );
	}

	/**
	 * resolveProductIds のテスト。
	 */
	public function test_resolveProductIds_productId属性を抽出する(): void {
		WP_Mock::userFunction( 'parse_blocks' )->andReturn(
			array(
				array(
					'blockName'   => 'affilicard/product-card',
					'attrs'       => array( 'productId' => 12 ),
					'innerBlocks' => array(),
				),
				array(
					'blockName'   => 'core/paragraph',
					'attrs'       => array(),
					'innerBlocks' => array(),
				),
			)
		);

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 12 )->andReturn( $this->product( 12 ) );

		$ids = ( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->resolveProductIds( '<!-- wp:affilicard/product-card /-->' );

		$this->assertSame( array( 12 ), $ids );
	}

	public function test_resolveProductIds_slug属性はfindBySlugで解決する(): void {
		WP_Mock::userFunction( 'parse_blocks' )->andReturn(
			array(
				array(
					'blockName'   => 'affilicard/product-card',
					'attrs'       => array( 'slug' => 'sample-manga' ),
					'innerBlocks' => array(),
				),
			)
		);

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'findBySlug' )->once()->with( 'sample-manga' )->andReturn( $this->product( 21 ) );

		$ids = ( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->resolveProductIds( '<!-- wp:affilicard/product-card /-->' );

		$this->assertSame( array( 21 ), $ids );
	}

	public function test_resolveProductIds_externalIdとplatform属性はfindByExternalIdで解決する(): void {
		WP_Mock::userFunction( 'parse_blocks' )->andReturn(
			array(
				array(
					'blockName'   => 'affilicard/product-card',
					'attrs'       => array(
						'externalId' => 'deadbeef01',
						'platform'   => 'rakuten-kobo',
					),
					'innerBlocks' => array(),
				),
			)
		);

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'findByExternalId' )->once()->with( 'rakuten-kobo', 'deadbeef01' )->andReturn( $this->product( 33 ) );

		$ids = ( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->resolveProductIds( '<!-- wp:affilicard/product-card /-->' );

		$this->assertSame( array( 33 ), $ids );
	}

	public function test_resolveProductIds_innerBlocksを再帰的に走査する(): void {
		WP_Mock::userFunction( 'parse_blocks' )->andReturn(
			array(
				array(
					'blockName'   => 'core/group',
					'attrs'       => array(),
					'innerBlocks' => array(
						array(
							'blockName'   => 'affilicard/product-card',
							'attrs'       => array( 'productId' => 44 ),
							'innerBlocks' => array(),
						),
					),
				),
			)
		);

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 44 )->andReturn( $this->product( 44 ) );

		$ids = ( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->resolveProductIds( '<!-- wp:core/group -->...<!-- /wp:core/group -->' );

		$this->assertSame( array( 44 ), $ids );
	}

	public function test_resolveProductIds_商品が見つからない場合は結果に含めない(): void {
		WP_Mock::userFunction( 'parse_blocks' )->andReturn(
			array(
				array(
					'blockName'   => 'affilicard/product-card',
					'attrs'       => array( 'productId' => 999 ),
					'innerBlocks' => array(),
				),
			)
		);

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 999 )->andReturn( null );

		$ids = ( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->resolveProductIds( '<!-- wp:affilicard/product-card /-->' );

		$this->assertSame( array(), $ids );
	}

	public function test_resolveProductIds_parse_blocksが配列以外を返しても空配列を返す(): void {
		WP_Mock::userFunction( 'parse_blocks' )->andReturn( false );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );

		$ids = ( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->resolveProductIds( '' );

		$this->assertSame( array(), $ids );
	}

	/**
	 * onTransition のテスト。
	 */
	public function test_onTransition_publish以外は何もしない(): void {
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );

		$post = $this->post(
			array(
				'ID'           => 1,
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_content' => '',
			)
		);

		( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->onTransition( 'draft', 'auto-draft', $post );

		$this->assertConditionsMet();
	}

	public function test_onTransition_autosaveはスキップする(): void {
		WP_Mock::userFunction( 'wp_is_post_autosave' )->once()->with( 2 )->andReturn( true );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );

		$post = $this->post(
			array(
				'ID'           => 2,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => '',
			)
		);

		( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->onTransition( 'publish', 'draft', $post );

		$this->assertConditionsMet();
	}

	public function test_onTransition_revisionはスキップする(): void {
		WP_Mock::userFunction( 'wp_is_post_autosave' )->once()->with( 3 )->andReturn( false );
		WP_Mock::userFunction( 'wp_is_post_revision' )->once()->with( 3 )->andReturn( true );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );

		$post = $this->post(
			array(
				'ID'           => 3,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => '',
			)
		);

		( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->onTransition( 'publish', 'draft', $post );

		$this->assertConditionsMet();
	}

	public function test_onTransition_下書きから公開時に対象商品のauto有効listingをenqueueForcedする(): void {
		WP_Mock::userFunction( 'wp_is_post_autosave' )->andReturn( false );
		WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		WP_Mock::userFunction( 'parse_blocks' )->andReturn(
			array(
				array(
					'blockName'   => 'affilicard/product-card',
					'attrs'       => array( 'productId' => 12 ),
					'innerBlocks' => array(),
				),
			)
		);
		$this->stubRakutenPlatform();

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn( $this->product( 12, array( $this->eligibleListing() ) ) );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once()
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten'
			);
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 12,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_FORCE
			)
			->andReturn( 300 );

		$post = $this->post(
			array(
				'ID'           => 100,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:affilicard/product-card /-->',
			)
		);

		( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->onTransition( 'publish', 'draft', $post );

		$this->assertConditionsMet();
	}

	public function test_onTransition_manualかdisabledかauto_update無効のlistingはenqueueForcedしない(): void {
		WP_Mock::userFunction( 'wp_is_post_autosave' )->andReturn( false );
		WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		WP_Mock::userFunction( 'parse_blocks' )->andReturn(
			array(
				array(
					'blockName'   => 'affilicard/product-card',
					'attrs'       => array( 'productId' => 13 ),
					'innerBlocks' => array(),
				),
			)
		);
		$this->stubRakutenPlatform();

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 13 )->andReturn(
			$this->product(
				13,
				array(
					$this->eligibleListing( array( 'update_mode' => 'manual' ) ),
					$this->eligibleListing( array( 'enabled' => false ) ),
					$this->eligibleListing( array( 'auto_update' => false ) ),
				)
			)
		);

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$post = $this->post(
			array(
				'ID'           => 101,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:affilicard/product-card /-->',
			)
		);

		( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->onTransition( 'publish', 'draft', $post );

		$this->assertConditionsMet();
	}

	public function test_onTransition_未知platformのlistingはenqueueForcedしない(): void {
		WP_Mock::userFunction( 'wp_is_post_autosave' )->andReturn( false );
		WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		WP_Mock::userFunction( 'parse_blocks' )->andReturn(
			array(
				array(
					'blockName'   => 'affilicard/product-card',
					'attrs'       => array( 'productId' => 14 ),
					'innerBlocks' => array(),
				),
			)
		);
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn( array() ); // platform 定義なし → find() は null

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 14 )->andReturn(
			$this->product( 14, array( $this->eligibleListing( array( 'platform' => 'unknown-platform' ) ) ) )
		);

		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$post = $this->post(
			array(
				'ID'           => 102,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:affilicard/product-card /-->',
			)
		);

		( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->onTransition( 'publish', 'draft', $post );

		$this->assertConditionsMet();
	}

	public function test_onTransition_商品が見つからない場合はenqueueForcedしない(): void {
		WP_Mock::userFunction( 'wp_is_post_autosave' )->andReturn( false );
		WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		WP_Mock::userFunction( 'parse_blocks' )->andReturn(
			array(
				array(
					'blockName'   => 'affilicard/product-card',
					'attrs'       => array( 'productId' => 999 ),
					'innerBlocks' => array(),
				),
			)
		);

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 999 )->andReturn( null );

		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$post = $this->post(
			array(
				'ID'           => 103,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:affilicard/product-card /-->',
			)
		);

		( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->onTransition( 'publish', 'draft', $post );

		$this->assertConditionsMet();
	}

	/**
	 * onUpdated のテスト。
	 */
	public function test_onUpdated_公開のまま再保存時にsyncPostする(): void {
		WP_Mock::userFunction( 'wp_is_post_autosave' )->andReturn( false );
		WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		WP_Mock::userFunction( 'parse_blocks' )->andReturn(
			array(
				array(
					'blockName'   => 'affilicard/product-card',
					'attrs'       => array( 'productId' => 12 ),
					'innerBlocks' => array(),
				),
			)
		);
		$this->stubRakutenPlatform();

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn( $this->product( 12, array( $this->eligibleListing() ) ) );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once();
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()->andReturn( 301 );

		$after  = $this->post(
			array(
				'ID'           => 200,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:affilicard/product-card /-->',
			)
		);
		$before = $this->post(
			array(
				'ID'           => 200,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => '',
			)
		);

		( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->onUpdated( 200, $after, $before );

		$this->assertConditionsMet();
	}

	public function test_onUpdated_公開以外への遷移は何もしない(): void {
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );

		$after  = $this->post(
			array(
				'ID'           => 201,
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_content' => '',
			)
		);
		$before = $this->post(
			array(
				'ID'           => 201,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => '',
			)
		);

		( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->onUpdated( 201, $after, $before );

		$this->assertConditionsMet();
	}

	public function test_onUpdated_下書きから公開への遷移は処理しない(): void {
		// draft→publish は transition_post_status（onTransition）側の責務であり、
		// onUpdated（post_updated）は before も publish の再保存のみを扱う。
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );

		$after  = $this->post(
			array(
				'ID'           => 202,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => '',
			)
		);
		$before = $this->post(
			array(
				'ID'           => 202,
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_content' => '',
			)
		);

		( new PublishTrigger( $repo, new Enqueuer(), $this->registry() ) )->onUpdated( 202, $after, $before );

		$this->assertConditionsMet();
	}
}
