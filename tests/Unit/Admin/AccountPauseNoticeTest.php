<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Admin;

use Affilicard\Account\AccountRegistry;
use Affilicard\Account\AmazonAccount;
use Affilicard\Account\RakutenAccount;
use Affilicard\Admin\AccountPauseNotice;
use Affilicard\Queue\AccountPause;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * account を止めている間（AccountPause）の管理画面通知。
 */
final class AccountPauseNoticeTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$this->options = array();
		WP_Mock::userFunction( 'get_option' )
			->with( Mockery::pattern( '/^affilicard_account_pause_/' ), null )
			->andReturnUsing( fn( $key ) => $this->options[ $key ] ?? null );
		WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'esc_html' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'esc_html__' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'esc_url' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'admin_url' )->andReturnUsing( fn( $path ) => 'https://example.test/wp-admin/' . $path );
		WP_Mock::userFunction( 'wp_date' )->andReturn( '11/26 15:00' );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	private function registry(): AccountRegistry {
		$registry = new AccountRegistry();
		$registry->register( new AmazonAccount() );
		$registry->register( new RakutenAccount() );
		return $registry;
	}

	private function pause( string $account, int $until, string $reason ): void {
		$this->options[ AccountPause::OPTION_PREFIX . $account ] = array(
			'until'  => $until,
			'reason' => $reason,
			'since'  => time() - 60,
			'streak' => 1,
		);
	}

	/** @param array<string, mixed> $props */
	private function stubScreen( ?array $props ): void {
		$screen = null;
		if ( null !== $props ) {
			$screen = new \stdClass();
			foreach ( $props as $key => $value ) {
				$screen->{$key} = $value;
			}
		}
		WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );
	}

	private function render( bool $canManage ): string {
		WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( $canManage );
		ob_start();
		( new AccountPauseNotice( $this->registry() ) )->maybeRender();
		return (string) ob_get_clean();
	}

	public function test_止めているaccountだけを返す_期限切れは含めない(): void {
		$now = time();
		$this->pause( 'amazon', $now + 600, AccountPause::REASON_NOT_ELIGIBLE );
		$this->pause( 'rakuten', $now - 1, AccountPause::REASON_RATE_LIMITED );

		$rows = ( new AccountPauseNotice( $this->registry() ) )->pausedAccounts( $now );

		$this->assertSame(
			array(
				array(
					'label'  => 'Amazon',
					'reason' => AccountPause::REASON_NOT_ELIGIBLE,
					'until'  => $now + 600,
				),
			),
			$rows
		);
	}

	public function test_ダッシュボードで止めていれば理由と設定へのリンクを出す(): void {
		$this->pause( 'amazon', time() + 600, AccountPause::REASON_RATE_LIMITED );
		$this->stubScreen( array( 'id' => 'dashboard' ) );

		$html = $this->render( true );

		$this->assertStringContainsString( 'affilicard-account-pause-notice', $html );
		$this->assertStringContainsString( 'Amazon', $html );
		$this->assertStringContainsString( '呼び出し回数の上限', $html );
		$this->assertStringContainsString( '11/26 15:00', $html );
		$this->assertStringContainsString( 'page=affilicard-settings', $html );
	}

	public function test_403なら利用条件の文言を出す(): void {
		$this->pause( 'amazon', time() + 600, AccountPause::REASON_NOT_ELIGIBLE );
		$this->stubScreen(
			array(
				'id'        => 'edit-affilicard_product',
				'post_type' => 'affilicard_product',
			)
		);

		$this->assertStringContainsString( '適格販売', $this->render( true ) );
	}

	public function test_止めていなければ何も出さない(): void {
		$this->stubScreen( array( 'id' => 'dashboard' ) );

		$this->assertSame( '', $this->render( true ) );
	}

	public function test_manage_optionsが無ければ出さない(): void {
		$this->pause( 'amazon', time() + 600, AccountPause::REASON_RATE_LIMITED );
		$this->stubScreen( array( 'id' => 'dashboard' ) );

		$this->assertSame( '', $this->render( false ) );
	}

	public function test_対象外の画面では出さない(): void {
		$this->pause( 'amazon', time() + 600, AccountPause::REASON_RATE_LIMITED );
		$this->stubScreen(
			array(
				'id'        => 'edit-post',
				'post_type' => 'post',
			)
		);

		$this->assertSame( '', $this->render( true ) );
	}
}
