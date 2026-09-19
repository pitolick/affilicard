<?php
declare(strict_types=1);

namespace Affilicard\Admin;

use Affilicard\Repository\DerivedMetaSync;

/**
 * extid ミラーを作り直せず、再試行も積めなかった商品を管理画面に出す通知。
 *
 * **なぜ通知が要るのか。** ブロックエディタの保存はコアの `wp/v2` meta 経路で listings を
 * 書き、その後始末として派生 meta（`affilicard_extid_<platform>` ミラー）を作り直す。
 * ロックを取れなければ作り直しは見送られ、{@see DerivedMetaSync} が Action Scheduler へ
 * 再試行を積む——**積めればそれで足りる**。ジョブ一覧に残り、失敗すれば failed として
 * 見える。
 *
 * 問題は積めなかったときで、その瞬間にこの失敗は完全に不可視になる。呼び出し元は
 * `rest_after_insert` のフックで戻り値を見る者がおらず、AS にもアクションは無い。
 * 以前は `error_log()` に出していたが、本番の `WP_DEBUG_LOG` は off なので何処にも
 * 出ない（{@see OffersMigrationNotice} を作ったときと同じ理由である）。
 *
 * **放置してよい失敗ではない。** ミラーは {@see \Affilicard\Repository\ProductRepository::findByExternalId()}
 * の索引そのもので、古いままだと自動作成が既存商品を見落として**同じ商品を二重に作る**。
 * 直し方は単純で、対象の商品を開いて保存し直せばミラーは作り直される——だから件数だけ
 * でなく商品への導線まで出す。
 *
 * 表示は affilicard 管理画面に限定し、dismiss は「閉じた時点の件数」で覚える
 * （増えたら出し直す。規則は {@see DismissibleCountNotice}）。
 */
final class DerivedMetaSyncNotice extends DismissibleCountNotice {

	/**
	 * 「今後表示しない」を記録するユーザーメタキー。
	 *
	 * 移行通知とキーを分けるのは、片方を閉じたらもう片方まで黙る状態を避けるため
	 * （別の事象で、運用が取るべき行動も違う）。
	 */
	private const DISMISS_META = 'affilicard_derived_meta_unsynced_notice_dismissed';

	/** 「今後表示しない」リンクのアクション名（nonce/クエリ引数）。 */
	private const DISMISS_ACTION = 'affilicard_dismiss_derived_meta_unsynced_notice';

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'maybeRender' ) );
		add_action( 'admin_init', array( self::class, 'maybeHandleDismiss' ) );
	}

	/**
	 * ミラーを作り直せなかった商品を出すべきか。
	 */
	public static function shouldShow(): bool {
		if ( ! self::isAffilicardScreen() ) {
			return false;
		}
		$count = DerivedMetaSync::unsyncedCount();
		if ( $count < 1 ) {
			return false;
		}
		// 閉じた時点より増えていれば出し直す。未設定なら (int) '' === 0 で必ず出る。
		$dismissed_at = (int) get_user_meta( get_current_user_id(), self::DISMISS_META, true );
		return $count > $dismissed_at;
	}

	public static function maybeRender(): void {
		if ( ! self::shouldShow() ) {
			return;
		}

		// **件数は 1 回だけ読む。** URL 用と表示用で読み直すと、その間に別の保存が件数を
		// 増やしたとき「文言は N+1 件なのに nonce に載るのは N 件」になり、閉じた直後に
		// また通知が出る（移行通知と同じ理由）。
		$count       = DerivedMetaSync::unsyncedCount();
		$dismiss_url = self::dismissUrl( self::DISMISS_ACTION, $count );

		echo '<div class="notice notice-error"><p>';
		echo esc_html(
			sprintf(
				/* translators: %d: ミラーを作り直せなかった件数。 */
				__(
					'affilicard: 購入リンクの外部 ID 索引を作り直せなかった商品が %d 件あります。索引が古いままだと、価格の自動取得が既存の商品を見つけられず、同じ商品を二重に登録することがあります。下記の商品を開いて保存し直すと索引は作り直されます。',
					'affilicard'
				),
				$count
			)
		);
		echo ' <a href="' . esc_url( $dismiss_url ) . '">'
			. esc_html__( 'この通知を今後表示しない', 'affilicard' ) . '</a>';
		echo '</p>';
		self::renderPostLinks( DerivedMetaSync::unsyncedPostIds(), DerivedMetaSync::UNSYNCED_POST_IDS_CAP );
		echo '</div>';
	}

	/**
	 * 「今後表示しない」リンク押下（nonce 検証）でユーザーメタに記録する。
	 */
	public static function maybeHandleDismiss(): void {
		if ( isset( $_GET[ self::DISMISS_ACTION ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce は直後の check_admin_referer で検証する。
			self::dismiss( self::DISMISS_ACTION, self::DISMISS_META );
		}
	}
}
