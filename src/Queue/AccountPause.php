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
 * - 429: 応答に `retryAfterSeconds` があればその秒数。無ければ 1 分 → 2 分 → … と倍々に
 *   延ばし、1 時間で頭打ち（公式は指数バックオフを推奨）。
 * - 403（適格販売の不足）: 6 時間ごと。公式では売上が出荷されれば 2 日以内に戻る。
 *
 * ## 「1 回の停止」の区切り
 *
 * 取得に成功するまでを 1 回の停止とみなす。停止の時刻が過ぎて再開した最初の呼び出しが
 * また 429 なら、同じ停止の続き（倍々の段を 1 つ進め、通知も送り直さない）。成功したら
 * 記録を消す。
 *
 * option に保存する（autoload しない）。サイト固有の値は持たない。
 */
final class AccountPause {

	public const OPTION_PREFIX = 'affilicard_account_pause_';

	public const REASON_RATE_LIMITED = 'rate_limited';
	public const REASON_NOT_ELIGIBLE = 'not_eligible';

	/** 429 で retryAfterSeconds が無いときの最初の待ち（秒）。 */
	private const BASE_DELAY_SECONDS = 60;

	/** 429 の待ちの上限（秒）。 */
	private const MAX_DELAY_SECONDS = 3600;

	/** 403（適格販売の不足）のときに確かめ直す間隔（秒）。 */
	private const NOT_ELIGIBLE_DELAY_SECONDS = 21600;

	/**
	 * 停止中なら再開してよい時刻（UNIX 秒）、停止していなければ 0。
	 */
	public static function until( string $account, int $now ): int {
		$record = self::get( $account );
		if ( null === $record ) {
			return 0;
		}
		return $record['until'] > $now ? $record['until'] : 0;
	}

	/**
	 * 保存している停止の記録。無ければ null（時刻が過ぎていても、成功するまでは残る）。
	 *
	 * @return array{until: int, reason: string, since: int, streak: int, notified: bool}|null
	 */
	public static function get( string $account ): ?array {
		$raw = get_option( self::OPTION_PREFIX . $account, null );
		if ( ! is_array( $raw ) || ! isset( $raw['until'] ) ) {
			return null;
		}
		return array(
			'until'    => (int) $raw['until'],
			'reason'   => self::REASON_NOT_ELIGIBLE === ( $raw['reason'] ?? '' ) ? self::REASON_NOT_ELIGIBLE : self::REASON_RATE_LIMITED,
			'since'    => (int) ( $raw['since'] ?? 0 ),
			'streak'   => max( 0, (int) ( $raw['streak'] ?? 0 ) ),
			'notified' => (bool) ( $raw['notified'] ?? false ),
		);
	}

	/**
	 * 429 を受けた。retryAfterSeconds（正の値）があればそれに従い、無ければ倍々に延ばす。
	 *
	 * @return array{until: int, reason: string, since: int, streak: int, notified: bool}
	 */
	public static function recordRateLimited( string $account, int $now, ?int $retryAfterSeconds = null ): array {
		$previous = self::get( $account );
		$streak   = ( $previous['streak'] ?? 0 ) + 1;
		$delay    = null !== $retryAfterSeconds && $retryAfterSeconds > 0
			? min( $retryAfterSeconds, self::MAX_DELAY_SECONDS )
			: min( self::MAX_DELAY_SECONDS, self::BASE_DELAY_SECONDS * ( 2 ** min( $streak - 1, 10 ) ) );
		return self::store( $account, $now, $now + $delay, self::REASON_RATE_LIMITED, $streak, $previous );
	}

	/**
	 * 403（適格販売の不足）を受けた。6 時間後に確かめ直す。
	 *
	 * @return array{until: int, reason: string, since: int, streak: int, notified: bool}
	 */
	public static function recordNotEligible( string $account, int $now ): array {
		$previous = self::get( $account );
		$streak   = ( $previous['streak'] ?? 0 ) + 1;
		return self::store( $account, $now, $now + self::NOT_ELIGIBLE_DELAY_SECONDS, self::REASON_NOT_ELIGIBLE, $streak, $previous );
	}

	/**
	 * 取得に成功した。停止の記録を消す（1 回の停止の終わり）。記録が無ければ何も書かない。
	 */
	public static function recordSuccess( string $account ): void {
		if ( null !== self::get( $account ) ) {
			delete_option( self::OPTION_PREFIX . $account );
		}
	}

	/**
	 * @param array{until: int, reason: string, since: int, streak: int, notified: bool}|null $previous
	 * @return array{until: int, reason: string, since: int, streak: int, notified: bool}
	 */
	private static function store( string $account, int $now, int $until, string $reason, int $streak, ?array $previous ): array {
		$record = array(
			'until'    => $until,
			'reason'   => $reason,
			'since'    => null !== $previous && $previous['since'] > 0 ? $previous['since'] : $now,
			'streak'   => $streak,
			'notified' => null !== $previous && $previous['notified'],
		);
		if ( ! $record['notified'] ) {
			$record['notified'] = self::notify( $account, $record );
		}
		update_option( self::OPTION_PREFIX . $account, $record, false );
		return $record;
	}

	/**
	 * 停止に入ったことを WP の管理者に 1 回だけメールで知らせる（同じ停止の間は送らない）。
	 * `affilicard_notify_account_pause` フィルタ（引数: 既定値 true・account・理由）で false を返すと送らない。
	 *
	 * @param array{until: int, reason: string, since: int, streak: int, notified: bool} $record
	 * @return bool 通知を済ませた（送った・送らない設定だった）なら true
	 */
	private static function notify( string $account, array $record ): bool {
		if ( ! (bool) apply_filters( 'affilicard_notify_account_pause', true, $account, $record['reason'] ) ) {
			return true;
		}
		$to = (string) get_option( 'admin_email', '' );
		if ( '' === $to ) {
			return false;
		}
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
			__( "%1\$s の API 呼び出しを一時停止しました。\n\n理由: %2\$s\n再開予定: %3\$s（その後も上限が続けば、自動で停止を延ばします）\n\n止めている間は、この account の価格を取り直しません。表示中の価格は、取得から 24 時間を過ぎると表示されなくなります（購入リンクは残ります）。\n状況は 管理画面 → affilicard → 更新キュー で確認できます。\n\nこのメールは停止に入ったときに 1 回だけ送ります。", 'affilicard' ),
			$account,
			$reason,
			wp_date( 'Y-m-d H:i', $record['until'] )
		);
		return (bool) wp_mail( $to, $subject, $body );
	}
}
