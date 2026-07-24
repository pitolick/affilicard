<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Account\AccountRegistry;
use Affilicard\Account\DmmAccount;
use Affilicard\Account\RakutenAccount;
use Affilicard\Queue\ActionStoreInterface;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\QueueStats;
use Affilicard\Rest\QueueController;
use Affilicard\Settings\GeneralSettings;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;

/**
 * QueueController のテスト。
 *
 * QueueStats は final class のため Mockery でモックできず、他の Queue 系テスト（例:
 * RefreshControllerTest が Enqueuer を実インスタンスで使う）と同様に実 QueueStats を使い、
 * 内部で呼ぶ as_get_scheduled_actions を WP_Mock で固定する（QueueStatsTest と同じ手法）。
 *
 * failed 系（deleteFailed/retryFailed）は ActionStoreInterface（このタスクで新設した境界）
 * を Mockery でモックして検証する。
 *
 * v2.4.0: provider コード単位から account コード単位へ統一。group/summary キーは
 * account コード（'rakuten'/'dmm'）。
 */
final class QueueControllerTest extends TestCase {

	private const RAKUTEN_GROUP = 'affilicard-rakuten';
	private const DMM_GROUP     = 'affilicard-dmm';

	/** @var list<string> */
	private array $accounts = array( 'rakuten', 'dmm' );

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
		Mockery::close();
		parent::tearDown();
	}

	private function stats(): QueueStats {
		return new QueueStats( $this->accounts );
	}

	private function accountRegistry(): AccountRegistry {
		$registry = new AccountRegistry();
		$registry->register( new RakutenAccount() );
		$registry->register( new DmmAccount() );
		return $registry;
	}

	private function controller( ?ActionStoreInterface $actionStore = null ): QueueController {
		return new QueueController(
			$this->stats(),
			$this->accounts,
			$actionStore ?? Mockery::mock( ActionStoreInterface::class ),
			$this->accountRegistry()
		);
	}

	public function test_canManageOptions_manage_optionsを要求する(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
		$this->assertTrue( $this->controller()->canManageOptions() );
	}

	public function test_canManageOptions_権限がなければfalse(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( false );
		$this->assertFalse( $this->controller()->canManageOptions() );
	}

	/** provider 別 status 別件数を 0 件で固定する共通スタブ（stats/clearAll/cancelPending の前提）。 */
	private function stubEmptyQueueForAllProviders(): void {
		foreach ( array( self::RAKUTEN_GROUP, self::DMM_GROUP ) as $group ) {
			foreach ( array( 'pending', 'in-progress', 'failed' ) as $status ) {
				WP_Mock::userFunction( 'as_get_scheduled_actions' )
					->with(
						array(
							'group'    => $group,
							'status'   => $status,
							'per_page' => -1,
						),
						'ids'
					)
					->andReturn( array() );
			}
		}
	}

	public function test_stats_summaryとdepthとpausedを返す(): void {
		$this->stubEmptyQueueForAllProviders();
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array( 'queue_paused' => true ) );

		$res  = $this->controller()->stats( new WP_REST_Request() );
		$data = $res->get_data();

		$this->assertSame( 200, $res->get_status() );
		$this->assertArrayHasKey( 'summary', $data );
		$this->assertArrayHasKey( 'depth', $data );
		$this->assertArrayHasKey( 'paused', $data );
		$this->assertSame( array( 'rakuten', 'dmm' ), array_keys( $data['summary'] ) );
		// summary の各 account 行には code/label（AccountRegistry 由来）が埋め込まれ、
		// JS が account コード→表示ラベルの対応表をハードコードしなくて済む（v2.4.0）。
		$this->assertSame( 'rakuten', $data['summary']['rakuten']['code'] );
		$this->assertSame( '楽天', $data['summary']['rakuten']['label'] );
		$this->assertSame( 'dmm', $data['summary']['dmm']['code'] );
		$this->assertSame( 'DMM', $data['summary']['dmm']['label'] );
		$this->assertSame( 0, $data['depth'] );
		$this->assertTrue( $data['paused'] );
	}

	public function test_pause_pausedtrueでGeneralSettingsを更新し新状態を返す(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array( 'queue_paused' => false ) );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertSame( GeneralSettings::OPTION_KEY, $key );
					$this->assertTrue( $value['queue_paused'] );
					return true;
				}
			);

		$req = new WP_REST_Request();
		$req->set_param( 'paused', true );

		$res = $this->controller()->pause( $req );

		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertTrue( $res->get_data()['paused'] );
	}

	public function test_pause_pausedfalseでGeneralSettingsを更新し新状態を返す(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array( 'queue_paused' => true ) );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing(
				function ( $key, $value, $autoload ) {
					$this->assertFalse( $value['queue_paused'] );
					return true;
				}
			);

		$req = new WP_REST_Request();
		$req->set_param( 'paused', false );

		$res = $this->controller()->pause( $req );

		$this->assertFalse( $res->get_data()['paused'] );
	}

	public function test_clearAll_provider毎にpendingをunscheduleしclearedを返す(): void {
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => self::RAKUTEN_GROUP,
					'status'   => 'pending',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array( 1, 2, 3 ) );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => self::DMM_GROUP,
					'status'   => 'pending',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array( 4 ) );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )
			->once()->with( '', array(), self::RAKUTEN_GROUP );
		WP_Mock::userFunction( 'as_unschedule_all_actions' )
			->once()->with( '', array(), self::DMM_GROUP );

		$res = $this->controller()->clearAll( new WP_REST_Request() );

		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertSame( 4, $res->get_data()['cleared'] );
	}

	public function test_cancelPending_provider毎にpendingをunscheduleしcancelledを返す(): void {
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => self::RAKUTEN_GROUP,
					'status'   => 'pending',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array( 1 ) );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => self::DMM_GROUP,
					'status'   => 'pending',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array() );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )
			->once()->with( '', array(), self::RAKUTEN_GROUP );
		WP_Mock::userFunction( 'as_unschedule_all_actions' )
			->once()->with( '', array(), self::DMM_GROUP );

		$res = $this->controller()->cancelPending( new WP_REST_Request() );

		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertSame( 1, $res->get_data()['cancelled'] );
	}

	public function test_deleteFailed_provider毎にfailedをstoreから削除しdeletedを返す(): void {
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => self::RAKUTEN_GROUP,
					'status'   => 'failed',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array( 10, 11 ) );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => self::DMM_GROUP,
					'status'   => 'failed',
					'per_page' => -1,
				),
				'ids'
			)
			->andReturn( array() );

		$actionStore = Mockery::mock( ActionStoreInterface::class );
		$actionStore->shouldReceive( 'deleteAction' )->once()->with( 10 );
		$actionStore->shouldReceive( 'deleteAction' )->once()->with( 11 );

		$res = $this->controller( $actionStore )->deleteFailed( new WP_REST_Request() );

		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertSame( 2, $res->get_data()['deleted'] );
	}

	public function test_retryFailed_provider毎にfailedを再scheduleしstoreから削除しretriedを返す(): void {
		$action = new class() {
			public function get_hook(): string {
				return Enqueuer::HOOK_REFRESH;
			}

			/** @return array<string, mixed> */
			public function get_args(): array {
				return array(
					'post_id'  => 5,
					'platform' => 'rakuten-kobo',
				);
			}
		};

		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => self::RAKUTEN_GROUP,
					'status'   => 'failed',
					'per_page' => -1,
				)
			)
			->andReturn( array( 20 => $action ) );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => self::DMM_GROUP,
					'status'   => 'failed',
					'per_page' => -1,
				)
			)
			->andReturn( array() );

		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 5,
					'platform' => 'rakuten-kobo',
				),
				self::RAKUTEN_GROUP,
				true,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 999 );

		$actionStore = Mockery::mock( ActionStoreInterface::class );
		$actionStore->shouldReceive( 'deleteAction' )->once()->with( 20 );

		$res = $this->controller( $actionStore )->retryFailed( new WP_REST_Request() );

		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertSame( 1, $res->get_data()['retried'] );
	}

	public function test_retryFailed_失敗actionがhook取得メソッドを持たない場合はスキップする(): void {
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => self::RAKUTEN_GROUP,
					'status'   => 'failed',
					'per_page' => -1,
				)
			)
			->andReturn( array( 30 => 'not-an-action-object' ) );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => self::DMM_GROUP,
					'status'   => 'failed',
					'per_page' => -1,
				)
			)
			->andReturn( array() );

		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$actionStore = Mockery::mock( ActionStoreInterface::class );
		$actionStore->shouldNotReceive( 'deleteAction' );

		$res = $this->controller( $actionStore )->retryFailed( new WP_REST_Request() );

		$this->assertSame( 0, $res->get_data()['retried'] );
	}

	/**
	 * as_schedule_single_action は $unique=true で同一 hook/args の pending が既に存在すると
	 * 0（no-op）を返す。この場合、元の failed action を削除してはならない（再試行が実質
	 * 行われていないため、削除すると失敗記録もキュー表示件数も失われる）。
	 */
	public function test_retryFailed_scheduleが0を返すno_opの場合は元のfailedを削除せずretriedにも数えない(): void {
		$action = new class() {
			public function get_hook(): string {
				return Enqueuer::HOOK_REFRESH;
			}

			/** @return array<string, mixed> */
			public function get_args(): array {
				return array(
					'post_id'  => 7,
					'platform' => 'rakuten-kobo',
				);
			}
		};

		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => self::RAKUTEN_GROUP,
					'status'   => 'failed',
					'per_page' => -1,
				)
			)
			->andReturn( array( 40 => $action ) );
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->with(
				array(
					'group'    => self::DMM_GROUP,
					'status'   => 'failed',
					'per_page' => -1,
				)
			)
			->andReturn( array() );

		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 7,
					'platform' => 'rakuten-kobo',
				),
				self::RAKUTEN_GROUP,
				true,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 0 );

		$actionStore = Mockery::mock( ActionStoreInterface::class );
		$actionStore->shouldNotReceive( 'deleteAction' );

		$res = $this->controller( $actionStore )->retryFailed( new WP_REST_Request() );

		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertSame( 0, $res->get_data()['retried'] );
	}
}
