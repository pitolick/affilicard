<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\Amazon\AmazonProvider;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Provider\Rakuten\RakutenProvider;
use Affilicard\Queue\BatchSizer;
use Affilicard\Settings\GeneralSettings;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class BatchSizerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'     => 'amazon-kindle',
						'name'     => 'Amazon Kindle',
						'provider' => 'amazon-creators',
					),
					array(
						'code'     => 'rakuten-kobo',
						'name'     => '楽天Kobo',
						'provider' => 'rakuten-kobo',
					),
				)
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	private function sizer(): BatchSizer {
		$registry = new ProviderRegistry();
		$registry->register( new RakutenProvider() );
		$registry->register( new AmazonProvider() );
		return new BatchSizer( $registry );
	}

	public function test_時間予算と間隔から件数を出す(): void {
		// 実効 25 秒 ÷ 1.1 秒 = 22 件。
		$this->assertSame( 22, $this->sizer()->sizeFor( 'rakuten' ) );
	}

	/**
	 * まとめ取得（22 × 10 = 220 件）は、そのままだとジョブの引数が Action Scheduler の上限
	 * （8,000 文字）を超え、ジョブが黙って登録されない。登録できる件数に抑える。
	 */
	public function test_まとめ取得の件数はジョブの引数がActionSchedulerの上限に収まるよう抑える(): void {
		$size = $this->sizer()->sizeFor( 'amazon' );

		$this->assertLessThan( 220, $size );
		$this->assertSame( BatchSizer::maxItemsForArgs( 'amazon' ), $size );

		// 実際に積む形（Enqueuer::enqueueBatch の args）で、桁の大きい post_id でも上限に収まる。
		$items = array();
		for ( $i = 0; $i < $size; $i++ ) {
			$items[] = array(
				'post_id'  => PHP_INT_MAX - $i,
				'platform' => 'amazon-kindle',
			);
		}
		$json = (string) json_encode(
			array(
				'account' => 'amazon',
				'items'   => $items,
			)
		);
		$this->assertLessThanOrEqual( BatchSizer::MAX_ARGS_JSON_LENGTH, strlen( $json ) );
		$this->assertLessThan( 8000, strlen( $json ) );
	}

	public function test_フィルタで大きくしてもジョブの引数の上限は超えない(): void {
		WP_Mock::onFilter( 'affilicard_refresh_batch_size' )->with( 220, 'amazon' )->reply( 5000 );

		$this->assertSame( BatchSizer::maxItemsForArgs( 'amazon' ), $this->sizer()->sizeFor( 'amazon' ) );
	}

	public function test_まとめ取得できるかを返す(): void {
		$sizer = $this->sizer();

		$this->assertTrue( $sizer->isBatchFetching( 'amazon' ) );
		$this->assertFalse( $sizer->isBatchFetching( 'rakuten' ) );
		$this->assertFalse( $sizer->isBatchFetching( 'unknown' ) );
	}
}
