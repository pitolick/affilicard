<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider\Amazon;

use Affilicard\Account\AccountCredentials;
use Affilicard\Provider\Amazon\AmazonCreatorsClient;
use Affilicard\Provider\Amazon\AmazonProvider;
use Affilicard\Util\Crypto;
use Mockery;
use Mockery\MockInterface;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class AmazonProviderTest extends TestCase {

	private const CREDS = array(
		'credential_id'     => 'amzn1.application-oa2-client.test',
		'credential_secret' => 'secret',
		'partner_tag'       => 'example-22',
	);

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $text ) => $text );
		WP_Mock::userFunction( 'wp_salt' )->with( 'auth' )->andReturn( 'test-salt-1234567890abcdef' );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing(
			static fn( $value ) => json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $creds
	 */
	private function stubCredentials( array $creds = self::CREDS ): void {
		WP_Mock::userFunction( 'get_option' )
			->with( AccountCredentials::optionKey( 'amazon' ), '' )
			->andReturn( array() === $creds ? '' : Crypto::encrypt( (string) json_encode( $creds ) ) );
	}

	/**
	 * @return AmazonCreatorsClient&MockInterface
	 */
	private function client(): AmazonCreatorsClient {
		return Mockery::mock( AmazonCreatorsClient::class );
	}

	/**
	 * 2026-11-24 に実 API で取得した応答の形（Kindle 単巻）。
	 *
	 * @return array<string, mixed>
	 */
	private static function item( string $asin = 'B008ODF84I', array $overrides = array() ): array {
		return array_replace_recursive(
			array(
				'asin'          => $asin,
				'detailPageURL' => 'https://www.amazon.co.jp/dp/' . $asin . '?tag=example-22&linkCode=ogi&th=1&psc=1',
				'images'        => array(
					'primary' => array(
						'large' => array(
							'url'    => 'https://m.media-amazon.com/images/I/51f7n+2hquL._SL500_.jpg',
							'height' => 500,
							'width'  => 363,
						),
					),
				),
				'itemInfo'      => array(
					'byLineInfo'  => array(
						'contributors' => array(
							array(
								'name'     => '久住 昌之',
								'role'     => '著',
								'roleType' => 'author',
							),
							array(
								'name'     => '谷口ジロー',
								'role'     => 'イラスト',
								'roleType' => 'illustrator',
							),
						),
						'manufacturer' => array( 'displayValue' => '扶桑社' ),
					),
					'productInfo' => array(
						'isAdultProduct' => array( 'displayValue' => false ),
						'releaseDate'    => array( 'displayValue' => '2012-09-01T00:00:00.000Z' ),
					),
					'title'       => array( 'displayValue' => '孤独のグルメ【新装版】 (ＳＰＡ！コミックス)' ),
				),
				'offersV2'      => array(
					'listings' => array(
						array(
							'isBuyBoxWinner' => false,
							'price'          => array( 'money' => array( 'amount' => 999 ) ),
						),
						array(
							'isBuyBoxWinner' => true,
							'loyaltyPoints'  => array( 'points' => 6 ),
							'price'          => array(
								'money'       => array(
									'amount'        => 550,
									'currency'      => 'JPY',
									'displayAmount' => '￥550',
								),
								'savingBasis' => array(
									'money'                => array( 'amount' => 990 ),
									'savingBasisType'      => 'WAS_PRICE',
									'savingBasisTypeLabel' => '過去価格:',
								),
								'savings'     => array( 'percentage' => 44 ),
							),
						),
					),
				),
			),
			$overrides
		);
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return array{error: bool, code: int, decoded: array<string, mixed>|null}
	 */
	private static function ok( array $items, array $errors = array() ): array {
		$decoded = array( 'itemsResult' => array( 'items' => $items ) );
		if ( array() !== $errors ) {
			$decoded['errors'] = $errors;
		}
		return array(
			'error'   => false,
			'code'    => 200,
			'decoded' => $decoded,
		);
	}

	public function test_基本情報(): void {
		$provider = new AmazonProvider();
		$this->assertSame( 'amazon-creators', $provider->code() );
		$this->assertSame( 'amazon', $provider->accountCode() );
		$this->assertTrue( $provider->isAutomatic() );
		$this->assertSame( 10, $provider->maxBatchSize() );
		$this->assertSame( 1100, $provider->minRequestIntervalMs() );
	}

	public function test_後続機能の値も同じリクエストで取る(): void {
		// 付与ポイント・成人向けフラグ・セール情報のために別リクエストを増やさない（設計 §4-4）。
		$this->assertContains( 'offersV2.listings.loyaltyPoints', AmazonProvider::RESOURCES );
		$this->assertContains( 'offersV2.listings.dealDetails', AmazonProvider::RESOURCES );
		$this->assertContains( 'itemInfo.productInfo', AmazonProvider::RESOURCES );
		$this->assertContains( 'images.primary.large', AmazonProvider::RESOURCES );
	}

	public function test_認証情報が無ければ一時失敗(): void {
		$this->stubCredentials( array() );
		$client = $this->client();
		$client->shouldNotReceive( 'getItems' );

		$result = ( new AmazonProvider( $client ) )->fetch( 'B008ODF84I', array() );

		$this->assertFalse( $result->isHit() );
		$this->assertFalse( $result->isTerminalMiss() );
	}

	public function test_ASINの形式が不正なら叩かずに恒久失敗(): void {
		$this->stubCredentials();
		$client = $this->client();
		$client->shouldNotReceive( 'getItems' );
		$provider = new AmazonProvider( $client );

		$this->assertTrue( $provider->fetch( '', array() )->isTerminalMiss() );
		$this->assertTrue( $provider->fetch( 'B00000', array() )->isTerminalMiss() );
		$this->assertTrue( $provider->fetch( 'https://amazon.co.jp/dp/B0', array() )->isTerminalMiss() );
	}

	public function test_取得結果をlistingのフィールドへ写す(): void {
		$this->stubCredentials();
		$client = $this->client();
		$client->shouldReceive( 'getItems' )
			->once()
			->with( self::CREDS, array( 'B008ODF84I' ), AmazonProvider::RESOURCES )
			->andReturn( self::ok( array( self::item() ) ) );

		// 小文字・前後空白の ASIN も大文字に揃えて引く。
		$result = ( new AmazonProvider( $client ) )->fetch( ' b008odf84i ', array() );

		$this->assertTrue( $result->isHit() );
		$data = $result->data;
		$this->assertSame( '孤独のグルメ【新装版】 (ＳＰＡ！コミックス)', $data['title'] );
		$this->assertSame( '550', $data['price'] ); // カートを取っている出品の価格
		$this->assertSame( 'https://m.media-amazon.com/images/I/51f7n+2hquL._SL500_.jpg', $data['image_url'] );
		$this->assertSame( 'https://www.amazon.co.jp/dp/B008ODF84I', $data['regular_url'] );
		// 返ってきたリンクは改変しない。
		$this->assertSame( 'https://www.amazon.co.jp/dp/B008ODF84I?tag=example-22&linkCode=ogi&th=1&psc=1', $data['affiliate_url'] );
		$this->assertSame(
			array(
				'release_date' => '2012-09-01',
				'author'       => '久住 昌之',
				'publisher'    => '扶桑社',
			),
			$data['platform_extras']
		);
		// 基準価格のラベルを出せるようになるまで list_price / badge は入れない（設計 §4-5）。
		$this->assertArrayNotHasKey( 'list_price', $data );
		$this->assertArrayNotHasKey( 'badge', $data );
	}

	public function test_価格や書影が返らなければ空にして古い値を残さない(): void {
		$this->stubCredentials();
		$item = self::item();
		unset( $item['offersV2'], $item['images'] );
		$client = $this->client();
		$client->shouldReceive( 'getItems' )->once()->andReturn( self::ok( array( $item ) ) );

		$data = ( new AmazonProvider( $client ) )->fetch( 'B008ODF84I', array() )->data;

		// 取得成功で last_verified_at が更新されるため、残すと古い値が「今の値」として出てしまう。
		$this->assertSame( '', $data['price'] );
		$this->assertSame( '', $data['image_url'] );
		$this->assertSame( 'https://www.amazon.co.jp/dp/B008ODF84I', $data['regular_url'] );
	}

	public function test_カートを取っている出品が無ければ先頭の価格(): void {
		$item = self::item();
		$item['offersV2']['listings'][1]['isBuyBoxWinner'] = false;

		$data = AmazonProvider::normalizeItem( $item );

		$this->assertSame( '999', $data['price'] );
	}

	/**
	 * @return iterable<string, array{0: array{error: bool, code: int, decoded: array<string, mixed>|null}}>
	 */
	public static function transientResponses(): iterable {
		yield '売上要件を割った 403' => array(
			array(
				'error'   => false,
				'code'    => 403,
				'decoded' => array( 'reason' => 'AssociateNotEligible' ),
			),
		);
		yield '429' => array(
			array(
				'error'   => false,
				'code'    => 429,
				'decoded' => array(),
			),
		);
		yield '5xx' => array(
			array(
				'error'   => false,
				'code'    => 503,
				'decoded' => null,
			),
		);
		yield '通信断・トークン取得失敗' => array(
			array(
				'error'   => true,
				'code'    => 0,
				'decoded' => null,
			),
		);
	}

	/**
	 * @dataProvider transientResponses
	 * @param array{error: bool, code: int, decoded: array<string, mixed>|null} $response
	 */
	public function test_API側の失敗は一時失敗( array $response ): void {
		$this->stubCredentials();
		$client = $this->client();
		$client->shouldReceive( 'getItems' )->once()->andReturn( $response );

		$result = ( new AmazonProvider( $client ) )->fetch( 'B008ODF84I', array() );

		// 恒久失敗にすると give-up され、要件を満たし直しても戻らない。
		$this->assertFalse( $result->isHit() );
		$this->assertFalse( $result->isTerminalMiss() );
	}

	public function test_200でitemsに無いASINは恒久失敗(): void {
		$this->stubCredentials();
		$client = $this->client();
		$client->shouldReceive( 'getItems' )->once()->andReturn(
			self::ok(
				array(),
				array(
					array(
						'code'    => 'ItemNotAccessible',
						'message' => 'The ItemId B0F11MMGVK is not accessible through the Product Advertising API.',
					),
				)
			)
		);

		$this->assertTrue( ( new AmazonProvider( $client ) )->fetch( 'B0F11MMGVK', array() )->isTerminalMiss() );
	}

	public function test_prefetchは10件ずつまとめて取り_fetchは覚えた結果を返す(): void {
		$this->stubCredentials();
		$asins = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$asins[] = sprintf( 'B%09d', $i );
		}

		$client = $this->client();
		$client->shouldReceive( 'getItems' )
			->once()
			->with( self::CREDS, array_slice( $asins, 0, 10 ), AmazonProvider::RESOURCES )
			->andReturn( self::ok( array( self::item( $asins[0] ) ) ) );
		$client->shouldReceive( 'getItems' )
			->once()
			->with( self::CREDS, array_slice( $asins, 10 ), AmazonProvider::RESOURCES )
			->andReturn(
				array(
					'error'   => false,
					'code'    => 429,
					'decoded' => array(),
				)
			);

		$provider = new AmazonProvider( $client );
		// 形式違いはリクエストに載せない（1 件でも混ざると全体が 400 になる）。
		$provider->prefetch( array_merge( $asins, array( 'not-an-asin' ) ) );

		$this->assertTrue( $provider->hasPrefetched( strtolower( $asins[0] ) ) );
		$this->assertTrue( $provider->hasPrefetched( 'not-an-asin' ) );
		// 以降の fetch は HTTP しない（getItems は上の 2 回だけ）。
		$this->assertTrue( $provider->fetch( $asins[0], array() )->isHit() );
		$this->assertTrue( $provider->fetch( $asins[1], array() )->isTerminalMiss() );
		$this->assertTrue( $provider->fetch( 'not-an-asin', array() )->isTerminalMiss() );
		// まとめ取得の一時失敗も覚える（個別に叩き直すとレート制限をすり抜けるため）。
		$late = $provider->fetch( $asins[11], array() );
		$this->assertFalse( $late->isHit() );
		$this->assertFalse( $late->isTerminalMiss() );
	}

	public function test_clearPrefetched後は再び取りに行く(): void {
		$this->stubCredentials();
		$client = $this->client();
		$client->shouldReceive( 'getItems' )->twice()->andReturn( self::ok( array( self::item() ) ) );

		$provider = new AmazonProvider( $client );
		$provider->prefetch( array( 'B008ODF84I' ) );
		$provider->prefetch( array( 'B008ODF84I' ) ); // 覚えている分は送らない
		$provider->clearPrefetched();
		$this->assertFalse( $provider->hasPrefetched( 'B008ODF84I' ) );
		$provider->fetch( 'B008ODF84I', array() );
	}

	public function test_testConnection_入力不足(): void {
		$result = ( new AmazonProvider( $this->client() ) )->testConnection( array() );
		$this->assertFalse( $result['ok'] );
	}

	public function test_testConnection_売上要件を満たしていなければその旨を返す(): void {
		$client = $this->client();
		$client->shouldReceive( 'canAuthenticate' )->once()->andReturn( true );
		$client->shouldReceive( 'getItems' )->once()->andReturn(
			array(
				'error'   => false,
				'code'    => 403,
				'decoded' => array( 'reason' => 'AssociateNotEligible' ),
			)
		);

		$result = ( new AmazonProvider( $client ) )->testConnection( self::CREDS );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( '適格販売', $result['message'] );
	}

	public function test_testConnection_トークンを取れなければ認証情報の確認を促す(): void {
		$client = $this->client();
		$client->shouldReceive( 'canAuthenticate' )->once()->andReturn( false );
		$client->shouldNotReceive( 'getItems' );

		$result = ( new AmazonProvider( $client ) )->testConnection( self::CREDS );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'Credential', $result['message'] );
	}

	public function test_testConnection_成功(): void {
		$client = $this->client();
		$client->shouldReceive( 'canAuthenticate' )->once()->andReturn( true );
		$client->shouldReceive( 'getItems' )->once()->andReturn( self::ok( array( self::item() ) ) );

		$this->assertTrue( ( new AmazonProvider( $client ) )->testConnection( self::CREDS )['ok'] );
	}
}
