<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Admin;

use Affilicard\Admin\DerivedMetaSyncNotice;
use Affilicard\Repository\DerivedMetaSync;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * extid ミラーを作り直せなかった商品の通知を検証する。
 *
 * 再試行を積めた失敗は Action Scheduler の一覧に残るが、**積めなかった失敗はどこにも
 * 残らない**。ミラーは findByExternalId() の索引そのもので、古いままだと自動作成が
 * 既存商品を見落として重複を作る——運用が実際に見る場所へ出す必要がある。
 */
final class DerivedMetaSyncNoticeTest extends TestCase {

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

	/**
	 * 記録されている件数と post ID。
	 *
	 * **数値は文字列で返す。** WordPress は option / user meta を DB から文字列として
	 * 返すため、int を返すスタブは本番より緩い。
	 *
	 * @param list<int> $ids
	 */
	private function stubUnsynced( int $count, array $ids = array() ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( DerivedMetaSync::OPTION_UNSYNCED_COUNT, 0 )
			->andReturn( (string) $count );
		WP_Mock::userFunction( 'get_option' )
			->with( DerivedMetaSync::OPTION_UNSYNCED_POST_IDS, array() )
			->andReturn( $ids );
	}

	/** 「閉じた時点の件数」として記録されている値（0＝未 dismiss）。 */
	private function stubUser( int $dismissed ): void {
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		WP_Mock::userFunction( 'get_user_meta' )
			->with( 7, 'affilicard_derived_meta_unsynced_notice_dismissed', true )
			->andReturn( (string) $dismissed );
	}

	public function test_作り直せなかった商品があれば通知を出す(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubUnsynced( 1, array( 909 ) );
		$this->stubUser( 0 );

		$this->assertTrue( DerivedMetaSyncNotice::shouldShow() );
	}

	public function test_0件なら通知しない(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubUnsynced( 0 );

		$this->assertFalse( DerivedMetaSyncNotice::shouldShow() );
	}

	public function test_affilicard以外の画面では出さない(): void {
		$this->stubScreen( 'post' );
		$this->stubUnsynced( 3, array( 909 ) );

		$this->assertFalse( DerivedMetaSyncNotice::shouldShow() );
	}

	public function test_同じ件数でdismiss済みなら出さない(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubUnsynced( 3, array( 909 ) );
		$this->stubUser( 3 );

		$this->assertFalse( DerivedMetaSyncNotice::shouldShow() );
	}

	/**
	 * 閉じたあとに増えたら出し直す。
	 *
	 * 真偽値で覚えると、増えたぶんを誰にも知らせないまま索引がずれ続ける。
	 */
	public function test_dismiss後に件数が増えたら出し直す(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubUnsynced( 5, array( 909 ) );
		$this->stubUser( 1 );

		$this->assertTrue( DerivedMetaSyncNotice::shouldShow() );
	}

	/**
	 * 件数だけでなく、直すべき商品への編集リンクを出す。
	 *
	 * 「3 件ずれています」とだけ言われても運用は何もできない。この通知の直し方は
	 * 「その商品を開いて保存し直す」なので、商品を名指しできなければ意味がない。
	 */
	public function test_対象商品への編集リンクを出す(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubUnsynced( 2, array( 501, 777 ) );
		$this->stubUser( 0 );
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
		DerivedMetaSyncNotice::maybeRender();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'post=501&action=edit', $output, '対象商品への導線が無い' );
		$this->assertStringContainsString( 'post=777&action=edit', $output, '対象商品への導線が無い' );
		$this->assertStringContainsString( '商品501', $output );
	}

	/** 出す条件を満たさなければ何も描かない。 */
	public function test_条件を満たさなければ何も描かない(): void {
		$this->stubScreen( 'affilicard_product' );
		$this->stubUnsynced( 0 );

		ob_start();
		DerivedMetaSyncNotice::maybeRender();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_registerは通知とdismissハンドラを配線する(): void {
		WP_Mock::expectActionAdded( 'admin_notices', array( DerivedMetaSyncNotice::class, 'maybeRender' ) );
		WP_Mock::expectActionAdded( 'admin_init', array( DerivedMetaSyncNotice::class, 'maybeHandleDismiss' ) );

		DerivedMetaSyncNotice::register();

		$this->assertConditionsMet();
	}
}
