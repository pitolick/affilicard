<?php

declare(strict_types=1);

/**
 * E2E 補助スクリプト: offers 移行の開始トリガーの結果を読み出す。
 *
 * **必ず `wp --skip-plugins --skip-themes eval-file --use-include` で呼ぶこと。**
 * `wp` は 1 回ごとに WordPress を起動し、その `plugins_loaded` で
 * `PluginUpgrade::maybeUpgrade()` が走る。affilicard を読み込んだまま点検すると、
 * **点検そのものがアクションを積んでしまい**「ブラウザのリクエストがトリガーを
 * 引いた」という主張が成り立たなくなる（カーソルが残っている限り毎リクエスト
 * 再武装するため）。だからここでは affilicard の定数を一切使わず、フック名・
 * option キー・メタキーをリテラルで書く——回帰テストとしてはその方が良い。
 * 実装側でこれらの名前を変えれば、このテストが落ちて気づける。
 *
 * 出力: 1 行 `TRIGGER_INSPECT_JSON:{...}`
 */

global $wpdb;

$hook          = 'affilicard_migrate_offers_batch';
$actions_table = $wpdb->prefix . 'actionscheduler_actions';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Action Scheduler のテーブルを直接見るための E2E 専用スクリプト。
$action_rows = $wpdb->get_results(
	$wpdb->prepare( "SELECT action_id, hook, status FROM {$actions_table} WHERE hook = %s ORDER BY action_id ASC", $hook ),
	ARRAY_A
);
$action_rows = is_array( $action_rows ) ? $action_rows : array();

$ids = get_option( 'affilicard_e2e_trigger_fixture_ids', array() );
$ids = is_array( $ids ) ? $ids : array();

$products = array();
foreach ( $ids as $key => $post_id ) {
	$post_id            = (int) $post_id;
	$products[ $key ]   = array(
		'postId'        => $post_id,
		'listings'      => get_post_meta( $post_id, 'affilicard_listings', true ),
		'schemaVersion' => (string) get_post_meta( $post_id, 'affilicard_schema_version', true ),
	);
}

echo 'TRIGGER_INSPECT_JSON:' . wp_json_encode(
	array(
		'actionRows'    => $action_rows,
		'cursor'        => get_option( 'affilicard_offers_migration_cursor', false ),
		'version'       => (string) get_option( 'affilicard_plugin_version', '' ),
		'preserved'     => (int) get_option( 'affilicard_offers_migration_preserved_without_regular_url', 0 ),
		'doingItWrong'  => get_option( 'affilicard_e2e_doing_it_wrong', array() ),
		'products'      => $products,
	)
) . "\n";
