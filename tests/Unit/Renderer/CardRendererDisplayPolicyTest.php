<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Renderer;

use Affilicard\Platform\PlatformDefinition;
use Affilicard\Provider\DisplayPolicy;
use Affilicard\Renderer\CardRenderer;
use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * Provider が申告した表示の決まり（DisplayPolicy）に、カードが従うこと（設計 2026-11-24 §5）。
 */
final class CardRendererDisplayPolicyTest extends TestCase {

	private const DISCLAIMER  = '価格および発送可能時期は表示された日付/時刻の時点のものであり、変更される場合があります。';
	private const ATTRIBUTION = '本サイト上で表示されるコンテンツの一部は、アマゾンジャパン合同会社またはその関連会社により提供されたものです。';

	protected function setUp(): void {
		WP_Mock::setUp();
		WP_Mock::passthruFunction( 'esc_html' );
		WP_Mock::passthruFunction( 'esc_attr' );
		WP_Mock::passthruFunction( 'esc_url' );
		WP_Mock::passthruFunction( 'esc_url_raw' );
		WP_Mock::passthruFunction( 'esc_html__' );
		WP_Mock::passthruFunction( 'wp_kses_post' );
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'sanitize_hex_color', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'wp_date' )->andReturnUsing(
			static fn( $format, $timestamp = null ) => gmdate( (string) $format, (int) ( $timestamp ?? time() ) )
		);
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	/** @return list<PlatformDefinition> */
	private static function platforms(): array {
		return array(
			new PlatformDefinition( 'amazon-kindle', 'Amazon Kindle', 'amazon-creators', 1, true, array( 'generic' ), 'Kindleで読む', '#ff9900', '#000000', priceTtlHours: 24 ),
			new PlatformDefinition( 'other-store', '他ストア', 'manual', 2, true, array( 'generic' ), '他で読む', '#2563eb', '#ffffff', priceTtlHours: 24 ),
		);
	}

	/** @return array<string, mixed> */
	private static function options( array $extra = array() ): array {
		return array_merge(
			array(
				'display_policies' => array(
					'amazon-kindle' => new DisplayPolicy( self::DISCLAIMER, self::ATTRIBUTION, 24 ),
				),
			),
			$extra
		);
	}

	/**
	 * @param array<string, mixed> $amazonOffer
	 * @param array<string, mixed> $otherOffer
	 * @return array<string, mixed>
	 */
	private static function product( array $amazonOffer, array $otherOffer = array(), string $stock = 'available' ): array {
		$listings = array(
			array(
				'platform' => 'amazon-kindle',
				'enabled'  => true,
				'offers'   => array( array_merge( array( 'affiliate_url' => 'https://www.amazon.co.jp/dp/B0?tag=x' ), $amazonOffer ) ),
			),
		);
		if ( array() !== $otherOffer ) {
			$listings[] = array(
				'platform' => 'other-store',
				'enabled'  => true,
				'offers'   => array( array_merge( array( 'affiliate_url' => 'https://other.example/1' ), $otherOffer ) ),
			);
		}
		return array(
			'id'           => 1,
			'title'        => '作品 1',
			'content'      => '',
			'stock_status' => $stock,
			'extras'       => array(),
			'listings'     => $listings,
		);
	}

	private static function hoursAgo( int $hours ): string {
		return gmdate( 'c', time() - $hours * 3600 );
	}

	public function test_Amazonの価格を出すカードは免責文言と提供元の表記を出す(): void {
		$html = ( new CardRenderer() )->render(
			self::product(
				array(
					'price'            => '550',
					'last_verified_at' => self::hoursAgo( 1 ),
				)
			),
			self::platforms(),
			self::options()
		);

		$this->assertStringContainsString( '<details class="affilicard-card__price-disclaimer"><summary>詳しくはこちら</summary><p>' . self::DISCLAIMER . '</p></details>', $html );
		$this->assertStringContainsString( '<p class="affilicard-card__attribution">' . self::ATTRIBUTION . '</p>', $html );
	}

