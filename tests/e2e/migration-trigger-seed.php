<?php

declare(strict_types=1);

/**
 * E2E 補助スクリプト: offers 移行の**開始トリガーそのもの**を実 WP で確かめるための種まき。
 * `wp eval-file --use-include` でコンテナ内実行する。
 *
 * tests/e2e/migration-fixture.php との違い。あちらは `runOffersMigrationBatch()` を
 * 直接呼び、「実データに対して変換・保存が正しく起こるか」だけを見る。トリガー
 * （`plugins_loaded` のバージョン差分 → Action Scheduler へ投入）は
 * 「PHPUnit で検証済み」として素通ししていた。ところが PHPUnit の
 * `as_schedule_single_action` はスタブで、**必ず成功する**。実機では
 * データストア未初期化のため 0 を返して 1 件も積まれておらず、
 * **誰もそれを見ていなかった**。ここはその穴を塞ぐためのもので、
 * 通常のリクエスト（ブラウザからの管理画面アクセス）に引かせたトリガーが
 * `wp_actionscheduler_actions` に本当に行を作るかを見る。
 *
 * このスクリプトがやること:
 *
 * 1. v3 以前の flat な listing を持つ商品を `$wpdb->insert()` で作る
 *    （`update_post_meta()` は登録済みメタキーをその場で sanitize して `offers[]` に
 *    してしまうため使えない。理由の詳細は migration-fixture.php のコメント）。
 * 2. `affilicard_migrate_offers_batch` の既存アクション行を**全 status 削除**する。
 *    残っていると「行がある」の assertion が前回の残骸で通ってしまう。
 * 3. `_doing_it_wrong()` の発火を記録する mu-plugin を書き出す。
 *    `WP_DEBUG` の表示設定に依存せずに観測するためである——
 *    `_doing_it_wrong()` は `WP_DEBUG` を見る**前に** `doing_it_wrong_run` を
 *    do_action するので、そこを捕まえれば表示設定と無関係に「鳴ったか」が分かる
 *    （wp-env の tests 環境は `WP_DEBUG=false` / `display_errors=stderr` で、
 *    レスポンス HTML には 1 文字も出てこない）。
 * 4. **最後に** `affilicard_plugin_version` を移行前（3.5.1）へ戻し、カーソルを消す。
 *    順序が要。`wp` コマンドは 1 回ごとに WordPress を起動し、その
 *    `plugins_loaded` で `PluginUpgrade::maybeUpgrade()` が走る。先にバージョンを
 *    戻すと**この eval-file の中ではなく次の `wp` 起動時**に移行が始まってしまい、
 *    「ブラウザのリクエストがトリガーを引いた」という主張が崩れる。
 *
 * 呼び出し側は `--use-include` を必ず付けること（seed.php と同じ理由）。
 * 出力: 1 行 `TRIGGER_SEED_JSON:{...}`
 */

use Affilicard\PostType\ProductPostType;
use Affilicard\Upgrade\PluginUpgrade;

// 種まきした商品の post ID を控える option（inspect 側が読む）と、mu-plugin が
// `_doing_it_wrong()` の発火を積む option。**`const` ではなく変数で持つ。**
// `wp eval-file --use-include` はこのファイルを WP-CLI のメソッド内で include するため、
// トップレベル `const` は関数スコープでの宣言になってしまう。
$ids_option              = 'affilicard_e2e_trigger_fixture_ids';
$doing_it_wrong_option   = 'affilicard_e2e_doing_it_wrong';

global $wpdb;

// 前回分のフィクスチャを掃除する（status は移行本体と同じ列挙。'any' だとゴミ箱が漏れる）。
foreach (
	get_posts(
		array(
			'post_type'      => ProductPostType::POST_TYPE,
			'post_status'    => PluginUpgrade::MIGRATION_POST_STATUSES,
			's'              => 'E2E-TriggerFixture',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		)
	) as $stale_id
) {
	wp_delete_post( (int) $stale_id, true );
}

