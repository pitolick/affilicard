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
		$ttl = $platform->priceTtlHours * 3600;
		$age = $nowTs - $verifiedTs;
		return $age >= 0 && $age <= $ttl;
	}
}
