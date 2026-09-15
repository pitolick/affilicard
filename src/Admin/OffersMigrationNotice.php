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
 * 3. **新形式へ保存できず、移行を諦めた商品がある**。2 とは状況が違う——2 はデータが
 *    移行できた上で「次の保存で消える」猶予の話、3 は**そもそも書き込みが効かず
 *    旧形式のまま取り残された**商品の話である。表示は読み取り時のフォールバックで
 *    続くので即座に壊れはしないが、原因（別プラグインの meta フィルタ・壊れた meta 行）
 *    を取り除かない限り直らない。混ぜて書くと運用が取るべき行動を誤るため、
 *    別の通知として別の文言で出す。
 *
 * 表示は affilicard 管理画面に限定する（CronDisabledNotice と同じ判定）。
 */
final class OffersMigrationNotice {

	/**
	 * 「今後表示しない」を記録するユーザーメタキー（温存通知のみ）。
	 *
	 * **記録するのは真偽値ではなく「閉じた時点の温存件数」である。** 移行はバッチで
	 * 進むため、閉じたあとのバッチが温存件数を増やすことがある。真偽値で覚えると
	 * その増分を誰にも知らせないまま、次の保存で ProductSchema::sanitizeOffers() が
	 * 該当の購入リンクを消してしまう。件数を覚えておき、増えたら出し直す。
	 */
	private const DISMISS_META = 'affilicard_offers_migration_notice_dismissed';

	/** 表示していた件数を運ぶクエリ引数名（nonce action にも織り込む）。 */
	private const DISMISS_COUNT_ARG = 'affilicard_dismissed_count';

	/** 「今後表示しない」リンクのアクション名（nonce/クエリ引数）。 */
	private const DISMISS_ACTION = 'affilicard_dismiss_offers_migration_notice';

	/**
	 * 「移行できなかった商品」通知の dismiss を記録するユーザーメタキー。
	 *
	 * 温存通知と同じく、記録するのは真偽値ではなく「閉じた時点の件数」である
	 * （閉じたあとのバッチが件数を増やすことがあるため）。温存通知とキーを分けるのは、
	 * 片方を閉じたらもう片方まで黙る状態を避けるため——2 つは別の事象で、運用が
	 * 取るべき行動も違う。
	 */
	private const DISMISS_FAILED_META = 'affilicard_offers_migration_failed_notice_dismissed';

