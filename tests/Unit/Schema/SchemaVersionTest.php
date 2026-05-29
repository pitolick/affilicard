<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Schema;

use Affilicard\Schema\SchemaVersion;
use PHPUnit\Framework\TestCase;

final class SchemaVersionTest extends TestCase {

	public function test_current_constant_is_version_one(): void {
		$this->assertSame( '1', SchemaVersion::CURRENT );
		$this->assertSame( '1', SchemaVersion::current() );
	}

	public function test_compare_returns_expected_values(): void {
		$this->assertSame( 0, SchemaVersion::compare( '1' ) );
		$this->assertSame( 1, SchemaVersion::compare( '0' ) );
		$this->assertSame( -1, SchemaVersion::compare( '2' ) );
	}
}
