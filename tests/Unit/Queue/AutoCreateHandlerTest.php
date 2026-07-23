<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\AutoCreate\ProductAutoCreator;
use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\AutoCreateHandler;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\RateLimiter;
use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Settings\GeneralSettings;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * ProductAutoCreator は final のため直接 Mockery::mock() できない。
 * Task 8 の RefreshHandlerTest（ListingRefresher を直接モック）と異なり、
 * ProductAutoCreator 自身は実インスタンスを組み立て、その依存（ProviderRegistry
 * の中の provider・ProductRepositoryInterface）をモックすることで
 * 「creator->create が呼ばれたか」を fetch/save の呼び出し有無で検証する
 * （ProductAutoCreatorTest.php と同じ流儀）。
 */
final class AutoCreateHandlerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * isAutomatic=true・minRequestIntervalMs=1100 の 'rakuten' provider モック。
	 * AutoCreateHandler（ThrottledActionHandler 側の provider 判定）と
	 * ProductAutoCreator（実際の fetch）の両方から同一インスタンスを参照させる。
	 */
	private function provider(): ProviderInterface {
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'minRequestIntervalMs' )->andReturn( 1100 );
		return $provider;
	}

	private function registry( ProviderInterface $provider ): ProviderRegistry {
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		return $registry;
	}

	/**
	 * PlatformConfig::find('rakuten-kobo')->provider が 'rakuten' を返すよう
	 * affilicard_platforms option を stub する（実 defaults() の provider は 'rakuten-kobo'
	 * なので、RateLimiter option キー affilicard_ratelimit_rakuten と揃えるためスタブを使う）。
	 */
	private function stubPlatform(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'     => 'rakuten-kobo',
						'provider' => 'rakuten',
					),
				)
			);
	}

	public function test_handle_pause中は何もしない(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array( 'queue_paused' => true ) );

		$provider = $this->provider();
		$provider->shouldNotReceive( 'fetch' );
		$registry = $this->registry( $provider );

		// creator->create が呼ばれれば必ず save に到達するはずなので、
		// 呼ばれないことを save 不発火で検証する（ProductAutoCreator は final で直接モック不可）。
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'save' );
		$creator = new ProductAutoCreator( $registry, $repo );

		$handler = new AutoCreateHandler( new Enqueuer(), new RateLimiter(), $creator, $registry );
		$handler->handle( 'rakuten-kobo', 'ext-001' );

		$this->assertConditionsMet();
	}

	public function test_handle_throttle未経過なら再投入してcreateしない(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		// 実行時刻(ms)より確実に未来になる値を使い、実時計に依存せず「間隔未経過」を再現する。
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_ratelimit_rakuten', 0 )
			->andReturn( 9999999999999 );
		WP_Mock::userFunction( 'as_schedule_single_action' )->once(); // rescheduleAutoCreate

		$provider = $this->provider();
		$provider->shouldNotReceive( 'fetch' );
		$registry = $this->registry( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'save' );
		$creator = new ProductAutoCreator( $registry, $repo );

		$handler = new AutoCreateHandler( new Enqueuer(), new RateLimiter(), $creator, $registry );
		$handler->handle( 'rakuten-kobo', 'ext-001' );

		$this->assertConditionsMet();
	}

	public function test_handle_取得できればcreateを呼ぶ(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$this->stubPlatform();
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_ratelimit_rakuten', 0 )
			->andReturn( 0 ); // 経過済
		WP_Mock::userFunction( 'update_option' )
			->with( 'affilicard_ratelimit_rakuten', Mockery::type( 'int' ), false )
			->andReturn( true );
		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'affilicard_autocreate_attempts_rakuten-kobo_ext-001' )
			->andReturn( true );

		$provider = $this->provider();
		$provider->shouldReceive( 'fetch' )
			->once()
			->with( 'ext-001', Mockery::type( 'array' ) )
			->andReturn(
				array(
					'title'           => '架空のサンプル作品',
					'price'           => '600',
					'list_price'      => '1000',
					'badge'           => '',
					'image_url'       => 'https://example.test/i.jpg',
					'regular_url'     => 'https://example.test/r',
					'affiliate_url'   => 'https://example.test/a',
					'platform_extras' => array(),
				)
			);
		$registry = $this->registry( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'save' )->once()->andReturn( 55 );
		$creator = new ProductAutoCreator( $registry, $repo );

		$handler = new AutoCreateHandler( new Enqueuer(), new RateLimiter(), $creator, $registry );
		$handler->handle( 'rakuten-kobo', 'ext-001' );

		$this->assertConditionsMet();
	}
}
