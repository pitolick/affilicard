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
	 * 再取得が必要か（stale か）を判定する。フレッシュな価格の再 fetch を避けるための鮮度スキップ用。
	 *
	 * @param array<string, mixed> $listing
	 */
	public static function isStale( array $listing, ?PlatformDefinition $platform, int $nowTs ): bool {
		if ( null === $platform ) {
			return true;
		}
		$price = isset( $listing['price'] ) ? trim( (string) $listing['price'] ) : '';
		if ( '' === $price ) {
			return true;
		}
		$verified = isset( $listing['last_verified_at'] ) ? trim( (string) $listing['last_verified_at'] ) : '';
		if ( '' === $verified ) {
			return true;
		}
		$verifiedTs = strtotime( $verified );
		if ( false === $verifiedTs ) {
			return true;
		}
		$ttl = $platform->priceTtlHours * 3600;
		return ( $nowTs - $verifiedTs ) > $ttl;
	}
}
