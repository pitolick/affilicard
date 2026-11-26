<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Types;

use Affilicard\Plugin;
use Affilicard\Types\DefaultProductType;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class DefaultProductTypeTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $text ) => $text );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_forNewProduct_登録済みなら設定の値(): void {
		$this->assertSame( 'vod', DefaultProductType::forNewProduct( 'vod', Plugin::buildProductTypeRegistry() ) );
	}

	public function test_forNewProduct_未登録なら汎用(): void {
		$this->assertSame( 'generic', DefaultProductType::forNewProduct( 'gone', Plugin::buildProductTypeRegistry() ) );
	}

	public function test_forPlatform_ストアの対象タイプに含まれるなら設定の値(): void {
		$this->assertSame(
			'vod',
			DefaultProductType::forPlatform( 'vod', array( 'vod' ), Plugin::buildProductTypeRegistry() )
		);
	}

	/**
	 * 既定が動画配信でも、電子書籍ストアの商品は電子書籍で作る。
	 */
	public function test_forPlatform_含まれないならストアの対象タイプの先頭(): void {
		$this->assertSame(
			'ebook',
			DefaultProductType::forPlatform( 'vod', array( 'ebook', 'generic' ), Plugin::buildProductTypeRegistry() )
		);
	}

	public function test_forPlatform_未登録の対象タイプは飛ばす(): void {
		$this->assertSame(
			'ebook',
			DefaultProductType::forPlatform( 'vod', array( 'gone', 'ebook' ), Plugin::buildProductTypeRegistry() )
		);
	}

	public function test_forPlatform_対象タイプが空なら設定の値(): void {
		$this->assertSame(
			'vod',
			DefaultProductType::forPlatform( 'vod', array(), Plugin::buildProductTypeRegistry() )
		);
	}
}
