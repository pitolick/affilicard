<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\AutoCreate;

use Affilicard\AutoCreate\ProductAutoCreator;
use Affilicard\Provider\FetchResult;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\WorkOutcome;
use Affilicard\Repository\ProductRepositoryInterface;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProductAutoCreatorTest extends TestCase {
	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $t ) => $t );
	}
	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
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
		$repo->shouldReceive( 'save' )->once()->andReturnUsing(
			function ( array $data ) {
				$this->assertSame( '架空のサンプル作品', $data['title'] );
				$this->assertSame( 'publish', $data['status'] );
				$this->assertSame( 'dmm-books', $data['listings'][0]['platform'] );
				$this->assertSame( 'ext-1', $data['listings'][0]['external_id'] );
				$this->assertSame( '600', $data['listings'][0]['price'] );
				$this->assertSame( 'auto', $data['listings'][0]['update_mode'] );
				$this->assertTrue( $data['listings'][0]['auto_update'] );
				$this->assertNotEmpty( $data['listings'][0]['last_verified_at'] );
				$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['listings'][0]['last_verified_at'] );
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
		$repo->shouldReceive( 'save' )->once()->andReturn( 0 ); // 保存失敗（0 = 未作成）
		$creator = new ProductAutoCreator( $registry, $repo );
		// 保存失敗はリトライで解決し得るため一時失敗（transient）。
		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $creator->create( 'dmm-books', 'ext-1' ) );
	}
}
