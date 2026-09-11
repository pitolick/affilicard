<?php
declare(strict_types=1);

namespace Affilicard\Upgrade;

use Affilicard\PostType\ProductPostType;
use Affilicard\Pricing\FetchStatus;
use Affilicard\Pricing\OfferSelector;
use Affilicard\Repository\ProductRepository;
use Affilicard\Schema\SchemaVersion;
use Affilicard\Util\JsonField;

/**
 * プラグインのバージョン移行ルーチン。
 *
 * `register_activation_hook` は WordPress の自動更新・管理画面からの更新では
 * 実行されない。有効化したまま更新したサイトでも初期化処理が確実に走るよう、
 * `plugins_loaded` で保存済みバージョンと現在バージョンを比較して差分処理を行う。
 */
final class PluginUpgrade {

	public const OPTION_VERSION = 'affilicard_plugin_version';

	/** 棚卸し基準日。最終掲載日を持たない既存商品の判定基準になる（spec §5-3）。 */
	public const OPTION_STOCKTAKE_BASELINE = 'affilicard_stocktake_baseline';

	/**
	 * offers 移行バッチ（Task 14）のフック名。listing 直下の flat な取得結果フィールドを
	 * offers[0] へ移す一度きりの移行を、v3.5.0 のバッチ基盤（QueueMaintenance::sweep()）と
	 * 同じ「カーソルで分割走査し、続きは自分自身を積み直す」設計で行う。
	 * src/Queue/QueueMaintenance.php 参照。
	 */
	public const HOOK_MIGRATE_OFFERS = 'affilicard_migrate_offers_batch';

	/**
	 * offers 移行の走査カーソル（最後に処理した post_id）を保持する option。
	 * Queue\SweepCursor と同じ考え方——継続ジョブの投入に失敗しても、次回のバッチが
	 * 途中から再開できるようにするための保険。
	 */
	public const OPTION_MIGRATION_CURSOR = 'affilicard_offers_migration_cursor';

	/**
	 * regular_url を持たないまま offers[0] へ持ち越した listing の延べ件数。
	 *
	 * 新規保存（ProductSchema::sanitizeOffers）は regular_url が空の offer を弾く
	 * （生死を判定できないため）が、それは新規入力向けのルールであり、移行に遡って
	 * 適用すると手入力で affiliate_url のみ設定されていた既存 listing のデータが
	 * 復元不能な形で消える。移行はこのルールを適用せずデータを持ち越す代わりに、
	 * 件数をここへ積み上げて運用が気づけるようにする（サイレントな消失の禁止）。
	 */
	public const OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL = 'affilicard_offers_migration_preserved_without_regular_url';

	/** offers 移行バッチが使う Action Scheduler の group。 */
	private const MIGRATION_GROUP = 'affilicard-migration';

	/**
	 * 1 回のバッチで走査する商品数。QueueMaintenance::sweep() の既定値（200）と揃える。
	 * 大規模インストールでも 1 回の実行時間が伸びないよう、必ずこの単位に区切って処理する。
	 */
	public const MIGRATION_BATCH_SIZE = 200;

	/** offers 移行時、listing 直下から取り除いて offers[0] へ移す v3 以前の flat フィールド。 */
	private const LEGACY_FETCH_FIELDS = array(
		'external_id',
		'regular_url',
		'affiliate_url',
		'price',
		'list_price',
		'badge',
		'image_url',
		'search_key',
		'fetch_error',
		'last_fetched_at',
		'last_verified_at',
	);