/**
 * v3 以前の実データを模す。listings メタだけ `$wpdb->insert()` で直接書く
 * （`register_post_meta()` 済みキーは `update_post_meta()` 経由でも
 * `sanitize_meta()` を通り、その場で `offers[]` へ正規化されてしまう）。
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
	// 身元も通常 URL も持つ、ごく普通の flat listing。移行で offers[0] に入る。
	'normal'      => $make_legacy(
		'E2E-TriggerFixture 通常',
		array(
			array(
				'platform'        => 'dmm-books',
				'enabled'         => true,
				'external_id'     => 'trigger-normal',
				'regular_url'     => 'https://example.test/trigger-normal',
				'affiliate_url'   => 'https://example.test/trigger-normal-aff',
				'price'           => '600',
				'last_fetched_at' => '2026-01-01T00:00:00+00:00',
			),
		)
	),
	// 身元（external_id / regular_url）を 1 つも持たない flat listing。
	// 移行は温存し、運用向けカウンタを増やす。トリガーが引かれなければ
	// カウンタは 0 のまま＝「消えるかもしれない」という警告すら出ない。
	'no_identity' => $make_legacy(
		'E2E-TriggerFixture 身元なし',
		array(
			array(
				'platform'      => 'rakuten-kobo',
				'enabled'       => true,
				'affiliate_url' => 'https://example.test/trigger-no-identity',
				'price'         => '400',
			),
		)
	),
);

update_option( $ids_option, $ids, false );

// 既存のアクション行を status を問わず消す（前回の残骸で assertion が通らないように）。
$actions_table = $wpdb->prefix . 'actionscheduler_actions';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Action Scheduler のテーブルを検査・初期化するための E2E 専用スクリプト。
$wpdb->query( $wpdb->prepare( "DELETE FROM {$actions_table} WHERE hook = %s", PluginUpgrade::HOOK_MIGRATE_OFFERS ) );

// 移行の作業用 option を初期化する。
delete_option( PluginUpgrade::OPTION_MIGRATION_ATTEMPTS );
delete_option( PluginUpgrade::OPTION_MIGRATION_FAILED_COUNT );
delete_option( PluginUpgrade::OPTION_MIGRATION_FAILED_POST_IDS );
delete_option( PluginUpgrade::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL );
delete_option( PluginUpgrade::OPTION_MIGRATION_PRESERVED_POST_IDS );
delete_option( $doing_it_wrong_option );

// `_doing_it_wrong()` の発火を記録する mu-plugin。mu-plugin は通常プラグインより
// 先に読まれるので、affilicard の `plugins_loaded` が鳴らす分を確実に捕まえられる。
wp_mkdir_p( WPMU_PLUGIN_DIR );
$recorder = <<<'RECORDER'
<?php
/**
 * Plugin Name: affilicard E2E _doing_it_wrong recorder
 *
 * tests/e2e/migration-trigger-seed.php が書き出し、
 * tests/e2e/offers-migration-trigger.spec.js の afterAll が消す。
 * `_doing_it_wrong()` は WP_DEBUG を見る前に doing_it_wrong_run を do_action するため、
 * 表示設定（wp-env tests は WP_DEBUG=false / display_errors=stderr）と無関係に観測できる。
 */
add_action(
	'doing_it_wrong_run',
	static function ( $function_name, $message = '', $version = '' ) {
		$seen   = get_option( 'affilicard_e2e_doing_it_wrong', array() );
		$seen   = is_array( $seen ) ? $seen : array();
		$seen[] = (string) $function_name . ': ' . (string) $message;
		update_option( 'affilicard_e2e_doing_it_wrong', $seen, false );
	},
	10,
	3
);
RECORDER;
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- E2E 専用スクリプト。WP_Filesystem を初期化するだけの価値がない。
file_put_contents( WPMU_PLUGIN_DIR . '/affilicard-e2e-doing-it-wrong.php', $recorder );

// **最後に**移行前の状態へ戻す（順序の理由は冒頭の PHPDoc 4.）。
delete_option( PluginUpgrade::OPTION_MIGRATION_CURSOR );
update_option( PluginUpgrade::OPTION_VERSION, '3.5.1', false );

echo 'TRIGGER_SEED_JSON:' . wp_json_encode(
	array(
		'ids'     => $ids,
		'version' => (string) get_option( PluginUpgrade::OPTION_VERSION, '' ),
	)
) . "\n";
