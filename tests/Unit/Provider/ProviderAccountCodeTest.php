<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider;

use Affilicard\Provider\Dmm\DmmProvider;
use Affilicard\Provider\ManualProvider;
use Affilicard\Provider\Rakuten\RakutenProvider;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProviderAccountCodeTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $t ) => $t );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_account_codes(): void {
		$this->assertNull( ( new ManualProvider() )->accountCode() );
		$this->assertSame( 'dmm', ( new DmmProvider() )->accountCode() );
		$this->assertSame( 'rakuten', ( new RakutenProvider() )->accountCode() );
	}

	public function test_credentials_schema_removed_from_interface(): void {
		$this->assertFalse(
			method_exists( ManualProvider::class, 'credentialsSchema' ),
			'credentialsSchema は ProviderInterface から撤去され Account へ移設された'
		);
	}
}
