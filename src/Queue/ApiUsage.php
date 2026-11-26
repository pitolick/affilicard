<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * account ごとの API 呼び出しの日次集計（呼び出し回数・429・403）。
 *
 * 1 日の上限がある API（Amazon Creators API）で、上限に対してどれだけ使っているか、上限に
 * 当たっているかを更新キューの画面で見えるようにするためのもの。日付はサイトのタイムゾーンで
 * 区切り、直近 KEEP_DAYS 日ぶんだけ残す。
 *
 * 集計は「API に届いた呼び出し」だけを数える（通信断・認証前の失敗は API の上限を消費しない）。
 * option に保存する（autoload しない）。
 */
final class ApiUsage {

	public const OPTION_PREFIX = 'affilicard_api_usage_';

	public const KIND_CALLS        = 'calls';
	public const KIND_RATE_LIMITED = 'rate_limited';
	public const KIND_NOT_ELIGIBLE = 'not_eligible';

	private const KINDS = array( self::KIND_CALLS, self::KIND_RATE_LIMITED, self::KIND_NOT_ELIGIBLE );

	private const KEEP_DAYS = 7;

	/**
	 * @param int $count 足す回数（401 でトークンを取り直して再送したときは 2 回ぶん）。
	 */
	public static function record( string $account, string $kind, int $now, int $count = 1 ): void {
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return;
		}
		$days = self::load( $account );
		$date = wp_date( 'Y-m-d', $now );
		if ( ! isset( $days[ $date ] ) ) {
			$days[ $date ] = self::empty();
		}
		$days[ $date ][ $kind ] += max( 1, $count );
		krsort( $days );
		$days = array_slice( $days, 0, self::KEEP_DAYS, true );
		update_option( self::OPTION_PREFIX . $account, $days, false );
	}

	/**
	 * その日（サイトのタイムゾーン）の集計。記録が無ければすべて 0。
	 *
	 * @return array{calls: int, rate_limited: int, not_eligible: int}
	 */
	public static function forDay( string $account, int $now ): array {
		$days = self::load( $account );
		return $days[ wp_date( 'Y-m-d', $now ) ] ?? self::empty();
	}

	/**
	 * @return array<string, array{calls: int, rate_limited: int, not_eligible: int}>
	 */
	private static function load( string $account ): array {
		$raw = get_option( self::OPTION_PREFIX . $account, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$days = array();
		foreach ( $raw as $date => $counts ) {
			if ( ! is_string( $date ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! is_array( $counts ) ) {
				continue;
			}
			$row = self::empty();
			foreach ( self::KINDS as $kind ) {
				$row[ $kind ] = max( 0, (int) ( $counts[ $kind ] ?? 0 ) );
			}
			$days[ $date ] = $row;
		}
		return $days;
	}

	/**
	 * @return array{calls: int, rate_limited: int, not_eligible: int}
	 */
	private static function empty(): array {
		return array(
			self::KIND_CALLS        => 0,
			self::KIND_RATE_LIMITED => 0,
			self::KIND_NOT_ELIGIBLE => 0,
		);
	}
}
