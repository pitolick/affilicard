<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Repository;

use Affilicard\Repository\ListingLock;
use Mockery;
use PHPUnit\Framework\TestCase;

final class ListingLockTest extends TestCase {

	public function tearDown(): void {
		if ( isset( $GLOBALS['wpdb'] ) ) {
			unset( $GLOBALS['wpdb'] );
		}
		Mockery::close();
		parent::tearDown();
	}

	/** dbname と prefix を持つ $wpdb を差し込む。 */
	private function stubWpdb( string $dbname, string $prefix ): void {
		$wpdb         = Mockery::mock();
		$wpdb->dbname = $dbname;
		$wpdb->prefix = $prefix;

		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * ロック名は同じサイト・同じ商品なら安定する。
	 *
	 * 名前が呼び出しごとに変わると、取得したロックを解放できない。
	 */
	public function test_同じサイトと商品なら同じ名前になる(): void {
		$this->stubWpdb( 'wp_a', 'wp_' );

		$this->assertSame( ListingLock::name( 123 ), ListingLock::name( 123 ) );
	}

	/**
	 * DB が違えば別のロックになる。
	 *
	 * MySQL の名前付きロックは接続単位ではなくサーバ全体で共有される。共有ホスティングの
	 * ように 1 つの MySQL に複数の WordPress が同居していると、サイトを区別しない名前では
	 * サイト A の商品 123 がサイト B の商品 123 を待たせてしまう。
	 */
	public function test_DBが違えば別のロック名になる(): void {
		$this->stubWpdb( 'wp_a', 'wp_' );
		$a = ListingLock::name( 123 );

		$this->stubWpdb( 'wp_b', 'wp_' );
		$b = ListingLock::name( 123 );

		$this->assertNotSame( $a, $b, 'DB 名が違うのに同じロックを奪い合っている' );
	}

	/** テーブル prefix が違えば別のロックになる（同一 DB のマルチサイト相当）。 */
	public function test_prefixが違えば別のロック名になる(): void {
		$this->stubWpdb( 'wp_a', 'wp_' );
		$a = ListingLock::name( 123 );

		$this->stubWpdb( 'wp_a', 'wp2_' );
		$b = ListingLock::name( 123 );

		$this->assertNotSame( $a, $b, 'prefix が違うのに同じロックを奪い合っている' );
	}

	/** 同じサイトでも商品が違えば別のロックになる。 */
	public function test_商品が違えば別のロック名になる(): void {
		$this->stubWpdb( 'wp_a', 'wp_' );

		$this->assertNotSame( ListingLock::name( 123 ), ListingLock::name( 124 ) );
	}

	/** GET_LOCK の名前は 64 バイト以内。 */
	public function test_ロック名は64バイトを超えない(): void {
		$this->stubWpdb( str_repeat( 'd', 64 ), str_repeat( 'p', 64 ) );

		$this->assertLessThanOrEqual( 64, strlen( ListingLock::name( PHP_INT_MAX ) ) );
	}
}
