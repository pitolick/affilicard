<?php

declare(strict_types=1);

/**
 * E2E 補助スクリプト: 「移行がまだ到達していない listing に通常の価格更新が届く」窓を実 WP で作る。
 * `wp eval-file --use-include` でコンテナ内実行する。
 *
 * ## 何を確かめるためのものか
 *
 * 移行は「身元（`regular_url` / `external_id`）を 1 つも持たない購入リンクも温存し、
 * 対象商品を名指しで管理画面に通知する」と約束している。ところが価格更新の保存
 * （`ProductRepository::updateListingOffer()`）は listing を `offers[]` へ揃えてから
 * 書き戻すため、v3 の flat な listing へ価格更新が先に届くと**その保存が変換を兼ねて**
 * しまい、通常の sanitize が身元なしの購入リンクをそこで落とす（温存件数も増えず通知も
 * 出ない）。v4.0.0 の `ListingRefresher::isHeldForMigration()` はこれを見送りで塞いだ。
 *
 * ## なぜ 1 プロセスで全部やるのか（determinism）
 *
 * **真っさらな DB では offers 移行がインストール直後から未完である**（バージョン option が
 * 無い＝`needsOffersMigrationFrom('')` が true）。その状態では、どの管理画面リクエストも
 * Action Scheduler の非同期ランナーを起こしうる（`ActionScheduler_QueueRunner::
 * maybe_dispatch_async_request()` は `is_admin()` で発火する）。ランナーは
 * `maybeUpgrade()` が毎リクエスト積み直す `affilicard_migrate_offers_batch` を拾って
 * **こちらの観測の途中で移行を走らせてしまう**——実際 CI と、ローカルで DB を
 * リセットした再現でこれが起きた（移行が先に変換したため「見送ったので flat のまま」の
 * 主張が崩れた。開発者の使い込んだ DB では移行がとうに完了していて再現しなかった）。
 *
 * そこでシード・観測・価格更新・移行・再開までを**この 1 プロセスの中で順に行う**。
 * プロセス間の隙が無くなるので、外から来る非同期ランナーに割り込まれる窓がミリ秒単位に
 * なる。それでも割り込まれた場合に黙って誤判定しないよう、各段階で
 * `isOffersMigrationPending()` を記録し、spec 側が前提として突き合わせる。
 *
 * ## なぜ `wp action-scheduler run` を使わないのか
 *
 * 以前は価格更新を `wp action-scheduler run --hooks=... --group=affilicard-rakuten-kobo`
 * で流していたが、**Action Scheduler の group 行は最初に使われたときに遅延作成される**。
 * affilicard を一度も動かしたことのない DB（＝CI）にはその行が無く、ランナーは
 * `The group "affilicard-rakuten-kobo" does not exist.` で exit 1 になる
 * （`ActionScheduler_DBStore::claim_actions()` が投げる `InvalidArgumentException`）。
 * この spec が確かめたいのは「キューが動くか」ではなく「価格更新の書き込みが
 * 未変換の listing を壊さないか」なので、AS のランナーを介さず
 * **`Plugin::bootInstance()` が AS 用に配線したのと同じフックを直接発火**させる。
 * AS 自身も `do_action_ref_array( $hook, array_values( $args ) )` で同じことをしており、
 * `RefreshHandler` → `ThrottledActionHandler::run()`（pause ゲート・provider 解決・
 * レート制限・`refreshTargetCount()`）→ `ListingRefresher::refreshOne()` →
 * `ProductRepository::updateListingOffer()` → `update_post_meta()` →
 * `sanitize_meta()` までは完全に同じ経路を通る。AS の claim/complete 記録だけが省かれる。
 * キュー本体（積まれて実際に走ること）は tests/e2e/offers-migration-trigger.spec.js が
 * 実ランナーで見ている。
 *
 * 出力: 1 行 `RACE_JSON:{...}`
 */

use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductPostType;
use Affilicard\Pricing\LegacyOffer;
use Affilicard\Pricing\OfferSelector;
use Affilicard\Pricing\PriceFreshness;
use Affilicard\Queue\Enqueuer;
use Affilicard\Settings\GeneralSettings;
use Affilicard\Upgrade\PluginUpgrade;

global $wpdb;

$platform = 'rakuten-kobo';
$account  = 'rakuten-kobo';

// 前回分のフィクスチャを掃除する（status は移行本体と同じ列挙。'any' だとゴミ箱が漏れる）。
foreach (
	get_posts(
		array(
			'post_type'      => ProductPostType::POST_TYPE,
			'post_status'    => PluginUpgrade::MIGRATION_POST_STATUSES,
			's'              => 'E2E-RaceFixture',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		)
	) as $stale_id
) {
	wp_delete_post( (int) $stale_id, true );
}

