<?php
declare(strict_types=1);

namespace Affilicard\Repository;

/**
 * {@see ProductRepository::syncDerivedMeta()} がロックを取れず見送った extid ミラー同期を、
 * Action Scheduler で積み直す再試行。
 *
 * **なぜ要るか。** ブロックエディタのサイドバー保存はコアの `wp/v2` meta 経路で listings を
 * 書き、`rest_after_insert_affilicard_product` の後始末として派生 meta を作り直す。この
 * 同期は META_LISTINGS の read-modify-write なので {@see ListingLock} の中でしかできず、
 * ロックを取れなければ**やらない**（古い写しで先着のミラーを巻き戻さないため）。だが
 * ミラーは {@see ProductRepository::findByExternalId()} の索引そのもので、古いまま放置すると
 * 自動作成が既存商品を見落として重複を作る。**やらない**と**放置する**のあいだを埋めるのが
 * ここで、「今はできなかった」を登録済みの仕事に変える。
 *
 * **黙って消えない。** 積んだ再試行は Action Scheduler の「予定されたアクション」画面に
 * 出る。再試行を使い切った場合はハンドラが {@see ProductLockUnavailable} を投げ、AS が
 * failed アクションとして記録する。**これは単体テストでは確かめられない**（AS の
 * ランナーはテスト環境に存在しない）ので、同梱している実装を読んで確認した——
 * `vendor/woocommerce/action-scheduler/classes/abstracts/ActionScheduler_Abstract_QueueRunner.php`
 * の `process_action()` が `catch ( Throwable $e )` で Exception へ包み直し、
 * `handle_action_error()` へ渡している。どちらの状態も運用者が一覧で見られる。
 *
 * **投稿の保存そのものは止めない。** ここで扱うのは派生データ（listings の写し）の作り直し
 * だけで、listings 本体はコアが既に保存し終えている。だから REST の応答を 409 にはせず、
 * 裏で直す。listings 本体を書けなかった {@see ProductRepository::saveMeta()} 側とは、
 * 失うものが違うので伝え方も変える。
 */
final class DerivedMetaSync {

	/** 再試行アクションのフック名。 */
	public const HOOK = 'affilicard_sync_derived_meta';

	/**
	 * 再試行アクションの group。
	 *
	 * 価格更新のキュー（`affilicard-{account}`）とは別にする。あちらは account 単位の
	 * レート制限が付く枠で、こちらは外部 API を一切叩かない meta の整合取りなので、
	 * 同じ枠に混ぜると深さ集計（depth cap）まで巻き込む。
	 */
	public const GROUP = 'affilicard-derived-meta';

	/**
	 * 同じ商品に対して試みる回数の上限（最初の `rest_after_insert` を 1 回目と数える）。
	 *
	 * {@see ListingLock::TIMEOUT} は 10 秒なので、1 回の失敗は「同じ商品を 10 秒ふさぐ
	 * 競合が実在した」を意味する。それが {@see self::RETRY_DELAY} 間隔で 5 回とも続くのは
	 * 競合ではなく故障（握ったまま返さないセッション等）であり、積み直しを続けるより
	 * failed として見せた方がよい。
	 */
	public const MAX_ATTEMPTS = 5;

	/** 再試行までの待ち時間（秒）。10 秒待って取れなかった相手が抜けるだけの間を置く。 */
	public const RETRY_DELAY = 60;

	/**
	 * 再試行アクションのハンドラを配線する。
	 *
	 * これが無いと積んだアクションは AS 上に滞留したまま一切実行されない。
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ), 10, 2 );
	}

	/**
	 * `rest_after_insert_affilicard_product` の後始末。
	 *
	 * 同期できなければ 1 回目の失敗として再試行を積む。
	 */
	public static function afterRestSave( int $postId ): void {
		if ( ( new ProductRepository() )->syncDerivedMeta( $postId ) ) {
			return;
		}

		// この呼び出し自体が 1 回目。次は 2 回目である。
		self::schedule( $postId, 2 );
	}

	/**
	 * 再試行アクション本体。
	 *
	 * AS は `do_action_ref_array( $hook, array_values( $args ) )` で args を位置引数へ
	 * 展開するため、引数は `array( 'post_id' => ..., 'attempt' => ... )` の順で届く。
	 * 型は緩く受けて中でキャストする（AS から来る値は DB 経由の JSON で、int とは限らない）。
	 *
	 * @param mixed $postId  対象の投稿 ID。
	 * @param mixed $attempt この実行が何回目か（最初の rest_after_insert が 1 回目）。
	 * @throws ProductLockUnavailable 上限まで試して同期できなかったとき（AS が failed として記録する）.
	 */
	public static function run( $postId = 0, $attempt = 1 ): void {
		$post_id = (int) $postId;
		if ( $post_id <= 0 ) {
			return;
		}
		$attempt = max( 1, (int) $attempt );

		if ( ( new ProductRepository() )->syncDerivedMeta( $post_id ) ) {
			return;
		}

		if ( $attempt >= self::MAX_ATTEMPTS ) {
			$message = sprintf(
				'affilicard: 商品 %1$d の extid ミラー同期を %2$d 回試みてロックを取得できなかった。',
				$post_id,
				self::MAX_ATTEMPTS
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- HTML 出力ではなく Action Scheduler のログに残る例外メッセージ。埋め込むのは post ID（int）と定数のみ。
			throw new ProductLockUnavailable( $post_id, $message );
		}

		self::schedule( $post_id, $attempt + 1 );
	}

	/**
	 * 次の試行を積む。
	 *
	 * `$unique = true` で積むが、args に `attempt` を含めるため世代どうしは重複扱いに
	 * ならない（AS の unique 判定は pending だけでなく in-progress のアクションも重複と
	 * みなすため、同じ args で積み直す設計だと次の世代が落ちる）。同じ世代を 2 つ積むのは
	 * 防ぎたいので unique は活かす。
	 *
	 * @return bool 積めたら true。
	 */
	public static function schedule( int $postId, int $attempt ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			// Action Scheduler は Plugin::bootInstance() が plugins_loaded より前に同期
			// ロードするため本番では必ず存在する。存在しないのは単体テスト環境だけだが、
			// 万一の本番欠落で黙って消えないようログには残す。
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 再試行を積めない＝AS 側に痕跡が一切残らないため、運用が気づける唯一の経路。
			error_log(
				sprintf( 'affilicard: 商品 %d の extid ミラー同期を再投入できない（Action Scheduler が無い）。', $postId )
			);
			return false;
		}

		$action_id = (int) as_schedule_single_action(
			time() + self::RETRY_DELAY,
			self::HOOK,
			array(
				'post_id' => $postId,
				'attempt' => $attempt,
			),
			self::GROUP,
			true
		);

		if ( $action_id <= 0 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 投入失敗は AS 側で完全に不可視になるため、運用が気づけるようログに残す。
			error_log(
				sprintf( 'affilicard: 商品 %1$d の extid ミラー同期（%2$d 回目）を積めなかった。', $postId, $attempt )
			);
			return false;
		}

		return true;
	}
}
