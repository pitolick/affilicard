<?php
declare(strict_types=1);

namespace Affilicard\Admin;

use Affilicard\Account\AccountRegistry;
use Affilicard\Queue\AccountPause;

/**
 * API の上限（429）・利用条件（403）で account の価格更新を止めている間、ダッシュボードと
 * affilicard の管理画面に通知を出す（Queue\AccountPause）。
 *
 * 止めている間は価格を取り直さないため、表示中の価格は取得から 24 時間で順に消える。
 * メール（AccountPause が停止に入ったときに 1 回だけ送る）を見落としても、管理画面を開けば
 * 気づけるようにする。停止が解ければ自然に消えるので、「今後表示しない」は付けない。
 *
 * 表示条件: manage_options を持つユーザー かつ ダッシュボードか affilicard の画面 かつ
 * 止めている account が 1 つ以上ある。
 */
final class AccountPauseNotice {

	public function __construct( private AccountRegistry $accounts ) {}

	public static function register( AccountRegistry $accounts ): void {
		add_action( 'admin_notices', array( new self( $accounts ), 'maybeRender' ) );
	}

	/**
	 * 止めている account の表示行（ラベル・理由・再開予定）。
	 *
	 * @return list<array{label: string, reason: string, until: int}>
	 */
	public function pausedAccounts( int $now ): array {
		$rows = array();
		foreach ( $this->accounts->all() as $account ) {
			$code  = $account->code();
			$until = AccountPause::until( $code, $now );
			if ( 0 === $until ) {
				continue;
			}
			$record = AccountPause::get( $code );
			$rows[] = array(
				'label'  => $account->label(),
				'reason' => (string) ( $record['reason'] ?? AccountPause::REASON_RATE_LIMITED ),
				'until'  => $until,
			);
		}
		return $rows;
	}

	public function maybeRender(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::isTargetScreen() ) {
			return;
		}
		$rows = $this->pausedAccounts( time() );
		if ( array() === $rows ) {
			return;
		}
		$queue_url = admin_url( 'edit.php?post_type=affilicard_product&page=affilicard-settings' );
		foreach ( $rows as $row ) {
			$reason = AccountPause::REASON_NOT_ELIGIBLE === $row['reason']
				? __( 'API の利用条件（直近 30 日の適格販売）を満たしていません', 'affilicard' )
				: __( 'API の呼び出し回数の上限に達しました', 'affilicard' );
			echo '<div class="notice notice-warning affilicard-account-pause-notice"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: account label, 2: reason, 3: resume time */
					__( 'affilicard: %1$s の価格更新を一時停止しています（%2$s。%3$s ごろ再開）。止めている間、表示中の価格は取得から 24 時間で消えます（購入リンクは残ります）。', 'affilicard' ),
					$row['label'],
					$reason,
					wp_date( 'n/j H:i', $row['until'] )
				)
			);
			echo ' <a href="' . esc_url( $queue_url ) . '">'
				. esc_html__( '設定 → 更新キューで確認', 'affilicard' ) . '</a>';
			echo '</p></div>';
		}
	}

	private static function isTargetScreen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( null === $screen ) {
			return false;
		}
		if ( 'dashboard' === ( $screen->id ?? '' ) ) {
			return true;
		}
		return isset( $screen->post_type ) && 'affilicard_product' === $screen->post_type;
	}
}