	public function test_決まりを申告しないストアだけのカードには何も足さない(): void {
		$product             = self::product(
			array(),
			array(
				'price'            => '500',
				'last_verified_at' => self::hoursAgo( 1 ),
			)
		);
		$product['listings'] = array( $product['listings'][1] );
		$html                = ( new CardRenderer() )->render( $product, self::platforms(), self::options() );

		$this->assertStringContainsString( 'affilicard-card__timestamp', $html );
		$this->assertStringNotContainsString( 'affilicard-card__price-disclaimer', $html );
		$this->assertStringNotContainsString( 'affilicard-card__attribution', $html );
	}

	public function test_表示期限を過ぎたAmazonの書影は使わず次のストアの書影にする(): void {
		$html = ( new CardRenderer() )->render(
			self::product(
				array(
					'price'            => '550',
					'image_url'        => 'https://m.media-amazon.com/old.jpg',
					'last_verified_at' => self::hoursAgo( 25 ),
				),
				array( 'image_url' => 'https://other.example/cover.jpg' )
			),
			self::platforms(),
			self::options()
		);

		$this->assertStringNotContainsString( 'm.media-amazon.com', $html );
		$this->assertStringContainsString( 'https://other.example/cover.jpg', $html );
		// 価格も書影も Amazon のものを出していない＝提供元の表記も出さない。
		$this->assertStringNotContainsString( 'affilicard-card__attribution', $html );
	}

	public function test_確認時刻の無いAmazonの書影は期限内と言えないので使わない(): void {
		$html = ( new CardRenderer() )->render(
			self::product( array( 'image_url' => 'https://m.media-amazon.com/manual.jpg' ) ),
			self::platforms(),
			self::options( array( 'image_url' => 'https://site.example/eyecatch.jpg' ) )
		);

		$this->assertStringNotContainsString( 'm.media-amazon.com', $html );
		$this->assertStringContainsString( 'https://site.example/eyecatch.jpg', $html );
	}

	public function test_期限内のAmazonの書影だけを出すカードにも提供元の表記を出す(): void {
		// 在庫切れで価格行は出ないが、書影は Amazon のもの。
		$html = ( new CardRenderer() )->render(
			self::product(
				array(
					'image_url'        => 'https://m.media-amazon.com/new.jpg',
					'last_verified_at' => self::hoursAgo( 2 ),
				),
				array(),
				'out_of_stock'
			),
			self::platforms(),
			self::options()
		);

		$this->assertStringContainsString( 'https://m.media-amazon.com/new.jpg', $html );
		$this->assertStringContainsString( 'affilicard-card__attribution', $html );
		$this->assertStringNotContainsString( 'affilicard-card__price-disclaimer', $html );
	}

	public function test_決まりを申告しないストアの書影は確認時刻が無くても従来どおり使う(): void {
		$product             = self::product( array(), array( 'image_url' => 'https://other.example/cover.jpg' ) );
		$product['listings'] = array( $product['listings'][1] );

		$html = ( new CardRenderer() )->render( $product, self::platforms(), self::options() );

		$this->assertStringContainsString( 'https://other.example/cover.jpg', $html );
	}

	public function test_参考価格のラベルを取り消し線の価格の前に出す(): void {
		$html = ( new CardRenderer() )->render(
			self::product(
				array(
					'price'            => '550',
					'list_price'       => '990',
					'list_price_label' => '過去価格:',
					'badge'            => '44%OFF',
					'last_verified_at' => self::hoursAgo( 1 ),
				)
			),
			self::platforms(),
			self::options()
		);

		$this->assertStringContainsString(
			'<span class="affilicard-card__list-price-label">過去価格:</span><span class="affilicard-card__list-price">¥990</span>',
			$html
		);
		$this->assertStringContainsString( '44%OFF', $html );
	}
}
