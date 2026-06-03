<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Rest;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Rest\RefreshController;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;

final class RefreshControllerTest extends TestCase {
	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp(); }
	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown(); }

	public function test_refresh_all_when_no_platform(): void {
		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'run' )->once()->with( false );
		$refresher->shouldNotReceive( 'runForPlatform' );

		$req = new WP_REST_Request();
		$req->set_param( 'platform', '' );

		$res = ( new RefreshController( $refresher ) )->handle( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'all', $res->get_data()['scope'] );
		$this->assertFalse( $res->get_data()['force'] );
	}

	public function test_refresh_all_force_when_force_true(): void {
		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'run' )->once()->with( true );

		$req = new WP_REST_Request();
		$req->set_param( 'platform', '' );
		$req->set_param( 'force', true );

		$res = ( new RefreshController( $refresher ) )->handle( $req );
		$this->assertTrue( $res->get_data()['force'] );
	}

	public function test_refresh_for_platform_when_specified(): void {
		$refresher = Mockery::mock( ListingRefresher::class );
		$refresher->shouldReceive( 'runForPlatform' )->once()->with( 'dmm-books', false );
		$refresher->shouldNotReceive( 'run' );

		$req = new WP_REST_Request();
		$req->set_param( 'platform', 'dmm-books' );

		$res = ( new RefreshController( $refresher ) )->handle( $req );
		$this->assertSame( 'dmm-books', $res->get_data()['scope'] );
	}

	public function test_permission_requires_manage_options(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( false );
		$this->assertFalse( ( new RefreshController( Mockery::mock( ListingRefresher::class ) ) )->canManageOptions() );
	}
}
