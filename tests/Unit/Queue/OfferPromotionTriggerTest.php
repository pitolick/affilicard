<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductPostType;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Provider\Rakuten\RakutenProvider;
use Affilicard\Queue\Enqueuer;
use Affilicard\Queue\OfferPromotionTrigger;
use Affilicard\Queue\RefreshHandler;
use Affilicard\Settings\GeneralSettings;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * OfferPromotionTrigger のテスト。
 *
 * Enqueuer は final class のため Mockery で直接モックできず、他の Queue テスト
 * （PublishTriggerTest/QueueMaintenanceTest）と同様に実 Enqueuer を使い、Action
 * Scheduler 関数（as_schedule_single_action 等）呼び出しの有無・回数で
 * enqueueManual が実質的に呼ばれたかどうかを観測する（brief のフィルタ計数案は
 * テスト専用のフックを本番コードに持ち込むため採用しない）。
 */
final class OfferPromotionTriggerTest extends TestCase {

	/**
	 * `as_next_scheduled_action()` の既定応答（pending なジョブは無い＝false）。
	 *
	 * **ここで登録するのは意図的である。** WP_Mock::userFunction() は同じ関数名を
	 * 再登録しても最初の期待が残るため、個別テストからは差し替えられない。
	 * 値を変えたいテストはこのプロパティへ代入する。
	 *
	 * Action Scheduler の戻り値と同じ 3 値を取る——false（該当なし）／true（実行中、
	 * または実行時刻を持たない async）／int（次回の実行予定 unix 秒）。
	 *
	 * @var int|bool
	 */
	private $nextScheduledAction = false;

