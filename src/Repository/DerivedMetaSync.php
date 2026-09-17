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
 * **積めなかったときは option に記録する。** 再試行を積めれば AS の一覧が記録に
 * なるが、積めなかった瞬間にこの失敗は完全に不可視になる——`rest_after_insert` の
 * 戻り値を見る者はおらず、AS にもアクションが無い。`error_log()` は本番の
 * `WP_DEBUG_LOG` が off で何処にも出ないため記録として数えない。件数と post ID を
 * option へ残し、{@see \Affilicard\Admin\DerivedMetaSyncNotice} が管理画面に出す
 * （直し方は「その商品を開いて保存し直す」なので、商品を名指しできる必要がある）。
 * 再試行アクションの中（{@see self::run()}）で積めなかった場合は例外を投げる——
 * そちらは AS が failed アクションとして記録するため、記録の口は既にある。
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
	 * 再投入できなかった（＝ミラーが古いまま、再試行も残らなかった）延べ件数。
	 *
	 * **これは「記録」であって統計ではない。** 1 件でも積まれていれば、どこかの商品の
	 * extid ミラーが listings と食い違っている。放置すると自動作成が既存商品を
	 * 見落として重複を作るため、管理画面の通知
	 * （{@see \Affilicard\Admin\DerivedMetaSyncNotice}）がこの値を読んで運用へ出す。
	 */
	public const OPTION_UNSYNCED_COUNT = 'affilicard_derived_meta_unsynced_count';

	/**
	 * 上記が起きた商品の post ID（重複なし・先頭 {@see self::UNSYNCED_POST_IDS_CAP} 件）。
	 *
	 * 件数だけでは運用は何もできない——「1 件ずれています」と言われても、どの商品を
	 * 開いて保存し直せばよいか分からない。通知から編集画面へ辿れるように控える
	 * （{@see \Affilicard\Upgrade\PluginUpgrade::OPTION_MIGRATION_FAILED_POST_IDS} と同じ流儀）。
	 */
	public const OPTION_UNSYNCED_POST_IDS = 'affilicard_derived_meta_unsynced_post_ids';

	/** 控える post ID の上限（option の肥大を防ぐ歯止め）。 */
	public const UNSYNCED_POST_IDS_CAP = 50;

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
		if ( self::schedule( $postId, 2 ) ) {
			return;
		}

		// **積めなかったら、この失敗は何処にも残らない。** ここは REST の
		// `rest_after_insert` の中で、戻り値を見る呼び出し側も、投げても拾う相手も
		// いない（投稿の保存自体は既に終わっており、派生データの作り直しを理由に
		// 応答を落とすのは筋が違う。クラス PHPDoc 参照）。AS にもアクションが残らない。
		// error_log() は本番の WP_DEBUG_LOG が off で何処にも出ないため、記録として
		// 数えられない——運用が実際に見る場所へ残す。
		self::recordUnsynced( $postId );
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
	 * @throws ProductLockUnavailable 上限まで試して同期できなかったとき、または次の試行を積めなかったとき（AS が failed として記録する）.
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

		if ( self::schedule( $post_id, $attempt + 1 ) ) {
			return;
		}

		// **積めなかったのに黙って戻ると、このアクションは「成功」として完了する。**
		// ミラーは古いまま・次の試行は無い・AS の一覧は緑、で失敗が何処にも残らない。
		// 投げれば AS が failed アクションとして記録する（{@see self::run()} が上限に
		// 達したときと同じ扱い——どちらも「ミラーが古いまま、もう誰も直しに来ない」で
		// 結末は同じである）。
		$message = sprintf(
			'affilicard: 商品 %1$d の extid ミラー同期（%2$d 回目）を積めず、再試行が残らなかった。',
			$post_id,
			$attempt + 1
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- HTML 出力ではなく Action Scheduler のログに残る例外メッセージ。埋め込むのは post ID（int）と回数（int）のみ。
		throw new ProductLockUnavailable( $post_id, $message );
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

	/**
	 * 「ミラーを作り直せず、再試行も残らなかった」を運用が見られる形で記録する。
	 *
	 * 件数と post ID を別々の option に持つのは
	 * {@see \Affilicard\Upgrade\PluginUpgrade::giveUpOnProduct()} と同じ理由である——
	 * 通知は「閉じた時点の件数」と比べて出し直すため件数が要り、運用が実際に手を
	 * 動かすには「どの商品か」が要る。post ID は上限で打ち切る（option を育てない）。
	 *
	 * **成功しても消さない。** ミラーがずれた事実は、その商品を保存し直すまで残る。
	 * 消す判断は運用に委ね、通知の dismiss で閉じられるようにしてある。
	 */
	private static function recordUnsynced( int $postId ): void {
		update_option( self::OPTION_UNSYNCED_COUNT, self::unsyncedCount() + 1, false );

		$ids = self::unsyncedPostIds();
		if ( in_array( $postId, $ids, true ) || count( $ids ) >= self::UNSYNCED_POST_IDS_CAP ) {
			return;
		}

		$ids[] = $postId;
		update_option( self::OPTION_UNSYNCED_POST_IDS, $ids, false );
	}

	/**
	 * 再投入できなかった延べ件数（通知の表示判定が読む）。
	 */
	public static function unsyncedCount(): int {
		return (int) get_option( self::OPTION_UNSYNCED_COUNT, 0 );
	}

	/**
	 * 上記が起きた商品の post ID（最大 {@see self::UNSYNCED_POST_IDS_CAP} 件）。
	 *
	 * @return list<int>
	 */
	public static function unsyncedPostIds(): array {
		$raw = get_option( self::OPTION_UNSYNCED_POST_IDS, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
}
