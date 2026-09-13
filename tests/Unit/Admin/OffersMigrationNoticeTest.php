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

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	private function stubScreen( ?string $postType ): void {
		$screen            = new \stdClass();
		$screen->post_type = $postType;
		WP_Mock::userFunction( 'get_current_screen' )->andReturn( null === $postType ? null : $screen );
	}

	/** @param int|false $cursor カーソル option の値（false は未設定＝移行なし）。 */
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

	private function stubUser( int $dismissed ): void {
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		WP_Mock::userFunction( 'get_user_meta' )
			->with( 7, 'affilicard_offers_migration_notice_dismissed', true )
			->andReturn( $dismissed );
	}

	public function test_移行が未完なら未完通知を出す(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubCursor( 480 );

		$this->assertTrue( OffersMigrationNotice::shouldShowPending() );
	}

	/** カーソル 0（走り始めた直後）も未完。値ではなく存在で判定する。 */
	public function test_カーソルが0でも未完通知を出す(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubCursor( 0 );

		$this->assertTrue( OffersMigrationNotice::shouldShowPending() );
	}

	public function test_移行が完走していれば未完通知を出さない(): void {
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
		$this->stubCursor( 480 );
		$this->stubPreserved( 3 );
		$this->stubUser( 0 );

		$this->assertTrue( OffersMigrationNotice::shouldShowPreserved() );
	}

	public function test_温存が0件なら通知しない(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubPreserved( 0 );

		$this->assertFalse( OffersMigrationNotice::shouldShowPreserved() );
	}

	public function test_dismiss済みなら温存通知を出さない(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubPreserved( 3 );
		$this->stubUser( 1 );

		$this->assertFalse( OffersMigrationNotice::shouldShowPreserved() );
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
		$this->stubScreen( 'post' );
		$this->stubCursor( 480 );

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
		$this->stubScreen( 'affilicard_product' );
		$this->stubCursor( false );
		$this->stubPreserved( 2 );
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
}
