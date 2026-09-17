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
	 * @param array<int, mixed> $stored   保存前の listings meta。
	 * @param array<int, mixed> $incoming これから保存する listings。
	 * @return array<int, mixed> $incoming と同じ並び・同じキーで、該当 offer だけ差し替えたもの。
	 */
	public static function forIdentityChanges( array $stored, array $incoming ): array {
		$known = self::knownIdentities( $stored );

		foreach ( $incoming as $index => $listing ) {
			if ( ! is_array( $listing ) || ! isset( $listing['offers'] ) || ! is_array( $listing['offers'] ) ) {
				// offers を持たない listing（設定だけ・v3 以前の flat な形）はそのまま通す。
				// flat な形の取得状態は保存時に ProductSchema が offers[] へ畳む。
				continue;
			}

			$platform = ScalarField::string( $listing, 'platform' );
			if ( ! isset( $known[ $platform ] ) ) {
				// 保存前に無かった platform＝新しい listing。訂正ではないので触らない。
				continue;
			}
			$seen = $known[ $platform ];

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
