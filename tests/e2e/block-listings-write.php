<?php

declare(strict_types=1);

/**
 * E2E 補助スクリプト。`wp eval-file --use-include` でコンテナ内実行する。
 *
 * **「`update_post_meta()` が true を返しながら 1 バイトも書かない」状態を実 WordPress
 * 上で作る。** コアの `update_metadata()`（`wp-includes/meta.php` L241-243）は
 * `wp_unslash()` → `sanitize_meta()` の直後に `update_{$meta_type}_metadata` フィルタを
 * 呼び、null 以外が返れば `return (bool) $check;` で即座に戻る——$wpdb には触れない。
 * つまりフィルタが true を返すと、書き込みは成功を報告したまま何も保存されない。
 *
 * この分岐は**実 WordPress でしか踏めない**。単体テスト（WP_Mock）はコア関数ごと
 * スタブに差し替えるため、`update_post_meta()` の中身——フィルタも短絡も——が存在しない。
 * だからここでは本物のフィルタを mu-plugin として仕込む。
 *
 * **mu-plugin は option が指す商品にだけ効く。** 置きっぱなしになっても
 * （テストが途中で落ちた場合など）option が無ければ完全に無害で、他の spec を壊さない。
 *
 * 引数: <install|block|unblock|uninstall> [postId]
 * 出力: 1 行 `RESULT_JSON:{"ok":true}`
 */

const AFFILICARD_E2E_BLOCK_OPTION = 'affilicard_e2e_block_listings_post';
const AFFILICARD_E2E_MU_BASENAME  = 'affilicard-e2e-block-listings.php';

/** mu-plugin の中身。option に入った post ID の listings 書き込みだけを短絡させる。 */
function affilicard_e2e_mu_plugin_source(): string {
	return <<<'PHP'
<?php
/**
 * Plugin Name: affilicard E2E - block listings write
 *
 * affilicard_listings の update_post_meta() を「true を返しつつ書かない」形で短絡する。
 * option affilicard_e2e_block_listings_post が指す投稿にだけ効く（未設定なら完全に無害）。
 */

add_filter(
	'update_post_metadata',
	static function ( $check, $object_id, $meta_key ) {
		if ( 'affilicard_listings' !== $meta_key ) {
			return $check;
		}
		$target = (int) get_option( 'affilicard_e2e_block_listings_post', 0 );
		if ( 0 === $target || $target !== (int) $object_id ) {
			return $check;
		}

		// 書かずに成功を報告する。コアはこれを (bool) にして返すだけで DB へ行かない。
		return true;
	},
	10,
	3
);
PHP;
}

$command = (string) ( $args[0] ?? '' );
$post_id = (int) ( $args[1] ?? 0 );
$mu_file = WPMU_PLUGIN_DIR . '/' . AFFILICARD_E2E_MU_BASENAME;

switch ( $command ) {
	case 'install':
		if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
			wp_mkdir_p( WPMU_PLUGIN_DIR );
		}
		file_put_contents( $mu_file, affilicard_e2e_mu_plugin_source() );
		delete_option( AFFILICARD_E2E_BLOCK_OPTION );
		break;

	case 'block':
		update_option( AFFILICARD_E2E_BLOCK_OPTION, $post_id, false );
		break;

	case 'unblock':
		delete_option( AFFILICARD_E2E_BLOCK_OPTION );
		break;

	case 'uninstall':
		delete_option( AFFILICARD_E2E_BLOCK_OPTION );
		if ( file_exists( $mu_file ) ) {
			unlink( $mu_file );
		}
		break;

	default:
		echo 'RESULT_JSON:' . wp_json_encode( array( 'ok' => false ) ) . "\n";
		return;
}

echo 'RESULT_JSON:' . wp_json_encode( array( 'ok' => true ) ) . "\n";
