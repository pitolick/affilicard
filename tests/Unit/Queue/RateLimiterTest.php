<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\RateLimiter;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class RateLimiterTest extends TestCase {

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
	 * tryAcquire() が発行する条件付き UPDATE（CAS）を捕捉する $wpdb モックを
	 * $GLOBALS に設定する（UninstallTest::mockWpdb と同じ流儀）。
	 *
	 * @param int                           $queryReturn UPDATE の影響行数（1=獲得成功／0=未獲得）。
	 * @param array<int, string>            $capturedSql prepare() に渡された SQL テンプレートを蓄積する参照。
	 * @param array<int, array<int, mixed>> $capturedArgs prepare() に渡された bind 引数を蓄積する参照。
	 */
	private function mockWpdb( int $queryReturn, array &$capturedSql = array(), array &$capturedArgs = array() ): void {
		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( string $query, ...$args ) use ( &$capturedSql, &$capturedArgs ) {
				$capturedSql[]  = $query;
				$capturedArgs[] = $args;
				return $query;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function () use ( $queryReturn ) {
				return $queryReturn;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	public function test_effectiveIntervalMs_下限と上書きの大きい方(): void {
		$rl = new RateLimiter();
		$this->assertSame( 1100, $rl->effectiveIntervalMs( 1100, 0 ) );    // override 無し
		$this->assertSame( 2000, $rl->effectiveIntervalMs( 1100, 2000 ) ); // 遅い側に上書き
		$this->assertSame( 1100, $rl->effectiveIntervalMs( 1100, 500 ) );  // 下限より速い上書きは無効
	}

	public function test_tryAcquire_CASのUPDATEが成功したら獲得しnext_msはnowMsを返す(): void {
		$this->mockWpdb( 1 ); // UPDATE が 1 行に影響 = 獲得成功

		WP_Mock::userFunction( 'add_option' )->once()
			->with( 'affilicard_ratelimit_rakuten', '0', '', false )
			->andReturn( true );
		WP_Mock::userFunction( 'wp_cache_delete' )->once()
			->with( 'affilicard_ratelimit_rakuten', 'options' )
			->andReturn( true );
		// 獲得成功パスは CAS の結果だけで判断できるため get_option への追加読み出しは不要。
		WP_Mock::userFunction( 'get_option' )->never();

		$out = ( new RateLimiter() )->tryAcquire( 'rakuten', 1100, 3000 );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 3000, $out['next_ms'] );
		$this->assertConditionsMet();
	}

	public function test_tryAcquire_CASのUPDATEが失敗したら未獲得で現在値からnext_msを算出する(): void {
		$this->mockWpdb( 0 ); // UPDATE が 0 行 = 他ワーカーが既に獲得済み（条件不成立）

		WP_Mock::userFunction( 'add_option' )->once()
			->with( 'affilicard_ratelimit_rakuten', '0', '', false )
			->andReturn( false ); // 既に存在するため no-op
		WP_Mock::userFunction( 'wp_cache_delete' )->once()
			->with( 'affilicard_ratelimit_rakuten', 'options' )
			->andReturn( true );
		WP_Mock::userFunction( 'get_option' )->once()
			->with( 'affilicard_ratelimit_rakuten', 0 )
			->andReturn( 1000 );

		$out = ( new RateLimiter() )->tryAcquire( 'rakuten', 1100, 1500 ); // 1500-1000=500 < 1100

		$this->assertFalse( $out['ok'] );
		$this->assertSame( 2100, $out['next_ms'] ); // 1000 + 1100
		$this->assertConditionsMet();
	}

	public function test_tryAcquire_発行するSQLは条件付きUPDATEでありbareUPDATEではない(): void {
		$capturedSql  = array();
		$capturedArgs = array();
		$this->mockWpdb( 1, $capturedSql, $capturedArgs );

		WP_Mock::userFunction( 'add_option' )->once()->andReturn( true );
		WP_Mock::userFunction( 'wp_cache_delete' )->once();

		( new RateLimiter() )->tryAcquire( 'dmm', 1100, 5000 );

		$this->assertCount( 1, $capturedSql );
		$this->assertStringContainsString( 'UPDATE', $capturedSql[0] );
		$this->assertStringContainsString( 'wp_options', $capturedSql[0] );
		// 無条件更新（bare UPDATE）ではなく、現在値が閾値以下の場合のみ更新する CAS 条件を持つこと。
		$this->assertStringContainsString( 'WHERE', $capturedSql[0] );
		$this->assertStringContainsString( 'option_name', $capturedSql[0] );
		$this->assertStringContainsString( 'CAST(option_value AS UNSIGNED) <=', $capturedSql[0] );

		// bind 引数は [nowMs, key, threshold] の順（threshold = nowMs - intervalMs）。
		$this->assertSame( array( 5000, 'affilicard_ratelimit_dmm', 3900 ), $capturedArgs[0] );
	}

	public function test_tryAcquire_optionキーをaccountコード単位でシードする(): void {
		$this->mockWpdb( 1 );

		WP_Mock::userFunction( 'add_option' )->once()
			->with( 'affilicard_ratelimit_dmm', '0', '', false )
			->andReturn( true );
		WP_Mock::userFunction( 'wp_cache_delete' )->once()->with( 'affilicard_ratelimit_dmm', 'options' );

		( new RateLimiter() )->tryAcquire( 'dmm', 1100, 5000 );

		$this->assertConditionsMet();
	}
}
