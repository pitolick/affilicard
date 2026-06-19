<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\PostType;

use Affilicard\PostType\ProductMetaBox;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProductMetaBoxTest extends TestCase {

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

	public function test_register_hooks_only_admin_enqueue_scripts(): void {
		WP_Mock::expectActionAdded( 'admin_enqueue_scripts', array( ProductMetaBox::class, 'enqueueAssets' ) );
		ProductMetaBox::register();
		$this->assertConditionsMet();
	}
}
