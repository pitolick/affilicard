<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\PostType\ProductPostType;

/**
 * Action Scheduler のジョブ一覧（Tools > Scheduled Actions と同じ描画）を affilicard 自身の
 * 管理メニュー配下に埋め込む（v2.4.0 Phase2 §11-3）。
 *
 * 埋め込み方式: WooCommerce が自身の System Status タブから Action Scheduler の一覧を
 * 埋め込む際と同じ公開 API（`ActionScheduler_AdminView::instance()->render_admin_ui()` /
 * `process_admin_ui()`）を再利用する。AS 側に「任意ページへの埋め込み専用 API」はないが、
 * この 2 メソッドは AS 自身の Tools サブメニューと WooCommerce 埋め込みの両方から呼ばれる
 * 前提の public メソッドであり、`ActionScheduler_ListTable`/`ActionScheduler_Store` 等の
 * private 実装に依存せずに済むため、AS のバージョン間でも比較的安定して機能する。
 *
 * group（`affilicard-{account}`）での絞り込みは AS の一覧クエリが対応していないため、
 * 全 AS アクションの hook 名が `affilicard_` 始まりであることを利用し、`s`（検索）
 * クエリ引数を既定値 `affilicard` で事前投入することで実質的にスコープする
 * （検索ボックスは編集可能なので、ユーザーは空にして全件表示に戻せる）。
 *
 * 日本語化: AS は bundle 元の Composer パッケージに翻訳ファイルを同梱しておらず、
 * 自身で textdomain をロードもしないため（vendor/ 配下は WP の翻訳自動取得の対象にも
 * ならない）、既定では常に英語表示になる。affilicard 側で `action-scheduler` textdomain
 * 向けの限定的な日本語 .mo（languages/action-scheduler-ja.mo・
 * languages/generate-action-scheduler-ja-mo.php で生成）を用意し、本ページの読み込み時に
 * 明示的に `load_textdomain()` する。プレースホルダ（%s 等）・複数形を含む文字列は
 * 誤翻訳リスクを避けるため翻訳対象から意図的に除外しており、それらは英語のまま残る
 * （本ページ自身の見出し・説明文は常に日本語）。
 */
final class QueueJobsPage {

	public const MENU_SLUG      = 'affilicard-queue-jobs';
	public const DEFAULT_SEARCH = 'affilicard';

	/**
	 * `admin_menu` から呼ぶ。affilicard の商品 CPT メニュー配下にサブメニューを追加する。
	 */
	public static function registerMenu(): void {
		$hookSuffix = add_submenu_page(
			'edit.php?post_type=' . ProductPostType::POST_TYPE,
			__( '更新キュー（ジョブ一覧）', 'affilicard' ),
			__( '更新キュー（ジョブ一覧）', 'affilicard' ),
			'manage_options',
			self::MENU_SLUG,
			array( self::class, 'render' )
		);

		if ( ! is_string( $hookSuffix ) || '' === $hookSuffix ) {
			return;
		}

		// AS のブロック/行アクション処理（実行・キャンセル・一括削除）はページ本文の
		// 出力開始（send headers）前に済ませる必要があるため、AS 自身の register_menu() と
		// 同じ手法で `load-{hook_suffix}` に配線する（process_admin_ui 内の
		// process_bulk_action/process_row_actions が wp_safe_redirect() することがある）。
		add_action( 'load-' . $hookSuffix, array( self::class, 'onLoad' ) );
	}

	/**
	 * `load-{hook_suffix}` から呼ぶ。日本語訳のロード・検索既定値の投入・
	 * AS の一括/行アクション処理（redirect を伴い得る）をヘッダ送信前に済ませる。
	 */
	public static function onLoad(): void {
		self::maybeLoadJapaneseTranslations();
		self::seedDefaultSearch();
		self::registerColumnArgsFilter();

		if ( class_exists( 'ActionScheduler_AdminView' ) ) {
			\ActionScheduler_AdminView::instance()->process_admin_ui();
		}
	}

	/**
	 * `affilicard_refresh_batch` ジョブは 1 件の args に最大バッチサイズ件（既定 22 件）の
	 * ネストした連想配列（items）を持つ。AS のジョブ一覧
	 * （ActionScheduler_ListTable::column_args()）は args の各値を
	 * `esc_html( var_export( $value, true ) )` でそのまま出力するため、フィルタしないと
	 * Arguments 列 1 行あたり約 110 行・1,500 文字前後の PHP 配列ダンプが並ぶ（per-listing
	 * 時代は 2 行だった。バッチ化に起因する新しい退行）。運用者が「失敗しているジョブを
	 * 探す」ためにこの画面を開いたとき、hook / status / 実行予定日時の突き合わせが実質
	 * 不可能になるため、AS が提供する `action_scheduler_list_table_column_args` フィルタで
	 * `affilicard_refresh_batch` のときだけ「account / 件数」の要約に畳む。
	 */
	public static function registerColumnArgsFilter(): void {
		add_filter( 'action_scheduler_list_table_column_args', array( self::class, 'summarizeBatchArgs' ), 10, 2 );
	}

	/**
	 * `action_scheduler_list_table_column_args` のコールバック。`affilicard_refresh_batch`
	 * 以外の hook は AS の既定描画（$html）をそのまま通す。
	 *
	 * @param string                            $html 既定描画（<ul><li>...</li></ul> の配列ダンプ、または空文字）。
	 * @param array{hook?: mixed, args?: mixed} $row ActionScheduler_ListTable の行データ。
	 */
	public static function summarizeBatchArgs( string $html, array $row ): string {
		if ( Enqueuer::HOOK_REFRESH_BATCH !== ( $row['hook'] ?? '' ) ) {
			return $html;
		}

		$args    = is_array( $row['args'] ?? null ) ? $row['args'] : array();
		$account = isset( $args['account'] ) ? (string) $args['account'] : '';
		$items   = is_array( $args['items'] ?? null ) ? $args['items'] : array();

		return '<code>' . esc_html(
			sprintf(
				/* translators: 1: account コード（例: rakuten）, 2: listing 件数 */
				__( '%1$s / %2$d 件', 'affilicard' ),
				$account,
				count( $items )
			)
		) . '</code>';
	}

