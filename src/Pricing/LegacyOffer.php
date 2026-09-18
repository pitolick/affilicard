<?php
declare(strict_types=1);

namespace Affilicard\Pricing;

use Affilicard\Util\ScalarField;

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
			if ( '' !== ScalarField::string( $listing, $key ) ) {
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
			'display_order'    => OfferSelector::normaliseOrder( $listing['display_order'] ?? null ),
			'external_id'      => ScalarField::string( $listing, 'external_id' ),
			'regular_url'      => ScalarField::string( $listing, 'regular_url' ),
			'affiliate_url'    => ScalarField::string( $listing, 'affiliate_url' ),
			'price'            => ScalarField::string( $listing, 'price' ),
			'list_price'       => ScalarField::string( $listing, 'list_price' ),
			'badge'            => ScalarField::string( $listing, 'badge' ),
			'image_url'        => ScalarField::string( $listing, 'image_url' ),
			'search_key'       => ScalarField::string( $listing, 'search_key' ),
			'fetch_status'     => self::resolveFetchStatus( $listing ),
			'last_fetched_at'  => ScalarField::string( $listing, 'last_fetched_at' ),
			'last_verified_at' => ScalarField::string( $listing, 'last_verified_at' ),
		);
	}

	/**
	 * OfferSelector::select() に渡す offers を listing から取り出す。
	 *
	 * `offers` が非空配列ならそれをそのまま返す。無い・空配列（v3 以前の flat な listing）
	 * なら、この listing 自身を {@see self::toOffer()} で offers[0] 相当へ変換したものを
	 * 1 件返す。変換対象のフィールドを 1 つも持たない listing（設定系フィールドしか無い等）
	 * は空配列を返す。
	 *
	 * 移行バッチ（PluginUpgrade）は「積む」だけなので、v4 のコードが動き始めてから
	 * 当該商品にバッチが到達するまでのあいだ、meta は flat のままである。この窓は
	 * 短いとは限らない——Action Scheduler が止まっているインストール、offer を持たない
	 * listing を飛ばす QueueMaintenance::sweep() のいずれでも恒久化し得る。読み取り側
	 * （表示・カウント）が offers しか見ないと、その間カタログ全体のカードが購入ボタン・
	 * 価格・書影を失う。
	 *
	 * （ゴミ箱の商品は移行の走査対象に含まれる。かつて post_status='any' で走査して
	 * いた頃は「ゴミ箱から復元された商品」もこの窓の原因だったが、
	 * {@see \Affilicard\Upgrade\PluginUpgrade::MIGRATION_POST_STATUSES} で status を
	 * 明示するようになって解消済み。）
	 *
	 * **選択規則はここに持ち込まない。** 合成するのは配列だけで、どの購入リンクを
	 * 使うかは従来どおり OfferSelector::select() が決める（決定者は 1 つ）。
	 * 保存はしない（読み取り時の補完のみ。正規化は移行バッチと ProductSchema が行う）。
	 *
	 * **読み取り側（表示・カウント）専用。** 保存系のフォールバック
	 * （{@see \Affilicard\Rest\ProductSchema::sanitizeOffers()}）は「`offers` キーが
	 * 明示的に空配列で来た＝購入リンクを消す意図」を区別する必要があり、この関数とは
	 * 異なる判定を独自に行う。
	 *
	 * @param array<string, mixed> $listing
	 * @return list<array<string, mixed>>
	 */
	public static function offersWithFallback( array $listing ): array {
		$offers = isset( $listing['offers'] ) && is_array( $listing['offers'] ) ? $listing['offers'] : array();
		if ( array() !== $offers ) {
			return $offers;
		}
		return self::hasFlatFetchFields( $listing ) ? array( self::toOffer( $listing ) ) : array();
	}

	/**
	 * この listing にまだ offers 移行が到達していない（v3 以前の形のまま）か。
	 *
	 * **判定は {@see \Affilicard\Upgrade\PluginUpgrade::migrateListingToOffers()} と
	 * 同一にする。** あちらは `offers` が配列なら「変換済み」としてそのまま返す
	 * （冪等性）。つまり「この listing を移行がこれから変換するつもりかどうか」は
	 * この 1 条件で決まる。片方だけ条件を足すと、移行が変換する気でいる listing を
	 * 「移行済み」と誤判定する（またはその逆）。
	 *
	 * **{@see self::hasFlatFetchFields()} は足さない。** 取得結果フィールドを 1 つも
	 * 持たない listing（設定だけを持つもの）も移行の変換対象で、移行は `offers => []`
	 * を書き足す。ここで「失うものが無いから移行済み扱い」にすると、移行が変換する
	 * つもりの listing を別経路が先に書き換えてよいことになり、判定が 2 つに割れる。
	 *
	 * @param array<string, mixed> $listing
	 */
	public static function isUnmigrated( array $listing ): bool {
		return ! isset( $listing['offers'] ) || ! is_array( $listing['offers'] );
	}

	/**
	 * 既に `fetch_status` を持っていればそれを使い、無ければ旧 `fetch_error` の
	 * 文言から写す。
	 *
	 * 他のフィールドと同じく {@see ScalarField::string()} で読む。`(string)` で直に
	 * キャストすると、配列が入っていたとき「Array to string conversion」の警告を出した
	 * うえで `'Array'` という存在しない取得状態が offer に入る。
	 *
	 * @param array<string, mixed> $listing
	 */
	private static function resolveFetchStatus( array $listing ): string {
		$status = ScalarField::string( $listing, 'fetch_status' );
		if ( '' !== $status ) {
			return $status;
		}
		return FetchStatus::fromLegacyMessage( ScalarField::string( $listing, 'fetch_error' ) );
	}
}
