<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\RateLimiter;
use Affilicard\Queue\ThrottledActionHandler;
use Affilicard\Queue\WorkOutcome;
use Affilicard\Settings\GeneralSettings;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * ThrottledActionHandler::run() のレート制限の枠確保（interval × refreshTargetCount()）を
 * 検証する。
 *
 * RateLimiter は final class のため Mockery でモック不可（RefreshHandlerTest/
 * AutoCreateHandlerTest と同じ流儀で $GLOBALS['wpdb'] を CAS 用にスタブし、実インスタンス
 * で検証する）。
 */
final class ThrottledActionHandlerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		if ( isset( $GLOBALS['wpdb'] ) ) {
			unset( $GLOBALS['wpdb'] );
		}
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * RateLimiter::tryAcquire() の CAS UPDATE をスタブしつつ、prepare() へ渡された
	 * (nowMs, threshold) を $captured へ捕捉する。$captured[0]=nowMs, $captured[1]=threshold。
	 * nowMs - threshold が実際に tryAcquire() へ渡された intervalMs（= interval × 件数）になる
	 * ため、real な現在時刻（microtime）に依存せずに検証できる。
	 *
	 * @param array{0?: int, 1?: int} $captured
	 */
	private function mockRateLimiterWpdbCapturing( array &$captured ): void {
		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::type( 'string' ), Mockery::type( 'int' ), Mockery::type( 'string' ), Mockery::type( 'int' ) )
			->andReturnUsing(
				static function ( string $query, int $nowMs, string $key, int $threshold ) use ( &$captured ): string {
					$captured = array( $nowMs, $threshold );
					return $query;
				}
			);
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 1 ); // 獲得成功。
		$GLOBALS['wpdb'] = $wpdb;

		WP_Mock::userFunction( 'add_option' )->andReturn( true );
		WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );
	}

	/**
	 * isAutomatic=true・minRequestIntervalMs=1100・accountCode='rakuten' の provider を
	 * 登録した ProviderRegistry。
	 */
	private function registry(): ProviderRegistry {
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'minRequestIntervalMs' )->andReturn( 1100 );
		$provider->shouldReceive( 'accountCode' )->andReturn( 'rakuten' );

		$registry = new ProviderRegistry();
		$registry->register( $provider );
		return $registry;
	}

	/**
	 * refreshTargetCount() が固定件数を返す ThrottledActionHandler の匿名サブクラス。
	 * run() は protected のため、public な trigger() 経由で呼ぶ。
	 */
	private function handlerWithTargetCount( int $count, RateLimiter $limiter, ProviderRegistry $registry ): ThrottledActionHandler {
		return new class( $count, $limiter, $registry ) extends ThrottledActionHandler {
			public function __construct( private int $count, RateLimiter $limiter, ProviderRegistry $registry ) {
				parent::__construct( $limiter, $registry );
			}

			/**
			 * @param array<string, mixed> $args
			 */
			public function trigger( array $args ): void {
				$this->run( $args );
			}

			protected function providerCodeFor( array $args ): ?string {
				return 'rakuten';
			}

			protected function performWork( array $args ): WorkOutcome {
				return WorkOutcome::SUCCESS;
			}

			protected function reschedule( int $whenSec, array $args ): void {
				// no-op（このテストでは reschedule 経路は使わない）。
			}

			protected function attemptKey( array $args ): string {
				return 'test_attempts';
			}

			protected function throttleWaitKey( array $args ): string {
				return 'test_throttle_waits';
			}

			protected function refreshTargetCount( array $args ): int {
				return $this->count;
			}
		};
	}

	/**
	 * 現時点では選択係（OfferSelector）は常に 0 or 1 件しか返さないため、対象件数 1 では
	 * 枠は従来どおり interval × 1。
	 */
	public function test_レート制限の枠は対象件数に比例する(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$captured = array();
		$this->mockRateLimiterWpdbCapturing( $captured );
		WP_Mock::userFunction( 'delete_transient' )->andReturn( true );

		$handler = $this->handlerWithTargetCount( 1, new RateLimiter(), $this->registry() );
		$handler->trigger( array() );

		$this->assertSame( 1100, $captured[0] - $captured[1] );
	}

	/**
	 * 将来「複数の購入リンクを同時に見せる」へ進んだとき、表示を増やした瞬間にレート制限を
	 * 超える事故が起きないことを固定する回帰テスト。対象件数が 2 なら枠も interval × 2 になる。
	 */
	public function test_対象が2件なら枠も2倍になる(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$captured = array();
		$this->mockRateLimiterWpdbCapturing( $captured );
		WP_Mock::userFunction( 'delete_transient' )->andReturn( true );

		$handler = $this->handlerWithTargetCount( 2, new RateLimiter(), $this->registry() );
		$handler->trigger( array() );

		$this->assertSame( 2200, $captured[0] - $captured[1] );
	}
}
