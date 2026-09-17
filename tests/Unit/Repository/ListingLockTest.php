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

	/**
	 * GET_LOCK / RELEASE_LOCK の発行を記録する $wpdb を差し込む。
	 *
	 * @param int                $getLockReturn GET_LOCK の戻り（1=取得成功／0=タイムアウト）。
	 * @param array<int, string> $issued        発行された SQL の種類を積む参照。
	 */
	private function stubLockWpdb( int $getLockReturn, array &$issued ): void {
		$wpdb         = Mockery::mock();
		$wpdb->dbname = 'wp_a';
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $query ) {
				return $query;
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( string $query ) use ( $getLockReturn, &$issued ) {
				if ( false !== strpos( $query, 'GET_LOCK' ) ) {
					$issued[] = 'GET_LOCK';
				}
				return (string) $getLockReturn;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( string $query ) use ( &$issued ) {
				if ( false !== strpos( $query, 'RELEASE_LOCK' ) ) {
					$issued[] = 'RELEASE_LOCK';
				}
				return 1;
			}
		);

		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * 同じ商品の入れ子は、ロックを取り直さずそのまま中へ入る（再入可能）。
	 *
	 * 既にこのリクエストで握っているロックへ 2 度目の GET_LOCK を撃たない。MySQL の
	 * 名前付きロックが同一セッションの再取得をどう数えるかに依存せず、解放の対も
	 * 1 対 1 に保つためである（撃ってしまうと、解放が 1 回足りない実装では
	 * リクエストを抜けるまで当該商品の更新が全部詰まる）。
	 */
	public function test_同じ商品の入れ子はロックを取り直さない(): void {
		$issued = array();
		$this->stubLockWpdb( 1, $issued );

		$inner = null;
		ListingLock::around(
			7,
			static function ( bool $locked ) use ( &$inner ): void {
				ListingLock::around(
					7,
					static function ( bool $innerLocked ) use ( &$inner ): void {
						$inner = $innerLocked;
					}
				);
			}
		);

		$this->assertSame( array( 'GET_LOCK', 'RELEASE_LOCK' ), $issued );
		$this->assertTrue( $inner, '外側が握っているのだから内側も保護されている' );
	}

	/** 入れ子でも商品が違えば、それぞれロックを取る。 */
	public function test_入れ子でも商品が違えば別にロックを取る(): void {
		$issued = array();
		$this->stubLockWpdb( 1, $issued );

		ListingLock::around(
			7,
			static function ( bool $locked ): void {
				ListingLock::around(
					8,
					static function ( bool $innerLocked ): void {
					}
				);
			}
		);

		$this->assertSame(
			array( 'GET_LOCK', 'GET_LOCK', 'RELEASE_LOCK', 'RELEASE_LOCK' ),
			$issued
		);
	}

	/**
	 * 外側がロックを取れていなければ、内側は自分で取りに行く。
	 *
	 * 再入の判定は「取れた」ときにしか立てない。取れていない外側の中で内側まで
	 * 「保護されている」ことにすると、誰も握っていない区間を守られていると誤認する。
	 */
	public function test_外側が取れていなければ内側は自分で取りに行く(): void {
		$issued = array();
		$this->stubLockWpdb( 0, $issued );

		$inner = null;
		ListingLock::around(
			7,
			static function ( bool $locked ) use ( &$inner ): void {
				ListingLock::around(
					7,
					static function ( bool $innerLocked ) use ( &$inner ): void {
						$inner = $innerLocked;
					}
				);
			}
		);

		$this->assertSame( array( 'GET_LOCK', 'GET_LOCK' ), $issued );
		$this->assertFalse( $inner );
	}

	/**
	 * 例外が飛んでも「握っている」印は残さない。
	 *
	 * 残すと、同じリクエストの後続の呼び出しが GET_LOCK を撃たずに素通りし、
	 * 誰も握っていない区間を守られているものとして読み書きする。
	 */
	public function test_例外が飛んでも握っている印を残さない(): void {
		$issued = array();
		$this->stubLockWpdb( 1, $issued );

		try {
			ListingLock::around(
				7,
				static function ( bool $locked ): void {
					throw new \RuntimeException( 'affilicard-test: クリティカルセクションの失敗' );
				}
			);
			$this->fail( '例外がそのまま伝播すること' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'affilicard-test: クリティカルセクションの失敗', $e->getMessage() );
		}

		ListingLock::around(
			7,
			static function ( bool $locked ): void {
			}
		);

		$this->assertSame(
			array( 'GET_LOCK', 'RELEASE_LOCK', 'GET_LOCK', 'RELEASE_LOCK' ),
			$issued
		);
	}

	/** GET_LOCK の名前は 64 バイト以内。 */
	public function test_ロック名は64バイトを超えない(): void {
		$this->stubWpdb( str_repeat( 'd', 64 ), str_repeat( 'p', 64 ) );

		$this->assertLessThanOrEqual( 64, strlen( ListingLock::name( PHP_INT_MAX ) ) );
	}
}
