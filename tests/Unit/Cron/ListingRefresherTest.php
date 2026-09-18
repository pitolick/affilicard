<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Cron;

use Affilicard\Cron\ListingRefresher;
use Affilicard\Pricing\FetchStatus;
use Affilicard\Provider\FetchResult;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\WorkOutcome;
use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Settings\GeneralSettings;
use Affilicard\Upgrade\PluginUpgrade;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ListingRefresherTest extends TestCase {

	/**
	 * offers 移行カーソルの状態（false＝移行は未完ではない／'0' 等＝未完）。
	 *
	 * **プロパティで持つのは意図的である。** WP_Mock::userFunction() は同じ名前に対して
	 * 先に登録された期待を優先するため、setUp で登録したあとテスト本体で差し替えても
	 * 黙って無視される（PluginUpgradeTest と同じ理由）。切り替えたい値はここを書き換える。
	 *
	 * **既定は false（＝移行は未完ではない）にする。** stubDmmPlatform()/
	 * stubRakutenPlatform() が `with()` を持たない catch-all の `get_option` を登録して
	 * おり、カーソルの読み取りまで platform 定義の配列を返してしまう
	 * （`false !== array(...)` なので「未完」と誤判定される）。setUp で先に
	 * `with( OPTION_MIGRATION_CURSOR, false )` を登録して、その 1 キーだけを横取りする。
	 *
	 * @var string|false
	 */
	private $offersMigrationCursor = false;

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$this->offersMigrationCursor = false;
		// **catch-all の get_option より前に登録すること**（Mockery は宣言順に最初に
		// 引数が一致した期待を使う）。
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_CURSOR, false )
			->andReturnUsing( fn () => $this->offersMigrationCursor );
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $t ) => $t );
		WP_Mock::userFunction( 'current_time' )->andReturn( '2026-06-03T00:00:00+00:00' );
	}

	/** offers 移行が未完（カーソルが存在する）状態にする。 */
	private function markOffersMigrationPending(): void {
		$this->offersMigrationCursor = '0';
	}
	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	private function stubDmmPlatform(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn(
			array(
				array(
					'code'            => 'dmm-books',
					'name'            => 'DMM',
					'provider'        => 'dmm-ebook',
					'displayOrder'    => 1,
					'enabled'         => true,
					'applicableTypes' => array( 'ebook' ),
					'buttonLabel'     => '',
					'brandColor'      => '',
					'buttonTextColor' => '',
				),
			)
		);
	}
	private function dmmProvider( FetchResult $fetchReturn ): ProviderRegistry {
		$p = Mockery::mock( ProviderInterface::class );
		$p->shouldReceive( 'code' )->andReturn( 'dmm-ebook' );
		$p->shouldReceive( 'isAutomatic' )->andReturn( true );
		$p->shouldReceive( 'fetch' )->andReturn( $fetchReturn );
		$r = new ProviderRegistry();
		$r->register( $p );
		return $r;
	}

	private function stubRakutenPlatform(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn(
			array(
				array(
					'code'            => 'rakuten-kobo',
					'name'            => '楽天Kobo',
					'provider'        => 'rakuten-kobo',
					'displayOrder'    => 3,
					'enabled'         => true,
					'applicableTypes' => array( 'ebook' ),
					'buttonLabel'     => '',
					'brandColor'      => '',
					'buttonTextColor' => '',
				),
			)
		);
	}
	private function rakutenProvider( FetchResult $fetchReturn ): ProviderRegistry {
		$p = Mockery::mock( ProviderInterface::class );
		$p->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$p->shouldReceive( 'isAutomatic' )->andReturn( true );
		$p->shouldReceive( 'fetch' )->andReturn( $fetchReturn );
		$r = new ProviderRegistry();
		$r->register( $p );
		return $r;
	}
	/** @param array<int,mixed> $listings */
	private function product( int $id, array $listings ): array {
		return array(
			'id'             => $id,
			'title'          => 'X',
			'status'         => 'publish',
			'product_type'   => 'generic',
			'stock_status'   => 'available',
			'extras'         => array(),
			'content'        => '',
			'schema_version' => '1',
			'modified'       => '',
			'listings'       => $listings,
		);
	}

	/**
	 * offers 形式の商品を返すリポジトリモック。
	 *
	 * @param list<array<string, mixed>> $offers
	 * @return ProductRepositoryInterface&\Mockery\MockInterface
	 */
	private function repoWithOffers( array $offers, bool $saveOk = true ): ProductRepositoryInterface {
		/** @var ProductRepositoryInterface&\Mockery\MockInterface $repo */
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 20 )->andReturn(
			array(
				'id'           => 20,
				'title'        => '対象巻',
				'status'       => 'publish',
				'product_type' => 'generic',
				'stock_status' => 'available',
				'listings'     => array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'auto_update' => true,
						'update_mode' => 'auto',
						'offers'      => $offers,
					),
				),
			)
		);
		$this->savedOffer    = null;
		$this->savedIdentity = null;
		$repo->shouldReceive( 'updateListingOffer' )->andReturnUsing(
			function ( int $postId, string $platform, array $patch, string $identity ) use ( $saveOk ): bool {
				$this->savedOffer    = $patch;
				$this->savedIdentity = $identity;
				return $saveOk;
			}
		);
		return $repo;
	}

	/**
	 * 直近に updateListingOffer() へ渡された購入リンク 1 件。
	 *
	 * refreshOne() が保存経路へ渡すのは listing 全体ではなく offer 1 件だけである
	 * （fetch 前の写しを持ち込まないため）。他の購入リンクを保持したまま差し替える
	 * 突き合わせは Repository 側の責務で、ProductRepositoryTest が検証する。
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $savedOffer = null;

	private ?string $savedIdentity = null;

	/**
	 * v2.4.0: 死コード化した run()/refreshProduct()/runForPlatform() 系は削除済み
	 * （複数商品を横断する同期スイープは QueueMaintenance::sweep() + RefreshHandler へ
	 * 移行済み）。refreshListing() の反映ロジック（全フィールドマッピング・URL保持・
	 * last_verified_at 刻印等）は、唯一残る公開 API の refreshOne() 経由で検証する。
	 *
	 * v4.0.0: 反映先は listing 自身ではなく offers[0]（選択された購入リンク）になった。
	 */
	public function test_refreshOne_全フィールドを反映しsearch_keyとexternal_idをfetchへ渡す(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->withArgs(
			function ( string $externalId, array $context ) {
				return 'deadbeef01' === $externalId
					&& isset( $context['search_key'] ) && '対象巻' === $context['search_key']
					&& isset( $context['external_id'] ) && 'deadbeef01' === $context['external_id'];
			}
		)->andReturn(
			FetchResult::hit(
				array(
					'price'         => '693',
					'list_price'    => '900',
					'badge'         => '23%OFF',
					'image_url'     => 'https://example.test/i',
					'regular_url'   => 'https://example.test/r',
					'affiliate_url' => 'https://example.test/a',
				)
			)
		);
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 20 )->andReturn(
			array(
				'id'             => 20,
				'title'          => '対象巻',
				'status'         => 'publish',
				'product_type'   => 'generic',
				'stock_status'   => 'available',
				'extras'         => array(),
				'content'        => '',
				'schema_version' => '1',
				'modified'       => '',
				'listings'       => array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'offers'      => array(
							array(
								'external_id' => 'deadbeef01',
								'search_key'  => '対象巻',
								'price'       => '',
							),
						),
					),
				),
			)
		);
		$repo->shouldReceive( 'updateListingOffer' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $offer ) {
				$this->assertSame( 20, $postId );
				$this->assertSame( 'rakuten-kobo', $platform );
				$this->assertSame( '693', $offer['price'] );
				$this->assertSame( '900', $offer['list_price'] );
				$this->assertSame( '23%OFF', $offer['badge'] );
				$this->assertSame( 'https://example.test/i', $offer['image_url'] );
				$this->assertSame( 'https://example.test/r', $offer['regular_url'] );
				$this->assertSame( 'https://example.test/a', $offer['affiliate_url'] );
				$this->assertArrayHasKey( 'last_verified_at', $offer );
				$this->assertNotSame( '', (string) $offer['last_verified_at'] );
				$this->assertArrayHasKey( 'last_fetched_at', $offer );
				$this->assertNotSame( '', (string) $offer['last_fetched_at'] );
				return true;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 20, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_fetch結果のURLが空文字なら保存済みURLを上書きしない(): void {
		$this->stubDmmPlatform();
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 15 )->andReturn(
			$this->product(
				15,
				array(
					array(
						'platform'    => 'dmm-books',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'offers'      => array(
							array(
								'external_id'     => 'ext-1',
								'price'           => '900',
								'list_price'      => '1000',
								'badge'           => '',
								'image_url'       => '',
								'regular_url'     => 'https://example.test/existing-r',
								'affiliate_url'   => 'https://example.test/existing-a',
								'last_fetched_at' => '',
							),
						),
					),
				)
			)
		);
		$repo->shouldReceive( 'updateListingOffer' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $patch ) {
				$this->assertSame( '600', $patch['price'] );
				// 取得が空文字を返した URL は**パッチに載らない**。キーが無ければ保存側の
				// マージで既存値がそのまま残り、空で潰れようがない。
				$this->assertArrayNotHasKey( 'regular_url', $patch );
				$this->assertArrayNotHasKey( 'affiliate_url', $patch );
				return true;
			}
		);
		$registry  = $this->dmmProvider(
			FetchResult::hit(
				array(
					'price'           => '600',
					'list_price'      => '1000',
					'badge'           => '',
					'image_url'       => '',
					'regular_url'     => '',
					'affiliate_url'   => '',
					'platform_extras' => array(),
				)
			)
		);
		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 15, 'dmm-books' ) );
	}

	public function test_refreshOne_transient失敗時はlast_verified_atを更新せずTRANSIENTを返す(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->andReturn( FetchResult::error() );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 21 )->andReturn(
			array(
				'id'             => 21,
				'title'          => 'X',
				'status'         => 'publish',
				'product_type'   => 'generic',
				'stock_status'   => 'available',
				'extras'         => array(),
				'content'        => '',
				'schema_version' => '1',
				'modified'       => '',
				'listings'       => array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'offers'      => array(
							array(
								'external_id'      => 'deadbeef01',
								'last_verified_at' => '2020-01-01T00:00:00+09:00',
								'price'            => '500',
							),
						),
					),
				),
			)
		);
		$repo->shouldReceive( 'updateListingOffer' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $patch ) {
				// transient 失敗では表示鮮度も価格も触らない＝どちらもパッチに載らない。
				$this->assertArrayNotHasKey( 'last_verified_at', $patch );
				$this->assertArrayNotHasKey( 'price', $patch );
				return true;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $refresher->refreshOne( 21, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_fetch成功でSUCCESSを返し保存する(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->andReturn( FetchResult::hit( array( 'price' => '693' ) ) );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'offers'      => array(
							array(
								'external_id' => 'deadbeef01',
								'price'       => '',
							),
						),
					),
				)
			)
		);
		$repo->shouldReceive( 'updateListingOffer' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $offer ) {
				$this->assertSame( 12, $postId );
				$this->assertSame( '693', $offer['price'] );
				$this->assertSame( FetchStatus::NONE, $offer['fetch_status'] );
				return true;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	/**
	 * fetch は成功したが updateListingOffer() が false（find→再読込の間に対象 listing、
	 * または対象の購入リンクそのものが削除され保存できなかった）場合、refreshOne() は
	 * TRANSIENT_FAILURE を返す。ここで SUCCESS を返すとハンドラが成功と判断し、取得済みの
	 * 新価格が保存されないまま再試行もされないサイレントなデータロスになる。
	 *
	 * 消えた購入リンクを保存側が復活させない（＝false を返す）ことは
	 * ProductRepositoryTest 側で検証する。ここは「false を握り潰さない」ことだけを見る。
	 */
	public function test_refreshOne_updateListingOfferがfalseなら成功fetchでもTRANSIENTを返す(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->andReturn( FetchResult::hit( array( 'price' => '693' ) ) );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'offers'      => array(
							array(
								'external_id' => 'deadbeef01',
								'price'       => '',
							),
						),
					),
				)
			)
		);
		// 保存に失敗（fetch 中に対象の購入リンクが削除された等）を模して false を返す。
		$repo->shouldReceive( 'updateListingOffer' )->once()->andReturn( false );

		$refresher = new ListingRefresher( $registry, $repo );
		// 保存競合はリトライで解決し得るため TRANSIENT_FAILURE（give-up しない）。
		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_transient失敗でTRANSIENTを返しfetch_statusを記録(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->andReturn( FetchResult::error() );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'offers'      => array(
							array(
								'external_id' => 'deadbeef01',
								'price'       => '500',
							),
						),
					),
				)
			)
		);
		$repo->shouldReceive( 'updateListingOffer' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $patch ) {
				// transient 失敗でも保存はされる（fetch_status を記録するため）が、価格は
				// パッチに載らない＝マージで既存値が維持される。
				$this->assertArrayNotHasKey( 'price', $patch );
				$this->assertSame( FetchStatus::TRANSIENT, $patch['fetch_status'] );
				return true;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	/**
	 * 恒久失敗（miss/terminal＝該当なし・無効 ID）は TERMINAL_FAILURE を返し、fetch_status に
	 * FetchStatus::TERMINAL を記録する（last_verified_at は更新しない）。
	 * ハンドラはこれを見て give-up マーカーを立て、掃引で一定期間スキップする。
	 */
	public function test_refreshOne_terminal失敗でTERMINALを返しmiss用fetch_statusを記録(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->andReturn( FetchResult::miss() );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'offers'      => array(
							array(
								'external_id'      => 'deadbeef01',
								'price'            => '500',
								'last_verified_at' => '2020-01-01T00:00:00+09:00',
							),
						),
					),
				)
			)
		);
		$repo->shouldReceive( 'updateListingOffer' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $patch ) {
				$this->assertArrayNotHasKey( 'price', $patch );
				$this->assertSame( FetchStatus::TERMINAL, $patch['fetch_status'] );
				// terminal でも last_verified_at は更新しない（価格の表示鮮度は据え置き）。
				$this->assertArrayNotHasKey( 'last_verified_at', $patch );
				return true;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertSame( WorkOutcome::TERMINAL_FAILURE, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_platformが見つからなければSUCCESS_noopで保存しない(): void {
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'other-platform',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'external_id' => 'ext-1',
					),
				)
			)
		);
		$repo->shouldNotReceive( 'updateListingOffer' );

		$refresher = new ListingRefresher( new ProviderRegistry(), $repo );
		// platform 該当なしは対象なし（no-op）＝SUCCESS。failed 化させない。
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_商品が見つからなければSUCCESS_noop(): void {
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 999 )->andReturn( null );
		$repo->shouldNotReceive( 'updateListingOffer' );

		$refresher = new ListingRefresher( new ProviderRegistry(), $repo );
		// 削除済み商品は対象なし（no-op）＝SUCCESS。failed 化させない。
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 999, 'rakuten-kobo' ) );
	}

	/**
	 * v2.4.0: enqueue から worker 実行までの間に listing が disabled/manual へ切り替わる
	 * TOCTOU（Time-Of-Check-Time-Of-Use）を防ぐため、refreshOne は実行時に
	 * update_mode/enabled を再チェックする（force と両立するため auto_update は見ない）。
	 */
	public function test_refreshOne_disabledなlistingはSUCCESS_noopでfetchも保存もしない(): void {
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldNotReceive( 'isAutomatic' );
		$provider->shouldNotReceive( 'fetch' );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => false, // enqueue 後に無効化された
						'update_mode' => 'auto',
						'auto_update' => true,
						'external_id' => 'deadbeef01',
					),
				)
			)
		);
		$repo->shouldNotReceive( 'updateListingOffer' );

		$refresher = new ListingRefresher( $registry, $repo );
		// 実行時に無効化された listing は対象外（no-op）＝SUCCESS。failed 化させない。
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	public function test_refreshOne_manualモードに切り替わったlistingはSUCCESS_noopでfetchも保存もしない(): void {
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldNotReceive( 'isAutomatic' );
		$provider->shouldNotReceive( 'fetch' );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'manual', // enqueue 後に手動へ切り替わった
						'auto_update' => true,
						'external_id' => 'deadbeef01',
					),
				)
			)
		);
		$repo->shouldNotReceive( 'updateListingOffer' );

		$refresher = new ListingRefresher( $registry, $repo );
		// 実行時に手動化された listing は対象外（no-op）＝SUCCESS。failed 化させない。
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	/**
	 * force enqueue（管理画面「強制更新」）で積まれた auto_update=false の listing は、
	 * eligibility 再チェックで auto_update を見ないため refreshOne でも引き続き fetch される
	 * （force 機能を壊さないことの確認）。
	 */
	public function test_refreshOne_auto_updateがfalseでもenabledなautoならfetchする(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->andReturn( FetchResult::hit( array( 'price' => '693' ) ) );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => false, // 手動上書き中でも force enqueue 経路では対象
						'offers'      => array(
							array(
								'external_id' => 'deadbeef01',
								'price'       => '500',
							),
						),
					),
				)
			)
		);
		$repo->shouldReceive( 'updateListingOffer' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $offer ) {
				$this->assertSame( '693', $offer['price'] );
				return true;
			}
		);

		$refresher = new ListingRefresher( $registry, $repo );
		$this->assertSame( WorkOutcome::SUCCESS, $refresher->refreshOne( 12, 'rakuten-kobo' ) );
	}

	public function test_選択された購入リンクだけを更新する(): void {
		// 表示しているものを更新する。リクエスト数は 1 listing につき 1 回のまま。
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		// 先頭（表示順 10）だけが fetch される。2 件あっても 1 回きり。
		$provider->shouldReceive( 'fetch' )->once()->withArgs(
			static fn( string $id ): bool => 'sale' === $id
		)->andReturn( FetchResult::hit( array( 'price' => '0' ) ) );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = $this->repoWithOffers(
			array(
				array(
					'display_order' => 10,
					'external_id'   => 'sale',
					'regular_url'   => 'https://example.test/sale',
				),
				array(
					'display_order' => 100,
					'external_id'   => 'normal',
					'regular_url'   => 'https://example.test/normal',
				),
			)
		);

		$outcome = ( new ListingRefresher( $registry, $repo ) )->refreshOne( 20, 'rakuten-kobo' );

		$this->assertSame( WorkOutcome::SUCCESS, $outcome );
		// 保存へ渡すのは選ばれた 1 件だけ。後続（normal）は渡さない＝触りようがない。
		$this->assertNotNull( $this->savedOffer );
		// どの購入リンクを更新するかは identity で指定する（パッチには載らない）。
		$this->assertSame( 'external_id:sale', $this->savedIdentity );
		$this->assertSame( '0', $this->savedOffer['price'] );
	}

	public function test_成功でfetch_statusが空になる(): void {
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::hit( array( 'price' => '660' ) ) );
		$repo     = $this->repoWithOffers(
			array(
				array(
					'display_order' => 100,
					'external_id'   => 'x',
					'regular_url'   => 'https://example.test/x',
					'fetch_status'  => 'transient',
				),
			)
		);

		$outcome = ( new ListingRefresher( $registry, $repo ) )->refreshOne( 20, 'rakuten-kobo' );

		$this->assertSame( WorkOutcome::SUCCESS, $outcome );
		$this->assertSame( FetchStatus::NONE, $this->savedOffer['fetch_status'] );
		$this->assertArrayNotHasKey( 'fetch_error', $this->savedOffer );
	}

	public function test_terminal_missでfetch_statusがterminalになる(): void {
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::miss() );
		$repo     = $this->repoWithOffers(
			array(
				array(
					'display_order' => 100,
					'external_id'   => 'gone',
					'regular_url'   => 'https://example.test/gone',
				),
			)
		);

		$outcome = ( new ListingRefresher( $registry, $repo ) )->refreshOne( 20, 'rakuten-kobo' );

		$this->assertSame( WorkOutcome::TERMINAL_FAILURE, $outcome );
		$this->assertSame( FetchStatus::TERMINAL, $this->savedOffer['fetch_status'] );
		// last_fetched_at は成功・失敗を問わず毎試行で記録される。OfferPromotionTrigger の
		// クロスリクエストなループ防止は「needsRefetch() がこの刻印を見て false を返す」
		// ことに依存しており、terminal 失敗でも刻まれ続けることがその前提。
		$this->assertNotSame( '', (string) ( $this->savedOffer['last_fetched_at'] ?? '' ) );
	}

	public function test_一時失敗でfetch_statusがtransientになる(): void {
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::error() );
		$repo     = $this->repoWithOffers(
			array(
				array(
					'display_order' => 100,
					'external_id'   => 'busy',
					'regular_url'   => 'https://example.test/busy',
				),
			)
		);

		$outcome = ( new ListingRefresher( $registry, $repo ) )->refreshOne( 20, 'rakuten-kobo' );

		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $outcome );
		$this->assertSame( FetchStatus::TRANSIENT, $this->savedOffer['fetch_status'] );
		// last_fetched_at は成功・失敗を問わず毎試行で記録される（OfferPromotionTrigger の
		// クロスリクエストなループ防止の前提。上のterminalケースと同じ理由）。
		$this->assertNotSame( '', (string) ( $this->savedOffer['last_fetched_at'] ?? '' ) );
	}

	public function test_external_idが空ならunsupportedで自動取得しない(): void {
		// 管理画面で URL だけ手入力した購入リンク。手動更新専用として扱う。
		$this->stubRakutenPlatform();
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->never();
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = $this->repoWithOffers(
			array(
				array(
					'display_order' => 100,
					'external_id'   => '',
					'regular_url'   => 'https://example.test/manual',
				),
			)
		);

		$outcome = ( new ListingRefresher( $registry, $repo ) )->refreshOne( 20, 'rakuten-kobo' );

		// WorkOutcome は TRANSIENT_FAILURE のまま（リトライ挙動は変えない）。
		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $outcome );
		$this->assertSame( FetchStatus::UNSUPPORTED, $this->savedOffer['fetch_status'] );
		// last_fetched_at は成功・失敗を問わず毎試行で記録される（OfferPromotionTrigger の
		// クロスリクエストなループ防止の前提。上の terminal/transient ケースと同じ理由）。
		$this->assertNotSame( '', (string) ( $this->savedOffer['last_fetched_at'] ?? '' ) );
	}

	/**
	 * fetch のあいだに管理画面が購入リンクを追加・削除・並べ替えても、その編集は
	 * 巻き戻ってはならない。そのための最初の条件が「refreshOne() が fetch 前の
	 * listing の写しを保存経路へ渡さない」ことである（渡した瞬間、ロックの中で
	 * 読み直しても書き込む値が古い）。
	 *
	 * 身元で突き合わせて 1 件だけ差し替える処理そのものは Repository 側にあり、
	 * ProductRepositoryTest::test_updateListingOffer_* が検証する。
	 */
	public function test_保存へはlisting全体ではなく選ばれた購入リンク1件だけを渡す(): void {
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::hit( array( 'price' => '0' ) ) );
		$repo     = $this->repoWithOffers(
			array(
				array(
					'display_order' => 100,
					'external_id'   => 'normal',
					'regular_url'   => 'https://example.test/normal',
					'price'         => '660',
				),
				array(
					'display_order' => 10,
					'external_id'   => 'sale',
					'regular_url'   => 'https://example.test/sale',
				),
			)
		);

		// listing 全体を渡す旧経路は使わない。
		$repo->shouldNotReceive( 'updateListing' );

		( new ListingRefresher( $registry, $repo ) )->refreshOne( 20, 'rakuten-kobo' );

		// 渡ったのは表示順 10 の 'sale'（配列では 2 番目）1 件だけ。
		$this->assertNotNull( $this->savedOffer );
		$this->assertSame( 'external_id:sale', $this->savedIdentity );
		$this->assertSame( '0', $this->savedOffer['price'] );
		$this->assertArrayNotHasKey( 'offers', $this->savedOffer );
		// 管理者が持つ項目は取得側から送らない＝同時編集を巻き戻しようがない。
		$this->assertArrayNotHasKey( 'display_order', $this->savedOffer );
		$this->assertArrayNotHasKey( 'search_key', $this->savedOffer );
		$this->assertArrayNotHasKey( 'external_id', $this->savedOffer );
	}

	/**
	 * v4.0.0: targetCount() は refreshOne() が実際に fetch する件数（OfferSelector::select()
	 * の選択結果件数）を fetch を伴わずに返す。ThrottledActionHandler::run() が
	 * performWork()（＝refreshOne()）の前にレート制限の枠をこの件数へ比例させるために使う。
	 * offers が1件（fallback 既定 OFF）なら選択結果どおり 1。
	 */
	public function test_targetCount_offersが1件なら1を返す(): void {
		// targetCount() は「実際に外部 API を叩くか」を refreshListing() と同じ
		// willFetch() で判定するため、platform 定義の解決を通る。
		$this->stubRakutenPlatform();
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$repo = $this->repoWithOffers(
			array(
				array(
					'external_id' => 'e1',
					'regular_url' => 'https://example.test/a',
				),
			)
		);

		$registry = $this->rakutenProvider( FetchResult::hit( array( 'price' => '100' ) ) );
		$count    = ( new ListingRefresher( $registry, $repo ) )->targetCount( 20, 'rakuten-kobo' );

		$this->assertSame( 1, $count );
	}

	/**
	 * 選ばれても外部 API を叩かない購入リンクは枠を使わない。
	 *
	 * refreshListing() は自動 Provider 未対応・external_id 無しの購入リンクを
	 * fetch せず UNSUPPORTED で返す。ここで枠を取ると、叩きもしないのに
	 * レート制限を 1 件ぶん焼くことになる。
	 */
	public function test_targetCount_外部APIを叩かない購入リンクは0を返す(): void {
		$this->stubRakutenPlatform();
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$repo = $this->repoWithOffers(
			array(
				array(
					// external_id が無い＝自動取得の対象外（fetch されない）。
					'external_id' => '',
					'regular_url' => 'https://example.test/manual',
				),
			)
		);

		$registry = $this->rakutenProvider( FetchResult::hit( array( 'price' => '100' ) ) );
		$count    = ( new ListingRefresher( $registry, $repo ) )->targetCount( 20, 'rakuten-kobo' );

		$this->assertSame( 0, $count );
	}

	/** offers が空なら OfferSelector::select() の選択結果も空＝0（refreshOne 自身も fetch しない）。 */
	public function test_targetCount_offersが空なら0を返す(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$repo = $this->repoWithOffers( array() );

		$count = ( new ListingRefresher( new ProviderRegistry(), $repo ) )->targetCount( 20, 'rakuten-kobo' );

		$this->assertSame( 0, $count );
	}

	/** 該当 platform の listing が無ければ 0（refreshOne 自身も対象なし＝no-op）。 */
	public function test_targetCount_該当platformのlistingが無ければ0を返す(): void {
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 12 )->andReturn(
			$this->product(
				12,
				array(
					array(
						'platform'    => 'other-platform',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'offers'      => array( array( 'external_id' => 'e1' ) ),
					),
				)
			)
		);

		$count = ( new ListingRefresher( new ProviderRegistry(), $repo ) )->targetCount( 12, 'rakuten-kobo' );

		$this->assertSame( 0, $count );
	}

	/**
	 * CodeRabbit Minor #3: refreshOne() は ListingEligibility::isEnabledAuto() が false の
	 * listing を fetch せず SUCCESS_noop で終える。targetCount() が同じゲートを掛けずに
	 * OfferSelector::select() の件数をそのまま返すと、disabled/manual な listing にも
	 * レート制限の枠を予約してしまう（実際には fetch されない分の枠が無駄になる）。
	 * offers が1件あっても enabled=false なら 0 を返すことを固定する。
	 */
	public function test_targetCount_disabledなlistingはoffersがあっても0を返す(): void {
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 20 )->andReturn(
			$this->product(
				20,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => false,
						'update_mode' => 'auto',
						'auto_update' => true,
						'offers'      => array( array( 'external_id' => 'e1' ) ),
					),
				)
			)
		);

		$count = ( new ListingRefresher( new ProviderRegistry(), $repo ) )->targetCount( 20, 'rakuten-kobo' );

		$this->assertSame( 0, $count );
	}

	/** 商品が見つからなければ 0（refreshOne 自身も対象なし＝no-op）。 */
	public function test_targetCount_商品が見つからなければ0を返す(): void {
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 999 )->andReturn( null );

		$count = ( new ListingRefresher( new ProviderRegistry(), $repo ) )->targetCount( 999, 'rakuten-kobo' );

		$this->assertSame( 0, $count );
	}

	/**
	 * A: 移行がもう来ない flat な listing（offers キーを持たず、取得結果フィールドが
	 * listing 直下に並ぶ v3 以前の形）でも fetch する。
	 *
	 * refreshListing() が $listing['offers'] を直接読むと、そういう listing は
	 * OfferSelector::select() が空を返して一度も fetch されず TRANSIENT_FAILURE になる
	 * （リトライ枠だけを焼く）。読み取り側と同じ LegacyOffer::offersWithFallback() を
	 * 通し、flat な listing も取得対象にする。
	 *
	 * **移行が未完のあいだはここへ来ない**（refreshOne() が isHeldForMigration() で
	 * 先に見送る。下の test_refreshOne_移行が未完なら... を参照）。このフォールバックが
	 * 効くのは移行が完走したあとも flat のまま残った listing——
	 * PluginUpgrade が保存に失敗し続けて諦めた商品——であり、外すとそれらが自動更新から
	 * 恒久的に外れる。このテストの既定状態（カーソル無し＝移行は未完ではない）が
	 * まさにその状況を表している。
	 */
	public function test_refreshOne_移行がもう来ないflat_listingはフォールバックでfetchして保存する(): void {
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->withArgs(
			static function ( string $externalId, array $context ): bool {
				return 'flat-1' === $externalId
					&& 'flat-key' === ( $context['search_key'] ?? '' );
			}
		)->andReturn( FetchResult::hit( array( 'price' => '550' ) ) );
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 31 )->andReturn(
			$this->product(
				31,
				array(
					// offers キーが無い＝移行前の flat な listing。
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'external_id' => 'flat-1',
						'regular_url' => 'https://example.test/flat',
						'search_key'  => 'flat-key',
						'price'       => '700',
					),
				)
			)
		);
		$repo->shouldReceive( 'updateListingOffer' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $patch, string $identity ): bool {
				$this->savedOffer    = $patch;
				$this->savedIdentity = $identity;
				return true;
			}
		);

		$outcome = ( new ListingRefresher( $registry, $repo ) )->refreshOne( 31, 'rakuten-kobo' );

		$this->assertSame( WorkOutcome::SUCCESS, $outcome );
		$this->assertNotNull( $this->savedOffer );
		// flat な listing でも identity は同じ規則で決まる。
		$this->assertSame( 'external_id:flat-1', $this->savedIdentity );
		$this->assertSame( '550', $this->savedOffer['price'] );
		$this->assertSame( FetchStatus::NONE, $this->savedOffer['fetch_status'] );
		// 取得が返さなかった URL はパッチに載らない（既存値がマージで残る）。
		$this->assertArrayNotHasKey( 'regular_url', $this->savedOffer );
	}

	/**
	 * A: targetCount() も同じフォールバックを通す。refreshOne() が fetch するのに
	 * ここが 0 を返すと、レート制限の枠を確保しないまま外部 API を叩くことになる。
	 *
	 * 上と同じく、移行が未完でない（＝もう来ない）状態での話である。
	 */
	public function test_targetCount_移行がもう来ないflat_listingでは1を返す(): void {
		// targetCount() は「実際に外部 API を叩くか」を refreshListing() と同じ
		// willFetch() で判定するため、platform 定義の解決を通る。
		$this->stubRakutenPlatform();
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 32 )->andReturn(
			$this->product(
				32,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'external_id' => 'flat-2',
						'regular_url' => 'https://example.test/flat2',
					),
				)
			)
		);

		$registry = $this->rakutenProvider( FetchResult::hit( array( 'price' => '100' ) ) );
		$count    = ( new ListingRefresher( $registry, $repo ) )->targetCount( 32, 'rakuten-kobo' );

		$this->assertSame( 1, $count );
	}

	/**
	 * 移行が未完のあいだ、まだ変換されていない flat な listing には書き込まない。
	 *
	 * **なぜ。** 保存（ProductRepository::updateListingOffer()）は listing を offers[] へ
	 * 揃えてから書き戻すため、flat な listing への価格更新は flat → offers[] の変換を
	 * 兼ねてしまう。その変換は sanitize を通り、身元（regular_url / external_id）を
	 * 1 つも持たない購入リンクをそこで落とす。移行だけがその規則を外して温存し、
	 * 件数と post ID を控えて管理画面が名指しで通知する——価格更新が先に届くと、
	 * データが消えるうえに通知も出ない。
	 *
	 * **主張は「書き込みが起きなかったこと」である。** updateListingOffer() に never() を
	 * 置くだけだと、違反は Mockery の close 時にしか現れず、しかも never() には戻り値
	 * ハンドラが無いので $this->savedOffer は null のまま——「書き込まれたのに
	 * assertNull が通る」状態になる（＝試行を見て効果を見ていない）。記録する
	 * ハンドラを付けたうえで savedOffer が null であることを主張する。
	 */
	public function test_refreshOne_移行が未完なら未変換のflat_listingへは書き込まない(): void {
		$this->markOffersMigrationPending();
		$this->stubRakutenPlatform();

		$registry = $this->rakutenProvider( FetchResult::hit( array( 'price' => '550' ) ) );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 41 )->andReturn(
			$this->product(
				41,
				array(
					// offers キーが無い＝移行がまだ到達していない listing。
					// 身元（regular_url / external_id）を 1 つも持たない＝保存が起きた
					// 瞬間に sanitize が落とす、まさに守りたいデータ。
					array(
						'platform'      => 'rakuten-kobo',
						'enabled'       => true,
						'update_mode'   => 'auto',
						'auto_update'   => true,
						'affiliate_url' => 'https://example.test/aff-only',
						'price'         => '400',
					),
				)
			)
		);
		$repo->shouldReceive( 'updateListingOffer' )->andReturnUsing(
			function ( int $postId, string $platform, array $patch, string $identity ): bool {
				$this->savedOffer    = $patch;
				$this->savedIdentity = $identity;
				return true;
			}
		);

		$outcome = ( new ListingRefresher( $registry, $repo ) )->refreshOne( 41, 'rakuten-kobo' );

		$this->assertNull(
			$this->savedOffer,
			'移行がまだ到達していない listing へ価格更新が書き込んだ（身元なしの購入リンクが sanitize で消える）'
		);
		// 一時失敗ではなく no-op（SUCCESS）で返す。TRANSIENT_FAILURE にすると backoff の
		// 試行回数を焼き、移行が長引くインストールで failed が積み上がる。
		$this->assertSame( WorkOutcome::SUCCESS, $outcome );
	}

	/**
	 * 見送りは fetch より前に効く。外部 ID を持つ flat listing でも、移行が未完なら
	 * 外部 API を 1 度も叩かない。
	 *
	 * 身元を持つ購入リンクは保存されても消えないが、**保存が flat → offers[] の変換を
	 * 兼ねてしまう点は同じ**である。同じ商品の別 listing が身元なしだった場合、
	 * updateListing 系はその商品の listings をまとめて sanitize し直すため巻き添えになる。
	 * 判定を「listing に身元があるか」ではなく「移行が変換するつもりか」に置いているのは
	 * そのためで、ここではその範囲（fetch すらしない）を固定する。
	 */
	public function test_refreshOne_移行が未完なら未変換のlistingでは外部APIも叩かない(): void {
		$this->markOffersMigrationPending();
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->never();
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 44 )->andReturn(
			$this->product(
				44,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'external_id' => 'flat-held',
						'regular_url' => 'https://example.test/flat-held',
					),
				)
			)
		);
		$repo->shouldReceive( 'updateListingOffer' )->andReturnUsing(
			function ( int $postId, string $platform, array $patch, string $identity ): bool {
				$this->savedOffer = $patch;
				return true;
			}
		);

		$outcome = ( new ListingRefresher( $registry, $repo ) )->refreshOne( 44, 'rakuten-kobo' );

		$this->assertNull( $this->savedOffer, '見送ったはずの listing へ書き込んだ' );
		$this->assertSame( WorkOutcome::SUCCESS, $outcome );
	}

	/**
	 * 見送りは「未変換の listing」に限る。移行が未完でも、既に offers[] を持つ listing は
	 * 普通に更新する。
	 *
	 * ここを止めると、移行が走っているあいだカタログ全体の価格更新が止まる。止める理由が
	 * あるのは「価格更新が変換を兼ねてしまう」listing だけである。
	 */
	public function test_refreshOne_移行が未完でも変換済みのlistingは普通に更新する(): void {
		$this->markOffersMigrationPending();
		$this->stubRakutenPlatform();

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->andReturn(
			FetchResult::hit( array( 'price' => '880' ) )
		);
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 42 )->andReturn(
			$this->product(
				42,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'offers'      => array(
							array(
								'display_order' => 10,
								'external_id'   => 'migrated-1',
								'regular_url'   => 'https://example.test/migrated',
							),
						),
					),
				)
			)
		);
		$repo->shouldReceive( 'updateListingOffer' )->once()->andReturnUsing(
			function ( int $postId, string $platform, array $patch, string $identity ): bool {
				$this->savedOffer    = $patch;
				$this->savedIdentity = $identity;
				return true;
			}
		);

		$outcome = ( new ListingRefresher( $registry, $repo ) )->refreshOne( 42, 'rakuten-kobo' );

		$this->assertSame( WorkOutcome::SUCCESS, $outcome );
		$this->assertNotNull( $this->savedOffer );
		$this->assertSame( '880', $this->savedOffer['price'] );
	}

	/**
	 * targetCount() も同じゲートを通す。refreshOne() が外部 API を 1 度も叩かないのに
	 * 枠を確保すると、account の最終リクエスト時刻だけが進み、実際に fetch したい
	 * 後続のジョブを無駄に待たせる（isEnabledAuto ゲートを写しているのと同じ理由）。
	 */
	public function test_targetCount_移行が未完なら未変換のflat_listingでは0を返す(): void {
		$this->markOffersMigrationPending();
		$this->stubRakutenPlatform();
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		$repo = Mockery::mock( ProductRepositoryInterface::class );
		$repo->shouldReceive( 'find' )->with( 43 )->andReturn(
			$this->product(
				43,
				array(
					array(
						'platform'    => 'rakuten-kobo',
						'enabled'     => true,
						'update_mode' => 'auto',
						'auto_update' => true,
						'external_id' => 'flat-3',
						'regular_url' => 'https://example.test/flat3',
					),
				)
			)
		);

		$registry = $this->rakutenProvider( FetchResult::hit( array( 'price' => '100' ) ) );
		$count    = ( new ListingRefresher( $registry, $repo ) )->targetCount( 43, 'rakuten-kobo' );

		$this->assertSame( 0, $count );
	}

	/**
	 * 非スカラーを 1 つ持つだけの購入リンクを作る。
	 *
	 * **配列ではなく `__toString()` を持つオブジェクトを使う。** 配列を `(string)` で
	 * 畳むと「Array to string conversion」の警告が出るが、phpunit.xml は
	 * `convertWarningsToExceptions` なので、直す前のコードは「assertion が食い違う」
	 * ではなく「警告が例外になる」形で落ちてしまい、テストが狙いどおり落ちたのか
	 * 分からない。`__toString()` を持つオブジェクトは `(string)` なら黙って文字列に
	 * なり、{@see \Affilicard\Util\ScalarField::string()}（`is_scalar()` は
	 * オブジェクトに false）なら空文字になる——読み方の違いだけが結果に出る。
	 */
	private function stringableOf( string $value ): object {
		return new class( $value ) {
			public function __construct( private string $value ) {}

			public function __toString(): string {
				return $this->value;
			}
		};
	}

	/**
	 * 非スカラーの external_id は身元として読まない＝自動取得の対象外にする。
	 *
	 * `(string)` で直に畳むと、でたらめな外部 ID（配列なら `'Array'`）がそのまま
	 * Provider の fetch() へ渡り、外部 API を叩いたうえで誰の価格とも分からない結果を
	 * 持ち帰る。ScalarField::string() で読めば「値なし」に倒れ、UNSUPPORTED として
	 * fetch せずに終わる。
	 */
	public function test_非スカラーのexternal_idは自動取得の対象外にする(): void {
		$this->stubRakutenPlatform();

		// **fetch() は never() ではなく「呼ばれたら分かる」形で置く。** never() だと
		// Mockery が既定の戻り値を作ろうとして final な FetchResult で例外になり、
		// 「assertion が食い違った」のか「モックが足りない」のか区別できない。
		$fetched  = false;
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->andReturnUsing(
			static function () use ( &$fetched ): FetchResult {
				$fetched = true;
				return FetchResult::hit( array( 'price' => '550' ) );
			}
		);
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = $this->repoWithOffers(
			array(
				array(
					'external_id' => $this->stringableOf( 'rk-1' ),
					'regular_url' => 'https://example.test/rk-1',
				),
			)
		);

		$outcome = ( new ListingRefresher( $registry, $repo ) )->refreshOne( 20, 'rakuten-kobo' );

		$this->assertFalse( $fetched, '非スカラーの external_id で外部 API を叩いてはならない' );
		$this->assertSame( WorkOutcome::TRANSIENT_FAILURE, $outcome );
		$this->assertNotNull( $this->savedOffer );
		$this->assertSame( FetchStatus::UNSUPPORTED, $this->savedOffer['fetch_status'] );
		// 身元も同じ規則で読む（external_id が「値なし」なら regular_url 側へ落ちる）。
		$this->assertSame( 'regular_url:https://example.test/rk-1', $this->savedIdentity );
	}

	/**
	 * 非スカラーの external_id はレート制限の枠も取らない。
	 *
	 * targetCount() が 1 を返すのに refreshOne() は fetch しない、という枠と実行の
	 * ズレを作らないため、判定は willFetch() 1 箇所に集約されている。読み方だけが
	 * ずれても同じズレが起きる。
	 */
	public function test_非スカラーのexternal_idはレート制限の枠を取らない(): void {
		$this->stubRakutenPlatform();
		$registry = $this->rakutenProvider( FetchResult::hit( array( 'price' => '100' ) ) );

		$repo = $this->repoWithOffers(
			array(
				array(
					'external_id' => $this->stringableOf( 'rk-1' ),
					'regular_url' => 'https://example.test/rk-1',
				),
			)
		);

		$count = ( new ListingRefresher( $registry, $repo ) )->targetCount( 20, 'rakuten-kobo' );

		$this->assertSame( 0, $count );
	}

	/**
	 * 非スカラーの search_key / regular_url は Provider へ渡さない。
	 *
	 * search_key は「空なら商品タイトル」という既存のフォールバックへ落ち、
	 * regular_url は空文字で渡る。`(string)` のままだと `'Array'` のような検索語で
	 * 外部 API を叩き、当たるはずのない商品を「該当なし（恒久失敗）」として
	 * give-up させてしまう。
	 */
	public function test_非スカラーのsearch_keyとregular_urlはfetchへ渡さない(): void {
		$this->stubRakutenPlatform();

		$seen     = null;
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'code' )->andReturn( 'rakuten-kobo' );
		$provider->shouldReceive( 'isAutomatic' )->andReturn( true );
		$provider->shouldReceive( 'fetch' )->once()->andReturnUsing(
			function ( string $externalId, array $context ) use ( &$seen ): FetchResult {
				$seen = $context;
				return FetchResult::hit( array( 'price' => '550' ) );
			}
		);
		$registry = new ProviderRegistry();
		$registry->register( $provider );

		$repo = $this->repoWithOffers(
			array(
				array(
					'external_id' => 'rk-1',
					'search_key'  => $this->stringableOf( 'おかしな検索語' ),
					'regular_url' => $this->stringableOf( 'https://example.test/rk-1' ),
				),
			)
		);

		( new ListingRefresher( $registry, $repo ) )->refreshOne( 20, 'rakuten-kobo' );

		$this->assertIsArray( $seen );
		// repoWithOffers() の商品タイトル。
		$this->assertSame( '対象巻', $seen['search_key'] );
		$this->assertSame( '', $seen['regular_url'] );
	}
}
