<?php
declare(strict_types=1);

namespace Affilicard\Admin;

use Affilicard\Upgrade\PluginUpgrade;

/**
 * offers 移行（listing 直下の flat な取得結果 → offers[]）の状態を管理画面に出す通知。
 *
 * 移行が運用へ伝えるべきことは 2 つあり、どちらも従来は `error_log()` と option の
 * 生値でしか見えなかった。本番は `WP_DEBUG_LOG` が off なのでログは何処にも出ず、
 * option を読むには未文書のキー名を知っている必要がある。しかも `error_log()` は
 * 完走時にしか通らないため、**移行が途中で止まっているという最も伝えるべき状態
 * では 1 行も出ない**。管理画面に出して、運用が実際に見る場所へ届ける。
 *
 * 1. **移行が未完**（カーソル option が残っている）。通常は数分で消える。出続けるなら
 *    Action Scheduler 側でジョブが落ちている。dismiss できない——消えないこと自体が
 *    情報であり、完走すれば自動的に消える。
 * 2. **regular_url を持たないまま購入リンクを温存した listing がある**。新規保存なら
 *    弾かれる形（生死を判定できない＝棚卸しの対象外）であり、**この温存は次の保存
 *    （同じ商品の別 platform の価格更新による自動保存も含む）までの猶予でしかない**——
 *    `ProductSchema::sanitizeOffers()` は常時この形の offer を弾くため、通常 URL を
 *    追加しない限りいずれ黙って消える。「確認してほしい」ではなく「消える前に
 *    通常 URL を追加してほしい」と明示する。確認したら消せるよう dismiss できる。
 *
 * 表示は affilicard 管理画面に限定する（CronDisabledNotice と同じ判定）。
 */
final class OffersMigrationNotice {

	/** 「今後表示しない」を記録するユーザーメタキー（温存通知のみ）。 */
	private const DISMISS_META = 'affilicard_offers_migration_notice_dismissed';

	/** 「今後表示しない」リンクのアクション名（nonce/クエリ引数）。 */
	private const DISMISS_ACTION = 'affilicard_dismiss_offers_migration_notice';

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'maybeRender' ) );
		add_action( 'admin_init', array( self::class, 'maybeHandleDismiss' ) );
	}

	/**
	 * 移行が未完である旨を出すべきか。
	 *
	 * dismiss を効かせない（完走すればカーソルが消えて通知も消える。運用が消せる
	 * ようにすると、止まったままの移行を握り潰せてしまう）。
	 *
	 * **画面を限定しない。** 移行が終わるまでカードは読み側のフォールバック
	 * （CardRenderer::legacyOffers()）で描かれており、この通知はカタログが移行途上に
	 * あることを伝える唯一の signal である。affilicard の画面を開いた運用者にしか
	 * 見えないと、止まった移行が誰にも気づかれない。短命かつ dismiss 不可なので
	 * 全画面に出しても居座らない（温存通知の方は恒久的に出得るので従来どおり限定する）。
	 */
	public static function shouldShowPending(): bool {
		return PluginUpgrade::isOffersMigrationPending();
	}

	/** 温存した listing の件数を出すべきか。 */
	public static function shouldShowPreserved(): bool {
		if ( ! self::isAffilicardScreen() ) {
			return false;
		}
		if ( PluginUpgrade::preservedWithoutRegularUrlCount() < 1 ) {
			return false;
		}
		return 1 !== (int) get_user_meta( get_current_user_id(), self::DISMISS_META, true );
	}

	public static function maybeRender(): void {
		if ( self::shouldShowPending() ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__(
				'affilicard: 購入リンクのデータ移行が実行中です。この通知が長く出続ける場合、移行ジョブが失敗している可能性があります。ジョブ一覧を確認してください。',
				'affilicard'
			);
			echo '</p></div>';
		}

		if ( ! self::shouldShowPreserved() ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( self::DISMISS_ACTION, '1' ),
			self::DISMISS_ACTION
		);

		echo '<div class="notice notice-warning"><p>';
		echo esc_html(
			sprintf(
				/* translators: %d: 温存した listing の件数。 */
				__(
					'affilicard: データ移行で、通常 URL（商品ページ URL）も外部 ID も持たないまま購入リンクを維持した listing が %d 件あります。このままではこの購入リンクは生死を確認できず棚卸しの対象外になるだけでなく、この商品を次に保存する（別プラットフォームの価格更新による自動保存も含む）と自動的に削除されます。消える前に、この購入リンクへ通常 URL を追加してください。',
					'affilicard'
				),
				PluginUpgrade::preservedWithoutRegularUrlCount()
			)
		);
		echo ' <a href="' . esc_url( $dismiss_url ) . '">'
			. esc_html__( 'この通知を今後表示しない', 'affilicard' ) . '</a>';
		echo '</p>';
		self::renderPreservedPostLinks();
		echo '</div>';
	}

	/**
	 * 温存が起きた商品への編集リンクを列挙する。
	 *
	 * 件数だけを告げる通知は運用上何もできない——「12 件消えます」と言われても、
	 * どの商品を直せばよいか分からない。移行が数えるついでに控えた post ID
	 * （{@see PluginUpgrade::preservedWithoutRegularUrlPostIds()}）を編集画面への
	 * 導線にする。ID の保持には上限があるため、件数の方が多い場合は「ほか」と添える。
	 */
	private static function renderPreservedPostLinks(): void {
		$ids = PluginUpgrade::preservedWithoutRegularUrlPostIds();
		if ( array() === $ids ) {
			return;
		}

		echo '<ul style="margin:0 0 0 1.5em;list-style:disc">';
		foreach ( $ids as $id ) {
			$link = get_edit_post_link( $id );
			if ( ! is_string( $link ) || '' === $link ) {
				continue;
			}
			$title = (string) get_the_title( $id );
			if ( '' === trim( $title ) ) {
				/* translators: %d: 商品の投稿 ID。 */
				$title = sprintf( (string) __( '（無題 #%d）', 'affilicard' ), $id );
			}
			echo '<li><a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a></li>';
		}
		echo '</ul>';

		$remaining = PluginUpgrade::preservedWithoutRegularUrlCount() - count( $ids );
		if ( $remaining > 0 ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: 一覧に出していない残りの件数。 */
					__( 'ほか %d 件は一覧に出していません（表示上限）。', 'affilicard' ),
					$remaining
				)
			) . '</p>';
		}
	}

	/**
	 * 「今後表示しない」リンク押下（nonce 検証）でユーザーメタに記録し、クエリ引数を
	 * 除いてリダイレクトする（CronDisabledNotice と同じ手順）。
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

	/** 現在の管理画面が affilicard 商品 CPT 配下（一覧/編集/設定/ジョブ一覧）か。 */
	private static function isAffilicardScreen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( null === $screen ) {
			return false;
		}
		return isset( $screen->post_type ) && 'affilicard_product' === $screen->post_type;
	}
}