	/**
	 * `as_get_scheduled_actions()` が返す **実行中（in-progress）** アクションの ID 配列。
	 *
	 * `as_next_scheduled_action()` の true は「実行中」と「非同期 pending」を区別しない
	 * ため、繰り上がりトリガーは status=in-progress の問い合わせで切り分ける。
	 * 既定は空配列＝実行中のアクションは無い。
	 *
	 * setUp() で登録する理由は $nextScheduledAction と同じ（WP_Mock::userFunction() は
	 * 最初の期待を保持するため、個別テストからは差し替えられない）。
	 *
	 * @var list<int>
	 */
	private array $runningActionIds = array();

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$this->nextScheduledAction = false;
		$this->runningActionIds    = array();
		WP_Mock::userFunction( 'as_next_scheduled_action' )
			->andReturnUsing(
				fn () => $this->nextScheduledAction
			);
		WP_Mock::userFunction( 'as_get_scheduled_actions' )
			->andReturnUsing(
				fn () => $this->runningActionIds
			);
		OfferPromotionTrigger::resetForTests();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/** affilicard_platforms option を rakuten-kobo（priceTtlHours=24）1件で stub する。 */
	private function stubRakutenPlatform(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'          => 'rakuten-kobo',
						'name'          => '楽天Kobo',
						'provider'      => 'rakuten-kobo',
						'displayOrder'  => 3,
						'enabled'       => true,
						'priceTtlHours' => 24,
					),
				)
			);
	}

	/**
	 * affilicard_general option をスタブする（既定 fallback_on_terminal=false）。
	 *
	 * 使い回す複数テストのために `->with()` で option key を限定した独立の期待値として
	 * 登録する（同一引数の userFunction を後から再登録しても上書きされない仕様のため、
	 * テストごとに毎回このメソッドを呼んで新しい期待値を積む）。
	 */
	private function stubGeneralSettings(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
	}

	/** RakutenProvider（code='rakuten-kobo', accountCode='rakuten'）を登録した ProviderRegistry。 */
	private function registry(): ProviderRegistry {
		$registry = new ProviderRegistry();
		$registry->register( new RakutenProvider() );
		return $registry;
	}

	/**
	 * listings meta の中身（rakuten-kobo・auto 対象）を組み立てる。
	 *
	 * @param list<array<string, mixed>> $offers
	 * @return list<array<string, mixed>>
	 */
	private function listings( array $offers ): array {
		return array(
			array(
				'platform'    => 'rakuten-kobo',
				'enabled'     => true,
				'auto_update' => true,
				'update_mode' => 'auto',
				'offers'      => $offers,
			),
		);
	}

	private function trigger(): OfferPromotionTrigger {
		return new OfferPromotionTrigger( new Enqueuer(), $this->registry() );
	}

	public function test_繰り上がった購入リンクが古ければ投入する(): void {
		$this->stubRakutenPlatform();
		$this->stubGeneralSettings();

		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->with( 123, ProductPostType::META_LISTINGS, true )
			->andReturn(
				$this->listings(
					array(
						array(
							'display_order'   => 100,
							'external_id'     => 'promoted',
							'regular_url'     => 'https://example.test/p',
							'last_fetched_at' => gmdate( 'c', time() - 30 * 3600 ),
						),
					)
				)
			);
		WP_Mock::userFunction( 'get_post_status' )->once()->with( 123 )->andReturn( 'publish' );
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( false );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once()
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 123,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten'
			);
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 123,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 500 );

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	/**
	 * listings meta が JSON 文字列で入っていても投入する。
	 *
	 * meta は配列とは限らない——`JsonField::encode()` を通した保存や外部ツールの
	 * 直書きで JSON 文字列になる。他の読み手（PluginUpgrade::migrateOneProduct() /
	 * ProductRepository::listingSummary() / ProductListColumns）はいずれも
	 * `JsonField::decode()` で文字列形を復号している。ここだけ `is_array()` で弾くと、
	 * JSON で書かれた商品は繰り上がりの即時取得が一度も積まれず、古い価格のまま
	 * 次の掃引まで待たされる。
	 */
	public function test_listings_meta_がJSON文字列でも投入する(): void {
		$this->stubRakutenPlatform();
		$this->stubGeneralSettings();

		$listings_json = (string) json_encode(
			$this->listings(
				array(
					array(
						'display_order'   => 100,
						'external_id'     => 'json-shaped',
						'regular_url'     => 'https://example.test/j',
						'last_fetched_at' => gmdate( 'c', time() - 30 * 3600 ),
					),
				)
			)
		);

		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->with( 123, ProductPostType::META_LISTINGS, true )
			->andReturn( $listings_json );
		WP_Mock::userFunction( 'get_post_status' )->once()->with( 123 )->andReturn( 'publish' );
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( false );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once()
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 123,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten'
			);
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 123,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 500 );

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	/**
	 * 古い購入リンク 1 件を持つ listings meta を返すスタブ一式を積む。
	 *
	 * 2 層目（pending 判定）の分岐だけを変えて挙動を比べたい 3 テストで共有する。
	 */
	private function stubStaleListing(): void {
		$this->stubRakutenPlatform();
		$this->stubGeneralSettings();

		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->with( 123, ProductPostType::META_LISTINGS, true )
			->andReturn(
				$this->listings(
					array(
						array(
							'display_order'   => 100,
							'external_id'     => 'stale',
							'regular_url'     => 'https://example.test/s',
							'last_fetched_at' => gmdate( 'c', time() - 30 * 3600 ),
						),
					)
				)
			);
		WP_Mock::userFunction( 'get_post_status' )->once()->with( 123 )->andReturn( 'publish' );
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
	}

	/**
	 * 同じジョブが既に pending で、かつ**実行時刻が来ている**なら投入しない。
	 *
	 * enqueueManual() は「手動更新を先頭へ繰り上げる」ため毎回 unschedule →
	 * schedule し直す。同一リクエストで複数の listing が書かれるとそのたびに
	 * Action Scheduler の行を作り直すことになり、無駄な churn が出る。まもなく
	 * 走るジョブを積み直しても得るものが無いので、ここは抑止する。
	 *
	 * 時間窓ではなく「pending の有無」で抑えるので、更新を取りこぼさない——
	 * pending なジョブは実行時に最新の listing を読む。
	 */
	public function test_実行時刻の来ているpendingジョブがあれば投入しない(): void {
		$this->stubStaleListing();
		// 予定時刻は過ぎている（AS のワーカーが次に回ったとき即座に走る）。
		$this->nextScheduledAction = time() - 60;

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	/**
	 * 実行中のジョブがあるあいだに起きた変更は、**unique 判定に吸収されない**
	 * follow-up として残る。
	 *
	 * 実行中のアクションは**変更前の listing を読んで**走っているので、その最中に
	 * 購入リンクが変わっても結果には反映されない。かといって base args
	 * （{post_id, platform}）で積み直しても、`as_schedule_single_action( ..., $unique = true )`
	 * は in-progress のアクションを重複とみなして新しいアクションを作らない
	 * （ActionScheduler_DBStore::isActionUnique）——投入を「試みた」だけで、follow-up は
	 * 黙って消える。
	 *
	 * **だからこのテストは「呼ばれたこと」ではなく「アクションが残ったこと」を見る。**
	 * AS の unique 判定（in-progress の base args を重複とみなす）をスタブで再現し、
	 * その上で 1 件のアクションが実際に作られることと、その args が base args と
	 * **別物**であることを確かめる。「投入を試みた」だけを見ていた以前のテストは、
	 * follow-up が吸収されて消えていても緑のままだった。
	 */
	public function test_実行中のジョブがあるときは吸収されないfollow_upが残る(): void {
		$this->stubStaleListing();
		// 実行中（in-progress）のアクションがある状態。
		$this->nextScheduledAction = true;
		$this->runningActionIds    = array( 777 );

		$base_args = array(
			'post_id'  => 123,
			'platform' => 'rakuten-kobo',
		);

		// as_unschedule_all_actions() は **pending しか**取り消さない。実行中の
		// base args アクションは残り続ける（Enqueuer の docblock と同じ前提）。
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->andReturn( 0 );

		$created = array();
		WP_Mock::userFunction( 'as_schedule_single_action' )->andReturnUsing(
			static function ( $when, $hook, $args, $group, $unique = false, $priority = 10 ) use ( &$created, $base_args ): int {
				if ( $unique && $args === $base_args ) {
					// 実行中の base args アクションと重複＝アクションは作られず 0 が返る。
					return 0;
				}
				$created[] = array(
					'hook'     => $hook,
					'args'     => $args,
					'group'    => $group,
					'priority' => $priority,
				);
				return count( $created );
			}
		);

		$this->trigger()->onListingsSaved( 123 );

		$this->assertCount( 1, $created, '実行中のジョブがあっても follow-up のアクションが 1 件残る' );
		$this->assertSame( Enqueuer::HOOK_REFRESH, $created[0]['hook'] );
		$this->assertNotSame( $base_args, $created[0]['args'], 'unique 判定に吸収されない別 args で積む' );
		$this->assertSame( 123, $created[0]['args']['post_id'] );
		$this->assertSame( 'rakuten-kobo', $created[0]['args']['platform'] );
		$this->assertSame( 'affilicard-rakuten', $created[0]['group'] );
		$this->assertSame( Enqueuer::PRIORITY_MANUAL, $created[0]['priority'] );
	}

	/**
	 * 非同期 pending（実行中のアクションは無い）なら抑止する。
	 *
	 * `as_next_scheduled_action()` は実行中と非同期 pending をどちらも true で返すが、
	 * 意味はまるで違う。非同期 pending は「予定時刻を持たない＝次にワーカーが回った
	 * ときに走る」ジョブで、走るときには最新の listing を読む——実行時刻の来ている
	 * pending と同じく、抑止しても取りこぼしは無い。
	 */
	public function test_非同期pendingで実行中のジョブが無ければ抑止する(): void {
		$this->stubStaleListing();
		$this->nextScheduledAction = true;
		// 実行中のアクションは無い＝true の正体は非同期 pending。
		$this->runningActionIds = array();

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	/**
	 * pending なジョブの実行予定が**将来**なら、抑止せず投入して今へ繰り上げる。
	 *
	 * 一時失敗のあと RefreshHandler は backoff を付けて積み直す（最大で 1 時間先）。
	 * そのあいだに繰り上がりが起きて今使う購入リンクが古くなっても、pending の
	 * 有無だけで抑止すると、即時取得がその遠い予定時刻まで待たされる。
	 * enqueueManual() は unschedule → time() で schedule し直す＝「今へ動かす」
	 * ことそのものなので、ここで積むのが正しい。
	 */
	public function test_pendingジョブの実行予定が先ならすぐ投入して繰り上げる(): void {
		$this->stubStaleListing();
		// backoff で 1 時間先に積み直されている状態。
		$this->nextScheduledAction = time() + 3600;

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once()
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 123,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten'
			);
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 123,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 500 );

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	/**
	 * 一括書き込み（アップグレード移行など）の窓の中では、何も読まず何も投入しない。
	 *
	 * 移行は全商品の listings を書き直すが、移行した offer は元の（多くは古い）
	 * last_fetched_at をそのまま引き継ぐため needsRefetch() がほぼ全件で true になる。
	 * 抑止しないと、アップグレードした瞬間にカタログ全件ぶんの即時取得が積まれて
	 * API のレート制限を焼き切る。移行は形状を変えるだけで購入リンクの内容は
	 * 変えていないので、繰り上がりの契機ではない。
	 */
	public function test_一括書き込みの抑止中は投入しない(): void {
		// get_post_meta も get_post_status も呼ばれない（0層目で即 return する）。
		WP_Mock::userFunction( 'get_post_meta' )->never();
		WP_Mock::userFunction( 'get_post_status' )->never();
		WP_Mock::userFunction( 'get_transient' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$trigger = $this->trigger();
		$inside  = OfferPromotionTrigger::withSuppression(
			static function () use ( $trigger ): bool {
				$trigger->onListingsSaved( 123 );
				return OfferPromotionTrigger::isSuppressed();
			}
		);

		$this->assertTrue( $inside );
		// 窓は callback の実行中だけ。抜けたら必ず閉じている。
		$this->assertFalse( OfferPromotionTrigger::isSuppressed() );
		$this->assertConditionsMet();
	}

	/** 窓の中で例外が飛んでも抑止は解除される（finally）。 */
	public function test_抑止中に例外が飛んでも窓は閉じる(): void {
		try {
			OfferPromotionTrigger::withSuppression(
				static function (): void {
					throw new \RuntimeException( 'boom' );
				}
			);
			$this->fail( '例外が伝播していない' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertFalse( OfferPromotionTrigger::isSuppressed() );
	}

	public function test_更新直後は投入しない(): void {
		$this->stubRakutenPlatform();
		$this->stubGeneralSettings();

		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->with( 123, ProductPostType::META_LISTINGS, true )
			->andReturn(
				$this->listings(
					array(
						array(
							'display_order'   => 100,
							'external_id'     => 'fresh',
							'regular_url'     => 'https://example.test/f',
							'last_fetched_at' => gmdate( 'c' ),
						),
					)
				)
			);
		WP_Mock::userFunction( 'get_post_status' )->once()->with( 123 )->andReturn( 'publish' );
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( false );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	public function test_取得に失敗し続けている間は投入しない(): void {
		// needsRefetch のクールダウン（last_fetched_at は成功・失敗を問わず記録される）。
		$this->stubRakutenPlatform();
		$this->stubGeneralSettings();

		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->with( 123, ProductPostType::META_LISTINGS, true )
			->andReturn(
				$this->listings(
					array(
						array(
							'display_order'   => 100,
							'external_id'     => 'failing',
							'regular_url'     => 'https://example.test/x',
							'fetch_status'    => 'transient',
							'last_fetched_at' => gmdate( 'c' ),
						),
					)
				)
			);
		WP_Mock::userFunction( 'get_post_status' )->once()->with( 123 )->andReturn( 'publish' );
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( false );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	/**
	 * 移行前の flat な listing でも繰り上がりを拾う。
	 *
	 * offers を直読みすると、掃引（QueueMaintenance）と価格更新（ListingRefresher）は
	 * フォールバックで扱えるのに、ここだけ拾わないという不整合になる。
	 */
	public function test_移行前のflat_listingでも投入する(): void {
		$this->stubRakutenPlatform();
		$this->stubGeneralSettings();

		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->with( 123, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'        => 'rakuten-kobo',
						'enabled'         => true,
						'update_mode'     => 'auto',
						'auto_update'     => true,
						// offers キーが無い＝移行前の形。
						'external_id'     => 'flat-1',
						'regular_url'     => 'https://example.test/flat',
						'last_fetched_at' => gmdate( 'c', time() - 30 * 3600 ),
					),
				)
			);
		WP_Mock::userFunction( 'get_post_status' )->once()->with( 123 )->andReturn( 'publish' );
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once();
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()->andReturn( 500 );

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	public function test_自動更新の対象外なら投入しない(): void {
		// enabled=false（ListingEligibility::isAutoEligible が最初に弾く）。この経路は
		// GeneralSettings/PlatformConfig を一切読まないため、それらの option はスタブしない。
		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->with( 123, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'rakuten-kobo',
						'enabled'  => false,
						'offers'   => array(),
					),
				)
			);
		WP_Mock::userFunction( 'get_post_status' )->once()->with( 123 )->andReturn( 'publish' );
		WP_Mock::userFunction( 'get_transient' )->never();

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	public function test_フックはpost_metaを書かない(): void {
		// ループしない根拠がこの一点に依存する。壊れたらここが落ちる。
		WP_Mock::userFunction( 'update_post_meta' )->never();
		WP_Mock::userFunction( 'add_post_meta' )->never();
		WP_Mock::userFunction( 'delete_post_meta' )->never();

		$this->stubRakutenPlatform();
		$this->stubGeneralSettings();

		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->with( 123, ProductPostType::META_LISTINGS, true )
			->andReturn(
				$this->listings(
					array(
						array(
							'display_order'   => 100,
							'external_id'     => 'x',
							'regular_url'     => 'https://example.test/x',
							'last_fetched_at' => gmdate( 'c', time() - 30 * 3600 ),
						),
					)
				)
			);
		WP_Mock::userFunction( 'get_post_status' )->once()->with( 123 )->andReturn( 'publish' );
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( false );
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once();
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()->andReturn( 500 );

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	/**
	 * CodeRabbit Major #2 の回帰テスト。
	 *
	 * **このテストの意図は旧版から変わっている。** 旧実装は $inFlight をリクエスト終了まで
	 * 解放しなかったため、同一リクエスト内で 2 回呼んでも 2 回目は丸ごと無視され
	 * 「投入は1件」になっていた——だがこれは、platform A の保存で本フックが発火した
	 * 直後に platform B を保存しても B が一度も評価されないのと同じ欠陥である
	 * （$inFlight が実行中だけの再入ガードではなく、事実上のセッションスコープの
	 * 抑止になっていた）。
	 *
	 * 修正後は $inFlight を `finally` で必ず解放するため、同一リクエスト内でも
	 * 実行が完全に終わった後の独立した 2 回目の呼び出しはきちんと評価される。
	 * この例では 2 回とも同じ stale な状態を渡しているため、2 回とも投入が起きる
	 * （enqueueManual は要求のたびに unschedule→再schedule するため、最終的に
	 * pending なジョブは常に1件に収束する。「呼ばれた回数」自体は増えてよい）。
	 */
	public function test_同一リクエスト内の2回目の呼び出しも独立して評価される(): void {
		$this->stubRakutenPlatform();
		$this->stubGeneralSettings();

		WP_Mock::userFunction( 'get_post_meta' )
			->twice()
			->with( 123, ProductPostType::META_LISTINGS, true )
			->andReturn(
				$this->listings(
					array(
						array(
							'display_order'   => 100,
							'external_id'     => 'stale',
							'regular_url'     => 'https://example.test/s',
							'last_fetched_at' => gmdate( 'c', time() - 30 * 3600 ),
						),
					)
				)
			);
		WP_Mock::userFunction( 'get_post_status' )->twice()->with( 123 )->andReturn( 'publish' );
		WP_Mock::userFunction( 'get_transient' )
			->twice()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( false );
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->twice();
		WP_Mock::userFunction( 'as_schedule_single_action' )->twice()->andReturn( 500 );

		$trigger = $this->trigger();
		$trigger->onListingsSaved( 123 );
		$trigger->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	public function test_再帰させても深さ1で止まる(): void {
		// 投入処理（as_schedule_single_action）の中から再度 onListingsSaved を呼んでも、
		// 再入ガードで即座に止まり、投入は1件のまま増えない。再帰呼び出しは外側の
		// onListingsSaved() の try ブロック内（$inFlight がまだ解放される前）で起きるため、
		// finally での解放化後もこのテストの前提（1層目が同期的な再帰を止める）は変わらない。
		$this->stubRakutenPlatform();
		$this->stubGeneralSettings();

		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->with( 123, ProductPostType::META_LISTINGS, true )
			->andReturn(
				$this->listings(
					array(
						array(
							'display_order'   => 100,
							'external_id'     => 'stale',
							'regular_url'     => 'https://example.test/s',
							'last_fetched_at' => gmdate( 'c', time() - 30 * 3600 ),
						),
					)
				)
			);
		WP_Mock::userFunction( 'get_post_status' )->once()->with( 123 )->andReturn( 'publish' );
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( false );
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once();

		$trigger = $this->trigger();
		WP_Mock::userFunction( 'as_schedule_single_action' )
			->once()
			->andReturnUsing(
				static function () use ( $trigger ) {
					$trigger->onListingsSaved( 123 ); // 再帰させる
					return 500;
				}
			);

		$trigger->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	public function test_非公開の商品は投入しない(): void {
		// QueueMaintenance::sweep() は post_status => 'publish' のクエリでしか公開商品を
		// 見ないが、本フックにはそのクエリが無い。listings meta への書き込みは
		// draft/pending/trash でも起こり得るため、sweep 同様に明示的な post_status
		// ガードが要る。
		WP_Mock::userFunction( 'get_post_status' )->once()->with( 123 )->andReturn( 'draft' );

		WP_Mock::userFunction( 'get_transient' )->never();
		WP_Mock::userFunction( 'get_post_meta' )->never();
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	public function test_ギブアップ中のプラットフォームは投入しない(): void {
		// RefreshHandler が恒久失敗を検知して立てた give-up transient が残っている間は、
		// QueueMaintenance::sweep() と同じく再取得を積まない。積んでしまうと、外部
		// ツールが listings meta を書き換えるたびに廃盤/無効 ID へのリトライ連鎖を
		// give-up の TTL 内で何度も焼くことになる。
		$this->stubRakutenPlatform();
		$this->stubGeneralSettings();

		WP_Mock::userFunction( 'get_post_status' )->once()->with( 123 )->andReturn( 'publish' );
		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->with( 123, ProductPostType::META_LISTINGS, true )
			->andReturn(
				$this->listings(
					array(
						// 恒久失敗を検知した ListingRefresher が fetch_status=terminal を
						// 書き込んだのと同じタイミングで give-up マーカーが立つ（両者は
						// 常に対で残る）。マーカーだけを持つ fixture は実データに無い。
						array(
							'display_order'   => 100,
							'external_id'     => 'gone',
							'regular_url'     => 'https://example.test/g',
							'fetch_status'    => 'terminal',
							'last_fetched_at' => gmdate( 'c', time() - 30 * 3600 ),
						),
					)
				)
			);

		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( 1 );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	/**
	 * C: give-up マーカーは (post_id, platform) 単位でしか立たないため、恒久失敗した
	 * 購入リンク A のマーカーが、繰り上げた別の購入リンク B にまで効いてしまっていた。
	 * B は一度も失敗していないのに TTL（3日）のあいだ投入されない。
	 *
	 * マーカーの効く範囲は「今使う購入リンク自身が terminal のとき」に限る
	 * （{@see RefreshHandler::isGivenUp()}）。terminal かどうかは offer に保存済みで、
	 * マーカーは常にその書き込みと対で立つため、キーの形を変えずに済む。
	 */
	public function test_ギブアップ中でも繰り上げた別の購入リンクは投入する(): void {
		$this->stubRakutenPlatform();
		$this->stubGeneralSettings();

		WP_Mock::userFunction( 'get_post_status' )->once()->with( 123 )->andReturn( 'publish' );
		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->with( 123, ProductPostType::META_LISTINGS, true )
			->andReturn(
				$this->listings(
					array(
						// 恒久失敗した購入リンク（give-up マーカーの原因）。後ろへ回した。
						array(
							'display_order'   => 100,
							'external_id'     => 'gone',
							'regular_url'     => 'https://example.test/g',
							'fetch_status'    => 'terminal',
							'last_fetched_at' => gmdate( 'c', time() - 30 * 3600 ),
						),
						// 先頭へ繰り上げた購入リンク。一度も失敗していない。
						array(
							'display_order'   => 10,
							'external_id'     => 'promoted',
							'regular_url'     => 'https://example.test/p',
							'last_fetched_at' => gmdate( 'c', time() - 30 * 3600 ),
						),
					)
				)
			);

		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( 1 );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once();
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()
			->with(
				Mockery::type( 'int' ),
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 123,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-rakuten',
				true,
				Enqueuer::PRIORITY_MANUAL
			)
			->andReturn( 501 );

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}
}
