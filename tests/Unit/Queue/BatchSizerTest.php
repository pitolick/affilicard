<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

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

	public function test_時間予算と間隔から件数を出し_まとめ取得できるProviderは倍率を掛ける(): void {
		$sizer = $this->sizer();

		// 実効 25 秒 ÷ 1.1 秒 = 22 件。
		$this->assertSame( 22, $sizer->sizeFor( 'rakuten' ) );
		// まとめ取得（10 件/リクエスト）は 22 × 10。
		$this->assertSame( 220, $sizer->sizeFor( 'amazon' ) );
	}

	public function test_まとめ取得できるかを返す(): void {
		$sizer = $this->sizer();

		$this->assertTrue( $sizer->isBatchFetching( 'amazon' ) );
		$this->assertFalse( $sizer->isBatchFetching( 'rakuten' ) );
		$this->assertFalse( $sizer->isBatchFetching( 'unknown' ) );
	}
}
