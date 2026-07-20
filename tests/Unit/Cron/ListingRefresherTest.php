<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Cron;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Repository\ProductRepositoryInterface;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ListingRefresherTest extends TestCase {
	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $t ) => $t );
		WP_Mock::userFunction( 'current_time' )->andReturn( '2026-06-03T00:00:00+00:00' );
	}
	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	private function stubDmmPlatform(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn(
			array(
				array(
					'code'             => 'dmm-books',
					'name'             => 'DMM',
					'provider'         => 'dmm-ebook',
					'displayOrder'     => 1,
					'enabled'          => true,
					'applicableTypes'  => array( 'ebook' ),
					'buttonLabel'      => '',
					'brandColor'       => '',
					'buttonTextColor'  => '',
					'autoRefresh'      => true,
					'refreshFrequency' => 'weekly',
				),
			)
		);
	}
	private function dmmProvider( $fetchReturn ): ProviderRegistry {
		$p = Mockery::mock( ProviderInterface::class );
		$p->shouldReceive( 'code' )->andReturn( 'dmm-ebook' );
		$p->shouldReceive( 'isAutomatic' )->andReturn( true );
		$p->shouldReceive( 'fetch' )->andReturn( $fetchReturn );
		$r = new ProviderRegistry();
		$r->register( $p );
		return $r;
	}

	private function stubRakutenPlatform(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn(
			array(
				array(
					'code'             => 'rakuten-kobo',
					'name'             => '楽天Kobo',
					'provider'         => 'rakuten-kobo',
					'displayOrder'     => 3,
					'enabled'          => true,
					'applicableTypes'  => array( 'ebook' ),
					'buttonLabel'      => '',
					'brandColor'       => '',
					'buttonTextColor'  => '',
					'autoRefresh'      => true,
					'refreshFrequency' => 'weekly',
				),
			)
		);
	}
	/** @param array<int,mixed> $listings */
	private function product( int $id, array $listings ): array {
		return array(
			'id'             => $id,
			'title'          => 'X',
			'status'         => 'publish',
			'product_type'   => 'generic',
			'stock_status'   => 'available',
			'extras'         => array(),
			'content'        => '',
			'schema_version' => '1',
			'modified'       => '',
			'listings'       => $listings,
		);
	}

	public function test_run_queries_only_published_products(): void {
		WP_Mock::userFunction( 'get_posts' )->once()->with(
			Mockery::on(
				fn( $a ) =>
				'publish' === $a['post_status'] && 'affilicard_product' === $a['post_type']
			)
		)->andReturn( array() );
		( new ListingRefresher( new ProviderRegistry(), Mockery::mock( ProductRepositoryInterface::class ) ) )->run();
		$this->assertTrue( true );
	}

	public function test_refresh_updates_eligible_listing_via_provider(): void {
		$this->stubDmmPlatform();
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 10 )->andReturn(
			$this->product(
				10,
				array(
					array(
						'platform'        => 'dmm-books',
						'enabled'         => true,
						'update_mode'     => 'auto',
						'auto_update'     => true,
						'external_id'     => 'ext-1',
						'price'           => '900',
						'list_price'      => '1000',
						'badge'           => '',
						'image_url'       => '',
						'regular_url'     => '',
						'affiliate_url'   => '',
						'last_fetched_at' => '',
						'fetch_error'     => '',
					),
				)
			)
		);
		$repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( array $d ) {
				$this->assertSame( 10, $d['id'] );
				$this->assertSame( '600', $d['listings'][0]['price'] );
				$this->assertSame( '40%OFF', $d['listings'][0]['badge'] );
				$this->assertNotSame( '', $d['listings'][0]['last_fetched_at'] );
				$this->assertSame( '', $d['listings'][0]['fetch_error'] );
				return 10;
			}
		);
		$registry = $this->dmmProvider(
			array(
				'price'           => '600',
				'list_price'      => '1000',
				'badge'           => '40%OFF',
				'image_url'       => 'https://example.test/i',
				'regular_url'     => 'https://example.test/r',
				'affiliate_url'   => 'https://example.test/a',
				'platform_extras' => array(),
			)
		);
		( new ListingRefresher( $registry, $repo ) )->refreshProduct( 10 );
	}

	public function test_refresh_skips_manual_and_auto_update_off(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 11 )->andReturn(
			$this->product(
				11,
				array(
					array(
						'platform'    => 'a',
						'enabled'     => true,
						'update_mode' => 'manual',
						'auto_update' => true,
						'external_id' => 'e1',
					),
					array(
						'platform'    => 'b',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => false,
						'external_id' => 'e2',
					),
				)
			)
		);
		$repo->shouldNotReceive( 'save' );
		( new ListingRefresher( new ProviderRegistry(), $repo ) )->refreshProduct( 11 );
		$this->assertTrue( true );
	}

	public function test_force_refreshes_auto_update_off_listing(): void {
		$this->stubDmmPlatform();
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 14 )->andReturn(
			$this->product(
				14,
				array(
					array(
						'platform'    => 'dmm-books',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => false,
						'external_id' => 'ext-1',
						'price'       => '900',
					),
				)
			)
		);
		$repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( array $d ) {
				$this->assertSame( '600', $d['listings'][0]['price'] );
				return 14;
			}
		);
		$registry = $this->dmmProvider(
			array(
				'price'           => '600',
				'list_price'      => '1000',
				'badge'           => '',
				'platform_extras' => array(),
			)
		);
		( new ListingRefresher( $registry, $repo ) )->refreshProduct( 14, null, true );
	}

	public function test_run_for_platform_only_touches_matching_listing(): void {
		$this->stubDmmPlatform();
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 13 )->andReturn(
			$this->product(
				13,
				array(
					array(
						'platform'    => 'dmm-books',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'external_id' => 'ext-1',
						'price'       => '900',
					),
					array(
						'platform'    => 'other',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'external_id' => 'ext-2',
						'price'       => '500',
					),
				)
			)
		);
		$repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( array $d ) {
				$this->assertSame( '600', $d['listings'][0]['price'] );
				$this->assertSame( '500', $d['listings'][1]['price'] );
				return 13;
			}
		);
		$registry = $this->dmmProvider(
			array(
				'price'           => '600',
				'list_price'      => '1000',
				'badge'           => '',
				'platform_extras' => array(),
			)
		);
		( new ListingRefresher( $registry, $repo ) )->refreshProduct( 13, 'dmm-books' );
	}

	public function test_refresh_records_error_on_fetch_failure(): void {
		$this->stubDmmPlatform();
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'dmm-books',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'external_id' => 'ext-x',
						'price'       => '600',
						'fetch_error' => '',
					),
				)
			)
		);
		$repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( array $d ) {
				$this->assertNotSame( '', $d['listings'][0]['fetch_error'] );
				$this->assertSame( '600', $d['listings'][0]['price'] );
				return 12;
			}
		);
		( new ListingRefresher( $this->dmmProvider( null ), $repo ) )->refreshProduct( 12 );
	}

	public function test_成功時にlast_verified_atを刻みsearch_keyとexternal_idをfetchへ渡す(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->withArgs(
			function ( string $externalId, array $context ) {
				return 'deadbeef01' === $externalId
					&& isset( $context['search_key'] ) && '対象巻' === $context['search_key']
					&& isset( $context['external_id'] ) && 'deadbeef01' === $context['external_id'];
			}
		)->andReturn( array( 'price' => '693' ) );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 20 )->andReturn(
			array(
				'id'             => 20,
				'title'          => '対象巻',
				'status'         => 'publish',
				'product_type'   => 'generic',
				'stock_status'   => 'available',
				'extras'         => array(),
				'content'        => '',
				'schema_version' => '1',
				'modified'       => '',
				'listings'       => array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'external_id' => 'deadbeef01',
						'search_key'  => '対象巻',
						'price'       => '',
					),
				),
			)
		);
		$repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( array $d ) {
				$this->assertSame( '693', $d['listings'][0]['price'] );
				$this->assertArrayHasKey( 'last_verified_at', $d['listings'][0] );
				$this->assertNotSame( '', (string) $d['listings'][0]['last_verified_at'] );
				return 20;
			}
		);

		( new ListingRefresher( $registry, $repo ) )->refreshProduct( 20 );
	}

	public function test_fetch失敗時はlast_verified_atを更新しない(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->andReturn( null );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 21 )->andReturn(
			array(
				'id'             => 21,
				'title'          => 'X',
				'status'         => 'publish',
				'product_type'   => 'generic',
				'stock_status'   => 'available',
				'extras'         => array(),
				'content'        => '',
				'schema_version' => '1',
				'modified'       => '',
				'listings'       => array(
					array(
						'platform'         => 'rakuten-kobo',
						'enabled'          => true,
						'update_mode'      => 'auto',
						'auto_update'      => true,
						'external_id'      => 'deadbeef01',
						'last_verified_at' => '2020-01-01T00:00:00+09:00',
						'price'            => '500',
					),
				),
			)
		);
		$repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( array $d ) {
				$this->assertSame( '2020-01-01T00:00:00+09:00', $d['listings'][0]['last_verified_at'] );
				$this->assertSame( '500', $d['listings'][0]['price'] );
				return 21;
			}
		);

		( new ListingRefresher( $registry, $repo ) )->refreshProduct( 21 );
	}
}
