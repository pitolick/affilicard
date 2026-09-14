<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\AutoCreate;

use Affilicard\AutoCreate\ProductAutoCreator;
use Affilicard\Pricing\FetchStatus;
use Affilicard\Pricing\OfferSelector;
use Affilicard\Provider\FetchResult;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\WorkOutcome;
use Affilicard\Repository\ProductRepositoryInterface;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProductAutoCreatorTest extends TestCase {

	/**
	 * GET_LOCK の戻り値（1＝取得成功／0＝タイムアウト）。
	 *
	 * $wpdb モックの中から参照するためプロパティに置く。テストの中で
	 * モックを登録し直す方式にすると、先に登録した設定が残って無言で効かない。
	 *
	 * @var int
	 */
	private int $lockResult = 1;

	/** @var array<int, string> prepare() に渡された SQL テンプレート。 */
	private array $capturedSql = array();

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $t ) => $t );
		$this->lockResult  = 1;
		$this->capturedSql = array();
		$this->mockLockWpdb();
	}
	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * 自動作成が発行する GET_LOCK/RELEASE_LOCK を捕捉する $wpdb モックを
	 * $GLOBALS に設定する（ProductRepositoryTest::mockLockWpdb と同じ流儀）。
	 */
	private function mockLockWpdb(): void {
		$wpdb = Mockery::mock();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( string $query ) {
				$this->capturedSql[] = $query;
				return $query;
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function () {
				return (string) $this->lockResult;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb;
	}

	private function stubPlatformsOption(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn(
			array(
				array(
					'code'            => 'dmm-books',
					'name'            => 'DMM Books',
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
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->andReturn( $fetchReturn );
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		return $registry;
	}

	public function test_create_returns_terminal_when_platform_unknown(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'save' );
		$creator = new ProductAutoCreator( new ProviderRegistry(), $repo );
		// 未知 platform はリトライで解決しない＝恒久失敗（terminal）。
		$this->assertSame( WorkOutcome::TERMINAL_FAILURE, $creator->create( 'unknown', 'ext-1' ) );
	}

	public function test_create_saves_product_and_returns_success_on_fetch_hit(): void {
		$this->stubPlatformsOption();
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'dmm-ebook' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->with( 'ext-1', Mockery::type( 'array' ) )->andReturn(
			FetchResult::hit(
				array(
					'title'           => '架空のサンプル作品',
					'price'           => '600',
					'list_price'      => '1000',
					'badge'           => '40%OFF',
					'image_url'       => 'https://example.test/i.jpg',
					'regular_url'     => 'https://example.test/r',
					'affiliate_url'   => 'https://example.test/a',
					'platform_extras' => array(),
				)
			)
		);
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'findByExternalId' )->with( 'dmm-books', 'ext-1' )->andReturn( null );
		$repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( array $data ) {
				$this->assertSame( '架空のサンプル作品', $data['title'] );
				$this->assertSame( 'publish', $data['status'] );
				$this->assertSame( 'dmm-books', $data['listings'][0]['platform'] );
				$this->assertSame( 'auto', $data['listings'][0]['update_mode'] );
				$this->assertTrue( $data['listings'][0]['auto_update'] );
				$offer = $data['listings'][0]['offers'][0];
				$this->assertSame( 'ext-1', $offer['external_id'] );
				$this->assertSame( '600', $offer['price'] );
				$this->assertNotEmpty( $offer['last_verified_at'] );
				$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $offer['last_verified_at'] );
				return 123;
			}
		);
		$creator = new ProductAutoCreator( $registry, $repo );
		$this->assertSame( WorkOutcome::SUCCESS, $creator->create( 'dmm-books', 'ext-1' ) );
	}

	public function test_create_returns_terminal_when_provider_not_automatic(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn(
			array(
				array(
					'code'            => 'manual-shop',
					'name'            => 'Manual',
					'provider'        => 'manual',
					'displayOrder'    => 1,
					'enabled'         => true,
					'applicableTypes' => array( 'generic' ),
					'buttonLabel'     => '',
					'brandColor'      => '',
					'buttonTextColor' => '',
				),
			)
		);
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'manual' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( false );
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'save' );
		$creator = new ProductAutoCreator( $registry, $repo );
		// 手動 Provider はリトライで解決しない＝恒久失敗（terminal）。
		$this->assertSame( WorkOutcome::TERMINAL_FAILURE, $creator->create( 'manual-shop', 'ext-1' ) );
	}

	public function test_create_returns_transient_when_fetch_error(): void {
		$this->stubPlatformsOption();
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'dmm-ebook' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->andReturn( FetchResult::error() );
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'findByExternalId' )->andReturn( null );
		$repo->shouldNotReceive( 'save' );
		$creator = new ProductAutoCreator( $registry, $repo );
		// API 到達不可・エラーは一時失敗（transient）。give-up しない。
		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $creator->create( 'dmm-books', 'ext-1' ) );
	}

	public function test_create_returns_terminal_when_fetch_miss(): void {
		$this->stubPlatformsOption();
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'dmm-ebook' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->andReturn( FetchResult::miss() );
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'findByExternalId' )->andReturn( null );
		$repo->shouldNotReceive( 'save' );
		$creator = new ProductAutoCreator( $registry, $repo );
		// 該当なし・無効 ID は恒久失敗（terminal）。give-up してよい。
		$this->assertSame( WorkOutcome::TERMINAL_FAILURE, $creator->create( 'dmm-books', 'ext-1' ) );
	}

	public function test_create_returns_transient_when_save_fails(): void {
		$this->stubPlatformsOption();
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'dmm-ebook' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->andReturn( FetchResult::hit( array( 'title' => '架空作品' ) ) );
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'findByExternalId' )->andReturn( null );
		$repo->shouldReceive( 'save' )->once()->andReturn( 0 ); // 保存失敗（0 = 未作成）
		$creator = new ProductAutoCreator( $registry, $repo );
		// 保存失敗はリトライで解決し得るため一時失敗（transient）。
		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $creator->create( 'dmm-books', 'ext-1' ) );
	}

	public function test_自動作成した商品はoffersを持つ(): void {
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider(
			FetchResult::hit(
				array(
					'external_id'   => 'abc',
					'regular_url'   => 'https://example.test/abc',
					'affiliate_url' => 'https://af.test/abc',
					'price'         => '660',
					'image_url'     => 'https://img.test/abc.jpg',
				)
			)
		);

		$saved = null;
		$repo  = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'findByExternalId' )->andReturn( null );
		$repo->shouldReceive( 'save' )->andReturnUsing(
			function ( array $data ) use ( &$saved ): int {
				$saved = $data;
				return 42;
			}
		);

		( new ProductAutoCreator( $registry, $repo ) )->create( 'rakuten-kobo', 'abc' );

		$offers = $saved['listings'][0]['offers'];
		$this->assertCount( 1, $offers );
		$this->assertSame( OfferSelector::DEFAULT_ORDER, $offers[0]['display_order'] );
		$this->assertSame( 'abc', $offers[0]['external_id'] );
		$this->assertSame( '660', $offers[0]['price'] );
		$this->assertSame( FetchStatus::NONE, $offers[0]['fetch_status'] );
		// 取得結果が listing 直下に残っていないこと。
		$this->assertArrayNotHasKey( 'external_id', $saved['listings'][0] );
	}

	public function test_ロックの中で引き直し先着が作っていれば保存しない(): void {
		// Action Scheduler の unique=true は原子的ではないため、同じキーの
		// autocreate が 2 つ同時に走り得る。fetch（数百 ms〜数秒）のあいだに
		// 先着が商品を作り終えていれば、ロックの中の引き直しがそれを拾う。
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::hit( array( 'external_id' => 'abc' ) ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		// 1 回目（fetch 前の事前チェック）は不在、2 回目（ロックの中）は先着が作った商品。
		$repo->shouldReceive( 'findByExternalId' )
			->with( 'rakuten-kobo', 'abc' )
			->andReturn( null, array( 'id' => 7 ) );
		$repo->shouldReceive( 'save' )->never();

		$got = ( new ProductAutoCreator( $registry, $repo ) )->create( 'rakuten-kobo', 'abc' );

		$this->assertSame( WorkOutcome::SUCCESS, $got );
		// ロックは取り、必ず返す。
		$this->assertStringContainsString( 'GET_LOCK', $this->capturedSql[0] );
		$this->assertStringContainsString( 'RELEASE_LOCK', $this->capturedSql[1] );
	}

	public function test_ロックを取れなければ保存せず一時失敗を返す(): void {
		// ロック無しで押し通すと重複商品ができる（external_id に DB 制約は無い）。
		// 一時失敗なら AutoCreateHandler が backoff して再投入し、次の試行では
		// 先着が作った商品を事前チェックが引いて no-op で終わる。
		$this->lockResult = 0;
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::hit( array( 'external_id' => 'abc' ) ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'findByExternalId' )->andReturn( null );
		$repo->shouldReceive( 'save' )->never();

		$got = ( new ProductAutoCreator( $registry, $repo ) )->create( 'rakuten-kobo', 'abc' );

		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $got );
		// 取れていないロックを返しに行かない。
		$this->assertSame( 1, count( $this->capturedSql ) );
		$this->assertStringContainsString( 'GET_LOCK', $this->capturedSql[0] );
	}

	public function test_保存した後はロックを必ず返す(): void {
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::hit( array( 'external_id' => 'abc' ) ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'findByExternalId' )->andReturn( null );
		$repo->shouldReceive( 'save' )->once()->andReturn( 42 );

		$got = ( new ProductAutoCreator( $registry, $repo ) )->create( 'rakuten-kobo', 'abc' );

		$this->assertSame( WorkOutcome::SUCCESS, $got );
		$this->assertStringContainsString( 'GET_LOCK', $this->capturedSql[0] );
		$this->assertStringContainsString( 'RELEASE_LOCK', $this->capturedSql[1] );
	}

	public function test_既存商品があれば作らない(): void {
		// external_id ミラーが複数値化されたことで、後続の購入リンクでも引ける。
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::hit( array( 'external_id' => 'abc' ) ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'findByExternalId' )->with( 'rakuten-kobo', 'abc' )->andReturn( array( 'id' => 7 ) );
		$repo->shouldReceive( 'save' )->never();

		// 既存商品が見つかった場合は生成不要の no-op ＝ SUCCESS。
		$this->assertSame( WorkOutcome::SUCCESS, ( new ProductAutoCreator( $registry, $repo ) )->create( 'rakuten-kobo', 'abc' ) );
	}
}
