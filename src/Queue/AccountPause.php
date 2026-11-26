<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * account（認証情報の共有単位）ごとの「API の上限に当たったので一時的に呼ばない」状態。
 *
 * ## なぜ要るか
 *
 * Amazon Creators API は 1 日の呼び出し回数に上限があり（最初の 30 日は 1 日 8,640 回、
 * 以降は直近 30 日の売上に比例）、超えると 429（ThrottleException）を返す。直近 30 日の
 * 適格販売が足りないと 403（AssociateNotEligible）で使えなくなる。どちらも「待てば戻る」
 * 一時失敗だが、従来は 1 件ずつの再試行ジョブ（最大 5 回）へ回していたため、上限の最中に
 * 呼び出しがまとめ取得のときの何倍にも膨らみ、回復をかえって遅らせていた。
 *
 * ここに「いつまで止めるか」を記録し、Provider（呼ぶ前に確かめる）・ハンドラ（ジョブを
 * 後ろへ積み直す）・掃引（その account を積まない）が同じ値を見る。
 *
 * ## 止める長さ
 *
 * - 429: 応答に `retryAfterSeconds` があればその秒数（1 分〜1 時間に収める）。無ければ 1 分 → 2 分 → …
 *   と倍々に延ばし、1 時間で頭打ち（公式は指数バックオフを推奨）。1 分より短くしないのは、
 *   応答を待つ間に停止が切れて、同じまとめ取得の残りが 1 件ずつのジョブへ流れるのを防ぐため。
 *   1 日の上限で長い `retryAfterSeconds` が返っても 1 時間ごとに 1 回確かめるだけなので、消費は小さい。
 * - 403（適格販売の不足）: 6 時間ごと。公式では売上が出荷されれば 2 日以内に戻る。
 *
 * 起点は応答を受け取った時刻（`$now`）。呼ぶ前の時刻を使うと、応答に時間がかかったぶん停止が短くなる。
 *
 * ## 「1 回の停止」の区切り
 *
 * 取得に成功するまでを 1 回の停止とみなす。停止の時刻が過ぎて再開した最初の呼び出しが
 * また 429 なら、同じ停止の続き（倍々の段を 1 つ進める）。ただし、理由が変わったとき（429 → 403）と、
 * 停止の時刻から 1 日以上たって記録だけが残っていたとき（再開後の呼び出しが通信断などで終わり、
 * そのまま途絶えた）は、新しい停止として数え直す。
 *
 * 成功したら記録を消す。ただし、記録より前に始まったリクエストの成功では消さない（応答の遅い
 * 呼び出しの 200 が、その後に別の呼び出しが受けた 429 の停止を消してしまわないように）。
 *
 * ## 通知
 *
 * 停止に入ったら WP の管理者へメールする。同じ account・同じ理由では 24 時間に 1 回まで
 * （上限の付近で 429 と 200 を繰り返しても、そのたびに送らないため）。送った時刻は停止の記録とは
 * 別の option に持つ（成功で停止の記録を消しても残す）。
 *
 * option に保存する（autoload しない）。サイト固有の値は持たない。
 */
final class AccountPause {

	public const OPTION_PREFIX = 'affilicard_account_pause_';

	/** 最後に通知した時刻（理由 => UNIX 秒）を持つ option の接頭辞。 */
	public const NOTIFIED_OPTION_PREFIX = 'affilicard_pause_notified_';

	public const REASON_RATE_LIMITED = 'rate_limited';
	public const REASON_NOT_ELIGIBLE = 'not_eligible';

	/** 429 の最初の待ち・最短の待ち（秒）。 */
	private const BASE_DELAY_SECONDS = 60;

	/** 429 の待ちの上限（秒）。 */
	private const MAX_DELAY_SECONDS = 3600;

	/** 403（適格販売の不足）のときに確かめ直す間隔（秒）。 */
	private const NOT_ELIGIBLE_DELAY_SECONDS = 21600;

	/** 停止の時刻からこれ以上たって残っている記録は、次の失敗で新しい停止として数え直す（秒）。 */
	private const EPISODE_GAP_SECONDS = 86400;

	/** 同じ account・同じ理由で通知を送り直さない間隔（秒）。 */
	private const NOTIFY_COOLDOWN_SECONDS = 86400;

