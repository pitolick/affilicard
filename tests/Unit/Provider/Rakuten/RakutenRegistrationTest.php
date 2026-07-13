<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider\Rakuten;

use Affilicard\Plugin;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class RakutenRegistrationTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing(
			static function ( $text ) {
				return $text;
			}
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_registry_includes_rakuten_provider(): void {
		$registry = Plugin::buildProviderRegistry();
		$this->assertContains( 'rakuten-kobo', $registry->codes() );
	}
}