/**
 * v3 以前の実データを模す（`$wpdb` 直書き込みの理由は migration-fixture.php 参照——
 * `register_post_meta()` 済みキーは `update_post_meta()` 経由でも `sanitize_meta()` を
 * 通り、その場で `offers[]` へ正規化されてしまう）。
 *
 * @param array<int, array<string, mixed>> $listings
 */
$make_legacy = static function ( string $title, array $listings ) use ( $wpdb ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => ProductPostType::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
		)
	);
	$wpdb->insert(
		$wpdb->postmeta,
		array(
			'post_id'    => $id,
			'meta_key'   => ProductPostType::META_LISTINGS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- v3 以前の実データを模すための意図的な直書き込み。
			'meta_value' => maybe_serialize( $listings ),
		)
	);
	wp_cache_delete( $id, 'post_meta' );
	return $id;
};

$ids = array(
	// 身元を 1 つも持たない flat listing（手入力で affiliate_url だけ入れた v3 の実データ形）。
	'no_identity' => $make_legacy(
		'E2E-RaceFixture 身元なし',
		array(
			array(
				'platform'      => $platform,
				'enabled'       => true,
				'affiliate_url' => 'https://example.test/race-no-identity',
				'price'         => '400',
			),
		)
	),
	// 対照群: external_id を持つ flat listing。身元があるので通常の保存でも消えない
	// （これが消えるなら別の退行である。対照が無いと「保存経路が全部壊れている」のか
	// 「身元なしだけが落ちる」のかを切り分けられない）。
	'with_id'     => $make_legacy(
		'E2E-RaceFixture 身元あり',
		array(
			array(
				'platform'      => $platform,
				'enabled'       => true,
				'external_id'   => 'race-with-id',
				'affiliate_url' => 'https://example.test/race-with-id',
				'price'         => '500',
			),
		)
	),
);

delete_option( PluginUpgrade::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL );
delete_option( PluginUpgrade::OPTION_MIGRATION_PRESERVED_POST_IDS );

// **移行を「未完」にする（カーソルを 0 で立てる）。** 実インストールでこの状況が起きるのは
// 「移行は走っているが、この商品へまだ到達していない」ときであり、見送りもその条件でしか
// 効かない。
delete_option( PluginUpgrade::OPTION_MIGRATION_CURSOR );
add_option( PluginUpgrade::OPTION_MIGRATION_CURSOR, 0, '', false );

// **積まれている移行アクションを消す。** このプロセス自身の `plugins_loaded` →
// `action_scheduler_init` が 1 件積んでおり、放っておくと外から来た非同期ランナーが
// それを拾ってこの観測の途中で移行を走らせてしまう（クラス冒頭の determinism の節）。
$actions_table = $wpdb->prefix . 'actionscheduler_actions';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Action Scheduler のテーブルを初期化するための E2E 専用スクリプト。
$wpdb->query( $wpdb->prepare( "DELETE FROM {$actions_table} WHERE hook = %s", PluginUpgrade::HOOK_MIGRATE_OFFERS ) );

/**
 * 1 商品ぶんの状態を読む。
 *
 * `sweepWouldEnqueue` は **`QueueMaintenance::sweep()` が実際に使っている判定と同じもの**
 * （同ファイルの `! PriceFreshness::needsRefetch( $targets[0], $def, $now, ... )` で
 * continue する行）をこの 1 商品について再現したもの。見送った更新が失われないことは
 * 「書き込まない＝`last_fetched_at` が据え置かれる」→「`needsRefetch()` が true のまま」
 * →「掃引が積み直す」で成り立つ。掃引そのものを回すとカタログ全件のカーソル走査と
 * depth cap が絡んで結果が環境依存になるため、判定だけを再現する。
 *
 * @return array<string, mixed>
 */
