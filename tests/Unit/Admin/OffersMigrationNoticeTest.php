<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Admin;

use Affilicard\Admin\OffersMigrationNotice;
use Affilicard\Upgrade\PluginUpgrade;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * offers 移行の状態通知（表示条件）を検証する。
 *
 * 移行が運用へ伝えるべきことは error_log() と option の生値でしか見えなかった。
 * 本番は WP_DEBUG_LOG が off でログは何処にも出ず、しかも完走時にしか通らないため、
 * 「移行が途中で止まっている」という最も伝えるべき状態では 1 行も出ない。
 */
final class OffersMigrationNoticeTest extends TestCase {

	/**
	 * $_GET の退避（dismiss 系テストが書き換えるため）。
	 *
	 * **掃除は tearDown で行う。** テスト本体の末尾で戻すと、途中で失敗したときに
	 * 書き換えたまま次のテストへ漏れる。
	 *
	 * @var array<string, mixed>
	 */
	private array $originalGet = array();

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$this->originalGet = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テストの退避であり入力の処理ではない。
	}

	public function tearDown(): void {
		$_GET = $this->originalGet;
		WP_Mock::tearDown();
		parent::tearDown();
	}

	private function stubScreen( ?string $postType ): void {
		$screen            = new \stdClass();
		$screen->post_type = $postType;
		WP_Mock::userFunction( 'get_current_screen' )->andReturn( null === $postType ? null : $screen );
	}

	private function stubEditPosts( bool $allowed ): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts' )
			->andReturn( $allowed );
	}

	/** @param int|false $cursor カーソル option の値（false は未設定＝移行なし）。 */
	/**
	 * 移行カーソルのスタブ。
	 *
	 * **数値は文字列で返す。** WordPress は option / user meta を DB から文字列として
	 * 返すため、int を返すスタブは本番より緩い。厳密比較を書いたときに、テストだけ
	 * 通って本番で落ちる状態を作らないようにする。未設定だけが false。
	 *
	 * @param string|false $cursor
	 */
	private function stubCursor( $cursor ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_CURSOR, false )
			->andReturn( $cursor );
	}

	private function stubPreserved( int $count ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL, 0 )
			->andReturn( $count );
	}

	/** 移行を諦めた（新形式へ保存できなかった）商品の件数。 */
	private function stubFailed( int $count, array $ids = array() ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_FAILED_COUNT, 0 )
			->andReturn( $count );
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_FAILED_POST_IDS, array() )
			->andReturn( $ids );
	}

	/**
	 * @param int      $dismissed        「閉じた時点の温存件数」として記録されている値（0＝未 dismiss）。
	 * @param int|null $dismissed_failed 「閉じた時点の移行失敗件数」（null なら未 dismiss の 0）。
	 */
	/**
	 * @param int      $dismissed        「閉じた時点の温存件数」として記録されている値（0＝未 dismiss）。
	 * @param int|null $dismissed_failed 同・諦め通知側。
	 */
	private function stubUser( int $dismissed, ?int $dismissed_failed = null ): void {
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		// user meta も DB からは文字列で返る（stubCursor の説明と同じ理由）。
		WP_Mock::userFunction( 'get_user_meta' )
			->with( 7, 'affilicard_offers_migration_notice_dismissed', true )
			->andReturn( (string) $dismissed );
		WP_Mock::userFunction( 'get_user_meta' )
			->with( 7, 'affilicard_offers_migration_failed_notice_dismissed', true )
			->andReturn( (string) ( null === $dismissed_failed ? 0 : $dismissed_failed ) );
	}

	public function test_移行が未完なら未完通知を出す(): void {
		$this->stubEditPosts( true );
		$this->stubScreen( 'affilicard_product' );
		$this->stubCursor( '480' );

		$this->assertTrue( OffersMigrationNotice::shouldShowPending() );
	}

	/** カーソル 0（走り始めた直後）も未完。値ではなく存在で判定する。 */
	public function test_カーソルが0でも未完通知を出す(): void {
		$this->stubEditPosts( true );
		$this->stubScreen( 'affilicard_product' );
		$this->stubCursor( '0' );

		$this->assertTrue( OffersMigrationNotice::shouldShowPending() );
	}

	public function test_移行が完走していれば未完通知を出さない(): void {
		$this->stubEditPosts( true );
		$this->stubScreen( 'affilicard_product' );
		$this->stubCursor( false );

		$this->assertFalse( OffersMigrationNotice::shouldShowPending() );
	}

	/**
	 * 完走を待たずに温存件数を出す。完走時の error_log だけに頼ると、
	 * 途中で止まった移行では運用が何も知らされない。
	 */
	public function test_移行が未完でも温存件数を出す(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubCursor( '480' );
		$this->stubPreserved( 3 );
		$this->stubUser( 0 );

		$this->assertTrue( OffersMigrationNotice::shouldShowPreserved() );
	}

	public function test_温存が0件なら通知しない(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubPreserved( 0 );

		$this->assertFalse( OffersMigrationNotice::shouldShowPreserved() );
	}

	public function test_同じ件数でdismiss済みなら温存通知を出さない(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubPreserved( 3 );
		$this->stubUser( 3 );

		$this->assertFalse( OffersMigrationNotice::shouldShowPreserved() );
	}

	/**
	 * dismiss したあとのバッチが温存件数を増やしたら出し直す。
	 *
	 * 真偽値で覚えると、増分を誰にも知らせないまま次の保存で
	 * ProductSchema::sanitizeOffers() が該当の購入リンクを消してしまう。
	 */
	public function test_dismiss後に温存件数が増えたら通知を出し直す(): void {
		$this->stubScreen( 'affilicard_product' );
		// 温存 1 件のときに閉じ、その後のバッチで 5 件へ増えた状態。
		// 記録値が 1 なのは意図的——真偽値で覚える実装だと「閉じた」と解釈して
		// 隠してしまい、このテストが素通りしなくなる。
		$this->stubPreserved( 5 );
		$this->stubUser( 1 );

		$this->assertTrue( OffersMigrationNotice::shouldShowPreserved() );
	}

	/**
	 * 未完通知は affilicard の画面に限定しない。
	 *
	 * 移行が終わるまでカードは flat な listing を読み側のフォールバックで描いており、
	 * この通知がカタログの状態を伝える唯一の signal である。商品 CPT の画面を開いた
	 * 運用者にしか見えないと、止まった移行は誰にも気づかれない。短命かつ
	 * dismiss 不可なので、全画面に出しても居座らない。
	 */
	public function test_未完通知は商品画面以外にも出す(): void {
		$this->stubEditPosts( true );
		$this->stubScreen( 'post' );
		$this->stubCursor( '480' );

		$this->assertTrue( OffersMigrationNotice::shouldShowPending() );
	}

	/** 温存通知は従来どおり affilicard の画面に限定する（恒久的に出るため）。 */
	public function test_温存通知はaffilicard以外の画面では出さない(): void {
		$this->stubScreen( 'post' );
		$this->stubPreserved( 3 );

		$this->assertFalse( OffersMigrationNotice::shouldShowPreserved() );
	}

	/**
	 * 温存通知は件数だけでなく、対象商品への編集リンクを出す。
	 *
	 * 「12 件消えます」とだけ伝えて、どの商品か分からない通知は運用上何もできない。
	 */
	public function test_温存通知は対象商品への編集リンクを出す(): void {
		$this->stubEditPosts( true );
		$this->stubScreen( 'affilicard_product' );
		$this->stubCursor( false );
		$this->stubPreserved( 2 );
		$this->stubFailed( 0 );
		$this->stubUser( 0 );
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_PRESERVED_POST_IDS, array() )
			->andReturn( array( 501, 777 ) );
		WP_Mock::userFunction( 'get_edit_post_link' )
			->andReturnUsing(
				static function ( $id ): string {
					return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
				}
			);
		WP_Mock::userFunction( 'get_the_title' )
			->andReturnUsing(
				static function ( $id ): string {
					return '商品' . (int) $id;
				}
			);
		WP_Mock::userFunction( 'wp_nonce_url' )->andReturn( 'https://example.test/dismiss' );
		WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'https://example.test/current' );
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
		WP_Mock::passthruFunction( 'esc_html' );
		WP_Mock::passthruFunction( 'esc_html__' );
		WP_Mock::passthruFunction( 'esc_url' );

		ob_start();
		OffersMigrationNotice::maybeRender();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'post=501&action=edit', $output, '対象商品への導線が無い' );
		$this->assertStringContainsString( 'post=777&action=edit', $output, '対象商品への導線が無い' );
		$this->assertStringContainsString( '商品501', $output );
	}

	/**
	 * 移行中の通知は「この通知に対して何かできる人」にだけ出す。
	 *
	 * 画面の限定（affilicard 配下のみ）を外した結果、権限のゲートが無いと購読者が
	 * profile.php を開いただけで消せないプラグインの警告を見ることになる。
	 */
	public function test_edit_postsを持たない利用者には未完通知を出さない(): void {
		$this->stubEditPosts( false );
		$this->stubScreen( 'profile' );
		$this->stubCursor( '480' );

		$this->assertFalse( OffersMigrationNotice::shouldShowPending() );
	}

	/**
	 * 「ほか N 件」を件数の引き算で出さない。
	 *
	 * 上の件数は offer 単位、商品一覧は商品単位である。1 商品が温存 offer を 2 つ持つと
	 * 引き算は 1 になり、存在しない商品が隠れているかのように報告してしまう。
	 */
	public function test_温存通知は存在しない商品を隠れ件数として報告しない(): void {
		$this->stubEditPosts( true );
		$this->stubScreen( 'affilicard_product' );
		$this->stubCursor( false );
		// 1 商品が温存 offer を 2 件持つ状況（件数 2・商品 1）。
		$this->stubPreserved( 2 );
		$this->stubFailed( 0 );
		$this->stubUser( 0 );
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_PRESERVED_POST_IDS, array() )
			->andReturn( array( 501 ) );
		WP_Mock::userFunction( 'get_edit_post_link' )->andReturn( 'https://example.test/edit' );
		WP_Mock::userFunction( 'get_the_title' )->andReturn( '商品501' );
		WP_Mock::userFunction( 'wp_nonce_url' )->andReturn( 'https://example.test/dismiss' );
		WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'https://example.test/current' );
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
		WP_Mock::passthruFunction( 'esc_html' );
		WP_Mock::passthruFunction( 'esc_html__' );
		WP_Mock::passthruFunction( 'esc_url' );

		ob_start();
		OffersMigrationNotice::maybeRender();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '商品501', $output );
		$this->assertStringNotContainsString( 'ほか', $output, '存在しない商品を隠れ件数として報告している' );
	}

	/**
	 * 移行を諦めた商品は通知に出す。
	 *
	 * 諦めた商品はカーソルが通り過ぎて二度と再訪しないため、記録と通知が唯一の痕跡である。
	 * 黙って旧形式のまま取り残すと、原因（別プラグインの meta フィルタ等）が永久に直らない。
	 */
	public function test_移行を諦めた商品があれば通知を出す(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubFailed( 2, array( 909 ) );
		$this->stubUser( 0 );

		$this->assertTrue( OffersMigrationNotice::shouldShowFailed() );
	}

	public function test_諦めた商品が0件なら通知しない(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubFailed( 0 );

		$this->assertFalse( OffersMigrationNotice::shouldShowFailed() );
	}

	public function test_諦め通知はaffilicard以外の画面では出さない(): void {
		$this->stubScreen( 'post' );
		$this->stubFailed( 2, array( 909 ) );

		$this->assertFalse( OffersMigrationNotice::shouldShowFailed() );
	}

	/** dismiss は件数で覚える（諦めた商品が増えたら出し直す）。 */
	public function test_dismiss後に諦めた件数が増えたら通知を出し直す(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubFailed( 5, array( 909 ) );
		$this->stubUser( 0, 1 );

		$this->assertTrue( OffersMigrationNotice::shouldShowFailed() );
	}

	/**
	 * 温存通知を閉じても諦め通知は黙らない（dismiss の記録キーが別）。
	 *
	 * 2 つは別の事象で、運用が取るべき行動も違う。片方を閉じたらもう片方まで
	 * 隠れる作りにすると、より深刻な「移行できていない」方が見えなくなる。
	 */
	public function test_温存通知をdismissしても諦め通知は出る(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubPreserved( 3 );
		$this->stubFailed( 2, array( 909 ) );
		$this->stubUser( 3 );

		$this->assertFalse( OffersMigrationNotice::shouldShowPreserved() );
		$this->assertTrue( OffersMigrationNotice::shouldShowFailed() );
	}

	/**
	 * 諦め通知は温存通知と別の文言で、対象商品への導線を出す。
	 *
	 * 温存は「データは移行できたが次の保存で消える」猶予の話、諦めは「書き込み自体が
	 * 効かず旧形式のまま取り残された」話である。同じ文言に畳むと、運用は誤った対処
	 * （通常 URL の追加）へ誘導される。
	 */
	public function test_諦め通知は温存通知と別の文言で対象商品への導線を出す(): void {
		$this->stubEditPosts( true );
		$this->stubScreen( 'affilicard_product' );
		$this->stubCursor( false );
		$this->stubPreserved( 1 );
		$this->stubFailed( 1, array( 909 ) );
		$this->stubUser( 0 );
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_PRESERVED_POST_IDS, array() )
			->andReturn( array( 501 ) );
		WP_Mock::userFunction( 'get_edit_post_link' )
			->andReturnUsing(
				static function ( $id ): string {
					return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
				}
			);
		WP_Mock::userFunction( 'get_the_title' )
			->andReturnUsing(
				static function ( $id ): string {
					return '商品' . (int) $id;
				}
			);
		WP_Mock::userFunction( 'wp_nonce_url' )->andReturn( 'https://example.test/dismiss' );
		WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'https://example.test/current' );
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
		WP_Mock::passthruFunction( 'esc_html' );
		WP_Mock::passthruFunction( 'esc_html__' );
		WP_Mock::passthruFunction( 'esc_url' );

		ob_start();
		OffersMigrationNotice::maybeRender();
		$output = (string) ob_get_clean();

		// 諦め: 保存できなかったこと・旧形式のまま残ること。
		$this->assertStringContainsString( '新しい形式へ保存できなかった商品が 1 件', $output );
		$this->assertStringContainsString( '旧形式のまま残り', $output );
		// 温存: 次の保存で消えること（＝別の事象として併記されている）。
		$this->assertStringContainsString( '購入リンクを維持した listing が 1 件', $output );
		// 諦めた商品への導線。
		$this->assertStringContainsString( 'post=909&action=edit', $output, '諦めた商品への導線が無い' );
		$this->assertStringContainsString( '商品909', $output );
	}

	/**
	 * dismiss は「表示した件数」で閉じる（押下時の最新件数ではない）。
	 *
	 * 通知を出してからクリックするまでの間に後続バッチが件数を増やすことがある。
	 * 押下時に読み直すと、利用者が見ていない分まで抑止してしまう。表示した件数を
	 * URL で持ち回ることで「見たぶんだけ閉じる」になる。
	 */
	public function test_dismissは表示した件数で閉じる(): void {
		$_GET = array(
			'affilicard_dismiss_offers_migration_notice' => '1',
			'affilicard_dismissed_count'                 => '2',
		);

		$saved = null;
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		WP_Mock::userFunction( 'check_admin_referer' )->once()->with( 'affilicard_dismiss_offers_migration_notice:2' );
		WP_Mock::userFunction( 'update_user_meta' )
			->andReturnUsing(
				function ( $user_id, $key, $value ) use ( &$saved ): bool {
					$saved = $value;
					return true;
				}
			);
		WP_Mock::userFunction( 'remove_query_arg' )->andReturn( 'https://example.test/back' );
		WP_Mock::userFunction( 'wp_unslash', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'sanitize_text_field', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'wp_safe_redirect' )->andReturnUsing(
			static function (): void {
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			OffersMigrationNotice::maybeHandleDismiss();
			$this->fail( 'リダイレクトしていない' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame( 2, $saved, '表示した件数（2）ではなく別の値で閉じている' );
	}

	/**
	 * URL の件数を書き換えても nonce が一致せず弾かれる。
	 *
	 * 件数を nonce action に織り込んでいるので、数字だけ大きくして
	 * 「まだ見ていない分まで閉じる」ことはできない。
	 */
	public function test_dismissの件数を書き換えるとnonceが一致しない(): void {
		$_GET = array(
			'affilicard_dismiss_offers_migration_notice' => '1',
			'affilicard_dismissed_count'                 => '999',
		);

		WP_Mock::userFunction( 'wp_unslash', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'sanitize_text_field', array( 'return_arg' => 0 ) );
		// 改竄された件数がそのまま nonce action に入る＝発行時の action と一致しない。
		WP_Mock::userFunction( 'check_admin_referer' )
			->once()
			->with( 'affilicard_dismiss_offers_migration_notice:999' )
			->andReturnUsing(
				static function (): void {
					throw new \RuntimeException( 'nonce mismatch' );
				}
			);
		WP_Mock::userFunction( 'update_user_meta' )->never();

		try {
			OffersMigrationNotice::maybeHandleDismiss();
			$this->fail( 'nonce 検証を通ってしまった' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'nonce mismatch', $e->getMessage() );
		}
	}

	/**
	 * 表示件数と dismiss URL の件数は同じスナップショットを使う。
	 *
	 * URL 用と表示用で件数を読み直すと、その間に移行バッチが件数を増やしたとき
	 * 「文言は N+1 件なのに nonce に載るのは N 件」になり、閉じた直後にまた通知が出る。
	 * ここでは読むたびに増える get_option をスタブし、1 回しか読まないことを固定する。
	 */
	public function test_表示件数とdismissURLの件数が食い違わない(): void {
		$this->stubEditPosts( true );
		$this->stubScreen( 'affilicard_product' );
		$this->stubCursor( false );
		$this->stubFailed( 0 );
		$this->stubUser( 0 );

		// 呼ばれるたびに 1 件ずつ増える（並行する移行バッチの再現）。
		$reads = 0;
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL, 0 )
			->andReturnUsing(
				static function () use ( &$reads ): int {
					++$reads;
					return $reads;
				}
			);
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_PRESERVED_POST_IDS, array() )
			->andReturn( array() );

		$nonce_count = null;
		WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing(
				static function ( $args ) use ( &$nonce_count ): string {
					if ( is_array( $args ) && isset( $args['affilicard_dismissed_count'] ) ) {
						$nonce_count = (int) $args['affilicard_dismissed_count'];
					}
					return 'https://example.test/current';
				}
			);
		WP_Mock::userFunction( 'wp_nonce_url' )->andReturn( 'https://example.test/dismiss' );
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
		WP_Mock::passthruFunction( 'esc_html' );
		WP_Mock::passthruFunction( 'esc_html__' );
		WP_Mock::passthruFunction( 'esc_url' );

		ob_start();
		OffersMigrationNotice::maybeRender();
		$output = (string) ob_get_clean();

		$this->assertNotNull( $nonce_count, 'dismiss URL に件数が載っていない' );
		$this->assertStringContainsString(
			(string) $nonce_count . ' 件',
			$output,
			sprintf( '表示（%s）と dismiss URL（%d 件）で件数が食い違っている', $output, $nonce_count )
		);
	}
}
