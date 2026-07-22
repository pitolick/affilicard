<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Queue\ActionSchedulerLoader;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ActionSchedulerLoaderTest extends TestCase {
	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}
	public function tearDown(): void {
		WP_Mock::tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	public function test_register_plugins_loadedにフックする(): void {
		WP_Mock::expectActionAdded( 'plugins_loaded', array( ActionSchedulerLoader::class, 'boot' ), 0 );
		ActionSchedulerLoader::register();
		$this->assertConditionsMet();
	}

	public function test_path_action_schedulerのfunctions_phpを指す(): void {
		$this->assertStringEndsWith( 'action-scheduler/action-scheduler.php', ActionSchedulerLoader::path() );
	}
}
