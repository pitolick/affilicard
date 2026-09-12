<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Upgrade;

use Affilicard\PostType\ProductPostType;
use Affilicard\Pricing\FetchStatus;
use Affilicard\Pricing\OfferSelector;
use Affilicard\Queue\OfferPromotionTrigger;
use Affilicard\Rest\ProductSchema;
use Affilicard\Schema\SchemaVersion;
use Affilicard\Upgrade\PluginUpgrade;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class PluginUpgradeTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		OfferPromotionTrigger::resetForTests();

		// 「保存される形」を再現するために本物の ProductSchema::sanitizeListings() を
		// 走らせる。ProductSchemaTest と同じ振る舞いのスタブを置く。
		WP_Mock::userFunction( 'sanitize_text_field' )
			->andReturnUsing(
				static function ( $value ) {
					return is_scalar( $value ) ? trim( (string) $value ) : '';
				}
			);
		WP_Mock::userFunction( 'sanitize_key' )
			->andReturnUsing(
				static function ( $value ) {
					$value = is_scalar( $value ) ? strtolower( (string) $value ) : '';
					return preg_replace( '/[^a-z0-9_\-]/', '', $value );
				}
			);
		WP_Mock::userFunction( 'esc_url_raw' )
			->andReturnUsing(
				static function ( $value ) {
					return is_scalar( $value ) ? (string) $value : '';
				}
			);
	}

	public function tearDown(): void {
		OfferPromotionTrigger::resetForTests();
		WP_Mock::tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	/**
	 * `update_post_meta( ..., META_LISTINGS, ... )` を WordPress と同じように振る舞わせる。
	 *
	 * **既存のテストが見ていたのは「update_post_meta へ渡した配列」であって「格納される
	 * 配列」ではない。** ProductMeta::register() が META_LISTINGS に
	 * `sanitize_callback => ProductSchema::sanitizeListings` を登録しているため、
	 * WordPress は `update_metadata()` の中で `sanitize_meta()` を必ず通す。渡した値と
	 * 格納される値が食い違うのがまさに本バグ（救出したはずの offer が保存時に消えていた）で、
	 * モックで渡し値だけを見るテストでは永久に検出できない。
	 *
	 * このヘルパは渡された値をその場（＝移行が開いた窓の内側）で本物の
	 * sanitizeListings() に通し、その結果を参照へ書き出す。以後 listings の保存を
	 * 検証するテストは、渡し値ではなくこの「格納される形」を assert すること。
	 *
	 * @param array<string, mixed>|null $stored 格納される形の受け皿（参照）。
	 */
	private function expectListingsWriteCapturingStoredShape( int $postId, &$stored ): void {
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( $postId, ProductPostType::META_LISTINGS, \Mockery::type( 'array' ) )
			->andReturnUsing(
				static function ( $id, $key, $value ) use ( &$stored ): bool {
					// WordPress の update_metadata() → sanitize_meta() 相当。
					$stored = ProductSchema::sanitizeListings( $value );
					return true;
				}
			);
	}

	/** 未完の移行が無い（カーソル option が存在しない）状態を作る。 */
	private function stubNoMigrationPending(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_CURSOR, false )
			->andReturn( false );
	}

	/** scheduleOffersMigration() が立てる「未完」の印（カーソル作成）を期待する。 */
	private function expectMigrationMarkerCreated(): void {
		WP_Mock::userFunction( 'add_option' )
			->once()
			->with( PluginUpgrade::OPTION_MIGRATION_CURSOR, 0, '', false )
			->andReturn( true );
	}

	public function test_初回は棚卸し基準日を作成しバージョンを記録する(): void {
		$this->stubNoMigrationPending();
		$this->expectMigrationMarkerCreated();
		WP_Mock::userFunction( 'as_schedule_single_action' )->andReturn( 1 );
		WP_Mock::userFunction( 'get_option' )->with( PluginUpgrade::OPTION_VERSION, '' )->andReturn( '' );
		WP_Mock::userFunction( 'add_option' )
			->once()
			->with( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, \Mockery::type( 'string' ), '', false )
			->andReturn( true );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( PluginUpgrade::OPTION_VERSION, '3.5.0', false );

		PluginUpgrade::maybeUpgrade( '3.5.0' );

		$this->assertConditionsMet();
	}

	public function test_同一バージョンなら何もしない(): void {
		$this->stubNoMigrationPending();
		WP_Mock::userFunction( 'get_option' )->with( PluginUpgrade::OPTION_VERSION, '' )->andReturn( '3.5.0' );
		WP_Mock::userFunction( 'add_option' )->never();
		WP_Mock::userFunction( 'update_option' )->never();

		PluginUpgrade::maybeUpgrade( '3.5.0' );

		$this->assertConditionsMet();
	}

	/**
	 * add_option() は「既に存在する」場合も false を返す。既存の基準日が実在することを
	 * get_option() で確認できれば、移行として正常なのでバージョンは進める。
	 */
	public function test_既存の基準日がある場合はバージョンが更新される(): void {
		$this->stubNoMigrationPending();
		$this->expectMigrationMarkerCreated();
		WP_Mock::userFunction( 'as_schedule_single_action' )->andReturn( 1 );
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_VERSION, '' )
			->andReturn( '3.4.0' );
		WP_Mock::userFunction( 'add_option' )
			->once()
			->with( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, \Mockery::type( 'string' ), '', false )
			->andReturn( false );
		// add_option が false を返した理由を get_option で確認する。既存の値が実在する
		// （＝「既に存在する」ケース）ことを示す。
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, '' )
			->andReturn( '2026-01-01T00:00:00+00:00' );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( PluginUpgrade::OPTION_VERSION, '3.5.0', false );

		PluginUpgrade::maybeUpgrade( '3.5.0' );

		$this->assertConditionsMet();
	}

	/**
	 * add_option() が false を返し、かつ get_option() でも基準日が実在しないと確認できた
	 * 場合（＝真の保存失敗）は、バージョンを進めてはいけない。進めてしまうと
	 * maybeUpgrade() が次回以降 stored===currentVersion で早期 return し、基準日が
	 * 永久に作られない（＝棚卸しが永久に発動しない）。
	 */
	public function test_基準日の保存に失敗した場合はバージョンを更新せず次回再試行できる(): void {
		$this->stubNoMigrationPending();
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_VERSION, '' )
			->andReturn( '3.4.0' );
		WP_Mock::userFunction( 'add_option' )
			->once()
			->with( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, \Mockery::type( 'string' ), '', false )
			->andReturn( false );
		// get_option で確認しても基準日が存在しない（＝真の保存失敗）。
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, '' )
			->andReturn( '' );
		WP_Mock::userFunction( 'update_option' )->never();

		PluginUpgrade::maybeUpgrade( '3.5.0' );

		$this->assertConditionsMet();
	}

	/** v3 以前の flat な listing。 */
	private function legacyListing( string $fetchError = '' ): array {
		return array(
			'platform'         => 'rakuten-kobo',
			'enabled'          => true,
			'auto_update'      => true,
			'update_mode'      => 'auto',
			'external_id'      => 'abc',
			'regular_url'      => 'https://example.test/abc',
			'affiliate_url'    => 'https://af.test/abc',
			'price'            => '660',
			'list_price'       => '900',
			'badge'            => '26%OFF',
			'image_url'        => 'https://img.test/abc.jpg',
			'search_key'       => '対象巻',
			'fetch_error'      => $fetchError,
			'last_fetched_at'  => '2026-09-01T00:00:00+00:00',
			'last_verified_at' => '2026-09-01T00:00:00+00:00',
		);
	}

	public function test_flatな取得結果がoffers0へ移る(): void {
		$migrated = PluginUpgrade::migrateListingToOffers( $this->legacyListing() );

		$this->assertCount( 1, $migrated['offers'] );
		$this->assertSame( 100, $migrated['offers'][0]['display_order'] );
		$this->assertSame( 'abc', $migrated['offers'][0]['external_id'] );
		$this->assertSame( '660', $migrated['offers'][0]['price'] );
		$this->assertSame( '対象巻', $migrated['offers'][0]['search_key'] );
		// 設定フィールドは listing に残る。
		$this->assertTrue( $migrated['enabled'] );
		$this->assertSame( 'auto', $migrated['update_mode'] );
		// 取得結果は listing 直下から消える。
		$this->assertArrayNotHasKey( 'external_id', $migrated );
		$this->assertArrayNotHasKey( 'fetch_error', $migrated );
	}

	public function test_fetch_errorがfetch_statusへ写像される(): void {
		$cases = array(
			'該当する商品が見つかりませんでした'      => FetchStatus::TERMINAL,
			'対応する自動 Provider がありません' => FetchStatus::UNSUPPORTED,
			'価格情報の取得に失敗しました'         => FetchStatus::TRANSIENT,
			''                       => FetchStatus::NONE,
		);
		foreach ( $cases as $message => $expected ) {
			$migrated = PluginUpgrade::migrateListingToOffers( $this->legacyListing( (string) $message ) );
			$this->assertSame( $expected, $migrated['offers'][0]['fetch_status'], (string) $message );
		}
	}

	public function test_未知のfetch_errorはtransientへ倒れる(): void {
		// 恒久と誤認して購入リンクを飛ばすより安全。
		$migrated = PluginUpgrade::migrateListingToOffers( $this->legacyListing( '手で書き換えられた文言' ) );
		$this->assertSame( FetchStatus::TRANSIENT, $migrated['offers'][0]['fetch_status'] );
	}

	public function test_移行は冪等(): void {
		// offers が既にある listing はスキップする。2 回流しても結果が変わらない。
		$once  = PluginUpgrade::migrateListingToOffers( $this->legacyListing() );
		$twice = PluginUpgrade::migrateListingToOffers( $once );
		$this->assertSame( $once, $twice );
	}

	public function test_移行後のoffersは1件で挙動が変わらない(): void {
		// 選択係が常にその 1 件を返す＝表示も更新も移行前と同じ。
		$migrated = PluginUpgrade::migrateListingToOffers( $this->legacyListing() );

		$this->assertSame( $migrated['offers'], OfferSelector::select( $migrated['offers'], false ) );
		$this->assertSame( $migrated['offers'], OfferSelector::select( $migrated['offers'], true ) );
	}

	public function test_SchemaVersionが2になる(): void {
		$this->assertSame( '2', SchemaVersion::CURRENT );
	}

	/**
	 * regular_url を持たない listing（手入力で affiliate_url のみ設定されていた等）でも、
	 * 移行は offer を落とさず持ち越す。新規保存（ProductSchema::sanitizeOffers）が
	 * regular_url 空の offer を弾くルールを、移行に遡って適用してはならない
	 * （データが復元不能な形で消える）。
	 */
	public function test_regular_urlが無いofferも落とさず持ち越す(): void {
		$legacy = array_merge( $this->legacyListing(), array( 'regular_url' => '' ) );

		$migrated = PluginUpgrade::migrateListingToOffers( $legacy );

		$this->assertCount( 1, $migrated['offers'] );
		$this->assertSame( '', $migrated['offers'][0]['regular_url'] );
		$this->assertSame( 'https://af.test/abc', $migrated['offers'][0]['affiliate_url'] );
	}

	public function test_バージョン更新時にoffers移行の開始トリガーを積む(): void {
		$this->stubNoMigrationPending();
		$this->expectMigrationMarkerCreated();
		WP_Mock::userFunction( 'get_option' )->with( PluginUpgrade::OPTION_VERSION, '' )->andReturn( '3.5.0' );
		WP_Mock::userFunction( 'add_option' )
			->once()
			->with( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, \Mockery::type( 'string' ), '', false )
			->andReturn( true );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( PluginUpgrade::OPTION_VERSION, '3.6.0', false );
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with( \Mockery::type( 'int' ), PluginUpgrade::HOOK_MIGRATE_OFFERS, array(), \Mockery::type( 'string' ), true )
			->andReturn( 123 );

		PluginUpgrade::maybeUpgrade( '3.6.0' );

		$this->assertConditionsMet();
	}

	/**
	 * 走査件数がバッチサイズ未満なら完走とみなし、カーソルを消し、変換した listings を保存する。
	 */
	public function test_バッチが商品数未満で完走しカーソルを消してoffersを保存する(): void {
		$legacy = array_merge( $this->legacyListing(), array( 'external_id' => '' ) );

		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_CURSOR, 0 )
			->andReturn( 0 );
		WP_Mock::userFunction( 'remove_filter' )->andReturn( true );
		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array( 501 ) );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 501, ProductPostType::META_LISTINGS, true )
			->andReturn( array( $legacy ) );
		// syncDerivedMeta() の extid mirror 走査（external_id が空のため mirror 追加は発生しない）。
		WP_Mock::userFunction( 'get_post_meta' )->with( 501 )->andReturn( array() );

		$stored = null;
		$this->expectListingsWriteCapturingStoredShape( 501, $stored );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 501, ProductPostType::META_SCHEMA_VERSION, SchemaVersion::CURRENT )->andReturn( true );

		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL, 0 )
			->andReturn( 0 );
		WP_Mock::userFunction( 'delete_option' )->once()->with( PluginUpgrade::OPTION_MIGRATION_CURSOR );
		WP_Mock::userFunction( 'update_option' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		PluginUpgrade::runOffersMigrationBatch();

		// 渡し値ではなく「格納される形」を検証する。
		$this->assertCount( 1, $stored );
		$this->assertCount( 1, $stored[0]['offers'] );
		$this->assertSame( 'https://example.test/abc', $stored[0]['offers'][0]['regular_url'] );
		$this->assertSame( 'https://af.test/abc', $stored[0]['offers'][0]['affiliate_url'] );
		$this->assertSame( '660', $stored[0]['offers'][0]['price'] );
		$this->assertSame( '対象巻', $stored[0]['offers'][0]['search_key'] );
		$this->assertConditionsMet();
	}

	/**
	 * 走査件数がバッチサイズちょうどなら続きがあるとみなし、カーソルを保存して
	 * 自分自身を unique=false で積み直す（QueueMaintenance::sweep() の継続方式と同じ）。
	 */
	public function test_バッチが上限件数に達したらカーソルを保存し積み直す(): void {
		$ids = range( 1, PluginUpgrade::MIGRATION_BATCH_SIZE );

		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_CURSOR, 0 )
			->andReturn( 0 );
		WP_Mock::userFunction( 'remove_filter' )->andReturn( true );
		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( $ids );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( \Mockery::type( 'int' ), ProductPostType::META_LISTINGS, true )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( \Mockery::type( 'int' ) )
			->andReturn( array() );
		WP_Mock::userFunction( 'update_post_meta' )
			->with( \Mockery::type( 'int' ), ProductPostType::META_SCHEMA_VERSION, SchemaVersion::CURRENT )
			->andReturn( true );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( PluginUpgrade::OPTION_MIGRATION_CURSOR, PluginUpgrade::MIGRATION_BATCH_SIZE, false );
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with( \Mockery::type( 'int' ), PluginUpgrade::HOOK_MIGRATE_OFFERS, array(), \Mockery::type( 'string' ), false )
			->andReturn( 999 );
		WP_Mock::userFunction( 'delete_option' )->never();

		PluginUpgrade::runOffersMigrationBatch();

		$this->assertConditionsMet();
	}

	/**
	 * **本タスクの本丸。** regular_url を持たない listing は移行で消えてはならない。
	 *
	 * `ProductMeta::register()` が META_LISTINGS に `ProductSchema::sanitizeListings` を
	 * sanitize_callback として登録しているため、移行の `update_post_meta()` は
	 * `update_metadata()` の中で必ずそれを通る。素で書くと `sanitizeOffers()` の
	 * 「regular_url 空の offer は弾く」ルールが、移行が救出したまさにその offer を
	 * 消し（affiliate_url / price / external_id / search_key ごと失われ）、それでいて
	 * 「N 件温存しました」と報告する——沈黙より悪い偽の全問題なしになる。
	 *
	 * ここでは渡し値ではなく、WordPress が実際に格納する形（本物の sanitizeListings() を
	 * 通した結果）を検証する。
	 */
	public function test_保存される形でも救出したofferが生き残る(): void {
		$legacy = array_merge(
			$this->legacyListing(),
			array(
				'external_id' => '',
				'regular_url' => '',
			)
		);

		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_CURSOR, 0 )
			->andReturn( 0 );
		WP_Mock::userFunction( 'remove_filter' )->andReturn( true );
		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array( 501 ) );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 501, ProductPostType::META_LISTINGS, true )
			->andReturn( array( $legacy ) );
		WP_Mock::userFunction( 'get_post_meta' )->with( 501 )->andReturn( array() );

		$stored = null;
		$this->expectListingsWriteCapturingStoredShape( 501, $stored );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 501, ProductPostType::META_SCHEMA_VERSION, SchemaVersion::CURRENT )->andReturn( true );

		$preserved = 0;
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL, 0 )
			->andReturnUsing(
				static function () use ( &$preserved ): int {
					return $preserved;
				}
			);
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( PluginUpgrade::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL, 1, false )
			->andReturnUsing(
				static function ( $key, $value ) use ( &$preserved ): bool {
					$preserved = (int) $value;
					return true;
				}
			);
		WP_Mock::userFunction( 'delete_option' )->once()->with( PluginUpgrade::OPTION_MIGRATION_CURSOR );

		PluginUpgrade::runOffersMigrationBatch();

		// 格納される形でも offer が 1 件残り、購入リンクと価格が生きている。
		$this->assertIsArray( $stored );
		$this->assertCount( 1, $stored );
		$this->assertCount( 1, $stored[0]['offers'], '救出した offer が保存時に消えている' );
		$this->assertSame( '', $stored[0]['offers'][0]['regular_url'] );
		$this->assertSame( 'https://af.test/abc', $stored[0]['offers'][0]['affiliate_url'] );
		$this->assertSame( '660', $stored[0]['offers'][0]['price'] );
		$this->assertSame( '対象巻', $stored[0]['offers'][0]['search_key'] );

		// 「温存した」件数は、消えたデータではなく実際に残ったデータについての報告である。
		$this->assertSame( 1, $preserved );
		$this->assertConditionsMet();
	}

	/**
	 * 移行が渡す値は、WordPress の sanitize を通しても変化しない（不動点である）。
	 *
	 * 個別フィールドの assert は「今 sanitize が食う 1 つ」しか守れない。whitelist 型の
	 * sanitizer にフィールドやルールが増えれば、次に食われるのは別のフィールドになる
	 * （このリファクタで既に 2 度起きている）。渡し値と格納値の一致そのものを固定して、
	 * 「移行が書いたものが黙って書き換えられる」変更全般をここで落とす。
	 */
	public function test_移行が渡す値はsanitizeを通しても変化しない(): void {
		$legacy = array(
			// regular_url あり / なし・external_id あり / なしを 1 商品に混ぜる。
			$this->legacyListing(),
			array_merge(
				$this->legacyListing(),
				array(
					'platform'    => 'dmm',
					'external_id' => '',
					'regular_url' => '',
				)
			),
		);

		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_CURSOR, 0 )
			->andReturn( 0 );
		WP_Mock::userFunction( 'remove_filter' )->andReturn( true );
		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array( 777 ) );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 777, ProductPostType::META_LISTINGS, true )
			->andReturn( $legacy );
		WP_Mock::userFunction( 'get_post_meta' )->with( 777 )->andReturn( array() );
		// extid mirror の再構築（external_id が非空なので mirror へ 1 件追加される）。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 777, \Mockery::type( 'string' ), false )
			->andReturn( array() );
		WP_Mock::userFunction( 'add_post_meta' )->andReturn( 1 );

		$passed = null;
		$stored = null;
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 777, ProductPostType::META_LISTINGS, \Mockery::type( 'array' ) )
			->andReturnUsing(
				static function ( $id, $key, $value ) use ( &$passed, &$stored ): bool {
					$passed = $value;
					// WordPress の update_metadata() → sanitize_meta() 相当。
					$stored = ProductSchema::sanitizeListings( $value );
					return true;
				}
			);
		WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 777, ProductPostType::META_SCHEMA_VERSION, SchemaVersion::CURRENT )->andReturn( true );

		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL, 0 )
			->andReturn( 0 );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( PluginUpgrade::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL, 1, false )
			->andReturn( true );
		WP_Mock::userFunction( 'delete_option' )->once()->with( PluginUpgrade::OPTION_MIGRATION_CURSOR );

		PluginUpgrade::runOffersMigrationBatch();

		$this->assertSame( $passed, $stored, '移行が渡した listings が sanitize で書き換えられている' );
		$this->assertCount( 2, $stored );
		$this->assertCount( 1, $stored[1]['offers'] );
		$this->assertConditionsMet();
	}

	/**
	 * 移行の書き込みの最中は、regular_url 空の offer を弾くルールが外れており、
	 * 繰り上がりトリガーが抑止されている。
	 *
	 * 移行した offer は元の（多くは古い）last_fetched_at を引き継ぐため、抑止しないと
	 * アップグレードした瞬間にカタログ全件ぶんの即時取得が積まれる。
	 */
	public function test_移行の書き込み中は繰り上がりトリガーが止まっている(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_CURSOR, 0 )
			->andReturn( 0 );
		WP_Mock::userFunction( 'remove_filter' )->andReturn( true );
		WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array( 601 ) );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 601, ProductPostType::META_LISTINGS, true )
			->andReturn( array( $this->legacyListing() ) );
		WP_Mock::userFunction( 'get_post_meta' )->with( 601 )->andReturn( array() );
		// extid mirror の再構築（external_id が非空なので mirror へ 1 件追加される）。
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 601, \Mockery::type( 'string' ), false )
			->andReturn( array() );
		WP_Mock::userFunction( 'add_post_meta' )->andReturn( 1 );

		$suppressedDuringWrite = null;
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 601, ProductPostType::META_LISTINGS, \Mockery::type( 'array' ) )
			->andReturnUsing(
				static function () use ( &$suppressedDuringWrite ): bool {
					$suppressedDuringWrite = OfferPromotionTrigger::isSuppressed();
					return true;
				}
			);
		WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 601, ProductPostType::META_SCHEMA_VERSION, SchemaVersion::CURRENT )->andReturn( true );
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL, 0 )
			->andReturn( 0 );
		WP_Mock::userFunction( 'delete_option' )->once()->with( PluginUpgrade::OPTION_MIGRATION_CURSOR );

		PluginUpgrade::runOffersMigrationBatch();

		$this->assertTrue( $suppressedDuringWrite, '移行の書き込みで繰り上がりトリガーが走ってしまう' );
		// 窓は書き込み 1 回分。抜けたら必ず閉じている。
		$this->assertFalse( OfferPromotionTrigger::isSuppressed() );
		$this->assertConditionsMet();
	}

	/**
	 * 移行が中断しても再武装される。
	 *
	 * 移行は「バージョンが変わった 1 回」だけ積まれ、その直後にバージョン option が
	 * 書かれる。AS のジョブが fatal / timeout で消えると誰も積み直さず、半分だけ
	 * 移行された状態で永久に止まる。カーソル（＝未完の印）が残っている限り、
	 * バージョンが同じでも積み直すことでそれを塞ぐ。
	 */
	public function test_カーソルが残っていればバージョンが同じでも移行を積み直す(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_CURSOR, false )
			->andReturn( 480 );
		// 既に走っている移行のカーソルを 0 へ巻き戻さない（add_option なので既存キーは不変）。
		WP_Mock::userFunction( 'add_option' )
			->once()
			->with( PluginUpgrade::OPTION_MIGRATION_CURSOR, 0, '', false )
			->andReturn( false );
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->with( \Mockery::type( 'int' ), PluginUpgrade::HOOK_MIGRATE_OFFERS, array(), PluginUpgrade::MIGRATION_GROUP, true )
			->andReturn( 321 );
		WP_Mock::userFunction( 'get_option' )->with( PluginUpgrade::OPTION_VERSION, '' )->andReturn( '3.6.0' );
		WP_Mock::userFunction( 'update_option' )->never();

		PluginUpgrade::maybeUpgrade( '3.6.0' );

		$this->assertConditionsMet();
	}

	/**
	 * カーソルは「値が 0」と「存在しない」を区別する。走り始めた直後（カーソル 0）の
	 * 移行を「未完でない」と誤判定すると、そこで落ちた移行が再武装されない。
	 */
	public function test_カーソルが0でも未完とみなす(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_CURSOR, false )
			->andReturn( 0 );

		$this->assertTrue( PluginUpgrade::isOffersMigrationPending() );
	}
}
