<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider;

use Affilicard\Provider\FetchResult;
use PHPUnit\Framework\TestCase;

/**
 * FetchResult（取得結果の3値分類）の単体テスト。
 * 成功=hit(data)／恒久失敗=miss()／一時失敗=error() の3ケースを検証する。
 */
final class FetchResultTest extends TestCase {

	public function test_hit_はデータを保持しisHitがtrueかつisTerminalMissがfalse(): void {
		$data   = array( 'price' => '693' );
		$result = FetchResult::hit( $data );

		$this->assertTrue( $result->isHit() );
		$this->assertFalse( $result->isTerminalMiss() );
		$this->assertSame( $data, $result->data );
		$this->assertFalse( $result->terminal );
	}

	public function test_miss_はdataがnullでisTerminalMissがtrue(): void {
		$result = FetchResult::miss();

		$this->assertFalse( $result->isHit() );
		$this->assertTrue( $result->isTerminalMiss() );
		$this->assertNull( $result->data );
		$this->assertTrue( $result->terminal );
	}

	public function test_error_はdataがnullだがterminalではなくisTerminalMissもfalse(): void {
		$result = FetchResult::error();

		$this->assertFalse( $result->isHit() );
		$this->assertFalse( $result->isTerminalMiss() );
		$this->assertNull( $result->data );
		$this->assertFalse( $result->terminal );
	}
}
