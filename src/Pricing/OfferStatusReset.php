<?php
declare(strict_types=1);

namespace Affilicard\Pricing;

use Affilicard\Util\ScalarField;

/**
 * 身元を書き換えられた購入リンク（offer）の `fetch_status` を白紙へ戻す。
 *
 * **解く問題。** 運用者が誤った `external_id`（無ければ `regular_url`）を訂正しても、
 * その offer は前の身元のときに付いた `fetch_status` をそのまま持ち続ける。それが
 * `terminal` だと {@see \Affilicard\Queue\RefreshHandler::isGivenUp()} が
 * give-up マーカー（(post_id, platform) 単位の transient・3 日）と offer 自身の
 * terminal を AND で見るため、訂正した購入リンクは掃引でも繰り上がりでも投入されず、
 * マーカーが切れるまで数日のあいだ訂正が何も起こさない。前の身元の取得結果は
 * 新しい身元について何も語らないので、身元が変わったら取得状態も白紙に戻す。
 *
 * **give-up マーカー自体は消さない。** isGivenUp() は「今使う購入リンク自身が
 * terminal か」まで見るので、訂正した offer の status を空にすれば、その offer が
 * 選ばれている限り抑止は外れる（取得が成功すれば RefreshHandler::onSuccess() が
 * マーカーを消す）。マーカーを消すと、同じ platform に残る**別の**恒久失敗した
 * 購入リンク（本当に廃盤の SKU）への再取得まで解禁してしまい、cooldown が
 * 節約している API 予算を焼く。保存層がキューの transient を消しに行く結合も避ける。
 *
 * **ここが「運用者/API の編集」だけを通る層であることが要点。**
 * {@see \Affilicard\Rest\ProductSchema::sanitizeOffers()} は listings meta の
 * **すべての**書き込み（{@see \Affilicard\Cron\ListingRefresher} の書き戻しを含む）で
 * 走る。取得は fetch 結果で `regular_url` を正当に書き換えつつ、そのとき自分が
 * 決めた `fetch_status` を一緒に書くため、あちらで白紙化すると取得が今書いた
 * 状態を消してしまう。だから判定は「保存前の offers と突き合わせて、実際に身元が
 * 変わった offer だけ」に限定し、触っていない offer はそのまま通す。
 *
 * **その取得を除外できているのは、置き場所そのものによる。** この層を呼ぶのは
 * {@see \Affilicard\Repository\ProductRepository::saveMeta()} だけで、そこへ来るのは
 * 商品を丸ごと保存する 2 経路——REST（運用者/API の編集）と自動作成（新規作成のみ）——に
 * 限られる。取得の書き戻しは updateListing()／updateListingOffer() から
 * `update_post_meta()` を直接叩き、saveMeta() を通らない。したがって「取得が
 * regular_url と fetch_status を同時に書く」保存はここへ届かず、flat な形で届くことも
 * ない（下の flat 対応を足しても、取得の書き戻しを白紙にする経路は生まれない）。
 *
 * ## 突き合わせるのは身元の「集合」であって行の対応ではない（既知の限界）
 *
 * 判定は「保存後の身元が、保存前の身元の集合に居るか」しか見ない。身元が集合に
 * 残っていても、それが**同じ行に載っている**とは限らない。次の 2 つの編集は集合を
 * 変えないため、ここは何もしない。
 *
 * 1. 購入リンク A の `external_id` を B の値へ打ち替え、元の B を消す
 *    （{@see \Affilicard\Rest\ProductSchema::sanitizeOffers()} は重複した身元を後勝ちで
 *    畳むので、保存後に残るのは 1 行である）
 * 2. A と B の `external_id` を入れ替える
 *
 * どちらも、残った offer が**別の SKU で得た** `fetch_status` を持ち続ける。
 *
 * **それでも offer ごとの安定 ID は導入しない。残る害が有界で、自己修復するためである。**
 *
 * - **抑止は AND である。** {@see \Affilicard\Queue\RefreshHandler::isGivenUp()} は
 *   give-up マーカー（(post_id, platform) 単位の transient・
 *   {@see \Affilicard\Queue\RefreshHandler}::GIVEUP_COOLDOWN＝3 日）と offer 自身の
 *   terminal が揃ったときだけ真を返す。マーカーは恒久失敗が起きたときにしか立たず、
 *   再取得が成功すれば onSuccess() が消す。誤って引き継いだ terminal が再取得を
 *   止められるのは、長くてもマーカーの残り時間（最大 3 日）までである。その後は掃引が
 *   取得し、成功が `fetch_status` を白紙に戻す。
 * - **運用者にはその場の出口がある。** 管理画面の「今すぐ更新／強制更新」
 *   （{@see \Affilicard\Rest\RefreshController} →
 *   {@see \Affilicard\Queue\Enqueuer::enqueueProductListings()}）は isGivenUp() を見ない。
 *   見るのは掃引（{@see \Affilicard\Queue\QueueMaintenance::sweep()}）と繰り上がり
 *   （{@see \Affilicard\Queue\OfferPromotionTrigger}）の 2 経路だけである。
 * - **誤った terminal が他所で壊すものも無い。** `fetch_status` を読むのは
 *   {@see OfferSelector::select()}（fallback_on_terminal は既定 off）・
 *   {@see PriceFreshness::isPriceDisplayable()}（価格を隠す＝安全側）・
 *   {@see \Affilicard\PostType\ProductListColumns}（警告アイコン）で、これを根拠に listing や
 *   offer を消す判断はどこにも無い（棚卸し {@see \Affilicard\Stocktake\StocktakePolicy} は
 *   日付で判定し、手動経路には適用しない）。
 *
 * つまり入れ替えの側は、この層が無かった頃の自己修復（マーカー失効 → 取得 → 成功で
 * 白紙化）へ戻るだけで、悪化はしない。行の対応まで追うには offer ごとの安定 ID が要り、
 * それは新しい保存フィールドの追加（{@see \Affilicard\Rest\ProductSchema} の sanitize
 * 許可リストに足さないと保存時に無言で消える）と、全書き込み経路での採番・既存データの
 * 移行を伴う。配列位置で代用する道は sanitizeOffers() が明示的に退けている（並べ替えや
 * 途中の削除で別の offer を指すため）。有界で自己修復する取りこぼしに対して釣り合わない
 * ので、この層は「単独の訂正を cooldown のあいだ待たせない最適化」に留める。
 */
