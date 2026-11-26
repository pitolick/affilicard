<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Types;

use Affilicard\Plugin;
use Affilicard\Types\ProductTypeUiList;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * 管理画面へ渡す商品タイプの一覧。
 *
 * 期待値は tests/fixtures/product-types.json。JS のテスト（tests/js/setup.js）も同じ
 * ファイルを window.affilicardProductTypes として使うので、PHP のタイプを変えたら
 * このテストが落ち、fixture を直すと JS のテストも新しい定義で走る。
 */
final class ProductTypeUiListTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $text ) => $text );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_登録済みのタイプをfixtureどおりに返す(): void {
		$expected = json_decode(
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/product-types.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- ローカルの fixture を読むだけ。
			true
		);

		$this->assertSame( $expected, ProductTypeUiList::build( Plugin::buildProductTypeRegistry() ) );
	}
}
