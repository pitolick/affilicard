<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Cron;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Pricing\FetchStatus;
use Affilicard\Provider\FetchResult;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\WorkOutcome;
use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Settings\GeneralSettings;
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
	private function dmmProvider( FetchResult $fetchReturn ): ProviderRegistry {
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
	private function rakutenProvider( FetchResult $fetchReturn ): ProviderRegistry {
		$p = Mockery::mock( ProviderInterface::class );
		$p->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$p->shouldReceive( 'isAutomatic' )->andReturn( true );
		$p->shouldReceive( 'fetch' )->andReturn( $fetchReturn );
		$r = new ProviderRegistry();
		$r->register( $p );
		return $r;
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
	 * offers 形式の商品を返すリポジトリモック。
	 *
	 * @param list<array<string, mixed>> $offers
	 */
	private function repoWithOffers( array $offers, bool $saveOk = true ): ProductRepositoryInterface {
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 20 )->andReturn(
			array(
				'id'           => 20,
				'title'        => '対象巻',
				'status'       => 'publish',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'listings'     => array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'auto_update' => true,
						'update_mode' => 'auto',
						'offers'      => $offers,
					),
				),
			)
		);
		$this->savedListing = null;
		$repo->shouldReceive( 'updateListing' )->andReturnUsing(
			function ( int $postId, string $platform, array $fields ) use ( $saveOk ): bool {
				$this->savedListing = $fields;
				return $saveOk;
			}
		);
		return $repo;
	}

	/** @var array<string, mixed>|null 直近に updateListing へ渡された listing。 */
	private ?array $savedListing = null;

	/**
	 * 保存された offers のうち external_id が一致するものを返す。
	 *
	 * @return array<string, mixed>
	 */
	private function savedOffer( string $externalId ): array {
		foreach ( (array) ( $this->savedListing['offers'] ?? array() ) as $offer ) {
			if ( isset( $offer['external_id'] ) && $externalId === $offer['external_id'] ) {
				return $offer;
			}
		}
		$this->fail( "保存された offers に {$externalId} がない" );
	}

	/**
	 * v2.4.0: 死コード化した run()/refreshProduct()/runForPlatform() 系は削除済み
	 * （複数商品を横断する同期スイープは QueueMaintenance::sweep() + RefreshHandler へ
	 * 移行済み）。refreshListing() の反映ロジック（全フィールドマッピング・URL保持・
	 * last_verified_at 刻印等）は、唯一残る公開 API の refreshOne() 経由で検証する。
	 *
	 * v4.0.0: 反映先は listing 自身ではなく offers[0]（選択された購入リンク）になった。
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
			FetchResult::hit(
				array(
					'price'         => '693',
					'list_price'    => '900',
					'badge'         => '23%OFF',
					'image_url'     => 'https://example.test/i',
					'regular_url'   => 'https://example.test/r',
					'affiliate_url' => 'https://example.test/a',
				)
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
						'offers'      => array(
							array(
								'external_id' => 'deadbeef01',
								'search_key'  => '対象巻',
								'price'       => '',
							),
						),
					),
				),
			)
		);
		$repo->shouldReceive( 'updateListing' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $listing ) {
				$this->assertSame( 20, $postId );
				$this->assertSame( 'rakuten-kobo', $platform );
				$offer = $listing['offers'][0];
				$this->assertSame( '693', $offer['price'] );
				$this->assertSame( '900', $offer['list_price'] );
				$this->assertSame( '23%OFF', $offer['badge'] );
				$this->assertSame( 'https://example.test/i', $offer['image_url'] );
				$this->assertSame( 'https://example.test/r', $offer['regular_url'] );
				$this->assertSame( 'https://example.test/a', $offer['affiliate_url'] );
				$this->assertArrayHasKey( 'last_verified_at', $offer );
				$this->assertNotSame( '', (string) $offer['last_verified_at'] );
				$this->assertArrayHasKey( 'last_fetched_at', $offer );
				$this->assertNotSame( '', (string) $offer['last_fetched_at'] );
				return true;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 20, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_fetch結果のURLが空文字なら保存済みURLを上書きしない(): void {
		$this->stubDmmPlatform();
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 15 )->andReturn(
			$this->product(
				15,
				array(
					array(
						'platform'    => 'dmm-books',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'offers'      => array(
							array(
								'external_id'     => 'ext-1',
								'price'           => '900',
								'list_price'      => '1000',
								'badge'           => '',
								'image_url'       => '',
								'regular_url'     => 'https://example.test/existing-r',
								'affiliate_url'   => 'https://example.test/existing-a',
								'last_fetched_at' => '',
							),
						),
					),
				)
			)
		);
		$repo->shouldReceive( 'updateListing' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $listing ) {
				$offer = $listing['offers'][0];
				$this->assertSame( '600', $offer['price'] );
				$this->assertSame( 'https://example.test/existing-r', $offer['regular_url'] );
				$this->assertSame( 'https://example.test/existing-a', $offer['affiliate_url'] );
				return true;
			}
		);
		$registry  = $this->dmmProvider(
			FetchResult::hit(
				array(
					'price'           => '600',
					'list_price'      => '1000',
					'badge'           => '',
					'image_url'       => '',
					'regular_url'     => '',
					'affiliate_url'   => '',
					'platform_extras' => array(),
				)
			)
		);
		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 15, 'dmm-books' ) );
	}

	public function test_refreshOne_transient失敗時はlast_verified_atを更新せずTRANSIENTを返す(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->andReturn( FetchResult::error() );
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
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'offers'      => array(
							array(
								'external_id'      => 'deadbeef01',
								'last_verified_at' => '2020-01-01T00:00:00+09:00',
								'price'            => '500',
							),
						),
					),
				),
			)
		);
		$repo->shouldReceive( 'updateListing' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $listing ) {
				$offer = $listing['offers'][0];
				$this->assertSame( '2020-01-01T00:00:00+09:00', $offer['last_verified_at'] );
				$this->assertSame( '500', $offer['price'] );
				return true;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $refresher->refreshOne( 21, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_fetch成功でSUCCESSを返し保存する(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->andReturn( FetchResult::hit( array( 'price' => '693' ) ) );
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
						'offers'      => array(
							array(
								'external_id' => 'deadbeef01',
								'price'       => '',
							),
						),
					),
				)
			)
		);
		$repo->shouldReceive( 'updateListing' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $listing ) {
				$this->assertSame( 12, $postId );
				$offer = $listing['offers'][0];
				$this->assertSame( '693', $offer['price'] );
				$this->assertSame( FetchStatus::NONE, $offer['fetch_status'] );
				return true;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	/**
	 * fetch は成功したが updateListing() が false（find→再読込の間に対象 listing が
	 * 削除・変更され保存できなかった）場合、refreshOne() は false を返す。ここで true を
	 * 返すとハンドラが成功と判断し、取得済みの新価格が保存されないまま再試行もされない
	 * サイレントなデータロスになる（CodeRabbit 指摘の回帰防止）。
	 */
	public function test_refreshOne_updateListingがfalseなら成功fetchでもTRANSIENTを返す(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->andReturn( FetchResult::hit( array( 'price' => '693' ) ) );
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
						'offers'      => array(
							array(
								'external_id' => 'deadbeef01',
								'price'       => '',
							),
						),
					),
				)
			)
		);
		// 保存に失敗（対象 listing が消えた等）を模して false を返す。
		$repo->shouldReceive( 'updateListing' )->once()->andReturn( false );

		$refresher = new ListingRefresher( $registry, $repo );
		// 保存競合はリトライで解決し得るため TRANSIENT_FAILURE（give-up しない）。
		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_transient失敗でTRANSIENTを返しfetch_statusを記録(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->andReturn( FetchResult::error() );
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
						'offers'      => array(
							array(
								'external_id' => 'deadbeef01',
								'price'       => '500',
							),
						),
					),
				)
			)
		);
		$repo->shouldReceive( 'updateListing' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $listing ) {
				// transient 失敗でも保存はされる（fetch_status を記録するため）が price は維持される。
				$offer = $listing['offers'][0];
				$this->assertSame( '500', $offer['price'] );
				$this->assertSame( FetchStatus::TRANSIENT, $offer['fetch_status'] );
				return true;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	/**
	 * 恒久失敗（miss/terminal＝該当なし・無効 ID）は TERMINAL_FAILURE を返し、fetch_status に
	 * FetchStatus::TERMINAL を記録する（last_verified_at は更新しない）。
	 * ハンドラはこれを見て give-up マーカーを立て、掃引で一定期間スキップする。
	 */
	public function test_refreshOne_terminal失敗でTERMINALを返しmiss用fetch_statusを記録(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->andReturn( FetchResult::miss() );
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
						'offers'      => array(
							array(
								'external_id'      => 'deadbeef01',
								'price'            => '500',
								'last_verified_at' => '2020-01-01T00:00:00+09:00',
							),
						),
					),
				)
			)
		);
		$repo->shouldReceive( 'updateListing' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $listing ) {
				$offer = $listing['offers'][0];
				$this->assertSame( '500', $offer['price'] );
				$this->assertSame( FetchStatus::TERMINAL, $offer['fetch_status'] );
				// terminal でも last_verified_at は更新しない（価格の表示鮮度は据え置き）。
				$this->assertSame( '2020-01-01T00:00:00+09:00', $offer['last_verified_at'] );
				return true;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertSame( WorkOutcome::TERMINAL_FAILURE, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_platformが見つからなければSUCCESS_noopで保存しない(): void {
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
		$repo->shouldNotReceive( 'updateListing' );

		$refresher = new ListingRefresher( new ProviderRegistry(), $repo );
		// platform 該当なしは対象なし（no-op）＝SUCCESS。failed 化させない。
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_商品が見つからなければSUCCESS_noop(): void {
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 999 )->andReturn( null );
		$repo->shouldNotReceive( 'updateListing' );

		$refresher = new ListingRefresher( new ProviderRegistry(), $repo );
		// 削除済み商品は対象なし（no-op）＝SUCCESS。failed 化させない。
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 999, 'rakuten-kobo' ) );
	}

	/**
	 * v2.4.0: enqueue から worker 実行までの間に listing が disabled/manual へ切り替わる
	 * TOCTOU（Time-Of-Check-Time-Of-Use）を防ぐため、refreshOne は実行時に
	 * update_mode/enabled を再チェックする（force と両立するため auto_update は見ない）。
	 */
	public function test_refreshOne_disabledなlistingはSUCCESS_noopでfetchも保存もしない(): void {
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
		$repo->shouldNotReceive( 'updateListing' );

		$refresher = new ListingRefresher( $registry, $repo );
		// 実行時に無効化された listing は対象外（no-op）＝SUCCESS。failed 化させない。
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_manualモードに切り替わったlistingはSUCCESS_noopでfetchも保存もしない(): void {
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
		$repo->shouldNotReceive( 'updateListing' );

		$refresher = new ListingRefresher( $registry, $repo );
		// 実行時に手動化された listing は対象外（no-op）＝SUCCESS。failed 化させない。
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
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
		$provider->shouldReceive( 'fetch' )->once()->andReturn( FetchResult::hit( array( 'price' => '693' ) ) );
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
						'offers'      => array(
							array(
								'external_id' => 'deadbeef01',
								'price'       => '500',
							),
						),
					),
				)
			)
		);
		$repo->shouldReceive( 'updateListing' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $listing ) {
				$offer = $listing['offers'][0];
				$this->assertSame( '693', $offer['price'] );
				return true;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	public function test_選択された購入リンクだけを更新する(): void {
		// 表示しているものを更新する。リクエスト数は 1 listing につき 1 回のまま。
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		// 先頭（表示順 10）だけが fetch される。2 件あっても 1 回きり。
		$provider->shouldReceive( 'fetch' )->once()->withArgs(
			static fn( string $id ): bool => 'sale' === $id
		)->andReturn( FetchResult::hit( array( 'price' => '0' ) ) );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = $this->repoWithOffers(
			array(
				array(
					'display_order' => 10,
					'external_id'   => 'sale',
					'regular_url'   => 'https://example.test/sale',
				),
				array(
					'display_order' => 100,
					'external_id'   => 'normal',
					'regular_url'   => 'https://example.test/normal',
				),
			)
		);

		$outcome = ( new ListingRefresher( $registry, $repo ) )->refreshOne( 20, 'rakuten-kobo' );

		$this->assertSame( WorkOutcome::SUCCESS, $outcome );
		$this->assertSame( '0', $this->savedOffer( 'sale' )['price'] );
		// 後続は触られない。
		$this->assertSame( '', $this->savedOffer( 'normal' )['price'] ?? '' );
	}

	public function test_成功でfetch_statusが空になる(): void {
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::hit( array( 'price' => '660' ) ) );
		$repo     = $this->repoWithOffers(
			array(
				array(
					'display_order' => 100,
					'external_id'   => 'x',
					'regular_url'   => 'https://example.test/x',
					'fetch_status'  => 'transient',
				),
			)
		);

		$outcome = ( new ListingRefresher( $registry, $repo ) )->refreshOne( 20, 'rakuten-kobo' );

		$this->assertSame( WorkOutcome::SUCCESS, $outcome );
		$this->assertSame( FetchStatus::NONE, $this->savedOffer( 'x' )['fetch_status'] );
		$this->assertArrayNotHasKey( 'fetch_error', $this->savedOffer( 'x' ) );
	}

	public function test_terminal_missでfetch_statusがterminalになる(): void {
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::miss() );
		$repo     = $this->repoWithOffers(
			array(
				array(
					'display_order' => 100,
					'external_id'   => 'gone',
					'regular_url'   => 'https://example.test/gone',
				),
			)
		);

		$outcome = ( new ListingRefresher( $registry, $repo ) )->refreshOne( 20, 'rakuten-kobo' );

		$this->assertSame( WorkOutcome::TERMINAL_FAILURE, $outcome );
		$this->assertSame( FetchStatus::TERMINAL, $this->savedOffer( 'gone' )['fetch_status'] );
	}

	public function test_一時失敗でfetch_statusがtransientになる(): void {
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::error() );
		$repo     = $this->repoWithOffers(
			array(
				array(
					'display_order' => 100,
					'external_id'   => 'busy',
					'regular_url'   => 'https://example.test/busy',
				),
			)
		);

		$outcome = ( new ListingRefresher( $registry, $repo ) )->refreshOne( 20, 'rakuten-kobo' );

		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $outcome );
		$this->assertSame( FetchStatus::TRANSIENT, $this->savedOffer( 'busy' )['fetch_status'] );
	}

	public function test_external_idが空ならunsupportedで自動取得しない(): void {
		// 管理画面で URL だけ手入力した購入リンク。手動更新専用として扱う。
		$this->stubRakutenPlatform();
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->never();
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = $this->repoWithOffers(
			array(
				array(
					'display_order' => 100,
					'external_id'   => '',
					'regular_url'   => 'https://example.test/manual',
				),
			)
		);

		$outcome = ( new ListingRefresher( $registry, $repo ) )->refreshOne( 20, 'rakuten-kobo' );

		// WorkOutcome は TRANSIENT_FAILURE のまま（リトライ挙動は変えない）。
		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $outcome );
		$this->assertSame( FetchStatus::UNSUPPORTED, $this->savedListing['offers'][0]['fetch_status'] );
	}

	public function test_書き戻しは識別子で行い配列の位置に依存しない(): void {
		// 他の投入で位置が変わるため、添字で書き戻してはいけない。
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::hit( array( 'price' => '0' ) ) );
		$repo     = $this->repoWithOffers(
			array(
				array(
					'display_order' => 100,
					'external_id'   => 'normal',
					'regular_url'   => 'https://example.test/normal',
					'price'         => '660',
				),
				array(
					'display_order' => 10,
					'external_id'   => 'sale',
					'regular_url'   => 'https://example.test/sale',
				),
			)
		);

		( new ListingRefresher( $registry, $repo ) )->refreshOne( 20, 'rakuten-kobo' );

		// 表示順 10 の 'sale'（配列では 2 番目）が更新され、'normal' は無傷。
		$this->assertSame( '0', $this->savedOffer( 'sale' )['price'] );
		$this->assertSame( '660', $this->savedOffer( 'normal' )['price'] );
	}

	/**
	 * v4.0.0: targetCount() は refreshOne() が実際に fetch する件数（OfferSelector::select()
	 * の選択結果件数）を fetch を伴わずに返す。ThrottledActionHandler::run() が
	 * performWork()（＝refreshOne()）の前にレート制限の枠をこの件数へ比例させるために使う。
	 * offers が1件（fallback 既定 OFF）なら選択結果どおり 1。
	 */
	public function test_targetCount_offersが1件なら1を返す(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$repo = $this->repoWithOffers(
			array(
				array(
					'external_id' => 'e1',
					'regular_url' => 'https://example.test/a',
				),
			)
		);

		$count = ( new ListingRefresher( new ProviderRegistry(), $repo ) )->targetCount( 20, 'rakuten-kobo' );

		$this->assertSame( 1, $count );
	}

	/** offers が空なら OfferSelector::select() の選択結果も空＝0（refreshOne 自身も fetch しない）。 */
	public function test_targetCount_offersが空なら0を返す(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$repo = $this->repoWithOffers( array() );

		$count = ( new ListingRefresher( new ProviderRegistry(), $repo ) )->targetCount( 20, 'rakuten-kobo' );

		$this->assertSame( 0, $count );
	}

	/** 該当 platform の listing が無ければ 0（refreshOne 自身も対象なし＝no-op）。 */
	public function test_targetCount_該当platformのlistingが無ければ0を返す(): void {
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
						'offers'      => array( array( 'external_id' => 'e1' ) ),
					),
				)
			)
		);

		$count = ( new ListingRefresher( new ProviderRegistry(), $repo ) )->targetCount( 12, 'rakuten-kobo' );

		$this->assertSame( 0, $count );
	}

	/** 商品が見つからなければ 0（refreshOne 自身も対象なし＝no-op）。 */
	public function test_targetCount_商品が見つからなければ0を返す(): void {
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 999 )->andReturn( null );

		$count = ( new ListingRefresher( new ProviderRegistry(), $repo ) )->targetCount( 999, 'rakuten-kobo' );

		$this->assertSame( 0, $count );
	}
}
