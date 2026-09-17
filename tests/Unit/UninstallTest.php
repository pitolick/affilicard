<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit;

use Affilicard\Uninstall;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class UninstallTest extends TestCase {

	/** @var list<array{0:string,1:int,2:string,3:mixed,4:bool}> */
	private array $deletedMeta = array();

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		// run() は必ずユーザーメタの一括削除を通る。**捕捉はここでしか行えない**
		// ——WP_Mock::userFunction() を同じ関数名で再登録しても最初の期待が残り、
		// 個別テストでの上書きは黙って無視されるため。
		$this->deletedMeta = array();
		WP_Mock::userFunction( 'delete_metadata' )
			->andReturnUsing(
				function ( $type, $objectId, $key, $value, $deleteAll ): bool {
					$this->deletedMeta[] = array( $type, $objectId, $key, $value, $deleteAll );
					return true;
				}
			);
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
	 * provider credentials の一括 DELETE を捕捉する $wpdb モックを $GLOBALS に設定する。
	 *
	 * @param array<int, string> $captured DELETE に渡された option_name LIKE 値を蓄積する参照。
	 */
	private function mockWpdb( array &$captured ): void {
		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static function ( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}
		);
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $query, $arg ): string {
				return str_replace( '%s', (string) $arg, $query );
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( string $query ) use ( &$captured ) {
				$captured[] = $query;
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * cleanupQueue() が呼ぶ as_unschedule_all_actions と ratelimit option の delete_option を
	 * 汎用スタブする（queue クリーンアップの詳細を個別検証しないテスト用。詳細は
	 * test_run_はprovider別groupのpendingスケジュールをunscheduleする 等の専用テストで検証する）。
	 */
	private function stubQueueCleanup(): void {
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->andReturn( null );
		WP_Mock::userFunction( 'delete_option' )
			->withArgs(
				static function ( string $key ): bool {
					return str_starts_with( $key, 'affilicard_ratelimit_' );
				}
			)
			->andReturn( true );
	}

	/**
	 * アンインストールでユーザーメタも消す。
	 *
	 * OPTION_KEYS の掃除は options テーブルしか触らないため、移行通知の「閉じた」印は
	 * ユーザーメタとして全ユーザーに残り続けていた。ユーザー数ぶん個別に消すのは
	 * 現実的でないので delete-all で一括削除する。
	 */
	public function test_run_はユーザーメタも全ユーザーぶん消す(): void {
		$captured = array();
		$this->mockWpdb( $captured );
		$this->stubQueueCleanup();
		WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		WP_Mock::userFunction( 'get_posts' )->andReturn( array() );

		Uninstall::run();

		// **3 つの dismiss 記録をすべて確かめる。** 以前は温存通知の 1 つしか見て
		// いなかったため、残り 2 つは削除をやめても誰も気づけなかった。
		$expected = array(
			'affilicard_offers_migration_notice_dismissed',
			'affilicard_offers_migration_failed_notice_dismissed',
			'affilicard_derived_meta_unsynced_notice_dismissed',
		);
		foreach ( $expected as $meta_key ) {
			$this->assertContains(
				array( 'user', 0, $meta_key, '', true ),
				$this->deletedMeta,
				$meta_key . ' を全ユーザーぶん消していない'
			);
		}
	}

	public function test_run_deletes_known_options_and_all_products(): void {
		$captured = array();
		$this->mockWpdb( $captured );

		foreach ( Uninstall::OPTION_KEYS as $option_key ) {
			WP_Mock::userFunction( 'delete_option' )
				->once()
				->with( $option_key )
				->andReturn( true );
		}
		$this->stubQueueCleanup();

		WP_Mock::userFunction( 'get_posts' )
			->once()
			->with(
				WP_Mock\Functions::type( 'array' )
			)
			->andReturnUsing(
				function ( array $args ) {
					$this->assertSame( 'affilicard_product', $args['post_type'] );
					$this->assertSame( 'any', $args['post_status'] );
					$this->assertSame( -1, $args['numberposts'] );
					$this->assertSame( 'ids', $args['fields'] );
					return array( 101, 202, 303 );
				}
			);

		WP_Mock::userFunction( 'wp_delete_post' )
			->times( 3 )
			->with( WP_Mock\Functions::type( 'int' ), true )
			->andReturn( true );

		Uninstall::run();

		$this->assertConditionsMet();
	}

	public function test_run_skips_wp_delete_post_when_no_products_exist(): void {
		$captured = array();
		$this->mockWpdb( $captured );

		foreach ( Uninstall::OPTION_KEYS as $option_key ) {
			WP_Mock::userFunction( 'delete_option' )
				->once()
				->with( $option_key )
				->andReturn( true );
		}
		$this->stubQueueCleanup();

		WP_Mock::userFunction( 'get_posts' )
			->once()
			->andReturn( array() );

		// wp_delete_post should NOT be called.
		WP_Mock::userFunction( 'wp_delete_post' )
			->never();

		Uninstall::run();

		$this->assertConditionsMet();
	}

	public function test_option_keys_include_currently_written_settings(): void {
		$this->assertContains( \Affilicard\Platform\PlatformConfig::OPTION_KEY, Uninstall::OPTION_KEYS );
		$this->assertContains( \Affilicard\Settings\GeneralSettings::OPTION_KEY, Uninstall::OPTION_KEYS );
		$this->assertContains( \Affilicard\Plugin::SEEDED_AT_OPTION, Uninstall::OPTION_KEYS );
		$this->assertContains( \Affilicard\Upgrade\PluginUpgrade::OPTION_VERSION, Uninstall::OPTION_KEYS );
		$this->assertContains( \Affilicard\Upgrade\PluginUpgrade::OPTION_STOCKTAKE_BASELINE, Uninstall::OPTION_KEYS );
		// 本ブランチ（spec 2026-08-25 §4-2/§4-4/§6-2）で追加した 2 option。ここに追記漏れると
		// アンインストール→再インストールで前回の走査位置・完走時刻が残留する
		// （final-fix-report.md Important 1）。
		$this->assertContains( \Affilicard\Queue\SweepCursor::OPTION_KEY, Uninstall::OPTION_KEYS );
		$this->assertContains( \Affilicard\Queue\QueueMaintenance::OPTION_LAST_COMPLETED, Uninstall::OPTION_KEYS );
	}

	/**
	 * option を書くクラスの `OPTION_*` 定数は、すべて OPTION_KEYS に載っている。
	 *
	 * **手書きのリストと突き合わせない。** 以前はここに「移行が書く 3 option」を
	 * 書き写していたため、あとから増えた 3 つ（試行回数・移行失敗の件数と post ID）が
	 * 検証の外にこぼれていた——次に足す人も同じようにこぼす。定数そのものを列挙すれば、
	 * 新しい option を足した時点でこのテストが自動的にそれを要求する。
	 *
	 * 漏れるとアンインストール→再インストールで「未完」の印・温存件数・移行失敗の記録が
	 * 残留し、通知が出続ける（CodeRabbit Minor #4: OPTION_MIGRATION_PRESERVED_POST_IDS が
	 * 抜けていた）。
	 *
	 * @dataProvider optionWritingClasses
	 */
	public function test_option定数はすべてOPTION_KEYSに載っている( string $className ): void {
		$constants = ( new \ReflectionClass( $className ) )->getConstants();

		$found = false;
		foreach ( $constants as $name => $value ) {
			if ( ! str_starts_with( (string) $name, 'OPTION_' ) ) {
				continue;
			}
			$found = true;
			$this->assertContains(
				$value,
				Uninstall::OPTION_KEYS,
				$className . '::' . $name . ' が Uninstall::OPTION_KEYS に無い（アンインストール後も残留する）'
			);
		}

		// 定数の命名規約が変わって 1 件も拾えなくなった場合に、
		// 「全部通った」と見えないようにする。
		$this->assertTrue( $found, $className . ' から OPTION_* 定数を 1 つも拾えていない' );
	}

	/**
	 * `OPTION_*` 定数で option キーを持つクラス。
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function optionWritingClasses(): array {
		return array(
			'offers-migration'  => array( \Affilicard\Upgrade\PluginUpgrade::class ),
			'derived-meta-sync' => array( \Affilicard\Repository\DerivedMetaSync::class ),
		);
	}

	/**
	 * cleanupQueue() が unschedule する 'affilicard-migration' は
	 * PluginUpgrade::MIGRATION_GROUP と同じ値である（Uninstall.php 自身は vendor/ 不在
	 * フォールバックのため当該クラスを参照できずリテラルで持つ——'affilicard-sweep' と同じ理由）。
	 */
	public function test_migrationグループのリテラルはPluginUpgradeの定数と一致する(): void {
		$this->assertSame( \Affilicard\Upgrade\PluginUpgrade::MIGRATION_GROUP, 'affilicard-migration' );
	}

	public function test_run_deletes_provider_credentials_via_wpdb_like(): void {
		$captured = array();
		$this->mockWpdb( $captured );

		WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		$this->stubQueueCleanup();
		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array() );
		WP_Mock::userFunction( 'wp_delete_post' )->never();

		Uninstall::run();

		$this->assertCount( 2, $captured, 'provider credentials と account credentials の DELETE がそれぞれ 1 回ずつ実行されること' );
		$this->assertStringContainsString( 'DELETE FROM wp_options', $captured[0] );
		$this->assertStringContainsString( 'affilicard', $captured[0] );
		$this->assertStringContainsString( 'provider', $captured[0] );
		$this->assertStringContainsString( 'LIKE', $captured[0] );
	}

	public function test_run_deletes_account_credentials_via_wpdb_like(): void {
		$captured = array();
		$this->mockWpdb( $captured );

		WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		$this->stubQueueCleanup();
		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array() );
		WP_Mock::userFunction( 'wp_delete_post' )->never();

		Uninstall::run();

		$this->assertCount( 2, $captured, 'provider credentials と account credentials の DELETE がそれぞれ 1 回ずつ実行されること' );
		$this->assertStringContainsString( 'DELETE FROM wp_options', $captured[1] );
		$this->assertStringContainsString( 'affilicard', $captured[1] );
		$this->assertStringContainsString( 'account', $captured[1] );
		$this->assertStringContainsString( 'LIKE', $captured[1] );
	}

	/**
	 * spec §9-7 / v2.4.0: uninstall は account 別 group（`affilicard-{account}`）の pending
	 * スケジュールを as_unschedule_all_actions で解除する（provider コード単位から account
	 * コード単位へ統一）。AS 自身のテーブルは他プラグイン共有のため drop しない（unschedule のみ）。
	 */
	public function test_run_はaccount別groupのpendingスケジュールをunscheduleする(): void {
		$captured = array();
		$this->mockWpdb( $captured );

		WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array() );
		WP_Mock::userFunction( 'wp_delete_post' )->never();

		WP_Mock::userFunction( 'as_unschedule_all_actions' )
			->once()
			->with( '', array(), 'affilicard-dmm' )
			->andReturn( null );
		WP_Mock::userFunction( 'as_unschedule_all_actions' )
			->once()
			->with( '', array(), 'affilicard-rakuten' )
			->andReturn( null );
		// v3.5.0: 掃引トリガー（affilicard_sweep）の group（'affilicard-sweep'）も
		// account 別 group と同様に unschedule する。
		WP_Mock::userFunction( 'as_unschedule_all_actions' )
			->once()
			->with( '', array(), 'affilicard-sweep' )
			->andReturn( null );
		// offers 移行バッチの group も同様に unschedule する（移行が未完のまま
		// アンインストールされると継続ジョブが pending で残る）。
		WP_Mock::userFunction( 'as_unschedule_all_actions' )
			->once()
			->with( '', array(), 'affilicard-migration' )
			->andReturn( null );

		Uninstall::run();

		$this->assertConditionsMet();
	}

	/**
	 * v3.5.0 / spec 2026-08-25 §4-2: cleanupQueue() が unschedule する 'affilicard-sweep'
	 * は Enqueuer::group( Enqueuer::SWEEP_GROUP_ACCOUNT ) と同じ値である（両者がドリフトしない
	 * ことをテスト側で突き合わせる。Uninstall.php 自身は vendor/ 不在フォールバックのため
	 * Enqueuer クラスを参照できずリテラルで持つ——final-fix-report.md Important 1 と同じ理由）。
	 */
	public function test_sweepグループのリテラルはEnqueuerのgroup組み立てと一致する(): void {
		$this->assertSame(
			( new \Affilicard\Queue\Enqueuer() )->group( \Affilicard\Queue\Enqueuer::SWEEP_GROUP_ACCOUNT ),
			'affilicard-sweep'
		);
	}

	/**
	 * spec §9-7 / v2.4.0: uninstall は自前オプション（throttle 設定）も削除する対象に含む。
	 * RateLimiter が account 別に書き込む `affilicard_ratelimit_{account}` option を削除する
	 * （provider コード単位から account コード単位へ統一）。
	 */
	public function test_run_はaccount別のratelimitオプションを削除する(): void {
		$captured = array();
		$this->mockWpdb( $captured );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->andReturn( null );
		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array() );
		WP_Mock::userFunction( 'wp_delete_post' )->never();

		WP_Mock::userFunction( 'delete_option' )
			->once()
			->with( 'affilicard_ratelimit_dmm' )
			->andReturn( true );
		WP_Mock::userFunction( 'delete_option' )
			->once()
			->with( 'affilicard_ratelimit_rakuten' )
			->andReturn( true );
		// 'manual' provider は isAutomatic()===false のため ratelimit option を持たず対象外。
		WP_Mock::userFunction( 'delete_option' )
			->with( 'affilicard_ratelimit_manual' )
			->never();
		WP_Mock::userFunction( 'delete_option' )->andReturn( true );

		Uninstall::run();

		$this->assertConditionsMet();
	}
}
