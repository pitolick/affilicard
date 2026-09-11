<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Upgrade;

use Affilicard\PostType\ProductPostType;
use Affilicard\Pricing\FetchStatus;
use Affilicard\Pricing\OfferSelector;
use Affilicard\Schema\SchemaVersion;
use Affilicard\Upgrade\PluginUpgrade;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class PluginUpgradeTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	public function test_初回は棚卸し基準日を作成しバージョンを記録する(): void {
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

		$expected = array( PluginUpgrade::migrateListingToOffers( $legacy ) );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 501, ProductPostType::META_LISTINGS, $expected )->andReturn( true );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 501, ProductPostType::META_SCHEMA_VERSION, SchemaVersion::CURRENT )->andReturn( true );

		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL, 0 )
			->andReturn( 0 );
		WP_Mock::userFunction( 'delete_option' )->once()->with( PluginUpgrade::OPTION_MIGRATION_CURSOR );
		WP_Mock::userFunction( 'update_option' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		PluginUpgrade::runOffersMigrationBatch();

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
	 * regular_url を持たない listing はサイレントに失われてはならない。件数を option に
	 * 積み上げ、運用が気づけるようにする（Ruling: 移行は新規保存のルールを遡って適用しない代わりに、
	 * 何が起きたかを可視化する）。
	 *
	 * get_option/update_option は同じ option を「読む→書く」ため、Mockery の固定 andReturn では
	 * 2 回目の読み出しが更新後の値を反映できない。andReturnUsing + 参照変数で状態を再現する。
	 */
	public function test_regular_urlが無いlistingは残しつつ件数を数える(): void {
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

		$expected = array( PluginUpgrade::migrateListingToOffers( $legacy ) );
		$this->assertSame( '', $expected[0]['offers'][0]['regular_url'] );
		$this->assertSame( 'https://af.test/abc', $expected[0]['offers'][0]['affiliate_url'] );

		WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 501, ProductPostType::META_LISTINGS, $expected )->andReturn( true );
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

		$this->assertSame( 1, $preserved );
		$this->assertConditionsMet();
	}
}
