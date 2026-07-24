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

	/**
	 * ProviderRegistry が安全に利用できない環境（vendor/ 不在フォールバックで
	 * Plugin クラスが未 autoload）向けの最終手段リスト（account コード）。通常経路は
	 * automaticAccountCodes() が Plugin::automaticAccountCodes() を優先する。
	 *
	 * @var list<string>
	 */
	private const AUTOMATIC_ACCOUNT_CODES_FALLBACK = array( 'dmm', 'rakuten' );

	public static function run(): void {
		foreach ( self::OPTION_KEYS as $option_key ) {
			delete_option( $option_key );
		}

		self::deleteProviderCredentials();
		self::deleteAccountCredentials();
		self::cleanupQueue();

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

	/**
	 * 自動更新対象 account の queue クリーンアップ（spec §9-7）。
	 *
	 * v2.4.0: account 別 group（`affilicard-{account}`）の Action Scheduler スケジュールを
	 * 解除し、RateLimiter の throttle option（`affilicard_ratelimit_{account}`）を削除する
	 * （provider コード単位から account コード単位へ統一。レート制限は共有 API＝account
	 * 単位でかかるため）。AS 自身のテーブルは他プラグインと共有し得るため drop しない
	 * （`as_unschedule_all_actions` の呼び出しのみ・AS 未ロードなら function_exists で guard）。
	 */
	private static function cleanupQueue(): void {
		$canUnschedule = function_exists( 'as_unschedule_all_actions' );

		foreach ( self::automaticAccountCodes() as $account ) {
			if ( $canUnschedule ) {
				as_unschedule_all_actions( '', array(), 'affilicard-' . $account );
			}
			delete_option( 'affilicard_ratelimit_' . $account );
		}
	}

	/**
	 * 自動更新対象 account コード一覧。Plugin::automaticAccountCodes() が安全に
	 * 利用できれば（vendor/ 経由で autoload されていれば）それを優先し、
	 * vendor/ 不在フォールバック（uninstall.php 冒頭参照）で Plugin クラスが
	 * 未 autoload の場合のみ既知の固定リストへ縮退する。
	 *
	 * @return list<string>
	 */
	private static function automaticAccountCodes(): array {
		if ( ! class_exists( \Affilicard\Plugin::class ) ) {
			return self::AUTOMATIC_ACCOUNT_CODES_FALLBACK;
		}

		return \Affilicard\Plugin::automaticAccountCodes( \Affilicard\Plugin::buildProviderRegistry() );
	}
}
