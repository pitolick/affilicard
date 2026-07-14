<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider;

use Affilicard\Provider\ManualProvider;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ManualProviderTest extends TestCase {

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
		parent::tearDown();
	}

	public function test_basic_metadata(): void {
		$provider = new ManualProvider();
		$this->assertSame( 'manual', $provider->code() );
		$this->assertSame( '手動入力', $provider->label() );
		$this->assertFalse( $provider->isAutomatic() );
		$this->assertNull( $provider->accountCode() );
	}

	public function test_test_connection_always_ok(): void {
		$provider = new ManualProvider();
		$result   = $provider->testConnection( array() );
		$this->assertTrue( $result['ok'] );
		$this->assertNotEmpty( $result['message'] );
	}

	public function test_fetch_returns_null(): void {
		$provider = new ManualProvider();
		$this->assertNull( $provider->fetch( 'product-123', array() ) );
	}
}