final class OfferStatusReset {

	/**
	 * 保存前の listings と突き合わせ、身元の変わった offer の `fetch_status` だけを空にする。
	 *
	 * 突き合わせは platform ごとに行う（`external_id` はストア内でしか意味を持たない）。
	 * 保存前の listing が v3 以前の flat な形でも
	 * {@see LegacyOffer::offersWithFallback()} で身元を拾う——拾えないと「既知の身元」の
	 * 集合が空になり、移行前の商品を保存するたびに全 offer の状態を白紙にしてしまう。
	 *
	 * **保存前に存在しない platform の listing には手を出さない。** 新規作成や listing の
	 * 追加では「訂正された身元」という出来事がそもそも起きていない。ここを触ると、
	 * 取得結果を持ち込んで商品を作る外部ツール（投稿パイプライン・自動作成）の
	 * fetch_status を作成のたびに落とすことになる。
	 *
	 * **保存しようとしている listing が flat（移行前）でも判定する。** 保存時に
	 * {@see \Affilicard\Rest\ProductSchema::sanitizeOffers()} が offers[] へ畳むが、
	 * 畳み込みは古い `fetch_status`／`fetch_error` をそのまま新しい offer へ運ぶため、
	 * 素通りさせると移行前の listing だけ訂正が効かない（{@see self::resetFlatListing()}）。
	 *
	 * @param array<int, mixed> $stored   保存前の listings meta。
	 * @param array<int, mixed> $incoming これから保存する listings。
	 * @return array<int, mixed> $incoming と同じ並び・同じキーで、該当 offer だけ差し替えたもの。
	 */
	public static function forIdentityChanges( array $stored, array $incoming ): array {
		$known = self::knownIdentities( $stored );

		foreach ( $incoming as $index => $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}

			$platform = ScalarField::string( $listing, 'platform' );
			if ( ! isset( $known[ $platform ] ) ) {
				// 保存前に無かった platform＝新しい listing。訂正ではないので触らない。
				continue;
			}
			$seen = $known[ $platform ];

			if ( ! isset( $listing['offers'] ) || ! is_array( $listing['offers'] ) ) {
				// v3 以前の flat な listing。**素通りさせてはならない。**
				// 保存時に ProductSchema::sanitizeOffers() が offers[] へ畳むが、
				// その畳み込み（LegacyOffer::toOffer()）は古い fetch_status／fetch_error を
				// そのまま新しい offer へ運ぶ。運ばれた terminal は give-up マーカーと
				// AND で効くので、移行前の listing の身元を訂正しても cooldown のあいだ
				// 再取得が止まったままになる。保存前の listing を
				// LegacyOffer::offersWithFallback() で見ている {@see self::knownIdentities()}
				// と同じ写像を通し、こちらも身元で判定する。
				$incoming[ $index ] = self::resetFlatListing( $listing, $seen );
				continue;
			}

			foreach ( $listing['offers'] as $offerIndex => $offer ) {
				if ( ! is_array( $offer ) ) {
					continue;
				}
				if ( FetchStatus::NONE === ScalarField::string( $offer, 'fetch_status' ) ) {
					continue;
				}
				if ( isset( $seen[ OfferIdentity::of( $offer ) ] ) ) {
					// 身元は変わっていない。取得状態は取得だけのものなので触らない。
					continue;
				}

				$offer['fetch_status'] = FetchStatus::NONE;

				$offers                = $incoming[ $index ]['offers'];
				$offers[ $offerIndex ] = $offer;

				$incoming[ $index ]['offers'] = $offers;
			}
		}

