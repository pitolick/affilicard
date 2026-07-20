<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Pricing;

use Affilicard\Platform\PlatformDefinition;
use Affilicard\Pricing\PriceFreshness;
use PHPUnit\Framework\TestCase;

final class PriceFreshnessTest extends TestCase {

	private function platform( int $ttl ): PlatformDefinition {
		return PlatformDefinition::fromArray(
			array(
				'code'          => 'rakuten-kobo',
				'priceTtlHours' => $ttl,
			)
		);
	}

	public function test_確認済みかつ鮮度内は表示可(): void {
		$now     = 1_800_000_000;
		$listing = array(
			'price'            => '693',
			'last_verified_at' => gmdate( 'c', $now - 3600 ), // 1時間前
		);
		$this->assertTrue( PriceFreshness::isPriceDisplayable( $listing, $this->platform( 24 ), $now ) );
	}

	public function test_TTL超過は非表示(): void {
		$now     = 1_800_000_000;
		$listing = array(
			'price'            => '693',
			'last_verified_at' => gmdate( 'c', $now - 25 * 3600 ), // 25時間前
		);
		$this->assertFalse( PriceFreshness::isPriceDisplayable( $listing, $this->platform( 24 ), $now ) );
	}

	public function test_last_verified_at無し_手動価格は非表示(): void {
		$now     = 1_800_000_000;
		$listing = array( 'price' => '693' ); // 手動入力想定・verified 無し
		$this->assertFalse( PriceFreshness::isPriceDisplayable( $listing, $this->platform( 24 ), $now ) );
	}

	public function test_price空は非表示(): void {
		$now     = 1_800_000_000;
		$listing = array(
			'price'            => '',
			'last_verified_at' => gmdate( 'c', $now ),
		);
		$this->assertFalse( PriceFreshness::isPriceDisplayable( $listing, $this->platform( 24 ), $now ) );
	}

	public function test_platformがnullは非表示(): void {
		$now     = 1_800_000_000;
		$listing = array(
			'price'            => '693',
			'last_verified_at' => gmdate( 'c', $now ),
		);
		$this->assertFalse( PriceFreshness::isPriceDisplayable( $listing, null, $now ) );
	}

	public function test_僅かに未来のlast_verified_atも表示可_クロック差許容(): void {
		// 書き込み側と描画側 time() のクロック差で verified が僅かに未来になることがある。
		// これは「たった今確認済み＝最もフレッシュ」なので表示する（鮮度ゲートは古い価格を隠すためのもの）。
		$now     = 1_800_000_000;
		$listing = array(
			'price'            => '693',
			'last_verified_at' => gmdate( 'c', $now + 3600 ), // 1時間後（未来・クロック差想定）
		);
		$this->assertTrue( PriceFreshness::isPriceDisplayable( $listing, $this->platform( 24 ), $now ) );
	}
}