	public static function maybeUpgrade( string $currentVersion ): void {
		// offers 移行バッチ（HOOK_MIGRATE_OFFERS）は Action Scheduler のランナーが処理する
		// 別リクエストでも plugins_loaded を発火し直すため、ハンドラは下の早期 return より
		// 前で毎回登録する。ここを version 差分の分岐の中に置くと、バージョン更新後
		// 2 回目以降の plugins_loaded は早期 return してハンドラが登録されず、積んだ
		// ジョブが永久に実行されなくなる。
		add_action( self::HOOK_MIGRATE_OFFERS, array( self::class, 'runOffersMigrationBatch' ) );

		$stored = (string) get_option( self::OPTION_VERSION, '' );
		if ( $stored === $currentVersion ) {
			return;
		}

		// 基準日を作れなかった（＝存在も確認できなかった）場合はバージョンを進めない。
		// ここでバージョンだけ進めると、次回以降このメソッドが冒頭の早期 return で
		// 素通りしてしまい、基準日が永久に作られない（棚卸しが永久に発動しない）。
		// バージョンを更新しなければ、次回の plugins_loaded で再試行される。
		if ( ! self::ensureStocktakeBaseline() ) {
			return;
		}

		self::scheduleOffersMigration();

		update_option( self::OPTION_VERSION, $currentVersion, false );
	}

	/**
	 * 棚卸し基準日（{@see OPTION_STOCKTAKE_BASELINE}）の存在を保証する。
	 *
	 * 「無ければ作る。既にあるサイトでは絶対に書き換えない」を実現するため `add_option()`
	 * を使うが、`add_option()` は「既に値が存在する（＝正常）」場合と「保存に失敗した
	 * （＝異常）」場合のどちらでも false を返し、戻り値だけでは区別できない。
	 * false が返ったときは `get_option()` で実在を確認し、両者を切り分ける。
	 *
	 * @return bool 基準日が存在する（新規作成 or 既存）ことを確認できたら true。
	 *              保存に失敗し、かつ既存の値も確認できなければ false。
	 */
	private static function ensureStocktakeBaseline(): bool {
		if ( add_option( self::OPTION_STOCKTAKE_BASELINE, gmdate( 'c' ), '', false ) ) {
			return true;
		}

		// add_option が false を返した。「既に存在する」のか「保存に失敗した」のかを
		// get_option で確認する。空文字（未設定の既定値）以外が返れば実在すると判断する。
		return '' !== (string) get_option( self::OPTION_STOCKTAKE_BASELINE, '' );
	}

	/**
	 * v3 以前の flat な listing を offers[] 形式へ変換する（1 listing → 1 listing の純粋な変換）。
	 *
	 * WP 関数を一切呼ばないため単体テストしやすい。バッチ本体（商品を走査して保存する側）は
	 * これを呼ぶだけにする。
	 *
	 * offers が既に存在する listing はそのまま返す（冪等性）。中断後の再実行や、
	 * 二重にスケジュールされたバッチが同じ listing を重複変換することがない。
	 *
	 * regular_url を持たない listing でも offer は落とさず持ち越す。新規保存
	 * （ProductSchema::sanitizeOffers）は regular_url が空の offer を弾くが、それは
	 * 「今後は生死判定できない offer を作らせない」という新規入力向けのルールであり、
	 * 移行に遡って適用すると手入力で affiliate_url のみ設定されていた listing のデータが
	 * 復元不能な形で消える。件数の集計は呼び出し側（バッチ）が offers[0]['regular_url']
	 * を見て行う。
	 *
	 * @param array<string, mixed> $listing
	 * @return array<string, mixed>
	 */
	public static function migrateListingToOffers( array $listing ): array {
		if ( isset( $listing['offers'] ) && is_array( $listing['offers'] ) ) {
			return $listing;
		}

		$offer = array(
			'display_order'    => OfferSelector::DEFAULT_ORDER,
			'external_id'      => isset( $listing['external_id'] ) ? (string) $listing['external_id'] : '',
			'regular_url'      => isset( $listing['regular_url'] ) ? (string) $listing['regular_url'] : '',
			'affiliate_url'    => isset( $listing['affiliate_url'] ) ? (string) $listing['affiliate_url'] : '',
			'price'            => isset( $listing['price'] ) ? (string) $listing['price'] : '',
			'list_price'       => isset( $listing['list_price'] ) ? (string) $listing['list_price'] : '',
			'badge'            => isset( $listing['badge'] ) ? (string) $listing['badge'] : '',
			'image_url'        => isset( $listing['image_url'] ) ? (string) $listing['image_url'] : '',
			'search_key'       => isset( $listing['search_key'] ) ? (string) $listing['search_key'] : '',
			'fetch_status'     => FetchStatus::fromLegacyMessage( isset( $listing['fetch_error'] ) ? (string) $listing['fetch_error'] : '' ),
			'last_fetched_at'  => isset( $listing['last_fetched_at'] ) ? (string) $listing['last_fetched_at'] : '',
			'last_verified_at' => isset( $listing['last_verified_at'] ) ? (string) $listing['last_verified_at'] : '',
		);

		foreach ( self::LEGACY_FETCH_FIELDS as $field ) {
			unset( $listing[ $field ] );
		}

		$listing['offers'] = array( $offer );

		return $listing;
	}