	/** 「移行できなかった商品」通知の dismiss アクション名（nonce/クエリ引数）。 */
	private const DISMISS_FAILED_ACTION = 'affilicard_dismiss_offers_migration_failed_notice';

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
	 * **画面は限定しないが、読み手は限定する。** 移行が終わるまでカードは読み側の
	 * フォールバック（CardRenderer::legacyOffers()）で描かれており、この通知は
	 * カタログが移行途上にあることを伝える唯一の signal である。affilicard の画面を
	 * 開いた運用者にしか見えないと、止まった移行が誰にも気づかれない。短命かつ
	 * dismiss 不可なので全画面に出しても居座らない（温存通知の方は恒久的に出得るので
	 * 従来どおり画面を限定する）。
	 *
	 * ただし画面の限定を外すと、購読者が profile.php を開いただけで消せない警告が
	 * 出る。**この通知に対して何かできる人にだけ出す**——商品を編集できる権限
	 * （edit_posts。ProductRestController / SettingsController の読み取りと同じ
	 * capability）を条件にする。
	 */
	public static function shouldShowPending(): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		return PluginUpgrade::isOffersMigrationPending();
	}

	/** 温存した listing の件数を出すべきか。 */
	public static function shouldShowPreserved(): bool {
		if ( ! self::isAffilicardScreen() ) {
			return false;
		}
		$count = PluginUpgrade::preservedWithoutRegularUrlCount();
		if ( $count < 1 ) {
			return false;
		}
		// 閉じた時点より増えていれば出し直す。未設定なら (int) '' === 0 で必ず出る。
		$dismissed_at = (int) get_user_meta( get_current_user_id(), self::DISMISS_META, true );
		return $count > $dismissed_at;
	}

	/**
	 * 移行を諦めた（新形式へ保存できなかった）商品の件数を出すべきか。
	 *
	 * 判定は温存通知と同じ——affilicard の画面に限定し、閉じた時点より件数が増えたら
	 * 出し直す。件数は完走後も残る（カーソルが通り過ぎた商品は二度と再訪しないため、
	 * この記録が唯一の痕跡である）。
	 */
	public static function shouldShowFailed(): bool {
		if ( ! self::isAffilicardScreen() ) {
			return false;
		}
		$count = PluginUpgrade::migrationFailedCount();
		if ( $count < 1 ) {
			return false;
		}
		$dismissed_at = (int) get_user_meta( get_current_user_id(), self::DISMISS_FAILED_META, true );
		return $count > $dismissed_at;
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

		self::maybeRenderFailed();

		if ( ! self::shouldShowPreserved() ) {
			return;
		}

		$dismiss_url = self::dismissUrl( self::DISMISS_ACTION, PluginUpgrade::preservedWithoutRegularUrlCount() );

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
	 * 新形式へ保存できず移行を諦めた商品を出す。
	 *
	 * **温存通知とは別の文言にする。** 温存は「データは移行できたが、身元が無いので
	 * 次の保存で消える」猶予の話であり、運用が取るべき行動は「通常 URL を追加する」。
	 * こちらは「書き込み自体が効かず旧形式のまま取り残された」話で、行動は
	 * 「保存を妨げている原因を取り除く」である。混ぜると誤った対処へ誘導する。
	 */
	private static function maybeRenderFailed(): void {
		if ( ! self::shouldShowFailed() ) {
			return;
		}

		$dismiss_url = self::dismissUrl( self::DISMISS_FAILED_ACTION, PluginUpgrade::migrationFailedCount() );

		echo '<div class="notice notice-error"><p>';
		echo esc_html(
			sprintf(
				/* translators: %d: 移行できなかった商品の件数。 */
				__(
					'affilicard: データ移行で、購入リンクを新しい形式へ保存できなかった商品が %d 件あります。書き込みが繰り返し失敗したため、これらの商品は移行の対象から外しました（旧形式のまま残り、表示は従来どおりのフォールバックで続きます）。別のプラグインが meta の保存を書き換えている、または meta の値が壊れている可能性があります。原因を取り除いたうえで、下記の商品を開いて保存し直してください。',
					'affilicard'
				),
				PluginUpgrade::migrationFailedCount()
			)
		);
		echo ' <a href="' . esc_url( $dismiss_url ) . '">'
			. esc_html__( 'この通知を今後表示しない', 'affilicard' ) . '</a>';
		echo '</p>';
		self::renderPostLinks( PluginUpgrade::migrationFailedPostIds(), PluginUpgrade::FAILED_POST_IDS_CAP );
		echo '</div>';
	}

	/**
	 * 温存が起きた商品への編集リンクを列挙する。
	 *
	 * 件数だけを告げる通知は運用上何もできない——「12 件消えます」と言われても、
	 * どの商品を直せばよいか分からない。移行が数えるついでに控えた post ID
	 * （{@see PluginUpgrade::preservedWithoutRegularUrlPostIds()}）を編集画面への
	 * 導線にする。ID の保持には上限があるため、上限に達している場合はその旨を添える。
	 */
	private static function renderPreservedPostLinks(): void {
		self::renderPostLinks(
			PluginUpgrade::preservedWithoutRegularUrlPostIds(),
			PluginUpgrade::PRESERVED_POST_IDS_CAP
		);
	}

	/**
	 * 商品 post ID の一覧を編集画面へのリンクとして出す（温存・移行失敗で共用）。
	 *
	 * @param list<int> $ids 控えてある post ID。
	 * @param int       $cap 控える上限（達していればその旨を添える）。
	 */
	private static function renderPostLinks( array $ids, int $cap ): void {
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

		// **件数の引き算はしない。** 上の件数は offer 単位、この一覧は商品単位であり、
		// 1 商品が温存 offer を 2 つ持てば差分は「存在しない商品」を指す。控えている
		// post ID は上限（PRESERVED_POST_IDS_CAP）で打ち切るため、上限に達したかどうか
		// だけを伝える（打ち切った先に何件あるかは記録していない＝数えられない）。
		if ( count( $ids ) >= $cap ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: 一覧に出す商品数の上限。 */
					__( '商品の一覧は先頭 %d 件までです。これ以外にも対象商品がある場合は表示されません。', 'affilicard' ),
					$cap
				)
			) . '</p>';
		}
	}

	/**
	 * 「今後表示しない」リンク押下（nonce 検証）でユーザーメタに記録し、クエリ引数を
	 * 除いてリダイレクトする（CronDisabledNotice と同じ手順）。
	 */
	public static function maybeHandleDismiss(): void {
		if ( isset( $_GET[ self::DISMISS_ACTION ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce は直後の check_admin_referer で検証する。
			self::dismiss( self::DISMISS_ACTION, self::DISMISS_META );
		}

		if ( isset( $_GET[ self::DISMISS_FAILED_ACTION ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce は直後の check_admin_referer で検証する。
			self::dismiss( self::DISMISS_FAILED_ACTION, self::DISMISS_FAILED_META );
		}
	}

	/**
	 * 「今後表示しない」リンクの URL。**表示した件数を URL と nonce action の両方に載せる。**
	 *
	 * 押下時に最新件数を読み直すと、通知を出してからクリックするまでの間に後続バッチが
	 * 件数を増やした場合、利用者が見ていない分まで抑止してしまう。表示した件数を
	 * 持ち回ることで「見たぶんだけ閉じる」になる。
	 *
	 * 件数を nonce action にも織り込むのは改竄対策である。URL の数字だけを書き換えても
	 * nonce が一致せず check_admin_referer() で弾かれる。
	 */
	private static function dismissUrl( string $action, int $count ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					$action                 => '1',
					self::DISMISS_COUNT_ARG => (string) $count,
				)
			),
			$action . ':' . $count
		);
	}

	/**
	 * dismiss の共通処理。nonce を検証し、「閉じた時点の件数」をユーザーメタへ残して
	 * クエリ引数を除いてリダイレクトする。
	 */
	private static function dismiss( string $action, string $meta ): void {
		// 件数は URL から取る（表示した値）。nonce action にも同じ値が入っているので、
		// 書き換えられていれば check_admin_referer() が弾く。
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce は直後の check_admin_referer で検証する。
		$raw_count = isset( $_GET[ self::DISMISS_COUNT_ARG ] )
			? sanitize_text_field( wp_unslash( $_GET[ self::DISMISS_COUNT_ARG ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '0';
		$count     = max( 0, (int) $raw_count );
		check_admin_referer( $action . ':' . $count );
		// 真偽値ではなく「閉じた時点の件数」を残す（後続バッチの増分で出し直すため）。
		update_user_meta( get_current_user_id(), $meta, $count );
		wp_safe_redirect( remove_query_arg( array( $action, self::DISMISS_COUNT_ARG, '_wpnonce' ) ) );
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
