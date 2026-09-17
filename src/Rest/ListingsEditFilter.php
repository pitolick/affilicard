<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\PostType\ProductPostType;
use Affilicard\Pricing\OfferStatusReset;
use Affilicard\Repository\ProductRepository;
use WP_REST_Request;

/**
 * ブロックエディタ（core-data）からの商品保存に割り込み、身元を訂正された購入リンクの
 * 取得状態を白紙に戻す。
 *
 * **なぜ 2 つ目の割り込み口が要るのか。** 商品サイドバー（ProductSettingsPanel →
 * ListingsEditor）は `useEntityProp` で `wp/v2/affilicard_product` の `meta` を書く。
 * この経路は {@see ProductRepository::saveMeta()} を通らない——コアの
 * `WP_REST_Post_Meta_Fields::update_value()` が `update_post_meta()` を直接呼ぶためで、
 * 運用者が購入リンクの外部 ID を直す主な画面がまさにここである。判定の本体
 * （{@see OfferStatusReset}）は共有し、入口だけを 2 つ持つ。
 *
 * **`rest_pre_insert_{post_type}` に載せる。** コアの
 * `WP_REST_Posts_Controller::update_item()` は
 * `prepare_item_for_database()`（このフィルタが走る）→ `wp_update_post()` →
 * `$this->meta->update_value( $request['meta'], ... )` の順に進むため、ここで
 * リクエストの `meta` を書き換えれば、保存されるのは訂正後の値 1 回だけで済む
 * （保存後に直すと meta を 2 度書くことになる）。保存前の姿もまだ meta に残っている。
 *
 * **価格更新はここを通らない。** {@see \Affilicard\Cron\ListingRefresher} の書き戻しは
 * REST の投稿コントローラを経由せず {@see ProductRepository::updateListingOffer()} を
 * 呼ぶ。だから取得が今書いた `fetch_status` をこのフィルタが消すことはない。
 */
final class ListingsEditFilter {

	/**
	 * `rest_pre_insert_affilicard_product` フィルタ本体。
	 *
	 * @param mixed $prepared コアが組み立てた投稿オブジェクト（更新なら ID を持つ）。
	 * @param mixed $request  受信したリクエスト。
	 * @return mixed $prepared（このフィルタは投稿オブジェクトを変えない）。
	 */
	public static function resetEditedOfferStatus( $prepared, $request ) {
		if ( ! is_object( $prepared ) || ! isset( $prepared->ID ) ) {
			// 新規作成。保存前の身元が無いので「訂正」も起きていない。
			return $prepared;
		}
		if ( ! $request instanceof WP_REST_Request ) {
			return $prepared;
		}

		$meta = $request->get_param( 'meta' );
		if ( ! is_array( $meta ) ) {
			return $prepared;
		}
		$listings = $meta[ ProductPostType::META_LISTINGS ] ?? null;
		if ( ! is_array( $listings ) ) {
			// listings を送っていない保存（本文だけの更新など）。
			return $prepared;
		}

		$meta[ ProductPostType::META_LISTINGS ] = OfferStatusReset::forIdentityChanges(
			ProductRepository::listingsMeta( (int) $prepared->ID ),
			$listings
		);
		$request->set_param( 'meta', $meta );

		return $prepared;
	}
}