		return $incoming;
	}

	/**
	 * flat な（offers を持たない）listing の取得状態を、身元が変わっていれば白紙に戻す。
	 *
	 * **`fetch_status` と `fetch_error` の両方を空にする。** 畳み込みは fetch_status が
	 * 空なら旧 `fetch_error`（v3 以前が保存していた文言）から status を復元するため
	 * （{@see LegacyOffer::toOffer()} → {@see FetchStatus::fromLegacyMessage()}）、
	 * 片方だけ消しても畳まれた offer に terminal が蘇る。
	 *
	 * 取得結果フィールドを 1 つも持たない listing（設定だけ）は畳み込みの対象にならない
	 * ので、キーを足さずそのまま返す。
	 *
	 * @param array<string, mixed> $listing
	 * @param array<string, true>  $seen    同じ platform の保存前の身元。
	 * @return array<string, mixed>
	 */
	private static function resetFlatListing( array $listing, array $seen ): array {
		$folded = LegacyOffer::offersWithFallback( $listing );
		if ( array() === $folded ) {
			return $listing;
		}

		$offer = $folded[0];
		if ( FetchStatus::NONE === ScalarField::string( $offer, 'fetch_status' ) ) {
			return $listing;
		}
		if ( isset( $seen[ OfferIdentity::of( $offer ) ] ) ) {
			// 身元は変わっていない。取得状態は取得だけのものなので触らない。
			return $listing;
		}

		$listing['fetch_status'] = FetchStatus::NONE;
		$listing['fetch_error']  = '';

		return $listing;
	}

	/**
	 * 保存前の listings が持つ身元を platform ごとに集める。
	 *
	 * @param array<int, mixed> $stored
	 * @return array<string, array<string, true>>
	 */
	private static function knownIdentities( array $stored ): array {
		$known = array();
		foreach ( $stored as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$platform = ScalarField::string( $listing, 'platform' );
			foreach ( LegacyOffer::offersWithFallback( $listing ) as $offer ) {
				if ( ! is_array( $offer ) ) {
					continue;
				}
				$known[ $platform ][ OfferIdentity::of( $offer ) ] = true;
			}
		}
		return $known;
	}
}
