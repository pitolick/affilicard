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

	/**
	 * needsRefetch(): 掃引（sweep）の再取得判定。last_fetched_at（最終試行時刻・
	 * 成功/失敗問わず記録される）＋ platform priceTtlHours のクールダウンで判定する。
	 * last_verified_at（成功時刻）ベースの isPriceDisplayable とは独立。
	 */
	public function test_needsRefetch_last_fetched_at欠落は再取得が必要(): void {
		$platform = $this->platform( 24 );
		$this->assertTrue( PriceFreshness::needsRefetch( array(), $platform, 1_000_000 ) );
	}

	public function test_needsRefetch_TTL内は再取得不要(): void {
		$platform = $this->platform( 24 );
		$now      = 1_000_000;
		$listing  = array( 'last_fetched_at' => gmdate( 'c', $now - 3600 ) ); // 1h 前
		$this->assertFalse( PriceFreshness::needsRefetch( $listing, $platform, $now ) );
	}

	public function test_needsRefetch_TTL超過は再取得が必要(): void {
		$platform = $this->platform( 24 );
		$now      = 1_000_000;
		$listing  = array( 'last_fetched_at' => gmdate( 'c', $now - 25 * 3600 ) ); // 25h 前
		$this->assertTrue( PriceFreshness::needsRefetch( $listing, $platform, $now ) );
	}

	public function test_needsRefetch_platformがnullは再取得が必要(): void {
		$listing = array( 'last_fetched_at' => gmdate( 'c', 1_000_000 ) );
		$this->assertTrue( PriceFreshness::needsRefetch( $listing, null, 1_000_000 ) );
	}

	public function test_needsRefetch_last_fetched_atが不正な日時文字列は再取得が必要(): void {
		$platform = $this->platform( 24 );
		$listing  = array( 'last_fetched_at' => 'not-a-date' );
		$this->assertTrue( PriceFreshness::needsRefetch( $listing, $platform, 1_000_000 ) );
	}

	/**
	 * 掃引リード: 再取得を表示 TTL（24h）より前倒しで発火させる。lead=5h なら
	 * しきい値は 24-5=19h で、20h 前の listing は再取得が必要（lead=0 なら 24h 未満で不要）。
	 * 表示 TTL は変えず、価格が期限に達する前に再確認を終わらせるための機構。
	 */
	public function test_needsRefetch_リード分だけ前倒しで再取得を発火する(): void {
		$platform = $this->platform( 24 );
		$now      = 1_000_000;
		$listing  = array( 'last_fetched_at' => gmdate( 'c', $now - 20 * 3600 ) ); // 20h 前
		$lead     = 5 * 3600; // しきい値 24-5=19h
		$this->assertTrue( PriceFreshness::needsRefetch( $listing, $platform, $now, $lead ) );
		// lead=0（従来）なら 20h < 24h で再取得不要。
		$this->assertFalse( PriceFreshness::needsRefetch( $listing, $platform, $now, 0 ) );
	}

	/**
	 * リードのクランプ: しきい値は priceTtlHours の 1/2 未満には下げない
	 * （API 呼び出しを表示 TTL の 2 倍より増やさない）。TTL=24h ならしきい値下限は 12h。
	 */
	public function test_needsRefetch_リードは表示TTLの半分未満には下げない(): void {
		$platform = $this->platform( 24 );
		$now      = 1_000_000;
		$hugeLead = 100 * 3600; // 過大なリード → しきい値は 12h に下限クランプ
		$this->assertTrue(
			PriceFreshness::needsRefetch( array( 'last_fetched_at' => gmdate( 'c', $now - 13 * 3600 ) ), $platform, $now, $hugeLead )
		);
		$this->assertFalse(
			PriceFreshness::needsRefetch( array( 'last_fetched_at' => gmdate( 'c', $now - 11 * 3600 ) ), $platform, $now, $hugeLead )
		);
	}

	public function test_sweepLeadSeconds_掃引間隔とバッファの和を秒で返す(): void {
		// 既定バッファ 2h。掃引間隔 3h なら (3+2)*3600 = 18000 秒。
		$this->assertSame( 18000, PriceFreshness::sweepLeadSeconds( 3 ) );
		$this->assertSame( ( 6 + 2 ) * 3600, PriceFreshness::sweepLeadSeconds( 6 ) );
		$this->assertSame( 10 * 3600, PriceFreshness::sweepLeadSeconds( 3, 7 ) );
	}

	/**
	 * 失敗が続き last_verified_at が古い/空・price が空のままでも、last_fetched_at
	 * （直近の試行）が TTL 内なら再取得不要（＝毎掃引の連打を止める）。
	 */
	public function test_needsRefetch_last_verified_atやpriceが空でもlast_fetched_atがTTL内なら再取得不要(): void {
		$platform = $this->platform( 24 );
		$now      = 1_000_000;
		$listing  = array(
			'price'            => '',
			'last_verified_at' => '',
			'last_fetched_at'  => gmdate( 'c', $now - 3600 ), // 直近の失敗試行
		);
		$this->assertFalse( PriceFreshness::needsRefetch( $listing, $platform, $now ) );
	}
}
