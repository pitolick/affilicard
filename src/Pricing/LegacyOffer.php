<?php
declare(strict_types=1);

namespace Affilicard\Pricing;

/**
 * v3 以前の flat な listing（取得結果フィールドが listing 直下に並ぶ形）を
 * offers[0] 相当の 1 件へ写すだけの純粋な変換。
 *
 * **この写像を持つ場所を 1 箇所に固定するためのクラスである。** 同じ変換が
 * 3 箇所で必要になる:
 *
 * 1. 移行バッチ（{@see \Affilicard\Upgrade\PluginUpgrade::migrateListingToOffers()}）
 * 2. 保存時の畳み込み（{@see \Affilicard\Rest\ProductSchema::sanitizeListings()}）——
 *    外部の投稿パイプラインが flat のまま書いてくる互換経路
 * 3. 描画時のフォールバック（{@see \Affilicard\Renderer\CardRenderer}）——
 *    移行バッチが当該商品に到達するまでの窓でカードを空にしないため
 *
 * 3 箇所が別々にフィールド一覧を持つと、片方だけ直したときに「移行では拾うが
 * 描画では落ちる」といった非対称が生まれる。とりわけ `fetch_error`（文言）→
 * `fetch_status`（コード）の写像は、落とすと「失敗しているのに成功として
 * 扱われる」形で沈黙するため、必ず同じ関数を通す。
 *
 * WP 関数を一切呼ばない（配列を受けて配列を返すだけ）。
 */
final class LegacyOffer {

	/**
	 * flat な取得結果フィールドを 1 つでも持っているか（空文字は「持たない」）。
	 *
	 * 設定系フィールド（platform / enabled 等）しか持たない listing に対して
	 * 空の offer を作らないための番人。
	 *
	 * @param array<string, mixed> $listing
	 */
	public static function hasFlatFetchFields( array $listing ): bool {
		foreach ( array( 'external_id', 'regular_url', 'affiliate_url', 'price', 'image_url', 'search_key' ) as $key ) {
			if ( isset( $listing[ $key ] ) && '' !== (string) $listing[ $key ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * flat な listing から offer 1 件を組み立てる。
	 *
	 * `fetch_error`（v3 以前が保存していた日本語の文言）は
	 * {@see FetchStatus::fromLegacyMessage()} でコードへ写す。ここを飛ばすと
	 * `fetch_status` が空（＝成功）になり、恒久失敗していた購入リンクが
	 * 「取得成功」として振る舞う。
	 *
	 * @param array<string, mixed> $listing
	 * @return array<string, mixed>
	 */
	public static function toOffer( array $listing ): array {
		return array(
			'display_order'    => isset( $listing['display_order'] ) ? (int) $listing['display_order'] : OfferSelector::DEFAULT_ORDER,
			'external_id'      => isset( $listing['external_id'] ) ? (string) $listing['external_id'] : '',
			'regular_url'      => isset( $listing['regular_url'] ) ? (string) $listing['regular_url'] : '',
			'affiliate_url'    => isset( $listing['affiliate_url'] ) ? (string) $listing['affiliate_url'] : '',
			'price'            => isset( $listing['price'] ) ? (string) $listing['price'] : '',
			'list_price'       => isset( $listing['list_price'] ) ? (string) $listing['list_price'] : '',
			'badge'            => isset( $listing['badge'] ) ? (string) $listing['badge'] : '',
			'image_url'        => isset( $listing['image_url'] ) ? (string) $listing['image_url'] : '',
			'search_key'       => isset( $listing['search_key'] ) ? (string) $listing['search_key'] : '',
			'fetch_status'     => self::resolveFetchStatus( $listing ),
			'last_fetched_at'  => isset( $listing['last_fetched_at'] ) ? (string) $listing['last_fetched_at'] : '',
			'last_verified_at' => isset( $listing['last_verified_at'] ) ? (string) $listing['last_verified_at'] : '',
		);
	}

	/**
	 * 既に `fetch_status` を持っていればそれを使い、無ければ旧 `fetch_error` の
	 * 文言から写す。
	 *
	 * @param array<string, mixed> $listing
	 */
	private static function resolveFetchStatus( array $listing ): string {
		if ( isset( $listing['fetch_status'] ) && '' !== (string) $listing['fetch_status'] ) {
			return (string) $listing['fetch_status'];
		}
		return FetchStatus::fromLegacyMessage( isset( $listing['fetch_error'] ) ? (string) $listing['fetch_error'] : '' );
	}
}
