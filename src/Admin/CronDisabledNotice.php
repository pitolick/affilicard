<?php
declare(strict_types=1);

namespace Affilicard\Admin;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Settings\GeneralSettings;

/**
 * 自動更新（WP-Cron）が無効なのに自動取得プロバイダが設定されている場合、affilicard の
 * 管理画面に「価格が 24h で表示されなくなる」旨の注意通知を表示する。
 *
 * 価格鮮度表示（PriceFreshness::isPriceDisplayable）は規約（Amazon Creators API/楽天/DMM
 * とも価格は取得後 24h 以内の表示）に従い、最終確認から 24h を超えた価格を非表示にする。
 * よって自動更新が止まっていると価格が順次消える。既定 ON（GeneralSettings）で新規サイトは
 * 自動的に守られるが、既に cron_enabled=false を保存済みのサイトは既定変更の影響を受けない
 * ため、この通知で気づけるようにする。
 *
 * 表示条件: affilicard 管理画面 かつ 自動更新 OFF かつ 自動取得プロバイダが1つ以上設定済み
 * かつ ユーザーが「今後表示しない」を選んでいない。全て手動運用のサイトでは自動更新は不要
 * なので通知しない。
 */
final class CronDisabledNotice {

	/** 「今後表示しない」を記録するユーザーメタキー。 */
	private const DISMISS_META = 'affilicard_cron_notice_dismissed';

	/** 「今後表示しない」リンクのアクション名（nonce/クエリ引数）。 */
	private const DISMISS_ACTION = 'affilicard_dismiss_cron_notice';

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'maybeRender' ) );
		add_action( 'admin_init', array( self::class, 'maybeHandleDismiss' ) );
	}

	/**
	 * 通知を表示すべきか。affilicard 画面 + 自動更新 OFF + 自動取得プロバイダ設定済み +
	 * 未 dismiss のときだけ true。
	 */
	public static function shouldShow(): bool {
		if ( GeneralSettings::isCronEnabled() ) {
			return false;
		}
		if ( ! self::hasAutomaticProvider() ) {
			// 全て手動運用 → 自動更新は不要なので通知しない。
			return false;
		}
		if ( 1 === (int) get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return false;
		}
		return self::isAffilicardScreen();
	}

	public static function maybeRender(): void {
		if ( ! self::shouldShow() ) {
			return;
		}
		$settings_url = admin_url( 'edit.php?post_type=affilicard_product&page=affilicard-settings' );
		$dismiss_url  = wp_nonce_url(
			add_query_arg( self::DISMISS_ACTION, '1' ),
			self::DISMISS_ACTION
		);

		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo esc_html__(
			'affilicard: 自動取得プロバイダが設定されていますが、価格の自動更新（WP-Cron）が無効です。価格は最終確認から24時間で表示されなくなる（規約準拠）ため、自動更新を有効にしてください。',
			'affilicard'
		);
		echo ' <a href="' . esc_url( $settings_url ) . '">'
			. esc_html__( '設定 → 一般で有効化', 'affilicard' ) . '</a>';
		echo ' | <a href="' . esc_url( $dismiss_url ) . '">'
			. esc_html__( 'この通知を今後表示しない', 'affilicard' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * 「今後表示しない」リンク押下（nonce 検証）でユーザーメタに記録し、クエリ引数を除いて
	 * リダイレクトする。
	 */
	public static function maybeHandleDismiss(): void {
		if ( ! isset( $_GET[ self::DISMISS_ACTION ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce は直後の check_admin_referer で検証する。
			return;
		}
		check_admin_referer( self::DISMISS_ACTION );
		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		wp_safe_redirect( remove_query_arg( array( self::DISMISS_ACTION, '_wpnonce' ) ) );
		exit;
	}

	/** 自動取得プロバイダ（provider !== 'manual'）を持つプラットフォームが1つでもあるか。 */
	private static function hasAutomaticProvider(): bool {
		foreach ( PlatformConfig::all() as $definition ) {
			if ( 'manual' !== $definition->provider ) {
				return true;
			}
		}
		return false;
	}

	/** 現在の管理画面が affilicard 商品 CPT 配下（一覧/編集/設定/ジョブ一覧）か。 */
	private static function isAffilicardScreen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( null === $screen ) {
			return false;
		}
		// CPT の一覧/編集も、その配下のサブページ（設定・ジョブ一覧）も post_type が
		// 'affilicard_product' になる。
		return isset( $screen->post_type ) && 'affilicard_product' === $screen->post_type;
	}
}