	/**
	 * サブメニューのページコールバック。
	 */
	public static function render(): void {
		echo '<div class="wrap affilicard-queue-jobs-page">';
		echo '<h1>' . esc_html__( '更新キュー（ジョブ一覧）', 'affilicard' ) . '</h1>';
		echo '<p>' . esc_html__(
			'価格更新・商品自動作成のバックグラウンドジョブ（Action Scheduler）の一覧です。既定では検索欄に「affilicard」を入れ、affilicard のジョブ（フック名 affilicard_ 始まり）に絞り込んで表示します。検索欄を空にすると全プラグインのジョブを表示できます。',
			'affilicard'
		) . '</p>';
		// 日時カラムは Action Scheduler 本体が常に UTC（+0000）で描画する仕様で、サイトの
		// タイムゾーン設定には追従しない（埋め込み表示側からは制御不可）。誤読を避けるため明記する。
		echo '<p class="description">' . esc_html__(
			'※ 実行予定日時・ログの日時は UTC（協定世界時・+0000）で表示されます。これは Action Scheduler の仕様で、WordPress のタイムゾーン設定には追従しません（日本時間 = UTC + 9時間）。',
			'affilicard'
		) . '</p>';

		if ( ! class_exists( 'ActionScheduler_AdminView' ) ) {
			self::renderFallback();
			echo '</div>';
			return;
		}

		echo '</div>'; // AS 側の render_admin_ui() が自前で <div class="wrap"> を出力するため、
		// ここで一旦閉じて二重 wrap を避ける（見出し・説明文は上記の外側 wrap で完結させる）。

		\ActionScheduler_AdminView::instance()->render_admin_ui();
	}

	/**
	 * `class_exists('ActionScheduler_AdminView')` が false の場合（AS 未ロード環境。
	 * vendor/ 不在時の簡易 PSR-4 フォールバック等）に、フェイタルさせず Tools 側への
	 * 導線を提示する。
	 */
	private static function renderFallback(): void {
		echo '<div class="notice notice-warning"><p>' . esc_html__(
			'Action Scheduler の管理画面コンポーネントを読み込めなかったため、ここには埋め込めません。下記のリンクから直接ご確認ください。',
			'affilicard'
		) . '</p></div>';
		echo '<p><a class="button" href="' . esc_url( self::toolsPageUrl() ) . '">'
			. esc_html__( 'Scheduled Actions を開く（Tools）', 'affilicard' )
			. '</a></p>';
	}

	/**
	 * 検索クエリ（`s`）が指定されていない初回アクセス時のみ、既定値 `affilicard` を
	 * 補う（AS の一覧クエリに group フィルタが無いため、hook 名プレフィックスでの
	 * 実質的な絞り込みとして使う）。`s=`（空文字）で明示的にクリアされた場合は
	 * `isset()` が true になるため上書きしない。
	 *
	 * `ActionScheduler_Abstract_ListTable::get_request_search_query()`（クエリ絞り込み）と
	 * `WP_List_Table::search_box()`（検索ボックスの表示値・`$_REQUEST['s']` を参照）の
	 * 両方に効かせるため、$_GET と $_REQUEST の両方へ設定する。
	 */
	private static function seedDefaultSearch(): void {
		if ( ! isset( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$_GET['s']     = self::DEFAULT_SEARCH; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.VIP.SuperGlobalInputUsage.AccessDetected
			$_REQUEST['s'] = self::DEFAULT_SEARCH; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.VIP.SuperGlobalInputUsage.AccessDetected
		}
	}

	/**
	 * bundle した Action Scheduler は composer 配布物に languages/ を同梱しておらず
	 * （`vendor/` は本リポジトリでも .gitignore 対象）、textdomain の自動ロードもしない。
	 * ja ロケール時のみ、affilicard 側で用意した限定訳（languages/action-scheduler-ja.mo）を
	 * 明示ロードする。
	 */
	private static function maybeLoadJapaneseTranslations(): void {
		if ( is_textdomain_loaded( 'action-scheduler' ) ) {
			return;
		}
		if ( ! str_starts_with( get_locale(), 'ja' ) ) {
			return;
		}

		$path = AFFILICARD_PLUGIN_DIR . 'languages/action-scheduler-ja.mo';
		if ( is_readable( $path ) ) {
			load_textdomain( 'action-scheduler', $path );
		}
	}

	/**
	 * Tools > Scheduled Actions（AS ネイティブの一覧画面）への導線 URL。
	 * QueuePanel.jsx の同等リンクと同じ `s=affilicard` 絞り込みを付与する。
	 */
	public static function toolsPageUrl(): string {
		return admin_url( 'tools.php?page=action-scheduler&s=' . rawurlencode( self::DEFAULT_SEARCH ) );
	}

	/**
	 * このページ自身の URL（affilicard 商品一覧の子メニューとして生成される）。
	 * QueuePanel.jsx から本ページへリンクする際に使う。
	 */
	public static function pageUrl(): string {
		return admin_url( 'edit.php?post_type=' . ProductPostType::POST_TYPE . '&page=' . self::MENU_SLUG );
	}
}
