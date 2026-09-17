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
 *
 * ## 残る競合: この経路は {@see \Affilicard\Repository\ListingLock} の外にある
 *
 * META_LISTINGS を書く 3 つの入口のうち、{@see ProductRepository::updateListing()} /
 * {@see ProductRepository::updateListingOffer()} / {@see ProductRepository::saveMeta()} は
 * ロックの中で読み書きする。**サイドバー保存だけはロックの外にある。** 書き手がコアの
 * `WP_REST_Post_Meta_Fields::update_value()` で、こちらから囲めないためである。
 * 結果として「価格更新が書いた価格を、サイドバーの保存が上書きする」競合が残る。
 *
 * **`rest_pre_insert` で取って `rest_after_insert` で返す形は採らない。** 検討した
 * うえで見送った。理由は 4 つある。
 *
 * 1. **クリティカルセクションが `wp_update_post()` をまたぐ。** そのあいだに `save_post` /
 *    `wp_after_insert_post` と他プラグインのハンドラが走る。ListingLock は「メモリ上の
 *    変換と meta の読み書き」だけを囲む前提の待ち時間（{@see ListingLock::TIMEOUT}）で
 *    設計されており、サーバ全体で共有される MySQL 名前付きロックを第三者のフックを
 *    またいで握るのは、この前提を正面から破る。
 * 2. **失敗経路で解放が走らない。** `WP_REST_Posts_Controller::update_item()` は
 *    `rest_after_insert_{post_type}` に到達するまでに少なくとも 4 つの早期 return を
 *    持つ（`wp_update_post()` / `handle_terms()` / `update_value()` /
 *    `update_additional_fields_for_object()` の WP_Error）。どれを踏んでも解放フックは
 *    走らない。MySQL はコネクションが閉じればロックを解放するので漏れはリクエスト内に
 *    留まるが、そのリクエストのあいだ当該商品の価格更新はロック取得に失敗し続ける。
 * 3. **ListingLock を acquire/release に割る必要がある。** finally で必ず返す——という
 *    このクラスの存在理由そのものを捨て、対で呼ぶ責任を呼び出し側に配ることになる。
 * 4. **それで塞げる窓が実質的に無い。** コアの meta 書き込みはリクエスト内の
 *    read-modify-write ではない。エディタは**画面を開いた時点で読んだ** listings を
 *    まるごと送り返す。つまり価格が巻き戻る窓は「画面を開いてから保存するまで」（分の
 *    オーダー）であって、「`rest_pre_insert` から meta 書き込みまで」（ミリ秒）ではない。
 *    リクエスト内だけを直列化しても、古い写しが勝つことに変わりはない。
 *
 * **残った競合は次の掃引で自己修復する。** サイドバーは価格と一緒に**古い
 * `last_fetched_at` も書き戻す**ため、上書きされた購入リンクは
 * {@see \Affilicard\Pricing\PriceFreshness::needsRefetch()} で「古い」と判定される。
 * さらに META_LISTINGS への書き込み自体が
 * {@see \Affilicard\Queue\OfferPromotionTrigger::onListingsSaved()} を起こすため、
 * 実際には次の掃引を待たずその場で再取得が積まれる。
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
