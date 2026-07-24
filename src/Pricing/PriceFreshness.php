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
	 * 掃引（sweep）の再取得判定：再取得を試みるべきか。
	 *
	 * last_fetched_at（成功/失敗を問わず毎試行で記録される最終試行時刻）＋
	 * platform の priceTtlHours をクールダウンとして使う。last_verified_at
	 * （成功時刻）ベースの isPriceDisplayable とは独立の判定であり、失敗が
	 * 続いている listing でも「直近の試行から TTL 経過するまでは再投入しない」
	 * ことで、掃引のたびに際限なく再エンキューされる（perpetual retry）事態を防ぐ。
	 *
	 * @param array<string, mixed> $listing
	 */
	public static function needsRefetch( array $listing, ?PlatformDefinition $platform, int $nowTs ): bool {
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
		return ( $nowTs - $fetchedTs ) > $ttl;
	}
}
