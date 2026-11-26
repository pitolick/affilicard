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

	/** @var bool wp_mail が例外を投げるか（送信の失敗を再現する）。 */
	private bool $mailThrows = false;

	/**
	 * WP のリクエスト内キャッシュを模したもの。get_option は、ここに値があれば DB（$options）より
	 * 優先して返し、読んだ値をここに覚える（無い option は notoptions に覚える）。
	 *
	 * @var array<string, mixed>
	 */
	private array $cached = array();

	/** @var array<string, bool> 「無い」と覚えている option（WP の notoptions）。 */
	private array $notoptions = array();

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$this->options    = array();
		$this->mails      = array();
		$this->mailThrows = false;
		$this->cached     = array();
		$this->notoptions = array();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $text ) => $text );
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( $key, $default = false ) {
				if ( 'admin_email' === $key ) {
					return 'admin@example.test';
				}
				if ( 'blogname' === $key ) {
					return 'Demo';
				}
				if ( isset( $this->notoptions[ $key ] ) ) {
					return $default;
				}
				if ( array_key_exists( $key, $this->cached ) ) {
					return $this->cached[ $key ];
				}
				if ( ! array_key_exists( $key, $this->options ) ) {
					$this->notoptions[ $key ] = true;
					return $default;
				}
				$this->cached[ $key ] = $this->options[ $key ];
				return $this->options[ $key ];
			}
		);
		WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				$this->cached[ $key ]  = $value;
				unset( $this->notoptions[ $key ] );
				return true;
			}
		);
		WP_Mock::userFunction( 'delete_option' )->andReturnUsing(
			function ( $key ) {
				unset( $this->options[ $key ], $this->cached[ $key ] );
				return true;
			}
		);
		WP_Mock::userFunction( 'wp_cache_delete' )->andReturnUsing(
			function ( $key, $group ) {
				if ( 'options' === $group ) {
					unset( $this->cached[ $key ] );
				}
				return true;
			}
		);
		WP_Mock::userFunction( 'wp_cache_get' )->andReturnUsing(
			fn( $key, $group ) => ( 'notoptions' === $key && 'options' === $group ) ? $this->notoptions : false
		);
		WP_Mock::userFunction( 'wp_cache_set' )->andReturnUsing(
			function ( $key, $value, $group ) {
				if ( 'notoptions' === $key && 'options' === $group ) {
					$this->notoptions = $value;
				}
				return true;
			}
		);
		WP_Mock::userFunction( 'wp_date' )->andReturnUsing( static fn( $format, $ts ) => gmdate( $format, $ts ) );
		WP_Mock::userFunction( 'wp_mail' )->andReturnUsing(
			function ( $to, $subject ) {
				if ( $this->mailThrows ) {
					throw new \RuntimeException( 'smtp down' );
				}
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

	public function test_429のretryAfterに従う_ただし1分から1時間に収める(): void {
		$this->assertSame( 1090, AccountPause::recordRateLimited( 'amazon', 1000, 90 )['until'] );
		$this->assertSame( 1000 + 3600, AccountPause::recordRateLimited( 'amazon', 1000, 99999 )['until'] );
		// 1 秒など短い値でも 1 分は止める（応答を待つ間に停止が切れて、残りが 1 件ずつのジョブへ流れないように）。
		$this->assertSame( 1060, AccountPause::recordRateLimited( 'amazon', 1000, 1 )['until'] );
	}

	public function test_時刻を過ぎたら止めていない扱い_記録は成功まで残る(): void {
		AccountPause::recordRateLimited( 'amazon', 1000 );
		$this->assertSame( 0, AccountPause::until( 'amazon', 1061 ) );
		$this->assertNotNull( AccountPause::get( 'amazon' ) );

		AccountPause::recordSuccess( 'amazon', 1061 );
		$this->assertNull( AccountPause::get( 'amazon' ) );
	}

	public function test_記録より前に始まったリクエストの成功では消さない(): void {
		AccountPause::recordRateLimited( 'amazon', 1000 );
		// 999 に送ったリクエストの 200 が、1000 に記録した 429 の後に返ってきた。
		AccountPause::recordSuccess( 'amazon', 999 );
		AccountPause::recordSuccess( 'amazon', 1000 );
		$this->assertSame( 1060, AccountPause::until( 'amazon', 1000 ) );
	}

	/**
	 * 別のワーカーが書いた停止を見落とさないよう、$fresh なら option の値と「無い」という記録の
	 * 両方のキャッシュを捨ててから読む（WP は無い option もリクエストの間覚えている）。
	 */
	public function test_freshならリクエスト内のキャッシュを捨ててから読む(): void {
		$key = AccountPause::OPTION_PREFIX . 'amazon';
		// このワーカーは先に「停止の記録は無い」と読んで覚えている。
		$this->assertSame( 0, AccountPause::until( 'amazon', 1000 ) );
		// その後、別のワーカー（別のプロセス）が停止を保存した。こちらのキャッシュは古いまま。
		$this->options[ $key ]             = array(
			'until'   => 1060,
			'reason'  => AccountPause::REASON_RATE_LIMITED,
			'since'   => 1000,
			'streak'  => 1,
			'updated' => 1000,
		);
		$this->notoptions['other_missing'] = true;

		$this->assertSame( 0, AccountPause::until( 'amazon', 1000 ), 'キャッシュを使う読み方では古いまま' );
		$this->assertSame( 1060, AccountPause::until( 'amazon', 1000, true ) );
		// 捨てるのは停止の記録の印だけ。
		$this->assertSame( array( 'other_missing' => true ), $this->notoptions );
	}

	/**
	 * 200 で停止を解くときは最新の記録で判定する。キャッシュに残った古い記録（updated が古い）を
	 * 見ると、別のワーカーが後から書いた新しい停止を消してしまう。
	 */
	public function test_200で解くときはキャッシュに残った古い記録ではなく最新の記録で判定する(): void {
		$key = AccountPause::OPTION_PREFIX . 'amazon';
		AccountPause::recordRateLimited( 'amazon', 100 );
		// 別のワーカーが 200 の時点で新しい停止を記録した（こちらのキャッシュは 100 のまま）。
		$this->options[ $key ] = array(
			'until'   => 260,
			'reason'  => AccountPause::REASON_RATE_LIMITED,
			'since'   => 200,
			'streak'  => 1,
			'updated' => 200,
		);

		// こちらは 150 に送ったリクエストの 200。新しい停止（200 に記録）は消さない。
		AccountPause::recordSuccess( 'amazon', 150 );

		$this->assertSame( 260, AccountPause::until( 'amazon', 200, true ) );
	}

	public function test_403は6時間止める(): void {
		$record = AccountPause::recordNotEligible( 'amazon', 1000 );
		$this->assertSame( 1000 + 21600, $record['until'] );
		$this->assertSame( AccountPause::REASON_NOT_ELIGIBLE, $record['reason'] );
	}

	public function test_同じ停止の続きは通知せず_始まりの時刻を保つ(): void {
		AccountPause::recordRateLimited( 'amazon', 1000 );
		AccountPause::recordRateLimited( 'amazon', 1100 );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'admin@example.test', $this->mails[0][0] );
		$this->assertSame( 1000, AccountPause::get( 'amazon' )['since'] );
		$this->assertSame( 2, AccountPause::get( 'amazon' )['streak'] );
	}

	public function test_理由が変わったら新しい停止として数え直し_通知する(): void {
		AccountPause::recordRateLimited( 'amazon', 1000 );
		AccountPause::recordRateLimited( 'amazon', 1100 );
		$record = AccountPause::recordNotEligible( 'amazon', 1200 );
		$this->assertSame( 1, $record['streak'] );
		$this->assertSame( 1200, $record['since'] );
		$this->assertCount( 2, $this->mails );
	}

	public function test_停止の時刻から1日以上たった記録は新しい停止として数え直す(): void {
		AccountPause::recordRateLimited( 'amazon', 1000 );
		AccountPause::recordRateLimited( 'amazon', 1100 );
		// 再開後の呼び出しが通信断で終わり、記録だけが残ったまま 1 日以上たった。
		$later  = 1100 + 120 + 86400 + 1;
		$record = AccountPause::recordRateLimited( 'amazon', $later );
		$this->assertSame( 1, $record['streak'] );
		$this->assertSame( $later + 60, $record['until'] );
		$this->assertSame( $later, $record['since'] );
	}

	public function test_同じ理由の通知は24時間に1回まで_成功を挟んでも送り直さない(): void {
		AccountPause::recordRateLimited( 'amazon', 1000 );
		AccountPause::recordSuccess( 'amazon', 1100 );
		AccountPause::recordRateLimited( 'amazon', 1200 );
		AccountPause::recordSuccess( 'amazon', 1300 );
		AccountPause::recordRateLimited( 'amazon', 1400 );
		$this->assertCount( 1, $this->mails );

		AccountPause::recordSuccess( 'amazon', 1500 );
		AccountPause::recordRateLimited( 'amazon', 1000 + 86400 );
		$this->assertCount( 2, $this->mails );
	}

	public function test_通知の送信で例外が出ても停止は保存する(): void {
		$this->mailThrows = true;
		$log              = (string) tempnam( sys_get_temp_dir(), 'affilicard-log' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- テストの間だけ error_log の出力先を一時ファイルへ向け、書かれた内容を確かめる。
		$previous = ini_set( 'error_log', $log );
		try {
			AccountPause::recordRateLimited( 'amazon', 1000 );
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- 上で変えた出力先を元に戻す。
			ini_set( 'error_log', (string) $previous );
		}
		$this->assertSame( 1060, AccountPause::until( 'amazon', 1000 ) );
		// 送った印は付いている（次の 429 で送り直さない）。送れなかったことはログに残す。
		$this->assertArrayHasKey( AccountPause::NOTIFIED_OPTION_PREFIX . 'amazon', $this->options );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- ローカルの一時ファイルであり remote URL ではない。
		$this->assertStringContainsString( '通知メールを送れませんでした', (string) file_get_contents( $log ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- WP を読み込まない単体テストのため wp_delete_file は使えない。
		unlink( $log );
	}

	public function test_フィルタでfalseを返すとメールを送らない(): void {
		WP_Mock::onFilter( 'affilicard_notify_account_pause' )
			->with( true, 'amazon', AccountPause::REASON_RATE_LIMITED )
			->reply( false );
		AccountPause::recordRateLimited( 'amazon', 1000 );
		$this->assertCount( 0, $this->mails );
		$this->assertSame( 1060, AccountPause::until( 'amazon', 1000 ) );
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
