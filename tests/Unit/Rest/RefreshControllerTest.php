<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Provider\Rakuten\RakutenProvider;
use Affilicard\Queue\Enqueuer;
use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Rest\RefreshController;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;

/**
 * RefreshController のテスト（v2.4.0: 同期 ListingRefresher 呼び出しから
 * Enqueuer::enqueueProductListings 経由の enqueue（manual 経路）へ変更）。
 *
 * Enqueuer は final class のため Mockery でモックできず、他の Queue テストと同様に
 * 実 Enqueuer を使い、Action Scheduler 関数（as_schedule_single_action 等）呼び出しの
 * 有無で enqueue が実質的に呼ばれたかどうかを観測する。
 *
 * enqueueProductListings は platform の provider→account コードを ProviderRegistry で
 * 解決するため（v2.4.0）、以下のテストは Enqueuer に rakuten-kobo→rakuten を含む実
 * ProviderRegistry を注入する。
 */
final class RefreshControllerTest extends TestCase {
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

	/** RakutenProvider（code='rakuten-kobo', accountCode='rakuten'）を登録した Enqueuer。 */
	private function enqueuer(): Enqueuer {
		$registry = new ProviderRegistry();
		$registry->register( new RakutenProvider() );
		return new Enqueuer( 500, 300, array(), $registry );
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

	public function test_handle_platform未指定なら公開商品全件のeligibleListingをenqueueManualしscopeAllとqueued件数を即返しする(): void {
		$this->stubRakutenPlatform();

		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array( 12 ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 12 )->andReturn( $this->product( 12, array( $this->eligibleListing() ) ) );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
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
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 700 );

		$req = new WP_REST_Request();
		$req->set_param( 'platform', '' );

		$res = ( new RefreshController( $repo, $this->enqueuer() ) )->handle( $req );

		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertSame( 'all', $res->get_data()['scope'] );
		$this->assertSame( 1, $res->get_data()['queued'] );
	}

	public function test_handle_platform指定時は該当platformのlistingのみenqueueしscopeにplatformを返す(): void {
		$this->stubRakutenPlatform();

		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array( 20 ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 20 )->andReturn(
			$this->product(
				20,
				array(
					$this->eligibleListing(),
					$this->eligibleListing( array( 'platform' => 'other-platform' ) ),
				)
			)
		);

		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 20,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 701 );

		$req = new WP_REST_Request();
		$req->set_param( 'platform', 'rakuten-kobo' );

		$res = ( new RefreshController( $repo, $this->enqueuer() ) )->handle( $req );

		$this->assertSame( 'rakuten-kobo', $res->get_data()['scope'] );
		$this->assertSame( 1, $res->get_data()['queued'] );
	}

	public function test_handle_manualかdisabledかauto_update無効のlistingはenqueueせずqueued0(): void {
		$this->stubRakutenPlatform();

		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array( 21 ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 21 )->andReturn(
			$this->product(
				21,
				array(
					$this->eligibleListing( array( 'update_mode' => 'manual' ) ),
					$this->eligibleListing( array( 'enabled' => false ) ),
					$this->eligibleListing( array( 'auto_update' => false ) ),
				)
			)
		);

		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$req = new WP_REST_Request();
		$req->set_param( 'platform', '' );

		$res = ( new RefreshController( $repo, $this->enqueuer() ) )->handle( $req );

		$this->assertSame( 0, $res->get_data()['queued'] );
	}

	public function test_handle_商品が見つからない場合はスキップしqueued0(): void {
		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array( 30 ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 30 )->andReturn( null );

		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$req = new WP_REST_Request();
		$req->set_param( 'platform', '' );

		$res = ( new RefreshController( $repo, $this->enqueuer() ) )->handle( $req );

		$this->assertSame( 0, $res->get_data()['queued'] );
	}

	public function test_handle_get_postsが配列以外なら何もせずqueued0(): void {
		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( false );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );

		$req = new WP_REST_Request();
		$req->set_param( 'platform', '' );

		$res = ( new RefreshController( $repo, $this->enqueuer() ) )->handle( $req );

		$this->assertSame( 0, $res->get_data()['queued'] );
	}

	/**
	 * force=true リクエストは auto_update=false の listing も enqueue し、
	 * レスポンスに force=true を含める（強制更新ボタンの既存挙動の復元）。
	 */
	public function test_handle_forcetrue時はauto_updatefalseのlistingもenqueueしforcetrueを返す(): void {
		$this->stubRakutenPlatform();

		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array( 22 ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 22 )->andReturn(
			$this->product( 22, array( $this->eligibleListing( array( 'auto_update' => false ) ) ) )
		);

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 22,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 702 );

		$req = new WP_REST_Request();
		$req->set_param( 'platform', '' );
		$req->set_param( 'force', true );

		$res = ( new RefreshController( $repo, $this->enqueuer() ) )->handle( $req );

		$this->assertSame( 1, $res->get_data()['queued'] );
		$this->assertTrue( $res->get_data()['force'] );
	}

	/**
	 * force を省略（=false）した場合は auto_update=false の listing は enqueue されず、
	 * レスポンスの force も false になる。
	 */
	public function test_handle_forceを省略した場合はauto_updatefalseのlistingを積まずforcefalseを返す(): void {
		$this->stubRakutenPlatform();

		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array( 23 ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->once()->with( 23 )->andReturn(
			$this->product( 23, array( $this->eligibleListing( array( 'auto_update' => false ) ) ) )
		);

		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$req = new WP_REST_Request();
		$req->set_param( 'platform', '' );

		$res = ( new RefreshController( $repo, $this->enqueuer() ) )->handle( $req );

		$this->assertSame( 0, $res->get_data()['queued'] );
		$this->assertFalse( $res->get_data()['force'] );
	}

	public function test_permission_requires_manage_options(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( false );
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$this->assertFalse( ( new RefreshController( $repo, $this->enqueuer() ) )->canManageOptions() );
	}
}
