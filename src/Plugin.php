<?php
declare(strict_types=1);

namespace Affilicard;

use Affilicard\Account\AccountRegistry;
use Affilicard\Account\AccountUiList;
use Affilicard\Account\DmmAccount;
use Affilicard\Account\RakutenAccount;
use Affilicard\AutoCreate\ProductAutoCreator;
use Affilicard\Block\Block;
use Affilicard\Cron\ListingRefresher;
use Affilicard\Cron\RefreshScheduler;
use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductListColumns;
use Affilicard\PostType\ProductMetaBox;
use Affilicard\PostType\ProductPostType;
use Affilicard\Provider\Dmm\DmmProvider;
use Affilicard\Provider\ManualProvider;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Provider\ProviderUiList;
use Affilicard\Provider\Rakuten\RakutenProvider;
use Affilicard\Queue\ActionSchedulerLoader;
use Affilicard\Queue\ActionSchedulerStore;
use Affilicard\Queue\AutoCreateHandler;
use Affilicard\Queue\BatchRefreshHandler;
use Affilicard\Pricing\PriceFreshness;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\PublishTrigger;
use Affilicard\Queue\QueueJobsPage;
use Affilicard\Queue\QueueMaintenance;
use Affilicard\Queue\QueueStats;
use Affilicard\Queue\RateLimiter;
use Affilicard\Queue\RefreshHandler;
use Affilicard\Repository\ProductRepository;
use Affilicard\Rest\CardPreviewController;
use Affilicard\Rest\CredentialsController;
use Affilicard\Rest\PlatformsController;
use Affilicard\Rest\ProductsController;
use Affilicard\Rest\QueueController;
use Affilicard\Rest\RefreshController;
use Affilicard\Rest\RestController;
use Affilicard\Rest\SettingsController;
use Affilicard\Settings\DashboardWidget;
use Affilicard\Settings\GeneralSettings;
use Affilicard\Stocktake\PublicationDate;
use Affilicard\Types\EbookType;
use Affilicard\Types\GenericType;
use Affilicard\Types\ProductTypeRegistry;
use Affilicard\Types\VodType;
use Affilicard\Upgrade\PluginUpgrade;

/**
 * プラグインのブートストラップ。
 *
 * CPT 登録、管理画面メタボックス/一覧カラム/ダッシュボードウィジェット、
 * REST API、Provider/ProductType レジストリ、有効化フックを配線する。
 */
final class Plugin {

	public const SEEDED_AT_OPTION = 'affilicard_seeded_at';

	public static function boot(): void {
		$instance = new self();
		$instance->bootInstance();
	}