	/**
	 * 停止中なら再開してよい時刻（UNIX 秒）、停止していなければ 0。
	 *
	 * @param bool $fresh リクエスト内のキャッシュを捨てて読み直す。別のワーカー（別のプロセス）が
	 *                    書いた停止を見落とさないため、API を呼ぶ直前の判定で使う。永続オブジェクト
	 *                    キャッシュが無いと、WP は「option が無い」ことまでリクエストの間覚えている。
	 */
	public static function until( string $account, int $now, bool $fresh = false ): int {
		if ( $fresh ) {
			self::forgetCached( self::OPTION_PREFIX . $account );
		}
		$record = self::get( $account );
		if ( null === $record ) {
			return 0;
		}
		return $record['until'] > $now ? $record['until'] : 0;
	}

	/**
	 * option のリクエスト内キャッシュ（値と「無い」という記録の両方）を捨てる。
	 */
	private static function forgetCached( string $key ): void {
		wp_cache_delete( $key, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $key ] ) ) {
			unset( $notoptions[ $key ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * 保存している停止の記録。無ければ null（時刻が過ぎていても、成功するまでは残る）。
	 * updated は最後に記録した時刻。
	 *
	 * @return array{until: int, reason: string, since: int, streak: int, updated: int}|null
	 */
	public static function get( string $account ): ?array {
		$raw = get_option( self::OPTION_PREFIX . $account, null );
		if ( ! is_array( $raw ) || ! isset( $raw['until'] ) ) {
			return null;
		}
		return array(
			'until'   => (int) $raw['until'],
			'reason'  => self::REASON_NOT_ELIGIBLE === ( $raw['reason'] ?? '' ) ? self::REASON_NOT_ELIGIBLE : self::REASON_RATE_LIMITED,
			'since'   => (int) ( $raw['since'] ?? 0 ),
			'streak'  => max( 0, (int) ( $raw['streak'] ?? 0 ) ),
			'updated' => (int) ( $raw['updated'] ?? 0 ),
		);
	}

	/**
	 * 429 を受けた。retryAfterSeconds（正の値）があればそれに従い、無ければ倍々に延ばす。
	 *
	 * @param int $now 応答を受け取った時刻。
	 * @return array{until: int, reason: string, since: int, streak: int, updated: int}
	 */
	public static function recordRateLimited( string $account, int $now, ?int $retryAfterSeconds = null ): array {
		$previous = self::continuing( self::get( $account ), self::REASON_RATE_LIMITED, $now );
		$streak   = ( $previous['streak'] ?? 0 ) + 1;
		$delay    = null !== $retryAfterSeconds && $retryAfterSeconds > 0
			? max( self::BASE_DELAY_SECONDS, min( $retryAfterSeconds, self::MAX_DELAY_SECONDS ) )
			: min( self::MAX_DELAY_SECONDS, self::BASE_DELAY_SECONDS * ( 2 ** min( $streak - 1, 10 ) ) );
		return self::store( $account, $now, $now + $delay, self::REASON_RATE_LIMITED, $streak, $previous );
	}

	/**
	 * 403（適格販売の不足）を受けた。6 時間後に確かめ直す。
	 *
	 * @param int $now 応答を受け取った時刻。
	 * @return array{until: int, reason: string, since: int, streak: int, updated: int}
	 */
	public static function recordNotEligible( string $account, int $now ): array {
		$previous = self::continuing( self::get( $account ), self::REASON_NOT_ELIGIBLE, $now );
		$streak   = ( $previous['streak'] ?? 0 ) + 1;
		return self::store( $account, $now, $now + self::NOT_ELIGIBLE_DELAY_SECONDS, self::REASON_NOT_ELIGIBLE, $streak, $previous );
	}

	/**
	 * 取得に成功した。停止の記録を消す（1 回の停止の終わり）。
	 *
	 * 記録がこのリクエストの開始より後に書かれていれば消さない（並行して走った別の呼び出しが
	 * その後に 429 / 403 を受けている）。記録が無ければ何も書かない。
	 *
	 * @param int $requestStartedAt 成功したリクエストを送った時刻。
	 */
	public static function recordSuccess( string $account, int $requestStartedAt ): void {
		$record = self::get( $account );
		if ( null === $record || $record['updated'] >= $requestStartedAt ) {
			return;
		}
		delete_option( self::OPTION_PREFIX . $account );
	}

	/**
	 * 前の記録を同じ停止の続きとして扱うなら返し、新しい停止として数え直すなら null。
	 *
	 * @param array{until: int, reason: string, since: int, streak: int, updated: int}|null $previous
	 * @return array{until: int, reason: string, since: int, streak: int, updated: int}|null
	 */
	private static function continuing( ?array $previous, string $reason, int $now ): ?array {
		if ( null === $previous || $previous['reason'] !== $reason ) {
			return null;
		}
		return $previous['until'] + self::EPISODE_GAP_SECONDS < $now ? null : $previous;
	}

	/**
	 * 停止を保存してから通知する（通知に時間がかかっても、その間に他のジョブが停止を見られるように。
	 * 通知で例外が出ても停止は残す）。
	 *
	 * @param array{until: int, reason: string, since: int, streak: int, updated: int}|null $previous
	 * @return array{until: int, reason: string, since: int, streak: int, updated: int}
	 */
	private static function store( string $account, int $now, int $until, string $reason, int $streak, ?array $previous ): array {
		$record = array(
			'until'   => $until,
			'reason'  => $reason,
			'since'   => null !== $previous && $previous['since'] > 0 ? $previous['since'] : $now,
			'streak'  => $streak,
			'updated' => $now,
		);
		update_option( self::OPTION_PREFIX . $account, $record, false );
		if ( null === $previous ) {
			self::maybeNotify( $account, $record, $now );
		}
		return $record;
	}

	/**
	 * 停止に入ったことを WP の管理者にメールで知らせる。同じ account・同じ理由では 24 時間に 1 回まで。
	 * `affilicard_notify_account_pause` フィルタ（引数: 既定値 true・account・理由）で false を返すと送らない。
	 *
	 * @param array{until: int, reason: string, since: int, streak: int, updated: int} $record
	 */
	private static function maybeNotify( string $account, array $record, int $now ): void {
		$key      = self::NOTIFIED_OPTION_PREFIX . $account;
		$notified = get_option( $key, array() );
		$notified = is_array( $notified ) ? $notified : array();
		$last     = (int) ( $notified[ $record['reason'] ] ?? 0 );
		if ( $last > 0 && $now - $last < self::NOTIFY_COOLDOWN_SECONDS ) {
			return;
		}
		if ( ! (bool) apply_filters( 'affilicard_notify_account_pause', true, $account, $record['reason'] ) ) {
			return;
		}
		$to = (string) get_option( 'admin_email', '' );
		if ( '' === $to ) {
			return;
		}
		// 送る前に送った印を付ける（送信に時間がかかる間に、別のジョブが同じ通知を送らないように）。
		$notified[ $record['reason'] ] = $now;
		update_option( $key, $notified, false );
		try {
			$sent  = self::send( $to, $account, $record );
			$error = $sent ? '' : 'wp_mail returned false';
		} catch ( \Throwable $e ) {
			// 通知の失敗で価格更新のジョブを落とさない（停止は保存済み）。
			$error = $e->getMessage();
		}
		if ( '' !== $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 送った印は付けたため 24 時間は送り直さない。失敗に気づけるようログに残す。
			error_log( sprintf( 'affilicard: 一時停止の通知メールを送れませんでした（account=%s, reason=%s）: %s', $account, $record['reason'], $error ) );
		}
	}

	/**
	 * @param array{until: int, reason: string, since: int, streak: int, updated: int} $record
	 * @return bool wp_mail が受け付けたか
	 */
	private static function send( string $to, string $account, array $record ): bool {
		$reason  = self::REASON_NOT_ELIGIBLE === $record['reason']
			? __( 'API の利用条件（直近 30 日の適格販売）を満たしていません。売上が出荷されると 2 日ほどで戻ります。', 'affilicard' )
			: __( 'API の呼び出し回数の上限（レート制限）に達しました。上限が戻ると自動で再開します。', 'affilicard' );
		$subject = sprintf(
			/* translators: 1: site name, 2: account code */
			__( '[%1$s] affilicard: %2$s の価格更新を一時停止しました', 'affilicard' ),
			(string) get_option( 'blogname', '' ),
			$account
		);
		$body = sprintf(
			/* translators: 1: account code, 2: reason, 3: resume time */
			__( "%1\$s の API 呼び出しを一時停止しました。\n\n理由: %2\$s\n再開予定: %3\$s（その後も上限が続けば、自動で停止を延ばします）\n\n止めている間は、この account の価格を取り直しません。表示中の価格は、取得から 24 時間を過ぎると表示されなくなります（購入リンクは残ります）。\n状況は 管理画面 → affilicard → 設定 → 更新キュー で確認できます。\n\nこのメールは、同じ理由では 24 時間に 1 回まで送ります。", 'affilicard' ),
			$account,
			$reason,
			wp_date( 'Y-m-d H:i', $record['until'] )
		);
		return (bool) wp_mail( $to, $subject, $body );
	}
}
