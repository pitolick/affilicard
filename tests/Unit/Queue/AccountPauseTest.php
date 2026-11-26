<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\AccountPause;
use Affilicard\Queue\ApiUsage;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class AccountPauseTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var list<array{0: string, 1: string}> */
	private array $mails = array();

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$this->options = array();
		$this->mails   = array();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $text ) => $text );
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( $key, $default = false ) {
				if ( 'admin_email' === $key ) {
					return 'admin@example.test';
				}
				if ( 'blogname' === $key ) {
					return 'Demo';
				}
				return $this->options[ $key ] ?? $default;
			}
		);
		WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'delete_option' )->andReturnUsing(
			function ( $key ) {
				unset( $this->options[ $key ] );
				return true;
			}
		);
		WP_Mock::userFunction( 'wp_date' )->andReturnUsing( static fn( $format, $ts ) => gmdate( $format, $ts ) );
		WP_Mock::userFunction( 'wp_mail' )->andReturnUsing(
			function ( $to, $subject ) {
				$this->mails[] = array( $to, $subject );
				return true;
			}
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_記録が無ければ止めていない(): void {
		$this->assertSame( 0, AccountPause::until( 'amazon', 1000 ) );
		$this->assertNull( AccountPause::get( 'amazon' ) );
	}

	public function test_429でretryAfterが無ければ1分から倍々に延ばし1時間で頭打ち(): void {
		$expected = array( 60, 120, 240, 480, 960, 1920, 3600, 3600 );
		foreach ( $expected as $delay ) {
			$record = AccountPause::recordRateLimited( 'amazon', 1000 );
			$this->assertSame( 1000 + $delay, $record['until'] );
		}
		$this->assertSame( 1000 + 3600, AccountPause::until( 'amazon', 1000 ) );
	}

	public function test_429のretryAfterに従う_ただし1時間まで(): void {
		$this->assertSame( 1090, AccountPause::recordRateLimited( 'amazon', 1000, 90 )['until'] );
		$this->assertSame( 1000 + 3600, AccountPause::recordRateLimited( 'amazon', 1000, 99999 )['until'] );
	}

	public function test_時刻を過ぎたら止めていない扱い_記録は成功まで残る(): void {
		AccountPause::recordRateLimited( 'amazon', 1000 );
		$this->assertSame( 0, AccountPause::until( 'amazon', 1061 ) );
		$this->assertNotNull( AccountPause::get( 'amazon' ) );

		AccountPause::recordSuccess( 'amazon' );
		$this->assertNull( AccountPause::get( 'amazon' ) );
	}

	public function test_403は6時間止める(): void {
		$record = AccountPause::recordNotEligible( 'amazon', 1000 );
		$this->assertSame( 1000 + 21600, $record['until'] );
		$this->assertSame( AccountPause::REASON_NOT_ELIGIBLE, $record['reason'] );
	}

	public function test_通知は停止に入ったときに1回だけ_成功で区切ると次の停止でまた送る(): void {
		AccountPause::recordRateLimited( 'amazon', 1000 );
		AccountPause::recordRateLimited( 'amazon', 1100 );
		AccountPause::recordNotEligible( 'amazon', 1200 );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'admin@example.test', $this->mails[0][0] );
		// 停止の始まりは最初の 429 のまま。
		$this->assertSame( 1000, AccountPause::get( 'amazon' )['since'] );

		AccountPause::recordSuccess( 'amazon' );
		AccountPause::recordRateLimited( 'amazon', 5000 );
		$this->assertCount( 2, $this->mails );
		$this->assertSame( 5000, AccountPause::get( 'amazon' )['since'] );
	}

	public function test_フィルタでfalseを返すとメールを送らない(): void {
		WP_Mock::onFilter( 'affilicard_notify_account_pause' )
			->with( true, 'amazon', AccountPause::REASON_RATE_LIMITED )
			->reply( false );
		AccountPause::recordRateLimited( 'amazon', 1000 );
		$this->assertCount( 0, $this->mails );
		// 送らない設定でも「済ませた」扱いにして、毎回フィルタ→送信を試みない。
		$this->assertTrue( AccountPause::get( 'amazon' )['notified'] );
	}

	public function test_日次集計は日付ごとに数え_7日分だけ残す(): void {
		$day = 86400;
		for ( $i = 0; $i < 9; $i++ ) {
			ApiUsage::record( 'amazon', ApiUsage::KIND_CALLS, $i * $day );
		}
		ApiUsage::record( 'amazon', ApiUsage::KIND_CALLS, 8 * $day );
		ApiUsage::record( 'amazon', ApiUsage::KIND_RATE_LIMITED, 8 * $day );
		ApiUsage::record( 'amazon', 'unknown-kind', 8 * $day );

		$this->assertSame(
			array(
				'calls'        => 2,
				'rate_limited' => 1,
				'not_eligible' => 0,
			),
			ApiUsage::forDay( 'amazon', 8 * $day )
		);
		$this->assertCount( 7, $this->options[ ApiUsage::OPTION_PREFIX . 'amazon' ] );
		// 8 日以上前の日は消えている。
		$this->assertSame( 0, ApiUsage::forDay( 'amazon', 0 )['calls'] );
	}
}