	/**
	 * offers 移行バッチの開始トリガーを 1 件だけ積む。
	 *
	 * 全商品を 1 リクエストで処理すると大規模インストールで実行時間が切れるため、
	 * v3.5.0 で導入されたバッチ基盤（QueueMaintenance::sweep()。カーソルで分割走査し
	 * 続きは自分自身を積み直す設計）に乗せる。
	 *
	 * Action Scheduler は Plugin::bootInstance() が plugins_loaded より前に bundle 版を
	 * 同期ロードするため本番では必ず存在するが、単体テスト環境には存在しないため
	 * function_exists で防御する（存在しなければ何もしない。実運用では起こらない）。
	 */
	private static function scheduleOffersMigration(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		as_schedule_single_action( time(), self::HOOK_MIGRATE_OFFERS, array(), self::MIGRATION_GROUP, true );
	}

	/**
	 * offers 移行バッチ本体。HOOK_MIGRATE_OFFERS の Action Scheduler アクションから呼ばれる。
	 *
	 * カーソル以降の商品を MIGRATION_BATCH_SIZE 件だけ走査し、各商品の listings を
	 * migrateOneProduct() で変換・保存する。走査件数が MIGRATION_BATCH_SIZE 未満なら
	 * 完走とみなしカーソルを消す。そうでなければカーソルを保存し、続きを自分自身
	 * （unique=false。実行中の自分自身が in-progress として重複判定されるため true だと
	 * 必ず抑止される——QueueMaintenance::sweep() の継続トリガーと同じ理由）で積み直す。
	 */
	public static function runOffersMigrationBatch(): void {
		$after = (int) get_option( self::OPTION_MIGRATION_CURSOR, 0 );
		$ids   = self::fetchProductIdsForMigration( $after, self::MIGRATION_BATCH_SIZE );

		foreach ( $ids as $id ) {
			self::migrateOneProduct( $id );
		}

		if ( count( $ids ) < self::MIGRATION_BATCH_SIZE ) {
			self::finishOffersMigration();
			return;
		}

		update_option( self::OPTION_MIGRATION_CURSOR, (int) end( $ids ), false );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::HOOK_MIGRATE_OFFERS, array(), self::MIGRATION_GROUP, false );
		}
	}

	/**
	 * カーソル（$after）より後ろの post_id を ID 昇順で $limit 件返す。
	 *
	 * QueueMaintenance::sweep() と同じ posts_where カーソル方式（ID 順の分割走査）を踏襲する。
	 * 移行は post_status を問わずすべての商品が対象（棚卸し等の表示状態に関わらず
	 * 古い meta 形状を持ち得るため、公開中に限定する掃引と異なり 'any' を見る）。
	 *
	 * @return list<int>
	 */
	private static function fetchProductIdsForMigration( int $after, int $limit ): array {
		$queryVar = 'affilicard_offers_migration_after';

		$whereCursor = static function ( string $where, $query ) use ( $after, $queryVar ): string {
			if ( $after !== $query->get( $queryVar, null ) ) {
				return $where;
			}
			global $wpdb;
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
		};

		add_filter( 'posts_where', $whereCursor, 10, 2 );
		try {
			$ids = get_posts(
				array(
					'post_type'        => ProductPostType::POST_TYPE,
					'post_status'      => 'any',
					'fields'           => 'ids',
					'posts_per_page'   => $limit,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'no_found_rows'    => true,
					'suppress_filters' => false,
					$queryVar          => $after,
				)
			);
		} finally {
			remove_filter( 'posts_where', $whereCursor, 10 );
		}

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * 1 商品の listings を offers 形式へ移行して保存し、派生 meta を再構築する。
	 *
	 * listing 単位の変換ロジックは migrateListingToOffers() に委譲する（WP 非依存で
	 * 単体テスト済み）。ここは「読む・保存する・派生 meta を再構築する」という
	 * WordPress 依存の薄いグルーだけを担う。
	 *
	 * 保存後は必ず ProductRepository::syncDerivedMeta() を呼ぶ——listings に変更が
	 * 無い商品（既に offers[] 形式・listing 自体が無い）でも META_SCHEMA_VERSION は
	 * SchemaVersion::CURRENT へ更新する（スキーマのバージョンは商品単位の meta であり、
	 * この商品の listing 内容に変更があったかどうかとは独立している）。
	 */
	private static function migrateOneProduct( int $postId ): void {
		$raw      = get_post_meta( $postId, ProductPostType::META_LISTINGS, true );
		$listings = is_string( $raw ) ? JsonField::decode( $raw, array() ) : ( is_array( $raw ) ? $raw : array() );

		$migrated  = array();
		$changed   = false;
		$preserved = 0;

		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				$migrated[] = $listing;
				continue;
			}

			$hadOffers = isset( $listing['offers'] ) && is_array( $listing['offers'] );
			$after     = self::migrateListingToOffers( $listing );

			if ( ! $hadOffers ) {
				$changed      = true;
				$offer        = is_array( $after['offers'][0] ?? null ) ? $after['offers'][0] : array();
				$regular      = isset( $offer['regular_url'] ) ? (string) $offer['regular_url'] : '';
				$hasFetchData = ( isset( $offer['affiliate_url'] ) && '' !== (string) $offer['affiliate_url'] )
					|| ( isset( $offer['external_id'] ) && '' !== (string) $offer['external_id'] );
				if ( '' === $regular && $hasFetchData ) {
					++$preserved;
				}
			}

			$migrated[] = $after;
		}

		if ( $changed ) {
			update_post_meta( $postId, ProductPostType::META_LISTINGS, $migrated );
		}

		if ( $preserved > 0 ) {
			$total = (int) get_option( self::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL, 0 );
			update_option( self::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL, $total + $preserved, false );
		}

		( new ProductRepository() )->syncDerivedMeta( $postId );
	}

	/**
	 * 移行完走時の後始末。カーソルを消し、regular_url を持たないまま延命した listing が
	 * あれば運用が気づけるようログに残す（サイレントな消失にしないため。
	 * QueueMaintenance/BatchRefreshHandler が投入失敗を error_log に残すのと同じ理由）。
	 */
	private static function finishOffersMigration(): void {
		delete_option( self::OPTION_MIGRATION_CURSOR );

		$preserved = (int) get_option( self::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL, 0 );
		if ( $preserved > 0 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 新規保存なら弾かれる regular_url 欠落の listing を移行では温存しており、AS 側では完全に不可視になるため運用が気づけるようログに残す。
			error_log(
				sprintf(
					'affilicard: offers 移行が完了しました。regular_url を持たないまま購入リンクを維持した listing が %d 件あります。棚卸しの対象にならないため手動確認を推奨します。',
					$preserved
				)
			);
		}
	}
}
