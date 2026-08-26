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

	/**
	 * @param mixed $baselineRaw 棚卸し基準日 option の生値。null（既定）なら有効な
	 *              基準日（epoch 0）を返す。空文字・パース不能文字列等の無効値を
	 *              baselineTs() の正規化に通す経路を検証したいテストが明示的に渡す。
	 */
	private function policy( ?int $lastPublished, int $days = 180, bool $enabled = true, $baselineRaw = null ): StocktakePolicy {
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( $key, $default = false ) use ( $days, $enabled, $baselineRaw ) {
				if ( PluginUpgrade::OPTION_STOCKTAKE_BASELINE === $key ) {
					return null === $baselineRaw ? gmdate( 'c', 0 ) : $baselineRaw; // 基準日 = epoch 0（既定）
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

	public function test_基準日optionが未設定空文字なら判定不能としてfalse(): void {
		// 最終掲載日（PublicationDate::get）も基準日（get_option）も未設定という
		// 移行前相当の状態。baselineTs() は空文字を is_string チェックの外側で
		// トリムして無効値と判定し null を返すため、isRetired() は判定不能として
		// false を返す（`??` だけに頼らない正規化。spec §5-4）。
		$policy = $this->policy( null, 180, true, '' );
		$this->assertFalse( $policy->isRetired( 1, PHP_INT_MAX - 1 ) );
	}

	public function test_基準日optionがパース不能な文字列ならfalse(): void {
		$policy = $this->policy( null, 180, true, 'not-a-valid-date' );
		$this->assertFalse( $policy->isRetired( 1, PHP_INT_MAX - 1 ) );
	}

	public function test_最終掲載日も基準日も無効なら判定不能としてfalse(): void {
		// spec §5-2 の必須要件そのもの: 基準日すら無い（移行前）場合は判定不能として
		// false を返す（安全側）。$base が確定しないため、nowTs をどれだけ大きくしても
		// true へは決して転じないことを保証する（バグると「全商品が即座に棚卸しされ
		// 価格が一斉に消える」壊れ方をするため、境界値だけでなく極端な値でも確認する）。
		$policy = $this->policy( null, 180, true, '' );
		$this->assertFalse( $policy->isRetired( 1, 0 ) );
		$this->assertFalse( $policy->isRetired( 1, time() ) );
		$this->assertFalse( $policy->isRetired( 1, PHP_INT_MAX - 1 ) );
	}

	public function test_無効化されていれば常にfalse(): void {
		$policy = $this->policy( 1000, 180, false );
		$this->assertFalse( $policy->isRetired( 1, PHP_INT_MAX - 1 ) );
	}

	public function test_手動更新の経路は棚卸しの影響を受けない(): void {
		// 棚卸しは QueueMaintenance::sweep()（継続更新）にのみ適用する。
		// 手動更新・強制更新の入口（管理画面ボタン等が最終的に呼ぶ
		// Enqueuer::enqueueProductListings() と、REST 経由の入口である
		// RefreshController）は StocktakePolicy を参照しないことを、静的に保証する
		// （spec §5-5）。
		foreach ( array( 'src/Queue/Enqueuer.php', 'src/Rest/RefreshController.php' ) as $relativePath ) {
			$path = dirname( __DIR__, 3 ) . '/' . $relativePath;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- ローカルソースファイルの静的検査であり remote URL ではない。
			$source = file_get_contents( $path );

			// file_get_contents() は失敗時に false を返す。(string) false === '' のため、
			// このチェックを省くとファイルが移動・改名されても
			// assertStringNotContainsString が空文字に対して自明に成功し続け、
			// テストが中身を一切見ないまま永久に green になってしまう。
			$this->assertNotFalse( $source, "{$relativePath} を読み込めませんでした（パスの変更・削除の可能性があります）。" );

			$this->assertStringNotContainsString(
				'StocktakePolicy',
				$source,
				"{$relativePath} が StocktakePolicy を参照しています（手動更新・強制更新の経路に棚卸しを適用してはいけません。spec §5-5）。"
			);
		}
	}

	public function test_期間を延ばすと対象から復帰する(): void {
		$now = 15553001;
		$this->assertTrue( $this->policy( 1000, 180 )->isRetired( 1, $now ) );

		WP_Mock::tearDown();
		WP_Mock::setUp();

		$this->assertFalse( $this->policy( 1000, 365 )->isRetired( 1, $now ) );
	}
}
