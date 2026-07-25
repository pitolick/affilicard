<?php
declare(strict_types=1);

namespace Affilicard\Pricing;

use Affilicard\Platform\PlatformDefinition;

/**
 * 価格をカードに表示してよいか（API 確認済み・鮮度内か）を判定する共有ポリシー。
 *
 * CardRenderer（表示ゲート）と ProductListColumns（警告アイコン）で共用する。
 * 手動 Provider listing は last_verified_at を持たないため常に非表示（規約準拠）。
 */
final class PriceFreshness {

	/**
	 * @param array<string, mixed> $listing
	 */
	public static function isPriceDisplayable( array $listing, ?PlatformDefinition $platform, int $nowTs ): bool {
		if ( null === $platform ) {
			return false;
		}
		$price = isset( $listing['price'] ) ? trim( (string) $listing['price'] ) : '';
		if ( '' === $price ) {
			return false;
		}
		$verified = isset( $listing['last_verified_at'] ) ? trim( (string) $listing['last_verified_at'] ) : '';
		if ( '' === $verified ) {
			return false;
		}
		$verifiedTs = strtotime( $verified );
		if ( false === $verifiedTs ) {
			return false;
		}
		// 鮮度ゲートの目的は「古い（stale）価格を隠す」こと。verified が僅かに未来
		// （age < 0）になるのは、書き込み側（別コンテナ/別マシンの Cron・e-comi 投稿）と
		// 描画側の time() のクロック差で普通に起こり、これは「たった今確認済み＝最もフレッシュ」
		// を意味するため表示すべき。未来を弾くと fresh な価格を誤って隠すので上限のみで判定する。
		$ttl = $platform->priceTtlHours * 3600;
		$age = $nowTs - $verifiedTs;
		return $age <= $ttl;
	}

	/**
	 * 掃引リードのバッファ（時間）。掃引間隔に加算して「表示期限より前倒しで
	 * 再取得を発火させる」余白の既定値。掃引間隔ぶんの遅延に加え、キュー処理
	 * （レート制限つきワーカーの消化）の遅延を吸収するための安全マージン。
	 */
	public const SWEEP_LEAD_BUFFER_HOURS = 2;

	/**
	 * 掃引の再取得リード秒数を算出する。
	 *
	 * 再取得（needsRefetch）を表示期限（isPriceDisplayable の priceTtlHours）より
	 * この秒数だけ前倒しで発火させることで、価格が 24h（規約上の表示上限）に達する前に
	 * 再取得・再確認を完了させ、正常運用で価格が途切れないようにする。リードは
	 * 「掃引間隔 + バッファ」＝次の掃引までの待ち＋キュー消化の遅延を見込む。
	 *
	 * 実際の下限クランプ（priceTtlHours の 1/2 未満に再取得を早めない＝API 呼び出しを
	 * 2 倍より増やさない）は needsRefetch 側で行うため、ここでは純粋にリードを返す。
	 */
	public static function sweepLeadSeconds( int $refreshIntervalHours, int $bufferHours = self::SWEEP_LEAD_BUFFER_HOURS ): int {
		return max( 0, $refreshIntervalHours + $bufferHours ) * 3600;
	}

	/**
	 * 掃引（sweep）の再取得判定：再取得を試みるべきか。
	 *
	 * last_fetched_at（成功/失敗を問わず毎試行で記録される最終試行時刻）＋
	 * platform の priceTtlHours をクールダウンとして使う。last_verified_at
	 * （成功時刻）ベースの isPriceDisplayable とは独立の判定であり、失敗が
	 * 続いている listing でも「直近の試行から TTL 経過するまでは再投入しない」
	 * ことで、掃引のたびに際限なく再エンキューされる（perpetual retry）事態を防ぐ。
	 *
	 * $leadSeconds（掃引リード）: 再取得のしきい値を priceTtlHours より前倒しする秒数。
	 * 表示期限（isPriceDisplayable）は priceTtlHours（＝各 PF の規約上限 24h）のまま維持し、
	 * 再取得だけを早めることで、価格が表示期限に達する前に再確認を終わらせ、正常運用で
	 * 価格が途切れないようにする（Amazon Creators API/楽天/DMM とも価格は取得後 24h まで表示可）。
	 * 過剰な API 呼び出しを避けるため、しきい値は priceTtlHours の 1/2 未満には下げない
	 * （＝再取得頻度は表示 TTL の 2 倍までにクランプ）。$leadSeconds 既定 0 は従来挙動（前倒しなし）。
	 *
	 * @param array<string, mixed> $listing
	 */
	public static function needsRefetch( array $listing, ?PlatformDefinition $platform, int $nowTs, int $leadSeconds = 0 ): bool {
		if ( null === $platform ) {
			return true;
		}
		$fetched = isset( $listing['last_fetched_at'] ) ? trim( (string) $listing['last_fetched_at'] ) : '';
		if ( '' === $fetched ) {
			return true;
		}
		$fetchedTs = strtotime( $fetched );
		if ( false === $fetchedTs ) {
			return true;
		}
		$ttl = $platform->priceTtlHours * 3600;
		// 表示期限（$ttl）よりリード分だけ手前で再取得を発火。ただし $ttl/2 未満には
		// 下げない（API 呼び出しを表示 TTL の 2 倍より増やさないための下限クランプ）。
		$threshold = max( intdiv( $ttl, 2 ), $ttl - max( 0, $leadSeconds ) );
		return ( $nowTs - $fetchedTs ) > $threshold;
	}
}