$snapshot_one = static function ( int $post_id ) use ( $platform ): array {
	wp_cache_delete( $post_id, 'post_meta' );
	$listings = get_post_meta( $post_id, ProductPostType::META_LISTINGS, true );
	$listings = is_array( $listings ) ? $listings : array();

	// 「購入リンクが何件あるか」を移行前後で同じ数え方にする（flat な listing は 1 件相当）。
	$links = 0;
	foreach ( $listings as $listing ) {
		if ( ! is_array( $listing ) ) {
			continue;
		}
		if ( isset( $listing['offers'] ) && is_array( $listing['offers'] ) ) {
			$links += count( $listing['offers'] );
			continue;
		}
		if ( '' !== (string) ( $listing['regular_url'] ?? '' ) || '' !== (string) ( $listing['affiliate_url'] ?? '' ) ) {
			++$links;
		}
	}

	$sweep_would_enqueue = false;
	$last_fetched_at     = null;
	foreach ( $listings as $listing ) {
		if ( ! is_array( $listing ) || $platform !== ( $listing['platform'] ?? '' ) ) {
			continue;
		}
		$targets = OfferSelector::select(
			LegacyOffer::offersWithFallback( $listing ),
			GeneralSettings::fallbackOnTerminal()
		);
		if ( array() === $targets ) {
			break;
		}
		$last_fetched_at     = (string) ( $targets[0]['last_fetched_at'] ?? '' );
		$sweep_would_enqueue = PriceFreshness::needsRefetch(
			$targets[0],
			PlatformConfig::find( $platform ),
			time(),
			0
		);
		break;
	}

	return array(
		'postId'            => $post_id,
		'links'             => $links,
		'listings'          => $listings,
		'lastFetchedAt'     => $last_fetched_at,
		'sweepWouldEnqueue' => $sweep_would_enqueue,
	);
};

/**
 * 全フィクスチャ商品のスナップショット（移行の未完フラグ・温存件数つき）。
 *
 * @return array<string, mixed>
 */
$snapshot = static function () use ( $ids, $snapshot_one ): array {
	$products = array();
	foreach ( $ids as $key => $post_id ) {
		$products[ $key ] = $snapshot_one( (int) $post_id );
	}
	return array(
		'products'         => $products,
		'preserved'        => PluginUpgrade::preservedWithoutRegularUrlCount(),
		'migrationPending' => PluginUpgrade::isOffersMigrationPending(),
	);
};

/**
 * 通常の価格更新を、Action Scheduler が使うのと同じフックで発火する。
 *
 * レート制限の option を **1 商品ごとに** 落とす——**枠を取れなかったせいで書き込みが
 * 起きなかった**のを「見送りが効いた」と読み違えないため（`ThrottledActionHandler::run()`
 * は枠を取れないと `performWork()` を呼ばずに戻る）。見送り中も
 * `refreshTargetCount()` は「見送りが解けたら叩き得る件数」を返して枠を取りに行く
 * （`ListingRefresher::targetCount()` の docblock 参照）ため、ループの外で 1 度落とすだけ
 * だと後続の商品が先頭の商品に枠を奪われた状態で走る。
 */
$drive_refresh = static function () use ( $ids, $platform, $account ): void {
	foreach ( $ids as $post_id ) {
		delete_option( 'affilicard_ratelimit_' . $account );
		do_action( Enqueuer::HOOK_REFRESH, (int) $post_id, $platform );
	}
};

// --- 1. 見送りの窓 ---------------------------------------------------------
$before = $snapshot();

// ハンドラが配線されていること自体を確かめる。配線が無ければ do_action は何も起こさず、
// 「書き込みが無い＝見送りが効いた」と誤読してしまう（下の再開ステップも道連れで落ちるが、
// ここで名指しできる方が原因が早く分かる）。
$refresh_hook_registered = false !== has_action( Enqueuer::HOOK_REFRESH );

$drive_refresh();
$after_hold = $snapshot();

// --- 2. 移行を完走させる ---------------------------------------------------
$guard = 0;
do {
	PluginUpgrade::runOffersMigrationBatch();
	++$guard;
} while ( PluginUpgrade::isOffersMigrationPending() && $guard < 100 );

if ( PluginUpgrade::isOffersMigrationPending() ) {
	throw new RuntimeException(
		sprintf( 'offers 移行が %d 回のバッチで完了しませんでした（未完のまま E2E を続けない）。', $guard )
	);
}

// ループが自分で回した継続アクションの残骸を片付ける（migration-fixture.php と同じ理由）。
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( PluginUpgrade::HOOK_MIGRATE_OFFERS );
}

$after_migration = $snapshot();

// --- 3. 移行後は価格更新が再開する -----------------------------------------
$drive_refresh();
$after_resume = $snapshot();

echo 'RACE_JSON:' . wp_json_encode(
	array(
		'refreshHookRegistered' => $refresh_hook_registered,
		'before'                => $before,
		'afterHold'             => $after_hold,
		'afterMigration'        => $after_migration,
		'afterResume'           => $after_resume,
		'migrationBatches'      => $guard,
	)
) . "\n";
