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
					'code'            => 'dmm-books',
					'name'            => 'DMM',
					'provider'        => 'dmm-ebook',
					'displayOrder'    => 1,
					'enabled'         => true,
					'applicableTypes' => array( 'ebook' ),
					'buttonLabel'     => '',
					'brandColor'      => '',
					'buttonTextColor' => '',
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

	/**
	 * v2.4.0: 死コード化した run()/refreshProduct()/runForPlatform() 系は削除済み
	 * （複数商品を横断する同期スイープは QueueMaintenance::sweep() + RefreshHandler へ
	 * 移行済み）。refreshListing() の反映ロジック（全フィールドマッピング・URL保持・
	 * last_verified_at 刻印等）は、唯一残る公開 API の refreshOne() 経由で検証する。
	 */
	public function test_refreshOne_全フィールドを反映しsearch_keyとexternal_idをfetchへ渡す(): void {
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
		)->andReturn(
			array(
				'price'         => '693',
				'list_price'    => '900',
				'badge'         => '23%OFF',
				'image_url'     => 'https://example.test/i',
				'regular_url'   => 'https://example.test/r',
				'affiliate_url' => 'https://example.test/a',
			)
		);
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
				$listing = $d['listings'][0];
				$this->assertSame( '693', $listing['price'] );
				$this->assertSame( '900', $listing['list_price'] );
				$this->assertSame( '23%OFF', $listing['badge'] );
				$this->assertSame( 'https://example.test/i', $listing['image_url'] );
				$this->assertSame( 'https://example.test/r', $listing['regular_url'] );
				$this->assertSame( 'https://example.test/a', $listing['affiliate_url'] );
				$this->assertArrayHasKey( 'last_verified_at', $listing );
				$this->assertNotSame( '', (string) $listing['last_verified_at'] );
				$this->assertArrayHasKey( 'last_fetched_at', $listing );
				$this->assertNotSame( '', (string) $listing['last_fetched_at'] );
				return 20;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertTrue( $refresher->refreshOne( 20, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_fetch結果のURLが空文字なら保存済みURLを上書きしない(): void {
		$this->stubDmmPlatform();
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 15 )->andReturn(
			$this->product(
				15,
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
						'regular_url'     => 'https://example.test/existing-r',
						'affiliate_url'   => 'https://example.test/existing-a',
						'last_fetched_at' => '',
						'fetch_error'     => '',
					),
				)
			)
		);
		$repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( array $d ) {
				$this->assertSame( '600', $d['listings'][0]['price'] );
				$this->assertSame( 'https://example.test/existing-r', $d['listings'][0]['regular_url'] );
				$this->assertSame( 'https://example.test/existing-a', $d['listings'][0]['affiliate_url'] );
				return 15;
			}
		);
		$registry  = $this->dmmProvider(
			array(
				'price'           => '600',
				'list_price'      => '1000',
				'badge'           => '',
				'image_url'       => '',
				'regular_url'     => '',
				'affiliate_url'   => '',
				'platform_extras' => array(),
			)
		);
		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertTrue( $refresher->refreshOne( 15, 'dmm-books' ) );
	}

	public function test_refreshOne_fetch失敗時はlast_verified_atを更新しない(): void {
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

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertFalse( $refresher->refreshOne( 21, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_fetch成功でtrueを返し保存する(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->andReturn( array( 'price' => '693' ) );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'external_id' => 'deadbeef01',
						'price'       => '',
						'fetch_error' => '',
					),
				)
			)
		);
		$repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( array $d ) {
				$this->assertSame( 12, $d['id'] );
				$this->assertSame( '693', $d['listings'][0]['price'] );
				$this->assertSame( '', $d['listings'][0]['fetch_error'] );
				return 12;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertTrue( $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_fetch失敗でfalse(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->andReturn( null );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'external_id' => 'deadbeef01',
						'price'       => '500',
						'fetch_error' => '',
					),
				)
			)
		);
		$repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( array $d ) {
				// fetch 失敗でも保存はされる（fetch_error を記録するため）が price は維持される。
				$this->assertSame( '500', $d['listings'][0]['price'] );
				$this->assertNotSame( '', $d['listings'][0]['fetch_error'] );
				return 12;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertFalse( $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_platformが見つからなければfalseを返し保存しない(): void {
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'other-platform',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'external_id' => 'ext-1',
					),
				)
			)
		);
		$repo->shouldNotReceive( 'save' );

		$refresher = new ListingRefresher( new ProviderRegistry(), $repo );
		$this->assertFalse( $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_商品が見つからなければfalseを返す(): void {
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 999 )->andReturn( null );
		$repo->shouldNotReceive( 'save' );

		$refresher = new ListingRefresher( new ProviderRegistry(), $repo );
		$this->assertFalse( $refresher->refreshOne( 999, 'rakuten-kobo' ) );
	}

	/**
	 * v2.4.0: enqueue から worker 実行までの間に listing が disabled/manual へ切り替わる
	 * TOCTOU（Time-Of-Check-Time-Of-Use）を防ぐため、refreshOne は実行時に
	 * update_mode/enabled を再チェックする（force と両立するため auto_update は見ない）。
	 */
	public function test_refreshOne_disabledなlistingはfalseでfetchも保存もしない(): void {
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldNotReceive( 'isAutomatic' );
		$provider->shouldNotReceive( 'fetch' );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => false, // enqueue 後に無効化された
						'update_mode' => 'auto',
						'auto_update' => true,
						'external_id' => 'deadbeef01',
					),
				)
			)
		);
		$repo->shouldNotReceive( 'save' );

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertFalse( $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_manualモードに切り替わったlistingはfalseでfetchも保存もしない(): void {
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldNotReceive( 'isAutomatic' );
		$provider->shouldNotReceive( 'fetch' );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'manual', // enqueue 後に手動へ切り替わった
						'auto_update' => true,
						'external_id' => 'deadbeef01',
					),
				)
			)
		);
		$repo->shouldNotReceive( 'save' );

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertFalse( $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	/**
	 * force enqueue（管理画面「強制更新」）で積まれた auto_update=false の listing は、
	 * eligibility 再チェックで auto_update を見ないため refreshOne でも引き続き fetch される
	 * （force 機能を壊さないことの確認）。
	 */
	public function test_refreshOne_auto_updateがfalseでもenabledなautoならfetchする(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->andReturn( array( 'price' => '693' ) );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => false, // 手動上書き中でも force enqueue 経路では対象
						'external_id' => 'deadbeef01',
						'price'       => '500',
						'fetch_error' => '',
					),
				)
			)
		);
		$repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( array $d ) {
				$this->assertSame( '693', $d['listings'][0]['price'] );
				return 12;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertTrue( $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}
}
