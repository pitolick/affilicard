<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\QueueJobsPage;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class QueueJobsPageTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);
		WP_Mock::userFunction( 'esc_html__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);
		WP_Mock::userFunction( 'esc_html' )
			->andReturnUsing(
				static function ( $text ) {
					return (string) $text;
				}
			);
		WP_Mock::userFunction( 'esc_url' )
			->andReturnUsing(
				static function ( $text ) {
					return (string) $text;
				}
			);
	}

	public function tearDown(): void {
		unset( $_GET['s'], $_REQUEST['s'] );
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_registerMenu_add_submenu_pageを商品cptの子メニューとして呼び出す(): void {
		WP_Mock::userFunction( 'add_submenu_page' )
			->once()
			->andReturnUsing(
				function ( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback ) {
					$this->assertSame( 'edit.php?post_type=affilicard_product', $parent_slug );
					$this->assertSame( '更新キュー（ジョブ一覧）', $page_title );
					$this->assertSame( '更新キュー（ジョブ一覧）', $menu_title );
					$this->assertSame( 'manage_options', $capability );
					$this->assertSame( QueueJobsPage::MENU_SLUG, $menu_slug );
					$this->assertSame( array( QueueJobsPage::class, 'render' ), $callback );
					return 'affilicard_product_page_affilicard-queue-jobs';
				}
			);

		WP_Mock::expectActionAdded(
			'load-affilicard_product_page_affilicard-queue-jobs',
			array( QueueJobsPage::class, 'onLoad' )
		);

		QueueJobsPage::registerMenu();

		$this->assertConditionsMet();
	}

	/**
	 * add_submenu_page() が false（権限不足等）を返した場合、存在しないフック名
	 * （'load-' のみ等）で add_action しない。
	 */
	public function test_registerMenu_hook_suffixが取得できない場合はload_hookを配線しない(): void {
		WP_Mock::userFunction( 'add_submenu_page' )->once()->andReturn( false );
		WP_Mock::userFunction( 'add_action' )->never();

		QueueJobsPage::registerMenu();

		$this->assertConditionsMet();
	}

	public function test_onLoad_sが未指定なら検索既定値affilicardをgetとrequestの両方に補う(): void {
		unset( $_GET['s'], $_REQUEST['s'] );
		WP_Mock::userFunction( 'is_textdomain_loaded' )->with( 'action-scheduler' )->andReturn( true );

		QueueJobsPage::onLoad();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		$this->assertSame( 'affilicard', $_GET['s'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		$this->assertSame( 'affilicard', $_REQUEST['s'] );
	}

	/**
	 * ユーザーが検索欄を明示的にクリアした場合（s=空文字で isset は true）は
	 * 既定値で上書きしない。
	 */
	public function test_onLoad_sが空文字で明示指定されている場合は上書きしない(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		$_GET['s'] = '';
		WP_Mock::userFunction( 'is_textdomain_loaded' )->with( 'action-scheduler' )->andReturn( true );

		QueueJobsPage::onLoad();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		$this->assertSame( '', $_GET['s'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->assertArrayNotHasKey( 's', $_REQUEST );
	}

	public function test_onLoad_他の検索語が既に指定されている場合はそのまま維持する(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		$_GET['s'] = 'rakuten';
		WP_Mock::userFunction( 'is_textdomain_loaded' )->with( 'action-scheduler' )->andReturn( true );

		QueueJobsPage::onLoad();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		$this->assertSame( 'rakuten', $_GET['s'] );
	}

	public function test_maybeLoadJapaneseTranslations_既にロード済みならget_localeもload_textdomainも呼ばない(): void {
		unset( $_GET['s'] );
		WP_Mock::userFunction( 'is_textdomain_loaded' )->with( 'action-scheduler' )->andReturn( true );
		WP_Mock::userFunction( 'get_locale' )->never();
		WP_Mock::userFunction( 'load_textdomain' )->never();

		QueueJobsPage::onLoad();

		$this->assertConditionsMet();
	}

	public function test_maybeLoadJapaneseTranslations_ロケールがja始まりでなければload_textdomainを呼ばない(): void {
		unset( $_GET['s'] );
		WP_Mock::userFunction( 'is_textdomain_loaded' )->with( 'action-scheduler' )->andReturn( false );
		WP_Mock::userFunction( 'get_locale' )->andReturn( 'en_US' );
		WP_Mock::userFunction( 'load_textdomain' )->never();

		QueueJobsPage::onLoad();

		$this->assertConditionsMet();
	}

	/**
	 * ja ロケールかつ未ロードの場合、bundle した実ファイル
	 * languages/action-scheduler-ja.mo を load_textdomain('action-scheduler', ...) する。
	 * AFFILICARD_PLUGIN_DIR は tests/bootstrap.php でリポジトリルートを指すため、
	 * このパスは実際にファイルシステム上に存在する実ファイルである
	 * （is_readable() ガードは native PHP のためモック不要＝実ファイルで検証される）。
	 */
	public function test_maybeLoadJapaneseTranslations_jaロケールかつ未ロードならbundleしたmoをロードする(): void {
		unset( $_GET['s'] );
		WP_Mock::userFunction( 'is_textdomain_loaded' )->with( 'action-scheduler' )->andReturn( false );
		WP_Mock::userFunction( 'get_locale' )->andReturn( 'ja' );

		$loadedPath = null;
		WP_Mock::userFunction( 'load_textdomain' )
			->once()
			->andReturnUsing(
				function ( $domain, $path ) use ( &$loadedPath ) {
					$this->assertSame( 'action-scheduler', $domain );
					$loadedPath = $path;
					return true;
				}
			);

		QueueJobsPage::onLoad();

		$this->assertStringEndsWith( 'languages/action-scheduler-ja.mo', (string) $loadedPath );
		$this->assertFileExists( (string) $loadedPath, '実際に bundle した .mo ファイルが存在するべき' );
	}

	public function test_maybeLoadJapaneseTranslations_ja_JPのような地域付きロケールでもロードする(): void {
		unset( $_GET['s'] );
		WP_Mock::userFunction( 'is_textdomain_loaded' )->with( 'action-scheduler' )->andReturn( false );
		WP_Mock::userFunction( 'get_locale' )->andReturn( 'ja_JP' );
		WP_Mock::userFunction( 'load_textdomain' )->once()->andReturn( true );

		QueueJobsPage::onLoad();

		$this->assertConditionsMet();
	}

	/**
	 * ActionScheduler_AdminView が読み込まれていない（通常のユニットテストプロセスの状態。
	 * bundle した AS は ActionSchedulerLoader::boot() 経由でしか require されないため、
	 * このテストプロセスではロードされない）場合、render() はフェイタルせず
	 * 日本語の見出し・説明・Tools へのフォールバックリンクを出力する。
	 *
	 * ロード済みケース（defineFakeActionSchedulerAdminView を eval で定義）と同じプロセス分離に
	 * 揃え、他テストの実行順に依存せず「AS 未ロード」前提を確実にする。
	 *
	 * @runInSeparateProcess
	 */
	public function test_render_as管理ビュー未ロード時は日本語の見出しとtoolsリンクへフォールバックする(): void {
		$this->assertFalse(
			class_exists( 'ActionScheduler_AdminView', false ),
			'このテストは AS 未ロード前提（他テストで既にロードされていないこと）'
		);

		WP_Mock::userFunction( 'admin_url' )
			->with( 'tools.php?page=action-scheduler&s=affilicard' )
			->andReturn( 'https://wp.example.com/wp-admin/tools.php?page=action-scheduler&s=affilicard' );

		ob_start();
		QueueJobsPage::render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '更新キュー（ジョブ一覧）', $output );
		$this->assertStringContainsString( 'Action Scheduler の管理画面コンポーネントを読み込めなかった', $output );
		$this->assertStringContainsString(
			'https://wp.example.com/wp-admin/tools.php?page=action-scheduler&s=affilicard',
			$output
		);
	}

	public function test_toolsPageUrl_s_affilicardクエリ付きのtoolsリンクを返す(): void {
		WP_Mock::userFunction( 'admin_url' )
			->with( 'tools.php?page=action-scheduler&s=affilicard' )
			->andReturn( 'https://wp.example.com/wp-admin/tools.php?page=action-scheduler&s=affilicard' );

		$this->assertSame(
			'https://wp.example.com/wp-admin/tools.php?page=action-scheduler&s=affilicard',
			QueueJobsPage::toolsPageUrl()
		);
	}

	public function test_pageUrl_自身のサブメニューurlを返す(): void {
		WP_Mock::userFunction( 'admin_url' )
			->with( 'edit.php?post_type=affilicard_product&page=affilicard-queue-jobs' )
			->andReturn( 'https://wp.example.com/wp-admin/edit.php?post_type=affilicard_product&page=affilicard-queue-jobs' );

		$this->assertSame(
			'https://wp.example.com/wp-admin/edit.php?post_type=affilicard_product&page=affilicard-queue-jobs',
			QueueJobsPage::pageUrl()
		);
	}

	/**
	 * ActionScheduler_AdminView がロード済みの場合、render()/onLoad() は
	 * render_admin_ui()/process_admin_ui() に処理を委譲する（フォールバックは出さない）。
	 *
	 * 実際の AS 内部（ActionScheduler_ListTable/Store 等）は unit テストでは重すぎるため、
	 * WooCommerce と同じ埋め込み契約（instance()/render_admin_ui()/process_admin_ui() が
	 * public であること）だけを検証するスタブクラスを、他テストを汚染しないよう
	 * 隔離プロセスでグローバル名前空間に定義する。
	 *
	 * @runInSeparateProcess
	 */
	public function test_render_as管理ビューロード済み時はrender_admin_uiへ委譲しフォールバックを出さない(): void {
		self::defineFakeActionSchedulerAdminView();

		ob_start();
		QueueJobsPage::render();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, \ActionScheduler_AdminView::$renderCalls );
		$this->assertStringContainsString( '更新キュー（ジョブ一覧）', $output );
		$this->assertStringContainsString( 'AS-EMBED-MARKER', $output );
		$this->assertStringNotContainsString( '読み込めなかった', $output );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_onLoad_as管理ビューロード済み時はprocess_admin_uiを呼ぶ(): void {
		self::defineFakeActionSchedulerAdminView();

		unset( $_GET['s'] );
		WP_Mock::userFunction( 'is_textdomain_loaded' )->with( 'action-scheduler' )->andReturn( true );

		QueueJobsPage::onLoad();

		$this->assertSame( 1, \ActionScheduler_AdminView::$processCalls );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		$this->assertSame( 'affilicard', $_GET['s'] );
	}

	/**
	 * WooCommerce と同じ埋め込み契約（`instance()`/`render_admin_ui()`/`process_admin_ui()` が
	 * public であること）だけを検証する最小スタブを、実際の AS 内部
	 * （ActionScheduler_ListTable/Store 等・unit テストには重すぎる）の代わりに
	 * グローバル名前空間へ定義する。呼び出し元テストは必ず `@runInSeparateProcess` にして、
	 * このクラス定義が他テストへ漏れないようにすること。
	 */
	private static function defineFakeActionSchedulerAdminView(): void {
		if ( class_exists( 'ActionScheduler_AdminView', false ) ) {
			return;
		}

		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged
			'class ActionScheduler_AdminView {'
			. 'public static $renderCalls = 0;'
			. 'public static $processCalls = 0;'
			. 'public static function instance() { return new self(); }'
			. 'public function render_admin_ui() { self::$renderCalls++; echo "AS-EMBED-MARKER"; }'
			. 'public function process_admin_ui() { self::$processCalls++; }'
			. '}'
		);
	}
}
