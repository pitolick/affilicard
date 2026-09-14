<?php
declare(strict_types=1);

namespace Affilicard\Upgrade;

use Affilicard\PostType\ProductPostType;
use Affilicard\Pricing\LegacyOffer;
use Affilicard\Queue\OfferPromotionTrigger;
use Affilicard\Repository\ProductRepository;
use Affilicard\Rest\ProductSchema;
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
	 *
	 * **同時に「移行が未完である」ことの印でもある。** 移行を積むと同時に 0 で作成し、
	 * 完走時に削除する。この option が存在する限り maybeUpgrade() が毎リクエスト
	 * 積み直すため、AS のジョブが fatal / timeout で消えても移行が再開する
	 * （{@see self::maybeUpgrade()}）。作成を「継続時のみ」にすると、初回バッチが
	 * 落ちたインストールに印が残らず、永久に半分だけ移行された状態で止まる。
	 */
	public const OPTION_MIGRATION_CURSOR = 'affilicard_offers_migration_cursor';

	/**
	 * 身元（regular_url / external_id）を 1 つも持たないまま offers[0] へ持ち越した
	 * listing の延べ件数。
	 *
	 * 新規保存（ProductSchema::sanitizeOffers）は身元を 1 つも持たない offer を弾く
	 * （生死の判定も再同定もできないため）が、それは新規入力向けのルールであり、移行に遡って
	 * 適用すると手入力で affiliate_url のみ設定されていた既存 listing のデータが
	 * 復元不能な形で消える。移行はこのルールを適用せずデータを持ち越す代わりに、
	 * 件数をここへ積み上げて運用が気づけるようにする（サイレントな消失の禁止）。
	 */
	public const OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL = 'affilicard_offers_migration_preserved_without_regular_url';

	/**
	 * 上記の温存が起きた商品の post ID（重複なし・先頭 {@see self::PRESERVED_POST_IDS_CAP} 件）。
	 *
	 * 件数だけの通知は運用上何もできない——「12 件消えます」と言われても、どの商品を
	 * 直せばよいか分からない。数えるついでに ID を控えて、通知から編集画面へ直接
	 * 辿れるようにする。全件は持たない（option の肥大を避ける。上限を超えた分は
	 * 件数にだけ現れる）。
	 */
	public const OPTION_MIGRATION_PRESERVED_POST_IDS = 'affilicard_offers_migration_preserved_post_ids';

	/** 通知に出す post ID の保持上限。 */
	public const PRESERVED_POST_IDS_CAP = 50;

	/**
	 * offers 移行バッチが使う Action Scheduler の group。
	 * Uninstall::cleanupQueue() が同じ値をリテラルで unschedule する。
	 */
	public const MIGRATION_GROUP = 'affilicard-migration';

	/**
	 * 1 回のバッチで走査する商品数。QueueMaintenance::sweep() の既定値（200）と揃える。
	 * 大規模インストールでも 1 回の実行時間が伸びないよう、必ずこの単位に区切って処理する。
	 */
	/**
	 * listing が offers[] を持つようになったバージョン。
	 *
	 * 移行バッチを積むのはここより前から上がってきたときだけである。バージョンが
	 * 変わるたびに積むと、完走でカーソルを消したあと次の通常更新でまた 0 から
	 * 全商品を走査し、商品ごとに syncDerivedMeta() まで動かし直すことになる。
	 */
	public const OFFERS_INTRODUCED_IN = '4.0.0';

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

	/**
	 * 保存済みバージョンから見て offers 移行が要るか。
	 *
	 * 空文字（バージョン option が無い）は「いつからか分からない」なので積む。
	 * 新規インストールでも商品が 0 件なら 1 巡で完走して終わるため実害はなく、
	 * option を失った既存サイトを取りこぼす方が痛い。
	 */
	private static function needsOffersMigrationFrom( string $storedVersion ): bool {
		if ( '' === $storedVersion ) {
			return true;
		}
		return version_compare( $storedVersion, self::OFFERS_INTRODUCED_IN, '<' );
	}

	public static function maybeUpgrade( string $currentVersion ): void {
		// offers 移行バッチ（HOOK_MIGRATE_OFFERS）は Action Scheduler のランナーが処理する
		// 別リクエストでも plugins_loaded を発火し直すため、ハンドラは下の早期 return より
		// 前で毎回登録する。ここを version 差分の分岐の中に置くと、バージョン更新後
		// 2 回目以降の plugins_loaded は早期 return してハンドラが登録されず、積んだ
		// ジョブが永久に実行されなくなる。
		add_action( self::HOOK_MIGRATE_OFFERS, array( self::class, 'runOffersMigrationBatch' ) );

		// 中断した移行の再武装。移行は「バージョンが変わった 1 回」だけ積まれ、その直後に
		// バージョン option が書かれる。したがって AS のジョブが fatal / timeout で
		// 消えると、誰も積み直さないまま半分だけ移行された状態で固定される。カーソルは
		// 移行を積むと同時に作られ完走時に消えるので、「存在する＝未完」であり、
		// これを毎リクエスト見て積み直す。scheduleOffersMigration() は unique=true の
		// ため、既に pending なジョブがあれば二重には積まれない。
		// バージョン差分の早期 return より前に置く（バージョンは移行を積んだ直後に
		// 更新済みなので、後ろに置くと二度と到達しない）。
		if ( self::isOffersMigrationPending() ) {
			self::scheduleOffersMigration();
		}

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

		// offers 導入前から上がってきたときだけ移行を積む。中断した移行の積み直しは
		// 上の isOffersMigrationPending() が担うので、ここを絞っても取りこぼさない。
		if ( self::needsOffersMigrationFrom( $stored ) ) {
			self::scheduleOffersMigration();
		}

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
	 * 身元（regular_url / external_id）を持たない listing でも offer は落とさず持ち越す。
	 * 新規保存（ProductSchema::sanitizeOffers）はこの形の offer を弾くが、それは
	 * 「今後は生死判定も再同定もできない offer を作らせない」という新規入力向けのルールであり、
	 * 移行に遡って適用すると手入力で affiliate_url のみ設定されていた listing のデータが
	 * 復元不能な形で消える。件数の集計は呼び出し側（バッチ）が offers[0]['regular_url']
	 * を見て行う。
	 *
	 * **ただし、取得結果フィールドを 1 つも持たない listing には offer を作らない。**
	 * 設定（platform / enabled 等）だけを持つ listing は購入リンクを 1 件も持っていない。
	 * ここで空の offer を作ると、移行の書き込みは身元チェックを外している
	 * （{@see ProductSchema::withLegacyOfferPreservation()}）ため実際に保存され、
	 * 移行前は 0 件だった購入リンクが移行後は 1 件に増える——存在しない購入リンクを
	 * OfferSelector が選び、レート制限の枠取り（targetCount）も価格更新もそれを
	 * 対象にしてしまう。判定は読み取り側と同じ {@see LegacyOffer::hasFlatFetchFields()}
	 * ——移行前後で {@see LegacyOffer::offersWithFallback()} の件数が変わらないようにする。
	 *
	 * @param array<string, mixed> $listing
	 * @return array<string, mixed>
	 */
	public static function migrateListingToOffers( array $listing ): array {
		if ( isset( $listing['offers'] ) && is_array( $listing['offers'] ) ) {
			return $listing;
		}

		// flat → offer の写像は LegacyOffer が唯一持つ（保存時の畳み込み・描画時の
		// フォールバックと同じ関数を通し、片方だけ拾うフィールドが生まれないようにする）。
		// 持ち越す購入リンクが 1 件も無い listing は空の offers[] にする（存在しない
		// 購入リンクを作らない）。
		$offers = LegacyOffer::hasFlatFetchFields( $listing )
			? array( LegacyOffer::toOffer( $listing ) )
			: array();

		foreach ( self::LEGACY_FETCH_FIELDS as $field ) {
			unset( $listing[ $field ] );
		}

		$listing['offers'] = $offers;

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
		// 「未完」の印を先に立てる。add_option なので、既に走っている移行のカーソルを
		// 0 へ巻き戻すことはない（既存キーがあれば false を返して何もしない）。
		add_option( self::OPTION_MIGRATION_CURSOR, 0, '', false );

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		as_schedule_single_action( time(), self::HOOK_MIGRATE_OFFERS, array(), self::MIGRATION_GROUP, true );
	}

	/**
	 * offers 移行が未完（カーソル option が存在する）か。
	 *
	 * 既定値に false を渡して「存在しない」と「値が 0」を区別する——カーソルは
	 * 最初の 1 バッチが終わるまで 0 のままなので、`0 === get_option(...)` で
	 * 判定すると走り始めた直後の移行を「未完でない」と誤判定する。
	 */
	public static function isOffersMigrationPending(): bool {
		return false !== get_option( self::OPTION_MIGRATION_CURSOR, false );
	}

	/** 身元を持たないまま移行で温存した listing の延べ件数。 */
	public static function preservedWithoutRegularUrlCount(): int {
		return (int) get_option( self::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL, 0 );
	}

	/**
	 * 上記の温存が起きた商品の post ID（最大 {@see self::PRESERVED_POST_IDS_CAP} 件）。
	 *
	 * @return list<int>
	 */
	public static function preservedWithoutRegularUrlPostIds(): array {
		$raw = get_option( self::OPTION_MIGRATION_PRESERVED_POST_IDS, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ids = array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
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
	 * **温存件数は「保存後の形」から数える。** 変換直後のメモリ上の配列から数えると、
	 * その後 sanitize で落ちた offer まで「温存した」と報告してしまう（＝消えたデータに
	 * ついて全て問題なしと告げる、沈黙より悪い状態）。writeMigratedListings() が
	 * 実際に格納される形を返すので、数える対象と保存する値を同一にする。
	 *
	 * 保存後は必ず ProductRepository::syncDerivedMeta() を呼ぶ——listings に変更が
	 * 無い商品（既に offers[] 形式・listing 自体が無い）でも META_SCHEMA_VERSION は
	 * SchemaVersion::CURRENT へ更新する（スキーマのバージョンは商品単位の meta であり、
	 * この商品の listing 内容に変更があったかどうかとは独立している）。
	 */
	private static function migrateOneProduct( int $postId ): void {
		$raw      = get_post_meta( $postId, ProductPostType::META_LISTINGS, true );
		$listings = is_string( $raw ) ? JsonField::decode( $raw, array() ) : ( is_array( $raw ) ? $raw : array() );

		$migrated = array();
		$changed  = false;

		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				$migrated[] = $listing;
				continue;
			}

			if ( ! isset( $listing['offers'] ) || ! is_array( $listing['offers'] ) ) {
				$changed = true;
			}

			$migrated[] = self::migrateListingToOffers( $listing );
		}

		if ( $changed ) {
			$stored = self::writeMigratedListings( $postId, $migrated );
			self::countPreservedWithoutRegularUrl( $postId, $stored );
		}

		( new ProductRepository() )->syncDerivedMeta( $postId );
	}

	/**
	 * 移行した listings を保存し、**実際に格納された形**を返す。
	 *
	 * `update_post_meta()` は `update_metadata()` の中で `sanitize_meta()` を走らせ、
	 * ProductMeta::register() が登録した `ProductSchema::sanitizeListings` を通す。
	 * つまり呼び出し側が渡した配列がそのまま入るわけではない。ここで自分で同じ
	 * sanitize を掛けてから渡すことで、返り値＝格納される形になる（sanitizeListings は
	 * 冪等な whitelist 正規化なので、WordPress 側で二度目を通っても値は変わらない）。
	 *
	 * 2 つの窓をこの 1 回の書き込みだけに掛ける:
	 *
	 * - `ProductSchema::withLegacyOfferPreservation()`: 身元（regular_url / external_id）を
	 *   1 つも持たない offer を落とすルールを外す。外さないと、移行が丁寧に温存した手入力 listing
	 *   （affiliate_url だけを持つもの）が保存の瞬間に消える。
	 * - `OfferPromotionTrigger::withSuppression()`: 移行の書き込みで繰り上がり
	 *   トリガーを走らせない。移行した offer は元の（多くは古い）last_fetched_at を
	 *   引き継ぐため needsRefetch() がほぼ全件 true になり、アップグレードした
	 *   瞬間にカタログ全件ぶんの取得ジョブが積まれる。移行は形状を変えるだけで
	 *   購入リンクの内容は変えていないので、繰り上がりの契機ではない。
	 *
	 * どちらも finally で必ず戻すため、例外が飛んでも窓は開いたままにならない。
	 *
	 * **書き込みは必ず読み直して照合する。** update_post_meta() が失敗しても
	 * 戻り値を捨てていると、商品は旧形式のまま残るのに温存件数と派生 meta は
	 * 新形式が保存された前提で進み、カーソルも前進して二度と再試行されない。
	 * 戻り値そのものは判定に使えない——値が変わらなかった場合も false を返すためである。
	 * 食い違ったら例外を投げる。runOffersMigrationBatch() はカーソル前進も
	 * finishOffersMigration() もループの後で行うため、投げればカーソルは進まず
	 * 移行マーカーも残り、次の実行で同じ商品からやり直せる。
	 *
	 * @param list<mixed> $listings
	 * @return list<array<string, mixed>> 実際に格納された listings。
	 * @throws \RuntimeException 書き込み後の読み直しが $stored と一致しないとき.
	 */
	private static function writeMigratedListings( int $postId, array $listings ): array {
		return OfferPromotionTrigger::withSuppression(
			static function () use ( $postId, $listings ): array {
				return ProductSchema::withLegacyOfferPreservation(
					static function () use ( $postId, $listings ): array {
						$stored = ProductSchema::sanitizeListings( $listings );
						update_post_meta( $postId, ProductPostType::META_LISTINGS, $stored );

						$raw       = get_post_meta( $postId, ProductPostType::META_LISTINGS, true );
						$persisted = is_string( $raw )
							? JsonField::decode( $raw, array() )
							: ( is_array( $raw ) ? $raw : array() );
						if ( $persisted !== $stored ) {
							throw new \RuntimeException(
								// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- HTML 出力ではなく Action Scheduler のログ／PHP エラーログに残る例外メッセージ。埋め込むのは post ID（int）のみで外部入力を含まない。
								sprintf( 'affilicard: offers 移行の保存に失敗しました（post %d）。', $postId )
							);
						}

						return $stored;
					}
				);
			}
		);
	}

	/**
	 * 保存済み listings のうち、身元（regular_url / external_id）を 1 つも持たないまま
	 * 購入リンクを残した offer を数え、件数と対象 post ID を option へ積み上げる。
	 *
	 * **数える対象は「次の保存で消える offer」だけである。** external_id を持つ offer は
	 * 通常の保存でも消えない（ProductSchema::sanitizeOffers は身元を 1 つも持たない
	 * offer だけを弾く）ため、これを数えると通知が「消えます」と嘘をつく。
	 *
	 * 数えるのは「保存後の形」——沈黙も嘘も避けるため。
	 *
	 * @param list<array<string, mixed>> $stored
	 */
	private static function countPreservedWithoutRegularUrl( int $postId, array $stored ): void {
		$preserved = 0;

		foreach ( $stored as $listing ) {
			$offers = isset( $listing['offers'] ) && is_array( $listing['offers'] ) ? $listing['offers'] : array();
			foreach ( $offers as $offer ) {
				if ( ! is_array( $offer ) ) {
					continue;
				}
				if ( '' !== (string) ( $offer['regular_url'] ?? '' ) ) {
					continue;
				}
				if ( '' !== (string) ( $offer['external_id'] ?? '' ) ) {
					// 身元があるので再同定できる＝通常の保存でも消えない。
					continue;
				}
				if ( '' !== (string) ( $offer['affiliate_url'] ?? '' ) ) {
					++$preserved;
				}
			}
		}

		if ( $preserved < 1 ) {
			return;
		}

		$total = self::preservedWithoutRegularUrlCount();
		update_option( self::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL, $total + $preserved, false );
		self::recordPreservedPostId( $postId );
	}

	/**
	 * 温存が起きた商品の post ID を控える（重複なし・上限あり）。
	 *
	 * 上限を超えたら追加しない。件数は別 option で正確に積み上がるので、通知は
	 * 「N 件のうち、この商品を確認してください」を示せる。
	 */
	private static function recordPreservedPostId( int $postId ): void {
		$ids = self::preservedWithoutRegularUrlPostIds();
		if ( in_array( $postId, $ids, true ) || count( $ids ) >= self::PRESERVED_POST_IDS_CAP ) {
			return;
		}
		$ids[] = $postId;
		update_option( self::OPTION_MIGRATION_PRESERVED_POST_IDS, $ids, false );
	}

	/**
	 * 移行完走時の後始末。カーソル（＝未完の印）を消し、身元を持たないまま
	 * 延命した listing があればログに残す。
	 *
	 * **error_log() は運用への通知としては当てにならない**（本番は WP_DEBUG_LOG が off で
	 * 何処にも出ない。しかもここは完走時にしか通らないので、移行が途中で止まったまま
	 * だと 1 行も出ない）。運用が実際に見る場所への表示は Admin\OffersMigrationNotice が
	 * 担い、そちらは完走を待たず option を直接読む。ここのログは CLI 運用向けの補助。
	 */
	private static function finishOffersMigration(): void {
		delete_option( self::OPTION_MIGRATION_CURSOR );

		$preserved = self::preservedWithoutRegularUrlCount();
		if ( $preserved > 0 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 新規保存なら弾かれる regular_url 欠落の listing を移行では温存しており、AS 側では完全に不可視になるため運用が気づけるようログに残す。
			error_log(
				sprintf(
					'affilicard: offers 移行が完了しました。通常 URL も外部 ID も持たないまま購入リンクを維持した listing が %d 件あります。次回の保存で削除されるため、通常 URL の追加を推奨します。',
					$preserved
				)
			);
		}
	}
}
