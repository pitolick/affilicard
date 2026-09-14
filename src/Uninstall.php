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
		'affilicard_plugin_version',
		'affilicard_stocktake_baseline',
		// Affilicard\Queue\SweepCursor::OPTION_KEY のリテラル値（spec 2026-08-25 §4-2/§6-2）。
		'affilicard_sweep_cursor',
		// Affilicard\Queue\QueueMaintenance::OPTION_LAST_COMPLETED のリテラル値（spec §4-4/§6-2）。
		'affilicard_last_sweep_completed_at',
		// Affilicard\Upgrade\PluginUpgrade::OPTION_MIGRATION_CURSOR のリテラル値
		// （offers 移行の走査カーソル兼「未完」の印）。
		'affilicard_offers_migration_cursor',
		// Affilicard\Upgrade\PluginUpgrade::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL の
		// リテラル値（offers 移行で温存した listing の件数）。
		'affilicard_offers_migration_preserved_without_regular_url',
		// Affilicard\Upgrade\PluginUpgrade::OPTION_MIGRATION_PRESERVED_POST_IDS のリテラル値
		// （温存が起きた商品の post ID 一覧）。
		'affilicard_offers_migration_preserved_post_ids',
	);

	/**
	 * ProviderRegistry が安全に利用できない環境（vendor/ 不在フォールバックで
	 * Plugin クラスが未 autoload）向けの最終手段リスト（account コード）。通常経路は
	 * automaticAccountCodes() が Plugin::automaticAccountCodes() を優先する。
	 *
	 * @var list<string>
	 */
	private const AUTOMATIC_ACCOUNT_CODES_FALLBACK = array( 'dmm', 'rakuten' );

	/**
	 * 全ユーザーに残る本プラグインのユーザーメタを消す。
	 *
	 * OPTION_KEYS の掃除は options テーブルしか触らないため、移行通知の「閉じた」印
	 * （{@see \Affilicard\Admin\OffersMigrationNotice} が update_user_meta() で書く）は
	 * アンインストール後も全ユーザーに残り続けていた。ユーザー数ぶん個別に消すのは
	 * 現実的でないので、delete_metadata() の delete-all で一括削除する。
	 */
	private static function deleteUserMeta(): void {
		// Affilicard\Admin\OffersMigrationNotice::DISMISS_META のリテラル値。
		delete_metadata( 'user', 0, 'affilicard_offers_migration_notice_dismissed', '', true );
	}

	public static function run(): void {
		foreach ( self::OPTION_KEYS as $option_key ) {
			delete_option( $option_key );
		}

		self::deleteUserMeta();
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
	 *
	 * v3.5.0: 掃引トリガー（affilicard_sweep）の group（'affilicard-sweep'。account では
	 * ない疑似コード。Enqueuer::SWEEP_GROUP_ACCOUNT）も同様に unschedule する
	 * （QueueController::pendingCancellationGroups() の一括取消と同じ対象。ここが漏れると
	 * アンインストール後も pending な掃引継続ジョブが残ってしまう）。'affilicard-sweep' を
	 * Enqueuer::group(Enqueuer::SWEEP_GROUP_ACCOUNT) 経由で組み立てないのは、OPTION_KEYS と
	 * 同じ理由（vendor/ 不在フォールバックでは Enqueuer クラスが未 autoload で Fatal error に
	 * なる）でリテラルのまま持つ必要があるため。
	 */
	private static function cleanupQueue(): void {
		$canUnschedule = function_exists( 'as_unschedule_all_actions' );

		foreach ( self::automaticAccountCodes() as $account ) {
			if ( $canUnschedule ) {
				as_unschedule_all_actions( '', array(), 'affilicard-' . $account );
			}
			delete_option( 'affilicard_ratelimit_' . $account );
		}

		if ( $canUnschedule ) {
			as_unschedule_all_actions( '', array(), 'affilicard-sweep' );
			// offers 移行バッチの group（Affilicard\Upgrade\PluginUpgrade::MIGRATION_GROUP の
			// リテラル値）。移行が未完のままアンインストールされると、継続ジョブが
			// pending で残る。リテラルで持つ理由は OPTION_KEYS と同じ（vendor/ 不在
			// フォールバックでは当該クラスが未 autoload）。
			as_unschedule_all_actions( '', array(), 'affilicard-migration' );
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
