<?php
declare(strict_types=1);

namespace Affilicard;

/**
 * プラグイン uninstall 時のクリーンアップ処理。
 *
 * uninstall.php から呼び出される。設定オプションおよび CPT 投稿を全削除する。
 */
final class Uninstall {

	/**
	 * 既知の affilicard_* オプションキー。新しい設定を追加したらここにも追記する。
	 *
	 * @var list<string>
	 */
	public const OPTION_KEYS = array(
		'affilicard_amazon_settings',
		'affilicard_dmm_settings',
		'affilicard_rakuten_settings',
		'affilicard_link_checker_settings',
		'affilicard_schema_version',
		'affilicard_platforms',
		'affilicard_general',
		'affilicard_seeded_at',
		'affilicard_legacy_creds_purged',
	);

	public static function run(): void {
		foreach ( self::OPTION_KEYS as $option_key ) {
			delete_option( $option_key );
		}

		self::deleteProviderCredentials();
		self::deleteAccountCredentials();

		$product_ids = get_posts(
			array(
				'post_type'   => 'affilicard_product',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		if ( ! is_array( $product_ids ) ) {
			return;
		}

		foreach ( $product_ids as $product_id ) {
			wp_delete_post( (int) $product_id, true );
		}
	}

	/**
	 * `affilicard_provider_<code>_credentials` 形式の credentials オプションを一括削除する。
	 *
	 * ProviderCredentials は provider コード毎に動的なキー名で書き込むため、
	 * 固定リストでは捕捉できない。option_name の前方一致で wp_options から DELETE する。
	 */
	private static function deleteProviderCredentials(): void {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$like = $wpdb->esc_like( 'affilicard_provider_' ) . '%' . $wpdb->esc_like( '_credentials' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);
	}

	/**
	 * `affilicard_account_<code>_credentials` 形式の credentials オプションを一括削除する。
	 *
	 * AccountCredentials は account 単位で動的なキー名で書き込むため、
	 * 固定リストでは捕捉できない。option_name の前方一致で wp_options から DELETE する。
	 */
	private static function deleteAccountCredentials(): void {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$like = $wpdb->esc_like( 'affilicard_account_' ) . '%' . $wpdb->esc_like( '_credentials' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);
	}
}
