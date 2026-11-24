<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\Amazon\AmazonProvider;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Provider\Rakuten\RakutenProvider;
use Affilicard\Queue\BatchSizer;
use Affilicard\Queue\Enqueuer;
use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Rest\RefreshController;
use Affilicard\Settings\GeneralSettings;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;

/**
 * 手動更新（「このプラットフォームを更新」）で、まとめ取得できる Provider（Amazon）の listing を
 * 1 件ずつではなく、掃引と同じバッチジョブ（手動の優先度）に積むこと。
 * まとめ取得できない Provider（楽天）は従来どおり 1 件ずつ。
 */
final class RefreshControllerBatchTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $t ) => $t );
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'         => 'amazon-kindle',
						'name'         => 'Amazon Kindle',
						'provider'     => 'amazon-creators',
						'displayOrder' => 2,
						'enabled'      => true,
					),
					array(
						'code'         => 'rakuten-kobo',
						'name'         => '楽天Kobo',
						'provider'     => 'rakuten-kobo',
						'displayOrder' => 3,
						'enabled'      => true,
					),
				)
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	private function registry(): ProviderRegistry {
		$registry = new ProviderRegistry();
		$registry->register( new RakutenProvider() );
		$registry->register( new AmazonProvider() );
		return $registry;
	}

	private static function listing( string $platform, string $externalId ): array {
		return array(
			'platform'    => $platform,
			'enabled'     => true,
			'update_mode' => 'auto',
			'auto_update' => true,
			'offers'      => array( array( 'external_id' => $externalId ) ),
		);
	}

	private static function product( int $id, array $listings ): array {
		return array(
			'id'       => $id,
			'title'    => '作品 ' . $id,
			'listings' => $listings,
		);
	}

	private function controller( ProductRepositoryInterface $repo ): RefreshController {
		$registry = $this->registry();
		return new RefreshController( $repo, new Enqueuer( 500, 300, array(), $registry ), new BatchSizer( $registry ) );
	}

	/**
	 * @param list<int> $ids
	 */
	private function repo( array $ids ): ProductRepositoryInterface {
		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( $ids );
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		foreach ( $ids as $id ) {
			$repo->shouldReceive( 'find' )->with( $id )->andReturn(
				self::product(
					$id,
					array(
						self::listing( 'amazon-kindle', sprintf( 'B%09d', $id ) ),
						self::listing( 'rakuten-kobo', 'rk' . $id ),
					)
				)
			);
		}
		return $repo;
	}

	public function test_Amazonは手動の優先度でバッチジョブにまとめ_楽天は1件ずつ積む(): void {
		$controller = $this->controller( $this->repo( array( 1, 2, 3 ) ) );

		$batches = array();
		$singles = array();
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->andReturn( null );
		WP_Mock::userFunction( 'as_schedule_single_action' )->andReturnUsing(
			static function ( $when, $hook, $args, $group, $unique, $priority ) use ( &$batches, &$singles ) {
				if ( Enqueuer::HOOK_REFRESH_BATCH === $hook ) {
					$batches[] = compact( 'args', 'group', 'unique', 'priority' );
				} else {
					$singles[] = compact( 'hook', 'args', 'group', 'priority' );
				}
				return 1;
			}
		);

		$req = new WP_REST_Request();
		$req->set_param( 'platform', '' );
		$res = $controller->handle( $req );

		$this->assertSame( 6, $res->get_data()['queued'] );
		$this->assertCount( 1, $batches );
		$this->assertSame( 'amazon', $batches[0]['args']['account'] );
		$this->assertSame( 'affilicard-amazon', $batches[0]['group'] );
		$this->assertSame( Enqueuer::PRIORITY_MANUAL, $batches[0]['priority'] );
		$this->assertTrue( $batches[0]['unique'] );
		$this->assertSame(
			array(
				array(
					'post_id'  => 1,
					'platform' => 'amazon-kindle',
				),
				array(
					'post_id'  => 2,
					'platform' => 'amazon-kindle',
				),
				array(
					'post_id'  => 3,
					'platform' => 'amazon-kindle',
				),
			),
			$batches[0]['args']['items']
		);
		// 楽天はまとめ取得できないので、従来どおり 1 件ずつの手動ジョブ。
		$this->assertCount( 3, $singles );
		foreach ( $singles as $single ) {
			$this->assertSame( Enqueuer::HOOK_REFRESH, $single['hook'] );
			$this->assertSame( 'rakuten-kobo', $single['args']['platform'] );
			$this->assertSame( Enqueuer::PRIORITY_MANUAL, $single['priority'] );
		}
	}

	public function test_バッチは掃引と同じ件数で区切る(): void {
		$controller = $this->controller( $this->repo( array( 1, 2, 3 ) ) );
		// 掃引と同じ BatchSizer の計算（フィルタで上書きできる）を使う。
		WP_Mock::onFilter( 'affilicard_refresh_batch_size' )->with( 220, 'amazon' )->reply( 2 );

		$sizes = array();
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->andReturn( null );
		WP_Mock::userFunction( 'as_schedule_single_action' )->andReturnUsing(
			static function ( $when, $hook, $args ) use ( &$sizes ) {
				if ( Enqueuer::HOOK_REFRESH_BATCH === $hook ) {
					$sizes[] = count( $args['items'] );
				}
				return 1;
			}
		);

		$req = new WP_REST_Request();
		$req->set_param( 'platform', 'amazon-kindle' );
		$res = $controller->handle( $req );

		$this->assertSame( 3, $res->get_data()['queued'] );
		$this->assertSame( array( 2, 1 ), $sizes );
	}
}
