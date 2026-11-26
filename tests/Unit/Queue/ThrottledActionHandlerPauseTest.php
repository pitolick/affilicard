<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\AccountPause;
use Affilicard\Queue\RateLimiter;
use Affilicard\Queue\ThrottledActionHandler;
use Affilicard\Queue\WorkOutcome;
use Affilicard\Settings\GeneralSettings;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * account を止めている間（AccountPause）の ThrottledActionHandler::run()。
 *
 * 上限（429）・利用条件（403）で止めている間は API を呼ばず、試行回数も増やさずに再開時刻まで
 * 積み直す。1 件ずつの再試行で呼び出しが膨らみ、上限からの回復を遅らせないため。
 */
final class ThrottledActionHandlerPauseTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var int 停止の記録のキャッシュを捨てた回数（別のワーカーが書いた停止を読み直したか）。 */
	private int $pauseCachePurges = 0;

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		// 一時失敗の後などの停止の判定は、リクエスト内のキャッシュを捨ててから読む（AccountPause::until の $fresh）。
		$this->pauseCachePurges = 0;
		WP_Mock::userFunction( 'wp_cache_delete' )
			->with( \Mockery::pattern( '/^affilicard_account_pause_/' ), 'options' )
			->andReturnUsing(
				function () {
					++$this->pauseCachePurges;
					return true;
				}
			);
		WP_Mock::userFunction( 'wp_cache_get' )->with( 'notoptions', 'options' )->andReturn( false );
		$this->options = array();
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_option' )
			->with( Mockery::pattern( '/^affilicard_account_pause_/' ), null )
			->andReturnUsing( fn( $key ) => $this->options[ $key ] ?? null );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		if ( isset( $GLOBALS['wpdb'] ) ) {
			unset( $GLOBALS['wpdb'] );
		}
		Mockery::close();
		parent::tearDown();
	}

	private function pauseAmazonUntil( int $until ): void {
		$this->options[ AccountPause::OPTION_PREFIX . 'amazon' ] = array(
			'until'  => $until,
			'reason' => AccountPause::REASON_RATE_LIMITED,
			'since'  => time() - 60,
			'streak' => 1,
		);
	}

	private function registry(): ProviderRegistry {
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'amazon' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'minRequestIntervalMs' )->andReturn( 1100 );
		$provider->shouldReceive( 'accountCode' )->andReturn( 'amazon' );
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		return $registry;
	}

	private function allowRateLimiter(): void {
		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $q ) => $q );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb;
		WP_Mock::userFunction( 'add_option' )->andReturn( true );
		WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );
	}

	/**
	 * performWork() の結果と、その最中に起きること（account が止まる等）を差し替えられるハンドラ。
	 */
	private function handler( WorkOutcome $outcome, ?callable $duringWork = null ): object {
		return new class( $outcome, $duringWork, new RateLimiter(), $this->registry() ) extends ThrottledActionHandler {
			public int $worked = 0;

			/** @var list<int> */
			public array $rescheduledAt = array();

			public function __construct( private WorkOutcome $outcome, private $duringWork, RateLimiter $limiter, ProviderRegistry $registry ) {
				parent::__construct( $limiter, $registry );
			}

			public function trigger( array $args ): void {
				$this->run( $args );
			}

			protected function providerCodeFor( array $args ): ?string {
				return 'amazon';
			}

			protected function performWork( array $args ): WorkOutcome {
				++$this->worked;
				if ( null !== $this->duringWork ) {
					( $this->duringWork )();
				}
				return $this->outcome;
			}

			protected function reschedule( int $whenSec, array $args ): void {
				$this->rescheduledAt[] = $whenSec;
			}

			protected function attemptKey( array $args ): string {
				return 'test_attempts';
			}

			protected function throttleWaitKey( array $args ): string {
				return 'test_throttle_waits';
			}
		};
	}

	public function test_止めている間は呼ばずに再開時刻へ積み直し_試行回数も増やさない(): void {
		$until = time() + 600;
		$this->pauseAmazonUntil( $until );
		WP_Mock::userFunction( 'set_transient' )->never();

		$handler = $this->handler( WorkOutcome::SUCCESS );
		$handler->trigger( array() );

		$this->assertSame( 0, $handler->worked );
		$this->assertSame( array( $until ), $handler->rescheduledAt );
		// ジョブを始める前の判定も、別のワーカーが止めた直後を見落とさないようキャッシュを捨てて読む。
		$this->assertGreaterThan( 0, $this->pauseCachePurges );
	}

	public function test_その呼び出しで止まったら_backoffではなく再開時刻へ積み直す(): void {
		$this->allowRateLimiter();
		WP_Mock::userFunction( 'delete_transient' )->andReturn( true );
		// backoff は試行回数を set_transient で数える。止まったときは数えない。
		WP_Mock::userFunction( 'set_transient' )->never();
		$until   = time() + 120;
		$handler = $this->handler(
			WorkOutcome::TRANSIENT_FAILURE,
			fn() => $this->pauseAmazonUntil( $until )
		);

		$handler->trigger( array() );

		$this->assertSame( 1, $handler->worked );
		$this->assertSame( array( $until ), $handler->rescheduledAt );
		// 応答を待つ間に別のワーカーが止めた場合も見落とさないよう、一時失敗の後はキャッシュを捨てて読む。
		$this->assertGreaterThan( 0, $this->pauseCachePurges );
	}

	public function test_止まっていない一時失敗は従来どおりbackoff(): void {
		$this->allowRateLimiter();
		WP_Mock::userFunction( 'delete_transient' )->andReturn( true );
		WP_Mock::userFunction( 'get_transient' )->andReturn( 0 );
		WP_Mock::userFunction( 'set_transient' )->once()->with( 'test_attempts', 1, Mockery::any() )->andReturn( true );

		$handler = $this->handler( WorkOutcome::TRANSIENT_FAILURE );
		$handler->trigger( array() );

		$this->assertSame( 1, $handler->worked );
		$this->assertCount( 1, $handler->rescheduledAt );
		// 1 回目の backoff は 2 分後。
		$this->assertEqualsWithDelta( time() + 120, $handler->rescheduledAt[0], 2 );
	}
}
