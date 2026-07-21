<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\AutoCreate;

use Affilicard\AutoCreate\ProductAutoCreator;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
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

	public function test_create_returns_null_when_platform_unknown(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'save' );
		$creator = new ProductAutoCreator( new ProviderRegistry(), $repo );
		$this->assertNull( $creator->create( 'unknown', 'ext-1' ) );
	}

	public function test_create_saves_product_and_returns_id_on_fetch_success(): void {
		$this->stubPlatformsOption();
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'dmm-ebook' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->with( 'ext-1', Mockery::type( 'array' ) )->andReturn(
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
				return 123;
			}
		);
		$creator = new ProductAutoCreator( $registry, $repo );
		$this->assertSame( 123, $creator->create( 'dmm-books', 'ext-1' ) );
	}

	public function test_create_returns_null_when_provider_not_automatic(): void {
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
		$this->assertNull( $creator->create( 'manual-shop', 'ext-1' ) );
	}

	public function test_create_returns_null_when_fetch_fails(): void {
		$this->stubPlatformsOption();
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'dmm-ebook' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->andReturn( null );
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'save' );
		$creator = new ProductAutoCreator( $registry, $repo );
		$this->assertNull( $creator->create( 'dmm-books', 'ext-1' ) );
	}
}
