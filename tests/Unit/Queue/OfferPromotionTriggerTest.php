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

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
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
			->with( 'affilicard_offer_promote_123' )
			->andReturn( false );
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->once()->andReturn( true );

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
			->with( 'affilicard_offer_promote_123' )
			->andReturn( false );
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->once()->andReturn( true );

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
			->with( 'affilicard_offer_promote_123' )
			->andReturn( false );
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->once()->andReturn( true );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

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
		WP_Mock::userFunction( 'get_transient' )->once()->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->once()->andReturn( true );

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
			->with( 'affilicard_offer_promote_123' )
			->andReturn( false );
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->once()->andReturn( true );
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once();
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()->andReturn( 500 );

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	public function test_同一リクエストで2回呼んでも投入は1件(): void {
		// 1層目: 再入ガード。
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
			->with( 'affilicard_offer_promote_123' )
			->andReturn( false );
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->once()->andReturn( true );
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->once();
		WP_Mock::userFunction( 'as_schedule_single_action' )->once()->andReturn( 500 );

		$trigger = $this->trigger();
		$trigger->onListingsSaved( 123 );
		$trigger->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	public function test_短期クールダウン中は投入しない(): void {
		// 2層目: リクエスト跨ぎの連打を吸収する。get_transient が「クールダウン中」を
		// 返した時点で listings meta の読み出しにすら進まない。
		WP_Mock::userFunction( 'get_transient' )->once()->andReturn( 1 );
		WP_Mock::userFunction( 'get_post_meta' )->never();
		WP_Mock::userFunction( 'set_transient' )->never();
		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}

	public function test_再帰させても深さ1で止まる(): void {
		// 投入処理（as_schedule_single_action）の中から再度 onListingsSaved を呼んでも、
		// 再入ガードで即座に止まり、投入は1件のまま増えない。
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
			->with( 'affilicard_offer_promote_123' )
			->andReturn( false );
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->once()->andReturn( true );
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
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( 'affilicard_offer_promote_123' )
			->andReturn( false );
		WP_Mock::userFunction( 'get_post_status' )->once()->with( 123 )->andReturn( 'draft' );

		WP_Mock::userFunction( 'get_post_meta' )->never();
		WP_Mock::userFunction( 'set_transient' )->never();
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
						array(
							'display_order'   => 100,
							'external_id'     => 'gone',
							'regular_url'     => 'https://example.test/g',
							'last_fetched_at' => gmdate( 'c', time() - 30 * 3600 ),
						),
					)
				)
			);

		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( 'affilicard_offer_promote_123' )
			->andReturn( false );
		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( RefreshHandler::giveUpTransientKey( 123, 'rakuten-kobo' ) )
			->andReturn( 1 );
		WP_Mock::userFunction( 'set_transient' )->once()->andReturn( true );

		WP_Mock::userFunction( 'as_unschedule_all_actions' )->never();
		WP_Mock::userFunction( 'as_schedule_single_action' )->never();

		$this->trigger()->onListingsSaved( 123 );

		$this->assertConditionsMet();
	}
}