	private function bootInstance(): void {
		// bundle した Action Scheduler を同期ロード（プラグインファイル require 時点）。
		// plugins_loaded へ延期すると、AS 自身が plugins_loaded@0 で登録する init コールバックが
		// 現在イテレート中の plugins_loaded@0 バケットに追加され得ず（PHP/WP の do_action は
		// イテレート中のバケットへの追加コールバックを拾わない）、AS が一切初期化されない不具合がある。
		ActionSchedulerLoader::boot();

		// バージョン移行: register_activation_hook は自動更新・管理画面からの更新では
		// 実行されないため、plugins_loaded で保存済みバージョンとの差分を都度チェックする
		// （有効化したまま更新したサイトでも棚卸し基準日の初期化等が確実に走るようにする）。
		add_action(
			'plugins_loaded',
			static function (): void {
				PluginUpgrade::maybeUpgrade( AFFILICARD_VERSION );
			}
		);

		// CPT 登録
		add_action( 'init', array( ProductPostType::class, 'register' ) );
		add_action( 'init', array( \Affilicard\PostType\ProductMeta::class, 'register' ) );

		// Gutenberg Block 登録（フロント/エディタ両方で init 時に必要）
		Block::register_hook();

		// 管理画面
		if ( is_admin() ) {
			ProductMetaBox::register();
			ProductListColumns::register();
			$dashboard = new DashboardWidget( new ProductRepository() );
			$dashboard->register();

			\Affilicard\Admin\CronDisabledNotice::register();
			add_action( 'admin_menu', array( self::class, 'registerSettingsPage' ) );
			add_action( 'admin_menu', array( QueueJobsPage::class, 'registerMenu' ) );
			// affilicard 独自の「更新キュー（ジョブ一覧）」を持つため、Tools > Scheduled Actions の
			// 重複サブメニューを（affilicard が実質 AS のオーナーである純 affilicard サイトに限り）隠す。
			// 遅い優先度で走らせて AS 本体がサブメニューを登録し終えた後に remove する。
			add_action( 'admin_menu', array( self::class, 'hideActionSchedulerToolsMenu' ), 99 );
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueueSettingsAssets' ) );
			add_action( 'admin_init', array( self::class, 'purgeLegacyProviderCredentials' ) );
			add_action( 'admin_init', array( self::class, 'backfillEligibleProviders' ) );
		}

		// ProductType レジストリ（Block の type 解決でも buildProductTypeRegistry() を参照）
		$providers = self::buildProviderRegistry();
		$accounts  = self::buildAccountRegistry();
		self::buildProductTypeRegistry();

		// キューパネル（Task 15/16）・depth cap 集計（I1）共通: 自動更新対象 account コード
		// （'manual' 等の非自動 provider を除く。v2.4.0: provider コードから account コードへ
		// 統一。レート制限は共有 API＝account 単位でかかり、認証画面（楽天/DMM）と一致する）を
		// ProviderRegistry から都度導出する。QueueStats/QueueController/Enqueuer にハードコードしない。
		$automaticAccountCodes = self::automaticAccountCodes( $providers );

		// キュー: REST/AS ハンドラ/トリガー間で共有する Repository/Enqueuer
		// （depth cap は enqueueForced/enqueueManual では未使用だが、掃引と同じ構築パターンに揃える。
		// ProviderRegistry は enqueueProductListings が platform の provider→account を解決するために渡す）。
		$repository = new ProductRepository();
		$enqueuer   = new Enqueuer( GeneralSettings::queueDepthCap(), 300, array(), $providers );

		// REST API
		$rest = new RestController(
			new ProductsController( $repository ),
			new SettingsController(),
			new PlatformsController(),
			new CredentialsController( $providers, $accounts ),
			new RefreshController( $repository, $enqueuer ),
			new CardPreviewController( $repository ),
			new QueueController(
				new QueueStats( $automaticAccountCodes ),
				$automaticAccountCodes,
				new ActionSchedulerStore(),
				$accounts
			)
		);
		$rest->register();

		add_action(
			'rest_after_insert_' . ProductPostType::POST_TYPE,
			static function ( $post ) {
				if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
					return;
				}
				$post_id = (int) $post->ID;
				// autosave/revision では派生 meta を再構築しない。
				if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
					return;
				}
				( new \Affilicard\Repository\ProductRepository() )->syncDerivedMeta( $post_id );
			},
			10,
			1
		);

		// 価格更新 Cron: 全体単一イベントのハンドラ登録 + 設定との差分調整。
		// v3.5.0（Task 12・Ruling 3）で掃引自体を AS アクション化した。WP-Cron
		// （affilicard_refresh_all）は多重起動しないよう掃引トリガー（affilicard_sweep・
		// unique=true）を 1 件積むだけにし、実際の掃引（QueueMaintenance::sweep）は
		// 下記の affilicard_sweep ハンドラが担う。
		RefreshScheduler::register(
			static function (): void {
				self::triggerSweep( new Enqueuer() );
			}
		);
		add_action( 'init', array( RefreshScheduler::class, 'reconcile' ) );

		// キュー: 掃引（sweep）アクション本体。QueueMaintenance::sweep() を呼び、戻り値が
		// false（未完走。queue_depth_cap 到達や続きがある場合）なら同じアクションを
		// 即時に積み直して継続する（Task 12・Ruling 3）。
		// I1: depth cap backstop が affilicard 以外の pending action（WooCommerce 等）に
		// 誤反応しないよう、$automaticAccountCodes で account group 別集計に限定した
		// 専用 Enqueuer を使う（REST 等が使う共有 $enqueuer とは accountCodes のスコープが
		// 異なるため分ける）。depthCap/sweepLeadSeconds は Enqueuer と QueueMaintenance の
		// 双方へ同じ値を渡す（cap の出所を 2 箇所に分散させない）。
		add_action(
			Enqueuer::HOOK_SWEEP,
			static function () use ( $automaticAccountCodes, $providers, $repository ): void {
				$depthCap      = GeneralSettings::queueDepthCap();
				$sweepEnqueuer = new Enqueuer( $depthCap, 300, $automaticAccountCodes, $providers );
				$maintenance   = new QueueMaintenance(
					$repository,
					$sweepEnqueuer,
					$providers,
					sweepLeadSeconds: PriceFreshness::sweepLeadSeconds( GeneralSettings::refreshIntervalHours() ),
					depthCap: $depthCap
				);
				self::handleSweepCompletion( $maintenance->sweep(), $sweepEnqueuer );
			}
		);

		// キュー: AS の completed/failed アクション保持期間を GeneralSettings に連動。
		QueueMaintenance::registerRetentionFilters();

		// キュー: AS アクション（Enqueuer::HOOK_REFRESH/HOOK_REFRESH_BATCH/HOOK_AUTOCREATE）の
		// ハンドラ配線。これが無いと Enqueuer が積んだジョブは AS 上に滞留したまま一切実行されない。
		$refreshHandler = new RefreshHandler(
			$enqueuer,
			new RateLimiter(),
			new ListingRefresher( $providers, $repository ),
			$providers
		);
		add_action( Enqueuer::HOOK_REFRESH, array( $refreshHandler, 'handle' ), 10, 2 );

		// BatchRefreshHandler::handle(array $args) は AS フックへ直付けできない
		// （Action Scheduler は do_action_ref_array($hook, array_values($args)) で args を
		// 位置引数に展開するため、直付けすると第1引数に文字列 'account' が渡り TypeError に
		// なる）。RefreshHandler::handle と同様、クロージャで [account, items] を受けて
		// handleBatchRefreshAction() が配列に組み直す。
		$batchHandler = new BatchRefreshHandler(
			$enqueuer,
			new RateLimiter(),
			new ListingRefresher( $providers, $repository ),
			$providers
		);
		add_action(
			Enqueuer::HOOK_REFRESH_BATCH,
			static function ( $account, $items ) use ( $batchHandler ): void {
				self::handleBatchRefreshAction( $batchHandler, $account, $items );
			},
			10,
			2
		);

		$autoCreateHandler = new AutoCreateHandler(
			$enqueuer,
			new RateLimiter(),
			new ProductAutoCreator( $providers, $repository ),
			$providers
		);
		add_action( Enqueuer::HOOK_AUTOCREATE, array( $autoCreateHandler, 'handle' ), 10, 2 );

		// キュー: 記事（投稿）の公開時に、本文中の affilicard/product-card ブロックが参照する
		// 既存商品の ELIGIBLE な auto listing を force enqueue するトリガー。
		// これは商品 CPT 自身の future→publish（下の onTransitionPostStatus）とは独立した第 2 の
		// transition_post_status ハンドラであり、両方を配線する（onUpdated は意図的に配線しない。
		// transition_post_status は publish→publish の再保存も含め毎回発火するため、
		// onTransition 側のガード（newStatus==='publish'）だけで全 publish ケースを既にカバーする。
		// onUpdated も配線すると二重発火するため配線しない）。
		$publishTrigger = new PublishTrigger( $repository, $enqueuer, $providers, new PublicationDate() );
		add_action( 'transition_post_status', array( $publishTrigger, 'onTransition' ), 10, 3 );

		// 予約投稿（product CPT・future）→ publish 昇格時に、対象商品の ELIGIBLE な auto listing を
		// force enqueue する（PublishTrigger とは別系統・商品 CPT 自身の遷移を扱う）。
		add_action( 'transition_post_status', array( self::class, 'onTransitionPostStatus' ), 10, 3 );

		// 有効化フック: デフォルト platform を idempotent に seed
		register_activation_hook( AFFILICARD_PLUGIN_FILE, array( self::class, 'onActivate' ) );
		// 無効化フック: WP-Cron スケジュールをすべて解除
		register_deactivation_hook( AFFILICARD_PLUGIN_FILE, array( RefreshScheduler::class, 'clear' ) );
	}

	public static function buildProviderRegistry(): ProviderRegistry {
		$registry = new ProviderRegistry();
		$registry->register( new ManualProvider() );
		$registry->register( new DmmProvider() );
		$registry->register( new RakutenProvider() );
		return $registry;
	}

	/**
	 * 自動更新対象 provider の accountCode 一覧（重複排除・'manual' 等 isAutomatic()===false
	 * や accountCode()===null の provider は除く）。
	 *
	 * v2.4.0: provider コード単位から account コード単位へ統一した（レート制限は provider
	 * ではなく共有 API＝account 単位でかかり、認証画面（楽天/DMM）と一致させるため）。
	 * QueueStats/QueueController/Enqueuer/Uninstall がキューの account group
	 * （`affilicard-{account}`）を走査する対象として使う。
	 *
	 * @return list<string>
	 */
	public static function automaticAccountCodes( ProviderRegistry $providers ): array {
		$codes = array();
		foreach ( $providers->all() as $provider ) {
			if ( ! $provider->isAutomatic() ) {
				continue;
			}
			$account = $provider->accountCode();
			if ( null !== $account && ! in_array( $account, $codes, true ) ) {
				$codes[] = $account;
			}
		}
		return $codes;
	}

	/**
	 * affilicard_sweep ハンドラの完走判定を切り出したもの（Task 12・Ruling 3）。
	 * QueueMaintenance::sweep() が false（未完走。queue_depth_cap 到達や続きがある場合）を
	 * 返したときだけ、同じ掃引トリガーを unique=false で即時に積み直して継続する。
	 * true（完走）のときは何もしない——ここを誤ると継続更新が止まる。
	 *
	 * QueueMaintenance は final class で Mockery モック不可なため、bool の completed を
	 * 引数に取る形で切り出し、この分岐だけを直接ユニットテストできるようにしている。
	 */
	public static function handleSweepCompletion( bool $completed, Enqueuer $sweepEnqueuer ): void {
		if ( ! $completed ) {
			$sweepEnqueuer->enqueueSweepTrigger( false );
		}
	}

	/**
	 * WP-Cron（affilicard_refresh_all）の起動ハンドラ本体（Task 12・Ruling 3）。
	 * 掃引トリガー（affilicard_sweep）を unique=true で 1 件積むだけにし、多重起動を防ぐ。
	 * 実際の掃引は affilicard_sweep のハンドラ（QueueMaintenance::sweep）が担う。
	 */
	public static function triggerSweep( Enqueuer $enqueuer ): void {
		$enqueuer->enqueueSweepTrigger( true );
	}

	/**
	 * Enqueuer::HOOK_REFRESH_BATCH ハンドラの本体。AS が do_action_ref_array() で
	 * 展開する位置引数 [account, items] を BatchRefreshHandler::handle() が要求する
	 * 配列 {account, items} へ組み直す（先行タスクからの申し送り: 直付け不可・TypeError 対策）。
	 *
	 * @param mixed $account
	 * @param mixed $items
	 */
	public static function handleBatchRefreshAction( BatchRefreshHandler $handler, $account, $items ): void {
		$handler->handle(
			array(
				'account' => (string) $account,
				'items'   => is_array( $items ) ? $items : array(),
			)
		);
	}

	public static function buildAccountRegistry(): AccountRegistry {
		$registry = new AccountRegistry();
		$registry->register( new RakutenAccount() );
		$registry->register( new DmmAccount() );
		return $registry;
	}

	public static function buildProductTypeRegistry(): ProductTypeRegistry {
		$registry = new ProductTypeRegistry();
		$registry->register( new GenericType() );
		$registry->register( new EbookType() );
		$registry->register( new VodType() );
		return $registry;
	}

	public static function onActivate(): void {
		if ( false !== get_option( self::SEEDED_AT_OPTION, false ) ) {
			return;
		}
		PlatformConfig::save( PlatformConfig::defaults() );
		update_option( self::SEEDED_AT_OPTION, gmdate( 'c' ), false );
	}

	/**
	 * `affilicard_provider_<code>_credentials` 形式の旧 provider 単位 credentials を一度きり purge する。
	 *
	 * account 単位 credentials（AccountRegistry/CredentialsController）への移行に伴い不要となった
	 * 旧オプションを admin_init で削除する。`affilicard_legacy_creds_purged` フラグで一度だけ実行する。
	 */
	public static function purgeLegacyProviderCredentials(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( 'affilicard_legacy_creds_purged' ) ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$like = $wpdb->esc_like( 'affilicard_provider_' ) . '%' . $wpdb->esc_like( '_credentials' );
		$keys = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);
		foreach ( (array) $keys as $key ) {
			delete_option( (string) $key );
		}
		update_option( 'affilicard_legacy_creds_purged', 1, false );
	}

	/**
	 * `eligibleProvider` 追加前に保存された既存 platform へ、provider を一度きり補完する。
	 *
	 * v2.3.0 の手動/自動トグルは eligibleProvider が空だと「自動取得」を選べない一方、
	 * ListingRefresher の自動判定（`$provider->isAutomatic()`）は `provider !== 'manual'` で行うため、
	 * eligibleProvider が空のまま provider != 'manual' な platform は UI 上「手動固定」に見えつつ
	 * cron では自動 refresh される、という不整合が起き得る。
	 * seed（PlatformConfig::defaults()）は新規 install にしか効かないため、既存 install を
	 * `affilicard_eligible_provider_backfilled` フラグで一度だけ補完する。
	 *
	 * 優先順位: まず既知の code→provider マップを適用し、マップに無い code でも
	 * `provider !== 'manual'` かつ eligibleProvider が空なら `eligibleProvider = provider` を補完する
	 * 一般則を適用する。既に値がある platform は上書きしない。
	 */
	public static function backfillEligibleProviders(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( 'affilicard_eligible_provider_backfilled' ) ) {
			return;
		}

		$map = array(
			'rakuten-kobo' => 'rakuten-kobo',
			'dmm-books'    => 'dmm-ebook',
		);

		$changed = false;
		$out     = array();
		foreach ( PlatformConfig::all() as $definition ) {
			$arr = $definition->toArray();
			if ( '' === $definition->eligibleProvider ) {
				if ( isset( $map[ $definition->code ] ) ) {
					$arr['eligibleProvider'] = $map[ $definition->code ];
					$changed                 = true;
				} elseif ( 'manual' !== $definition->provider ) {
					// マップ未収載でも provider が自動系なら、UI/cron の判定を一致させるため provider を補完する。
					$arr['eligibleProvider'] = $definition->provider;
					$changed                 = true;
				}
			}
			$out[] = $arr;
		}

		if ( $changed ) {
			PlatformConfig::save( $out );
		}

		update_option( 'affilicard_eligible_provider_backfilled', 1, false );
	}

	public static function registerSettingsPage(): void {
		add_submenu_page(
			'edit.php?post_type=' . ProductPostType::POST_TYPE,
			__( 'Affilicard 設定', 'affilicard' ),
			__( '設定', 'affilicard' ),
			'manage_options',
			'affilicard-settings',
			array( self::class, 'renderSettingsPage' )
		);
	}

	/**
	 * Tools > Scheduled Actions のサブメニューを条件付きで隠す。
	 *
	 * affilicard は独自の「更新キュー（ジョブ一覧）」を管理画面に埋め込んでいるため、
	 * 純 affilicard サイトでは Tools 側の重複リンクを隠したい。ただし Action Scheduler は
	 * WooCommerce をはじめ多くのプラグインが共有する共通基盤であり、それらの利用者は
	 * Tools > Scheduled Actions に依存するため、無条件に消してはならない。
	 *
	 * ガード:
	 * - 既定では WooCommerce（AS の代表的な大口消費者）が存在しない場合のみ隠す。
	 * - 全体を `affilicard_hide_action_scheduler_tools_menu` フィルタで上書き可能にし、
	 *   他の AS 消費プラグインを持つサイトが明示的に無効化（または強制有効化）できるようにする。
	 */
	public static function hideActionSchedulerToolsMenu(): void {
		$shouldHide = apply_filters(
			'affilicard_hide_action_scheduler_tools_menu',
			! class_exists( 'WooCommerce' )
		);

		if ( $shouldHide ) {
			remove_submenu_page( 'tools.php', 'action-scheduler' );
		}
	}

	public static function renderSettingsPage(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Affilicard 設定', 'affilicard' ) . '</h1>';
		echo '<div id="affilicard-settings-root"></div></div>';
	}

	/**
	 * 予約投稿（future）が publish に昇格した瞬間に、対象商品の ELIGIBLE な auto listing を
	 * Enqueuer 経由で force enqueue する（v2.4.0: 同期 fetch から AS 非同期キュー化。実際の
	 * fetch/保存は RefreshHandler が Enqueuer::HOOK_REFRESH ハンドラとして担う）。
	 *
	 * @param object $post
	 */
	public static function onTransitionPostStatus( string $newStatus, string $oldStatus, $post ): void {
		if ( 'publish' !== $newStatus || 'future' !== $oldStatus ) {
			return;
		}
		if ( ! is_object( $post ) || ! isset( $post->post_type ) || ProductPostType::POST_TYPE !== $post->post_type ) {
			return;
		}

		$postId  = (int) $post->ID;
		$product = ( new ProductRepository() )->find( $postId );
		if ( null === $product ) {
			return;
		}

		( new Enqueuer( GeneralSettings::queueDepthCap(), 300, array(), self::buildProviderRegistry() ) )
			->enqueueProductListings( $postId, $product, false );
	}

	public static function enqueueSettingsAssets( string $hook ): void {
		if ( false === strpos( $hook, 'affilicard-settings' ) ) {
			return;
		}

		$asset_file = AFFILICARD_PLUGIN_DIR . 'build/settings.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => AFFILICARD_VERSION,
			);

		wp_enqueue_script(
			'affilicard-settings',
			AFFILICARD_PLUGIN_URL . 'build/settings.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_add_inline_script(
			'affilicard-settings',
			'window.affilicardAccounts=' . wp_json_encode( AccountUiList::build( self::buildAccountRegistry() ) ) . ';'
			. 'window.affilicardProviders=' . wp_json_encode( ProviderUiList::build( self::buildProviderRegistry() ) ) . ';',
			'before'
		);
		wp_set_script_translations( 'affilicard-settings', 'affilicard' );
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style(
			'affilicard-admin-settings',
			AFFILICARD_PLUGIN_URL . 'assets/admin-settings.css',
			array( 'wp-components' ),
			AFFILICARD_VERSION
		);
	}
}
