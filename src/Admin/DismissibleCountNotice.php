<?php
declare(strict_types=1);

namespace Affilicard\Admin;

/**
 * 「件数で出し直す」dismiss 付き管理画面通知の共通部分。
 *
 * **記録するのは真偽値ではなく「閉じた時点の件数」である。** 対象の事象は後から
 * 増えるため、真偽値で覚えると増分を誰にも知らせないまま黙る。件数を覚えておき、
 * 増えたら出し直す——この規則を実装する 4 つの部品（画面の限定・dismiss リンクの
 * URL・dismiss の処理・対象商品の一覧）を、通知どうしで共有する。
 *
 * 通知ごとに違うのは「何を数えるか」「何を書くか」「どの user meta に閉じた件数を
 * 残すか」だけなので、それらは各サブクラスが持つ。
 *
 * 継承する側は `register()` / `maybeRender()` / `maybeHandleDismiss()` を自分で持ち、
 * ここの `protected` を使う。
 */
abstract class DismissibleCountNotice {

	/** 表示していた件数を運ぶクエリ引数名（nonce action にも織り込む）。 */
	protected const DISMISS_COUNT_ARG = 'affilicard_dismissed_count';

	/** 現在の管理画面が affilicard 商品 CPT 配下（一覧/編集/設定/ジョブ一覧）か。 */
	protected static function isAffilicardScreen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( null === $screen ) {
			return false;
		}
		return isset( $screen->post_type ) && 'affilicard_product' === $screen->post_type;
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
	protected static function dismissUrl( string $action, int $count ): string {
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
	protected static function dismiss( string $action, string $meta ): void {
		// 件数は URL から取る（表示した値）。nonce action にも同じ値が入っているので、
		// 書き換えられていれば check_admin_referer() が弾く。
		//
		// **`?count[]=1` のような配列も明示のガード無しで安全に 0 になる。**
		// wp_unslash() は map_deep() で配列をそのまま返し、sanitize_text_field() は
		// _sanitize_text_fields() の冒頭（wp-includes/formatting.php）で配列・オブジェクトを
		// '' にする——これは 'sanitize_text_field' フィルタより前なので差し替えられない。
		// '' も '0' も (int) で 0 になるため、is_scalar() を足しても結果は変わらない。
		// ここを「素通りする」ように読み替えて短絡させないこと。
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

	/**
	 * 商品 post ID の一覧を編集画面へのリンクとして出す。
	 *
	 * @param list<int> $ids 控えてある post ID。
	 * @param int       $cap 控える上限（達していればその旨を添える）。
	 */
	protected static function renderPostLinks( array $ids, int $cap ): void {
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

		// **件数の引き算はしない。** 通知が出す件数は事象の延べ数、この一覧は商品単位で
		// あり、1 商品が 2 回該当すれば差分は「存在しない商品」を指す。控えている
		// post ID は上限（$cap）で打ち切るため、上限に達したかどうか
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
}
