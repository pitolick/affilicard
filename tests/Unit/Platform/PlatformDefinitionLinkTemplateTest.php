<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Platform;

use Affilicard\Platform\PlatformDefinition;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * プラットフォームのリンクテンプレート（作品 URL → アフィリエイトリンク）のテスト。
 *
 * 例の値（rk・af_id・tag）は架空。実際のトラッキング ID は利用側サイトが設定画面で入れる。
 */
final class PlatformDefinitionLinkTemplateTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url, $component = -1 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() のテストダブル
				return parse_url( (string) $url, $component );
			}
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * @param list<string> $hosts
	 */
	private function platform( string $template, array $hosts ): PlatformDefinition {
		return PlatformDefinition::fromArray(
			array(
				'code'         => 'hulu',
				'linkTemplate' => $template,
				'linkHosts'    => $hosts,
			)
		);
	}

	public function test_url_encで作品URLを包むリンクを組み立てる(): void {
		$def = $this->platform( 'https://h.example.test/sp/cc?rk=ABC&url={url_enc}', array( 'hulu.jp' ) );

		$this->assertSame(
			'https://h.example.test/sp/cc?rk=ABC&url=https%3A%2F%2Fwww.hulu.jp%2Fnny',
			$def->applyLinkTemplate( 'https://www.hulu.jp/nny' )
		);
	}

	public function test_sepはクエリが無ければ疑問符を返す(): void {
		$def = $this->platform( '{url}{sep}tag=example-22', array( 'amazon.co.jp' ) );

		$this->assertSame(
			'https://www.amazon.co.jp/gp/video/detail/B0TEST?tag=example-22',
			$def->applyLinkTemplate( 'https://www.amazon.co.jp/gp/video/detail/B0TEST' )
		);
	}

	public function test_sepはクエリがあればアンパサンドを返す(): void {
		$def = $this->platform( '{url}{sep}tag=example-22', array( 'amazon.co.jp' ) );

		$this->assertSame(
			'https://www.amazon.co.jp/gp/video/detail/B0TEST?ref=x&tag=example-22',
			$def->applyLinkTemplate( 'https://www.amazon.co.jp/gp/video/detail/B0TEST?ref=x' )
		);
	}

	/**
	 * url_enc の置換結果に `{url}` という文字列が含まれていても二重に置換しない。
	 */
	public function test_置換は一度だけ行う(): void {
		$def = $this->platform( 'https://al.example.test/?lurl={url_enc}&ch=link', array( 'tv.dmm.com' ) );

		$this->assertSame(
			'https://al.example.test/?lurl=https%3A%2F%2Ftv.dmm.com%2Fvod%2F%3Fq%3D%7Burl%7D&ch=link',
			$def->applyLinkTemplate( 'https://tv.dmm.com/vod/?q={url}' )
		);
	}

	public function test_サブドメインは対象ホストに一致する(): void {
		$def = $this->platform( 'https://h.example.test/?url={url_enc}', array( 'hulu.jp' ) );

		$this->assertNotSame( '', $def->applyLinkTemplate( 'https://www.hulu.jp/nny' ) );
		$this->assertNotSame( '', $def->applyLinkTemplate( 'https://hulu.jp/nny' ) );
	}

	/**
	 * 別サイトの URL を誤って登録しても、それをアフィリエイトリンクとして出さない。
	 */
	public function test_対象ホストに一致しなければ組み立てない(): void {
		$def = $this->platform( 'https://h.example.test/?url={url_enc}', array( 'hulu.jp' ) );

		$this->assertSame( '', $def->applyLinkTemplate( 'https://www.netflix.com/title/1' ) );
		// 末尾一致はドット境界でだけ認める（「evilhulu.jp」は別ホスト）。
		$this->assertSame( '', $def->applyLinkTemplate( 'https://evilhulu.jp/nny' ) );
	}

	public function test_対象ホストが空ならテンプレートを使わない(): void {
		$def = $this->platform( 'https://h.example.test/?url={url_enc}', array() );

		$this->assertSame( '', $def->applyLinkTemplate( 'https://www.hulu.jp/nny' ) );
	}

	public function test_テンプレートが空なら組み立てない(): void {
		$def = $this->platform( '', array( 'hulu.jp' ) );

		$this->assertSame( '', $def->applyLinkTemplate( 'https://www.hulu.jp/nny' ) );
	}

	public function test_作品URLが空なら組み立てない(): void {
		$def = $this->platform( 'https://h.example.test/?url={url_enc}', array( 'hulu.jp' ) );

		$this->assertSame( '', $def->applyLinkTemplate( '' ) );
	}

	public function test_toArrayとfromArrayで往復する(): void {
		$def = $this->platform( 'https://h.example.test/?url={url_enc}', array( 'hulu.jp' ) );
		$arr = $def->toArray();

		$this->assertSame( 'https://h.example.test/?url={url_enc}', $arr['linkTemplate'] );
		$this->assertSame( array( 'hulu.jp' ), $arr['linkHosts'] );
		$this->assertEquals( $def, PlatformDefinition::fromArray( $arr ) );
	}

	public function test_欠損時はテンプレートなし(): void {
		$def = PlatformDefinition::fromArray( array( 'code' => 'x' ) );

		$this->assertSame( '', $def->linkTemplate );
		$this->assertSame( array(), $def->linkHosts );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalidTemplates(): array {
		return array(
			'差し込み口なし'    => array( 'https://h.example.test/sp/cc?rk=ABC' ),
			'http'       => array( 'http://h.example.test/?url={url_enc}' ),
			'javascript' => array( 'javascript:alert(1)//{url}' ),
			'相対パス'       => array( '/go?url={url_enc}' ),
			'配列'         => array( array( 'https://h.example.test/?url={url_enc}' ) ),
		);
	}

	/**
	 * 使えない形のテンプレートは保存はするが空にする（使わない＝安全側）。
	 *
	 * @dataProvider invalidTemplates
	 */
	public function test_使えない形のテンプレートは空にする( mixed $template ): void {
		$def = PlatformDefinition::fromArray(
			array(
				'code'         => 'x',
				'linkTemplate' => $template,
			)
		);

		$this->assertSame( '', $def->linkTemplate );
	}

	public function test_テンプレートは前後の空白を除く(): void {
		$def = $this->platform( "  {url}{sep}tag=example-22 \n", array( 'amazon.co.jp' ) );

		$this->assertSame( '{url}{sep}tag=example-22', $def->linkTemplate );
	}

	public function test_対象ホストを正規化する(): void {
		$def = PlatformDefinition::fromArray(
			array(
				'code'      => 'x',
				'linkHosts' => array( ' Hulu.JP ', 'https://www.example.test/path', 'tv.dmm.com/vod', '', 'hulu.jp', 'bad host', 123 ),
			)
		);

		$this->assertSame( array( 'hulu.jp', 'www.example.test', 'tv.dmm.com' ), $def->linkHosts );
	}

	public function test_対象ホストはカンマ区切り文字列も受け付ける(): void {
		$def = PlatformDefinition::fromArray(
			array(
				'code'      => 'x',
				'linkHosts' => 'hulu.jp, video.unext.jp',
			)
		);

		$this->assertSame( array( 'hulu.jp', 'video.unext.jp' ), $def->linkHosts );
	}
}
