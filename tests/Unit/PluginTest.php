<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Plugin;
use Affilicard\Provider\Dmm\DmmProvider;
use Affilicard\Provider\ManualProvider;
use Affilicard\Types\EbookType;
use Affilicard\Types\GenericType;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class PluginTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_buildProviderRegistry_includes_manual_and_dmm_ebook(): void {
		$registry = Plugin::buildProviderRegistry();

		$codes = $registry->codes();
		$this->assertContains( ( new ManualProvider() )->code(), $codes );
		$this->assertContains( ( new DmmProvider() )->code(), $codes );
		$this->assertContains( 'manual', $codes );
		$this->assertContains( 'dmm-ebook', $codes );
	}

	/**
	 * QueueStats/QueueController に渡すのは isAutomatic()===true な provider の
	 * accountCode()（'manual' 等・accountCode()===null の provider を含まない）。
	 * v2.4.0: provider コード単位から account コード単位へ統一（レート制限は共有 API＝
	 * account 単位でかかり、認証画面（楽天/DMM）と一致させるため）。楽天/DMM をハードコード
	 * せず ProviderRegistry から導出することを固定する（Task 15 要件・v2.4.0 で account 化）。
	 */
	public function test_automaticAccountCodes_isAutomaticなproviderのaccountCodeのみを含みmanualを除く(): void {
		$registry = Plugin::buildProviderRegistry();

		$codes = Plugin::automaticAccountCodes( $registry );

		$this->assertContains( 'rakuten', $codes );
		$this->assertContains( 'dmm', $codes );
		$this->assertNotContains( 'manual', $codes );
		$this->assertNotContains( 'rakuten-kobo', $codes );
		$this->assertNotContains( 'dmm-ebook', $codes );

		foreach ( $registry->all() as $provider ) {
			if ( ! $provider->isAutomatic() ) {
				continue;
			}
			$this->assertContains( $provider->accountCode(), $codes );
		}
	}

	/**
	 * 現状 1 account = 1 自動 provider だが、将来複数 provider が同じ account を共有しても
	 * accountCode 一覧に重複が出ないことを固定する。
	 */
	public function test_automaticAccountCodes_accountCodeは重複排除される(): void {
		$registry = Plugin::buildProviderRegistry();

		$codes = Plugin::automaticAccountCodes( $registry );

		$this->assertSame( array_values( array_unique( $codes ) ), array_values( $codes ) );
	}

	public function test_buildProductTypeRegistry_includes_generic_and_ebook(): void {
		$registry = Plugin::buildProductTypeRegistry();

		$codes = $registry->codes();
		$this->assertContains( ( new GenericType() )->code(), $codes );
		$this->assertContains( ( new EbookType() )->code(), $codes );
		$this->assertContains( 'generic', $codes );
		$this->assertContains( 'ebook', $codes );
	}

	public function test_onActivate_is_idempotent_when_seeded_at_already_set(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( Plugin::SEEDED_AT_OPTION, false )
			->andReturn( '2026-01-01T00:00:00+00:00' );

		// seeded 済みなら PlatformConfig::save の前提となる update_option / get_option (defaults 読み出し) は呼ばない。
		WP_Mock::userFunction( 'update_option' )
			->never();

		Plugin::onActivate();

		$this->assertConditionsMet();
	}

	public function test_onActivate_seeds_defaults_and_records_timestamp_on_fresh_install(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( Plugin::SEEDED_AT_OPTION, false )
			->andReturn( false );

		// PlatformConfig::save 内部で defaults 個数分の update_option が走る。
		// ここでは「affilicard_seeded_at が確実に書かれる」ことと
		// 「PlatformConfig::OPTION_KEY (affilicard_platforms) が書かれる」ことを検査する。
		$seeded_recorded   = false;
		$platforms_written = false;
		WP_Mock::userFunction( 'update_option' )
			->andReturnUsing(
				function ( $key, $value, $autoload = false ) use ( &$seeded_recorded, &$platforms_written ) {
					if ( PlatformConfig::OPTION_KEY === $key ) {
						$platforms_written = true;
						$this->assertIsArray( $value );
						$this->assertNotEmpty( $value );
					}
					if ( Plugin::SEEDED_AT_OPTION === $key ) {
						$seeded_recorded = true;
						$this->assertIsString( $value );
						$this->assertFalse( $autoload );
					}
					return true;
				}
			);

		Plugin::onActivate();

		$this->assertTrue( $platforms_written, 'PlatformConfig::save がデフォルト platform を書き出すべき' );
		$this->assertTrue( $seeded_recorded, 'affilicard_seeded_at オプションが記録されるべき' );
	}

	/**
	 * Fix 3: 純 affilicard サイト（WooCommerce 不在 → フィルタ既定 true）では
	 * Tools > Scheduled Actions の重複サブメニューを remove_submenu_page で隠す。
	 */
	public function test_hideActionSchedulerToolsMenu_removes_submenu_when_filter_true(): void {
		WP_Mock::onFilter( 'affilicard_hide_action_scheduler_tools_menu' )
			->with( true )
			->reply( true );
		WP_Mock::userFunction( 'remove_submenu_page' )
			->once()
			->with( 'tools.php', 'action-scheduler' );

		Plugin::hideActionSchedulerToolsMenu();

		$this->assertConditionsMet();
	}

	/**
	 * Fix 3: フィルタで false を返すサイト（例: 他の AS 消費プラグインを持つ）では
	 * Tools > Scheduled Actions を隠さない（remove_submenu_page を呼ばない）。
	 * これにより WooCommerce 等の AS 利用者の管理画面を壊さない。
	 */
	public function test_hideActionSchedulerToolsMenu_keeps_submenu_when_filter_false(): void {
		WP_Mock::onFilter( 'affilicard_hide_action_scheduler_tools_menu' )
			->with( true )
			->reply( false );
		WP_Mock::userFunction( 'remove_submenu_page' )->never();

		Plugin::hideActionSchedulerToolsMenu();

		$this->assertConditionsMet();
	}

	/**
	 * Fix 3: 管理画面では admin_menu に priority 99（AS 本体のサブメニュー登録より後）で
	 * hideActionSchedulerToolsMenu を配線する。
	 */
	public function test_boot_registers_hide_action_scheduler_tools_menu_hook_in_admin(): void {
		WP_Mock::userFunction( 'is_admin', array( 'return' => true ) );
		WP_Mock::userFunction( 'register_activation_hook', array( 'return' => true ) );
		WP_Mock::userFunction( 'register_deactivation_hook', array( 'return' => true ) );

		WP_Mock::expectActionAdded(
			'admin_menu',
			array( Plugin::class, 'hideActionSchedulerToolsMenu' ),
			99
		);

		Plugin::boot();

		$this->assertConditionsMet();
	}

	public function test_registerSettingsPage_calls_add_submenu_page_under_cpt(): void {
		WP_Mock::userFunction( 'add_submenu_page' )
			->once()
			->andReturnUsing(
				function ( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback ) {
					$this->assertSame( 'edit.php?post_type=affilicard_product', $parent_slug );
					$this->assertSame( 'manage_options', $capability );
					$this->assertSame( 'affilicard-settings', $menu_slug );
					$this->assertSame( array( Plugin::class, 'renderSettingsPage' ), $callback );
					return 'affilicard_product_page_affilicard-settings';
				}
			);

		Plugin::registerSettingsPage();

		$this->assertConditionsMet();
	}

	/**
	 * Major（CodeRabbit レビュー）: BatchRefreshHandler の JobDeadline が「ジョブ自身の
	 * 開始時刻」ではなく「AS ランナー全体の開始時刻」を見て期限判定できるよう、
	 * RunnerClock::register() が boot() から確実に呼ばれ、AS 自身が内部で使う public フック
	 * `action_scheduler_before_process_queue` に markStarted が配線されることを固定する。
	 */
	public function test_boot_registers_runner_clock_hook(): void {
		WP_Mock::userFunction( 'is_admin', array( 'return' => false ) );
		WP_Mock::userFunction( 'register_activation_hook', array( 'return' => true ) );
		WP_Mock::userFunction( 'register_deactivation_hook', array( 'return' => true ) );

		WP_Mock::expectActionAdded(
			'action_scheduler_before_process_queue',
			array( \Affilicard\Queue\RunnerClock::class, 'markStarted' )
		);

		Plugin::boot();

		$this->assertConditionsMet();
	}

	public function test_boot_registers_block_init_hook(): void {
		WP_Mock::userFunction( 'is_admin', array( 'return' => false ) );
		WP_Mock::userFunction( 'register_activation_hook', array( 'return' => true ) );
		WP_Mock::userFunction( 'register_deactivation_hook', array( 'return' => true ) );

		// WP_Mock::add_action は userFunction でオーバーライドできないため、
		// WP_Mock ネイティブの expectActionAdded + AnyInstance マッチャーを使用する。
		WP_Mock::expectActionAdded(
			'init',
			array( new \WP_Mock\Matcher\AnyInstance( \Affilicard\Block\Block::class ), 'register' )
		);

		// Cron: 全体単一イベントのハンドラ登録
		WP_Mock::expectActionAdded( \Affilicard\Cron\RefreshScheduler::HOOK_ALL, \WP_Mock\Functions::type( 'callable' ) );

		// Cron: init 時の reconcile
		WP_Mock::expectActionAdded( 'init', array( \Affilicard\Cron\RefreshScheduler::class, 'reconcile' ) );

		// 予約投稿昇格時の refresh
		WP_Mock::expectActionAdded( 'transition_post_status', array( Plugin::class, 'onTransitionPostStatus' ), 10, 3 );

		Plugin::boot();

		$this->assertConditionsMet();
	}

	public function test_boot_registers_queue_handlers_and_publish_trigger_hooks(): void {
		// v2.4.0 で追加された 3 配線（RefreshHandler/AutoCreateHandler/PublishTrigger）が
		// Plugin::boot() から確実に登録されることを検証する。これが欠けると Enqueuer が積んだ
		// ジョブが Action Scheduler 上に滞留したまま一切実行されなくなる、キュー機能の生命線。
		WP_Mock::userFunction( 'is_admin', array( 'return' => false ) );
		WP_Mock::userFunction( 'register_activation_hook', array( 'return' => true ) );
		WP_Mock::userFunction( 'register_deactivation_hook', array( 'return' => true ) );

		// RefreshHandler::handle / AutoCreateHandler::handle は bootInstance() 内で生成される
		// インスタンスメソッド配列コールバックのため、インスタンス自体は特定できない。
		// block init hook のテストと同じ手法（AnyInstance マッチャー）でクラス型のみ厳密に検査する。
		WP_Mock::expectActionAdded(
			\Affilicard\Queue\Enqueuer::HOOK_REFRESH,
			array( new \WP_Mock\Matcher\AnyInstance( \Affilicard\Queue\RefreshHandler::class ), 'handle' ),
			10,
			2
		);
		WP_Mock::expectActionAdded(
			\Affilicard\Queue\Enqueuer::HOOK_AUTOCREATE,
			array( new \WP_Mock\Matcher\AnyInstance( \Affilicard\Queue\AutoCreateHandler::class ), 'handle' ),
			10,
			2
		);

		// transition_post_status は本テストで 2 系統登録される：
		// 1) 既存の静的 [Plugin::class, 'onTransitionPostStatus']（商品 CPT 自身の future→publish）
		// 2) 新規の PublishTrigger インスタンスコールバック（記事公開時に本文中の商品を force enqueue）
		// 両方を expect しないと、片方が削除されてももう一方の一致だけでテストが緑のままになる。
		WP_Mock::expectActionAdded( 'transition_post_status', array( Plugin::class, 'onTransitionPostStatus' ), 10, 3 );
		WP_Mock::expectActionAdded(
			'transition_post_status',
			array( new \WP_Mock\Matcher\AnyInstance( \Affilicard\Queue\PublishTrigger::class ), 'onTransition' ),
			10,
			3
		);

		Plugin::boot();

		$this->assertConditionsMet();
	}

	/**
	 * Task 12: HOOK_REFRESH_BATCH のラッパ（クロージャ）と affilicard_sweep ハンドラが
	 * Plugin::boot() から確実に登録されることを固定する。これが欠けると Enqueuer が積んだ
	 * バッチジョブ／掃引トリガーが Action Scheduler 上に滞留したまま一切実行されない。
	 */
	public function test_boot_registers_batch_refresh_and_sweep_action_hooks(): void {
		WP_Mock::userFunction( 'is_admin', array( 'return' => false ) );
		WP_Mock::userFunction( 'register_activation_hook', array( 'return' => true ) );
		WP_Mock::userFunction( 'register_deactivation_hook', array( 'return' => true ) );

		WP_Mock::expectActionAdded(
			\Affilicard\Queue\Enqueuer::HOOK_REFRESH_BATCH,
			Mockery::type( 'Closure' ),
			10,
			2
		);
		WP_Mock::expectActionAdded(
			\Affilicard\Queue\Enqueuer::HOOK_SWEEP,
			Mockery::type( 'Closure' )
		);

		Plugin::boot();

		$this->assertConditionsMet();
	}

	/**
	 * 先行タスクからの申し送り: BatchRefreshHandler::handle(array $args) は AS フックへ
	 * 直付けできない（Action Scheduler は do_action_ref_array($hook, array_values($args))
	 * で args を位置引数に展開するため、直付けすると第1引数に文字列 'account' が渡り
	 * TypeError になる）。boot() が配線するクロージャは WP_Mock では捕捉して直接呼び出せない
	 * ため（add_action/add_filter は WP_Mock 組み込みの hook 追跡機構が常に使われ、
	 * userFunction() では横取りできない）、実体である handleBatchRefreshAction() を直接
	 * 呼び出し、[account, items] の位置引数が正しく配列へ組み直されて
	 * BatchRefreshHandler::handle() に届くことを、pause 分岐
	 * （GeneralSettings::isQueuePaused()）経由の as_schedule_single_action 呼び出し引数
	 * （group='affilicard-rakuten' 等）で確認する。
	 */
	public function test_handleBatchRefreshAction_位置引数を配列へ正しく組み直す(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( \Affilicard\Settings\GeneralSettings::OPTION_KEY, array() )
			->andReturn( array( 'queue_paused' => true ) );

		$items = array(
			array(
				'post_id'  => 1,
				'platform' => 'rakuten-kobo',
			),
		);

		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::type( 'int' ),
				\Affilicard\Queue\Enqueuer::HOOK_REFRESH_BATCH,
				array(
					'account' => 'rakuten',
					'items'   => $items,
				),
				'affilicard-rakuten',
				false,
				\Affilicard\Queue\Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 500 );

		$handler = new \Affilicard\Queue\BatchRefreshHandler(
			new \Affilicard\Queue\Enqueuer(),
			new \Affilicard\Queue\RateLimiter(),
			Mockery::mock( \Affilicard\Cron\ListingRefresher::class ),
			new \Affilicard\Provider\ProviderRegistry()
		);

		Plugin::handleBatchRefreshAction( $handler, 'rakuten', $items );

		$this->assertConditionsMet();
	}

	/**
	 * 位置引数が文字列でない（AS から届く $account/$items の型が想定外の）場合でも
	 * 落ちずに空扱いへフォールバックすることを固定する（(string) キャスト・is_array ガード）。
	 */
	public function test_handleBatchRefreshAction_itemsが配列でない場合は空扱いで即returnする(): void {
		WP_Mock::userFunction( 'get_option' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$handler = new \Affilicard\Queue\BatchRefreshHandler(
			new \Affilicard\Queue\Enqueuer(),
			new \Affilicard\Queue\RateLimiter(),
			Mockery::mock( \Affilicard\Cron\ListingRefresher::class ),
			new \Affilicard\Provider\ProviderRegistry()
		);

		Plugin::handleBatchRefreshAction( $handler, 'rakuten', null );

		$this->assertConditionsMet();
	}

	/**
	 * Task 12・Ruling 3: WP-Cron（affilicard_refresh_all）のハンドラは掃引トリガー
	 * （affilicard_sweep）を unique=true で 1 件積むだけにする（多重起動防止）。
	 * boot() 内のクロージャは WP_Mock では捕捉できないため（上記と同じ理由）、
	 * 実体である triggerSweep() を直接呼び出して確認する。
	 */
	public function test_triggerSweep_affilicard_sweepをunique_trueで積む(): void {
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::type( 'int' ),
				\Affilicard\Queue\Enqueuer::HOOK_SWEEP,
				array(),
				'affilicard-sweep',
				true,
				\Affilicard\Queue\Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 400 );

		Plugin::triggerSweep( new \Affilicard\Queue\Enqueuer() );

		$this->assertConditionsMet();
	}

	/**
	 * Task 12・Ruling 3（核心）: QueueMaintenance::sweep() が false（未完走）を返したときだけ、
	 * 同じ掃引トリガーを unique=false で即時に積み直して継続する。ここを誤ると継続更新が
	 * 止まるため、false→積む／true→積まない の両方を固定する。
	 */
	public function test_handleSweepCompletion_falseなら継続トリガーをunique_falseで積む(): void {
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::type( 'int' ),
				\Affilicard\Queue\Enqueuer::HOOK_SWEEP,
				array(),
				'affilicard-sweep',
				false,
				\Affilicard\Queue\Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 0 );

		Plugin::handleSweepCompletion( false, new \Affilicard\Queue\Enqueuer() );

		$this->assertConditionsMet();
	}

	public function test_handleSweepCompletion_trueなら継続トリガーを積まない(): void {
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		Plugin::handleSweepCompletion( true, new \Affilicard\Queue\Enqueuer() );

		$this->assertConditionsMet();
	}

	/**
	 * Task 12・Ruling 8: delaySeconds > 0 を渡すと、その秒数だけ未来の時刻に
	 * 継続トリガーを積む（即時ではない）。
	 */
	public function test_handleSweepCompletion_delaySecondsを渡すと遅延した時刻に積む(): void {
		$before = time();
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::on(
					static function ( $when ) use ( $before ) {
						return $when >= $before + 590 && $when <= $before + 610;
					}
				),
				\Affilicard\Queue\Enqueuer::HOOK_SWEEP,
				array(),
				'affilicard-sweep',
				false,
				\Affilicard\Queue\Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 0 );

		Plugin::handleSweepCompletion( false, new \Affilicard\Queue\Enqueuer(), 600 );

		$this->assertConditionsMet();
	}

	/**
	 * これは私（コントローラ）の Ruling 3 の不備を修正するもの（Ruling 8）:
	 * 「false なら即時に積み直す」だけでは、pause 中でも sweep() を呼び直し続けて
	 * しまう。pause 中は sweep() を一切呼ばず（get_posts 等を何もスタブしないことで
	 * 「呼ばれれば即失敗する」形で保証する）、SWEEP_STALLED_RETRY_SECONDS 秒後へ
	 * 遅延して積み直す（unique=false でジョブは失わない）。
	 */
	public function test_handleSweepAction_pause中はsweepを呼ばず遅延再投入する(): void {
		$before = time();
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::on(
					static function ( $when ) use ( $before ) {
						return $when >= $before + Plugin::SWEEP_STALLED_RETRY_SECONDS - 5
							&& $when <= $before + Plugin::SWEEP_STALLED_RETRY_SECONDS + 5;
					}
				),
				\Affilicard\Queue\Enqueuer::HOOK_SWEEP,
				array(),
				'affilicard-sweep',
				false,
				\Affilicard\Queue\Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 0 );

		$repo = Mockery::mock( \Affilicard\Repository\ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );
		$maintenance = new \Affilicard\Queue\QueueMaintenance(
			$repo,
			new \Affilicard\Queue\Enqueuer(),
			new \Affilicard\Provider\ProviderRegistry()
		);

		Plugin::handleSweepAction( true, $maintenance, new \Affilicard\Queue\Enqueuer() );

		$this->assertConditionsMet();
	}

	/**
	 * Ruling 8（核心の失敗シナリオ）: queue_depth_cap に張り付いていて前進できない
	 * （QueueMaintenance::queueAtCapacity()===true）ときも sweep() を呼ばず遅延して
	 * 積み直す。これが無いと、pending 深さが cap を下回らない限り cursor が一切
	 * 進まないまま同じ空振りクエリ（get_posts / as_get_scheduled_actions）を
	 * 無限に繰り返し、completed アクションのチャーンを再発させる。
	 */
	public function test_handleSweepAction_cap到達時はsweepを呼ばず遅延再投入する(): void {
		// queueAtCapacity(): queueDepth()（既定 depthCap=500）を cap 以上にする。
		WP_Mock::userFunction( 'as_get_scheduled_actions' )->andReturn( array_fill( 0, 500, 1 ) );

		$before = time();
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::on(
					static function ( $when ) use ( $before ) {
						return $when >= $before + Plugin::SWEEP_STALLED_RETRY_SECONDS - 5
							&& $when <= $before + Plugin::SWEEP_STALLED_RETRY_SECONDS + 5;
					}
				),
				\Affilicard\Queue\Enqueuer::HOOK_SWEEP,
				array(),
				'affilicard-sweep',
				false,
				\Affilicard\Queue\Enqueuer::PRIORITY_SWEEP
			)
			->andReturn( 0 );

		// sweep() 本体（get_posts 等）が絶対に呼ばれないことを、それらを一切
		// スタブしないことで保証する（呼ばれれば "undefined function" で即座に失敗する）。
		$repo = Mockery::mock( \Affilicard\Repository\ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );
		$maintenance = new \Affilicard\Queue\QueueMaintenance(
			$repo,
			new \Affilicard\Queue\Enqueuer(),
			new \Affilicard\Provider\ProviderRegistry()
		);

		Plugin::handleSweepAction( false, $maintenance, new \Affilicard\Queue\Enqueuer() );

		$this->assertConditionsMet();
	}

	/**
	 * 通常時（pause しておらず cap にも達していない）は sweep() を実際に呼び、
	 * その戻り値を handleSweepCompletion() へそのまま渡す。ここでは get_posts が
	 * 空を返す＝即完走(true)のケースで、継続トリガーが積まれないことを確認する
	 * （pause/cap 判定の分岐が sweep() の実行そのものを妨げていないことの確認）。
	 */
	public function test_handleSweepAction_通常時はsweepを実行し戻り値をhandleSweepCompletionへ渡す(): void {
		// queueAtCapacity(): depth(0) < depthCap(既定500) → false。
		WP_Mock::userFunction( 'as_get_scheduled_actions' )->andReturn( array() );
		WP_Mock::userFunction( 'get_option' )
			->with( \Affilicard\Queue\SweepCursor::OPTION_KEY, 0 )
			->andReturn( 0 );
		WP_Mock::userFunction( 'remove_filter' )->andReturn( true );
		WP_Mock::userFunction( 'get_posts' )->andReturn( array() );
		WP_Mock::userFunction( 'delete_option' )->once()->with( \Affilicard\Queue\SweepCursor::OPTION_KEY );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( \Affilicard\Queue\QueueMaintenance::OPTION_LAST_COMPLETED, Mockery::type( 'string' ), false );

		// completed=true のため継続トリガーは積まれない。
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$repo = Mockery::mock( \Affilicard\Repository\ProductRepositoryInterface::class );
		$repo->shouldNotReceive( 'find' );
		$maintenance = new \Affilicard\Queue\QueueMaintenance(
			$repo,
			new \Affilicard\Queue\Enqueuer(),
			new \Affilicard\Provider\ProviderRegistry()
		);

		Plugin::handleSweepAction( false, $maintenance, new \Affilicard\Queue\Enqueuer() );

		$this->assertConditionsMet();
	}

	public function test_on_transition_refreshes_on_future_to_publish(): void {
		$post = (object) array(
			'ID'        => 77,
			'post_type' => 'affilicard_product',
		);
		WP_Mock::userFunction( 'get_post' )->with( 77 )->andReturn( null ); // find→null → enqueue 不発
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();
		\Affilicard\Plugin::onTransitionPostStatus( 'publish', 'future', $post );
		$this->assertConditionsMet();
	}

	public function test_on_transition_ignores_non_product(): void {
		$post = (object) array(
			'ID'        => 78,
			'post_type' => 'post',
		);
		\Affilicard\Plugin::onTransitionPostStatus( 'publish', 'future', $post );
		$this->assertConditionsMet();
	}

	public function test_on_transition_ignores_non_future_origin(): void {
		$post = (object) array(
			'ID'        => 79,
			'post_type' => 'affilicard_product',
		);
		\Affilicard\Plugin::onTransitionPostStatus( 'publish', 'draft', $post ); // draft→publish は対象外
		$this->assertConditionsMet();
	}

	/**
	 * D（v2.4.0）: 予約投稿 future→publish 昇格時、v2.4.0 以前は ListingRefresher::refreshProduct
	 * で同期 fetch していたが、AS 非同期キュー化により商品の ELIGIBLE な auto listing を
	 * Enqueuer::enqueueProductListings 経由で force enqueue する（同期 fetch はしない）。
	 */
	public function test_on_transition_enqueues_eligible_auto_listing_on_future_to_publish(): void {
		$postId = 80;
		$post   = (object) array(
			'ID'            => $postId,
			'post_type'     => \Affilicard\PostType\ProductPostType::POST_TYPE,
			'post_title'    => '対象作品',
			'post_content'  => '',
			'post_status'   => 'publish',
			'post_modified' => '2026-07-23 00:00:00',
		);

		WP_Mock::userFunction( 'get_post' )->with( $postId )->andReturn( $post );

		$listing = array(
			'platform'    => 'rakuten-kobo',
			'enabled'     => true,
			'update_mode' => 'auto',
			'auto_update' => true,
			'external_id' => 'deadbeef01',
			'price'       => '500',
		);

		WP_Mock::userFunction( 'get_post_meta' )
			->with( $postId, \Affilicard\PostType\ProductPostType::META_EXTRAS, true )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $postId, \Affilicard\PostType\ProductPostType::META_LISTINGS, true )
			->andReturn( array( $listing ) );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $postId, \Affilicard\PostType\ProductPostType::META_PRODUCT_TYPE, true )
			->andReturn( 'ebook' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $postId, \Affilicard\PostType\ProductPostType::META_STOCK_STATUS, true )
			->andReturn( 'available' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $postId, \Affilicard\PostType\ProductPostType::META_SCHEMA_VERSION, true )
			->andReturn( '2' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $postId, \Affilicard\PostType\ProductPostType::META_RELEASE_DATE, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $postId, \Affilicard\PostType\ProductPostType::META_MASK_BLUR, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $postId, \Affilicard\PostType\ProductPostType::META_MASK_R18, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( $postId, \Affilicard\PostType\ProductPostType::META_MASK_LABEL, true )
			->andReturn( '' );

		WP_Mock::userFunction( 'get_option' )
			->with( \Affilicard\Settings\GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'         => 'rakuten-kobo',
						'name'         => '楽天Kobo',
						'provider'     => 'rakuten-kobo',
						'displayOrder' => 3,
						'enabled'      => true,
					),
				)
			);

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once()
			->with(
				\Affilicard\Queue\Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => $postId,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten'
			);
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once()
			->with(
				\Affilicard\Queue\Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => $postId,
					'platform' => 'rakuten-kobo',
					'force'    => true,
				),
				'affilicard-rakuten'
			);
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::type( 'int' ),
				\Affilicard\Queue\Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => $postId,
					'platform' => 'rakuten-kobo',
					'force'    => true,
				),
				'affilicard-rakuten',
				true,
				\Affilicard\Queue\Enqueuer::PRIORITY_FORCE
			)
			->andReturn( 900 );

		\Affilicard\Plugin::onTransitionPostStatus( 'publish', 'future', $post );

		$this->assertConditionsMet();
	}

	public function test_enqueueSettingsAssets_skips_when_hook_not_settings(): void {
		// 別画面ではアセットを enqueue しない（wp_enqueue_* が一切呼ばれない）。
		Plugin::enqueueSettingsAssets( 'edit.php' );
		$this->assertConditionsMet();
	}

	public function test_enqueueSettingsAssets_enqueues_styles_on_settings_hook(): void {
		WP_Mock::userFunction( 'wp_enqueue_script' )->once();
		WP_Mock::userFunction( 'wp_set_script_translations' )->once();
		WP_Mock::userFunction( 'wp_json_encode' )
			->andReturnUsing(
				static function ( $data ) {
					return json_encode( $data );
				}
			);

		// wp-components スタイルと専用 admin CSS を enqueue する。
		WP_Mock::userFunction( 'wp_enqueue_style' )
			->once()
			->with( 'wp-components' );
		WP_Mock::userFunction( 'wp_enqueue_style' )
			->once()
			->with(
				'affilicard-admin-settings',
				AFFILICARD_PLUGIN_URL . 'assets/admin-settings.css',
				array( 'wp-components' ),
				AFFILICARD_VERSION
			);

		// 2 globals（affilicardAccounts / affilicardProviders）を settings script の直前に注入する。
		WP_Mock::userFunction( 'wp_add_inline_script' )
			->once()
			->with(
				'affilicard-settings',
				WP_Mock\Functions::type( 'string' ),
				'before'
			)
			->andReturnUsing(
				function ( $handle, $script, $position ) {
					$this->assertStringContainsString( 'window.affilicardAccounts=', $script );
					$this->assertStringContainsString( 'window.affilicardProviders=', $script );
					$this->assertStringContainsString( 'rakuten', $script );
					$this->assertStringContainsString( 'dmm-ebook', $script );
					return true;
				}
			);

		Plugin::enqueueSettingsAssets( 'affilicard_product_page_affilicard-settings' );

		$this->assertConditionsMet();
	}

	public function test_purgeLegacyProviderCredentials_skips_when_already_purged(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( true );
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_legacy_creds_purged' )
			->andReturn( 1 );
		WP_Mock::userFunction( 'update_option' )->never();
		WP_Mock::userFunction( 'delete_option' )->never();

		Plugin::purgeLegacyProviderCredentials();

		$this->assertConditionsMet();
	}

	/**
	 * manage_options 権限がないユーザー（例: admin_init が低権限ユーザーで走った場合）では
	 * purge 処理そのものに入らない（get_option/delete_option/update_option を一切呼ばない）。
	 */
	public function test_purgeLegacyProviderCredentials_skips_when_user_cannot_manage_options(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( false );
		WP_Mock::userFunction( 'get_option' )->never();
		WP_Mock::userFunction( 'update_option' )->never();
		WP_Mock::userFunction( 'delete_option' )->never();

		Plugin::purgeLegacyProviderCredentials();

		$this->assertConditionsMet();
	}

	public function test_purgeLegacyProviderCredentials_deletes_matching_options_and_sets_flag(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( true );
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_legacy_creds_purged' )
			->andReturn( false );

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
		$wpdb->shouldReceive( 'get_col' )->andReturn( array( 'affilicard_provider_rakuten_credentials', 'affilicard_provider_dmm_credentials' ) );
		$GLOBALS['wpdb'] = $wpdb;

		WP_Mock::userFunction( 'delete_option' )
			->twice()
			->andReturnUsing(
				function ( $key ) {
					$this->assertStringStartsWith( 'affilicard_provider_', $key );
					return true;
				}
			);
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( 'affilicard_legacy_creds_purged', 1, false )
			->andReturn( true );

		Plugin::purgeLegacyProviderCredentials();

		unset( $GLOBALS['wpdb'] );
		Mockery::close();

		$this->assertConditionsMet();
	}

	public function test_backfillEligibleProviders_skips_when_user_cannot_manage_options(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( false );
		WP_Mock::userFunction( 'get_option' )->never();
		WP_Mock::userFunction( 'update_option' )->never();

		Plugin::backfillEligibleProviders();

		$this->assertConditionsMet();
	}

	public function test_backfillEligibleProviders_skips_when_already_backfilled(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( true );
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_eligible_provider_backfilled' )
			->andReturn( 1 );
		WP_Mock::userFunction( 'update_option' )->never();

		Plugin::backfillEligibleProviders();

		$this->assertConditionsMet();
	}

	public function test_backfillEligibleProviders_fills_empty_known_codes_keeps_existing_and_ignores_unknown(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( true );
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_eligible_provider_backfilled' )
			->andReturn( false );
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'             => 'rakuten-kobo',
						'name'             => '楽天Kobo',
						'provider'         => 'rakuten-kobo',
						'displayOrder'     => 3,
						'enabled'          => true,
						'applicableTypes'  => array( 'ebook' ),
						'buttonLabel'      => '楽天Koboで読む',
						'brandColor'       => '#bf0000',
						'buttonTextColor'  => '#ffffff',
						'eligibleProvider' => '', // 既存 install で空のまま保存されているケース
					),
					array(
						'code'             => 'dmm-books',
						'name'             => 'DMMブックス',
						'provider'         => 'dmm-ebook',
						'displayOrder'     => 1,
						'enabled'          => true,
						'applicableTypes'  => array( 'ebook' ),
						'buttonLabel'      => 'DMMブックスで読む',
						'brandColor'       => '#d72d65',
						'buttonTextColor'  => '#ffffff',
						'eligibleProvider' => '',
					),
					array(
						'code'             => 'amazon-kindle',
						'name'             => 'Amazon Kindle',
						'provider'         => 'manual',
						'displayOrder'     => 2,
						'enabled'          => true,
						'applicableTypes'  => array( 'ebook' ),
						'buttonLabel'      => 'Kindleで読む',
						'brandColor'       => '#ff9900',
						'buttonTextColor'  => '#000000',
						'eligibleProvider' => 'already-set', // 既に値がある → 上書きしない
					),
					array(
						'code'             => 'mystery-platform',
						'name'             => 'Mystery',
						'provider'         => 'manual',
						'displayOrder'     => 4,
						'enabled'          => true,
						'applicableTypes'  => array( 'ebook' ),
						'buttonLabel'      => '読む',
						'brandColor'       => '#111111',
						'buttonTextColor'  => '#ffffff',
						'eligibleProvider' => '', // マップに無い未知 code → 変更しない
					),
				)
			);

		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( PlatformConfig::OPTION_KEY, WP_Mock\Functions::type( 'array' ), false )
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$by_code = array();
					foreach ( $value as $entry ) {
						$by_code[ $entry['code'] ] = $entry;
					}
					$this->assertSame( 'rakuten-kobo', $by_code['rakuten-kobo']['eligibleProvider'] );
					$this->assertSame( 'dmm-ebook', $by_code['dmm-books']['eligibleProvider'] );
					$this->assertSame( 'already-set', $by_code['amazon-kindle']['eligibleProvider'] );
					$this->assertSame( '', $by_code['mystery-platform']['eligibleProvider'] );
					return true;
				}
			);
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( 'affilicard_eligible_provider_backfilled', 1, false )
			->andReturn( true );

		Plugin::backfillEligibleProviders();

		$this->assertConditionsMet();
	}

	public function test_backfillEligibleProviders_sets_flag_even_when_nothing_changed(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( true );
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_eligible_provider_backfilled' )
			->andReturn( false );
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'             => 'amazon-kindle',
						'name'             => 'Amazon Kindle',
						'provider'         => 'manual',
						'displayOrder'     => 2,
						'enabled'          => true,
						'applicableTypes'  => array( 'ebook' ),
						'buttonLabel'      => 'Kindleで読む',
						'brandColor'       => '#ff9900',
						'buttonTextColor'  => '#000000',
						'eligibleProvider' => '',
					),
				)
			);

		// 変更対象が無いので PlatformConfig::OPTION_KEY への update_option は呼ばれない。
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( 'affilicard_eligible_provider_backfilled', 1, false )
			->andReturn( true );

		Plugin::backfillEligibleProviders();

		$this->assertConditionsMet();
	}

	/**
	 * F3: eligibleProvider が空で provider が 'manual' 以外（マップ未収載の code を含む）の platform は、
	 * 一般則で eligibleProvider = provider に補完される（既知 code マップと同様に一度だけ）。
	 * provider === 'manual' の platform は eligibleProvider が空のままでも変更しない。
	 */
	public function test_backfillEligibleProviders_fills_general_fallback_for_unmapped_automatic_provider(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( true );
		WP_Mock::userFunction( 'get_option' )
			->with( 'affilicard_eligible_provider_backfilled' )
			->andReturn( false );
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'             => 'bookwalker',
						'name'             => 'BOOK☆WALKER',
						'provider'         => 'rakuten-kobo', // マップ未収載の code だが provider は 'manual' 以外
						'displayOrder'     => 5,
						'enabled'          => true,
						'applicableTypes'  => array( 'ebook' ),
						'buttonLabel'      => 'BOOK☆WALKERで読む',
						'brandColor'       => '#00a1e9',
						'buttonTextColor'  => '#ffffff',
						'eligibleProvider' => '', // 空 → 一般則で provider にフォールバックすべき
					),
					array(
						'code'             => 'mystery-platform',
						'name'             => 'Mystery',
						'provider'         => 'manual',
						'displayOrder'     => 4,
						'enabled'          => true,
						'applicableTypes'  => array( 'ebook' ),
						'buttonLabel'      => '読む',
						'brandColor'       => '#111111',
						'buttonTextColor'  => '#ffffff',
						'eligibleProvider' => '', // provider が 'manual' → 空のまま不変
					),
				)
			);

		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( PlatformConfig::OPTION_KEY, WP_Mock\Functions::type( 'array' ), false )
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$by_code = array();
					foreach ( $value as $entry ) {
						$by_code[ $entry['code'] ] = $entry;
					}
					$this->assertSame( 'rakuten-kobo', $by_code['bookwalker']['eligibleProvider'] );
					$this->assertSame( '', $by_code['mystery-platform']['eligibleProvider'] );
					return true;
				}
			);
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( 'affilicard_eligible_provider_backfilled', 1, false )
			->andReturn( true );

		Plugin::backfillEligibleProviders();

		$this->assertConditionsMet();
	}
}
