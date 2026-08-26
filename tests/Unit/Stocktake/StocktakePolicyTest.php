<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Stocktake;

use Affilicard\Stocktake\PublicationDate;
use Affilicard\Stocktake\StocktakePolicy;
use Affilicard\Upgrade\PluginUpgrade;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class StocktakePolicyTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	private function policy( ?int $lastPublished, int $days = 180, bool $enabled = true ): StocktakePolicy {
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( $key, $default = false ) use ( $days, $enabled ) {
				if ( PluginUpgrade::OPTION_STOCKTAKE_BASELINE === $key ) {
					return gmdate( 'c', 0 ); // 基準日 = epoch 0
				}
				return array(
					'stocktake_enabled' => $enabled,
					'stocktake_days'    => $days,
				);
			}
		);

		// PublicationDate は final のため（Enqueuer/RateLimiter 等と同じ理由で）
		// Mockery::mock() できない。get_post_meta を stub し、実体の PublicationDate
		// 経由で $lastPublished を返させる。
		WP_Mock::userFunction( 'get_post_meta' )->andReturn(
			null === $lastPublished ? '' : gmdate( 'c', $lastPublished )
		);

		return new StocktakePolicy( new PublicationDate() );
	}

	public function test_期間内なら棚卸ししない(): void {
		$policy = $this->policy( 1000 );
		// 1000 + 180日 = 15,553,000
		$this->assertFalse( $policy->isRetired( 1, 15552999 ) );
	}

	public function test_期間を過ぎたら棚卸し対象(): void {
		$policy = $this->policy( 1000 );
		$this->assertTrue( $policy->isRetired( 1, 15553001 ) );
	}

	public function test_最終掲載日が無効なら基準日で判定する(): void {
		$policy = $this->policy( null ); // 基準日 = 0
		$this->assertFalse( $policy->isRetired( 1, 15551000 ) );
		$this->assertTrue( $policy->isRetired( 1, 15553000 ) );
	}

	public function test_無効化されていれば常にfalse(): void {
		$policy = $this->policy( 1000, 180, false );
		$this->assertFalse( $policy->isRetired( 1, PHP_INT_MAX - 1 ) );
	}

	public function test_手動更新の経路は棚卸しの影響を受けない(): void {
		// 棚卸しは QueueMaintenance::sweep()（継続更新）にのみ適用する。
		// RefreshController / 管理画面ボタン経由の Enqueuer::enqueueProductListings()
		// は StocktakePolicy を参照しないことを、静的に保証する（spec §5-5）。
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Queue/Enqueuer.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- ローカルソースファイルの静的検査であり remote URL ではない。

		$this->assertStringNotContainsString( 'StocktakePolicy', (string) $source );
	}

	public function test_期間を延ばすと対象から復帰する(): void {
		$now = 15553001;
		$this->assertTrue( $this->policy( 1000, 180 )->isRetired( 1, $now ) );

		WP_Mock::tearDown();
		WP_Mock::setUp();

		$this->assertFalse( $this->policy( 1000, 365 )->isRetired( 1, $now ) );
	}
}
