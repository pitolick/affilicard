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
 * 次の 2 つだけで、どちらも「商品を丸ごと保存する」運用者／API の編集の入口である。
 *
 * 1. {@see \Affilicard\Repository\ProductRepository::saveMeta()}——REST（`affilicard/v1`
 *    の商品 API）と自動作成（新規作成のみ）が通る
 * 2. {@see \Affilicard\Rest\ListingsEditFilter::resetEditedOfferStatus()}——ブロック
 *    エディタのサイドバー保存（コアの `wp/v2` meta 経路）が通る
 *
 * 取得の書き戻しはどちらも通らない。{@see \Affilicard\Cron\ListingRefresher} は
 * updateListing()／updateListingOffer() から `update_post_meta()` を直接叩き、移行
 * （{@see \Affilicard\Upgrade\PluginUpgrade}）も同じく直接書く。したがって「取得が
 * regular_url と fetch_status を同時に書く」保存はここへ届かず、flat な形で届くことも
 * ない（下の flat 対応を足しても、取得の書き戻しを白紙にする経路は生まれない）。
 *
 * ## 突き合わせるのは「身元」と「保存前にその身元の行が持っていた取得状態」
 *
 * 身元が保存前の集合に残っていても、それが**同じ行に載っている**とは限らない。
 * 次の 2 つの編集は集合を変えないため、集合だけを見る判定では素通りしてしまう。
 *
 * 1. 購入リンク A の `external_id` を B の値へ打ち替え、元の B を消す
 *    （{@see \Affilicard\Rest\ProductSchema::sanitizeOffers()} は重複した身元を後勝ちで
 *    畳むので、保存後に残るのは 1 行である）
 * 2. A と B の `external_id` を入れ替える
 *
 * どちらも、残った offer が**別の SKU で得た** `fetch_status` を持ち続ける。そこで
 * 突き合わせに取得状態も含める——**保存前の同じ身元の行が持っていた `fetch_status` と
 * 一致するときだけ保つ**。取得状態はその身元の行が取得で得たものなので、同じ身元を
 * 名乗りながら違う状態を載せてきたなら、その状態は別の行から運ばれてきたか（上の
 * 2 つ）、保存前の姿より古い写しである。どちらにせよ、その身元について何も語らない。
 *
 * offer ごとの安定 ID は要らない。入れ替えでも「rk-b の行は terminal ではなかった」
 * という保存前の事実は残っており、それだけで対応の変化が分かるためである。
 *
 * **白紙にする側へ倒しても危険が無いのは、この層を通る書き手が限られているからである。**
 * 取得（ListingRefresher）と移行（PluginUpgrade）はここを通らない（上の「置き場所」節）。
 * 残る書き手が保存前と違う `fetch_status` を持ち込む形は次の 3 つで、いずれも損をしない。
 *
 * - **ブロックエディタのサイドバー**は画面を開いた時点で読んだ listings をそのまま
 *   送り返す（flat な listing の畳み込みも {@see LegacyOffer} と同じ写像で行う）。
 *   触っていない行の状態は保存前と一致するので保たれる。一致しないのは「開いてから
 *   保存するまでに取得が状態を書き換えた」ときで、その古い写しは白紙にする方が正しい
 *   （素通りさせると解消済みの terminal が蘇る）。
 * - **`affilicard/v1` の商品更新**（{@see \Affilicard\Rest\ProductsController::update()}）は
 *   保存前の商品（{@see \Affilicard\Repository\ProductRepository::find()}）へリクエストを
 *   重ねるため、送られなかった listing は保存前の状態のまま届き、保たれる。
 * - **取得結果を持ち込む外部ツール**。新規作成は「保存前に無い platform」の番人が
 *   除外する。既存商品の更新でそれを行う書き手は現状いない（投稿パイプラインは
 *   `fetch_status` を送らない）が、仮に現れても失うのは抑止のヒントだけで、次の取得が
 *   同じ状態を書き直す。`price` / `regular_url` / `last_verified_at` には触れないため、
 *   価格表示（{@see PriceFreshness::isPriceDisplayable()}）の根拠も消えない。
 *
 * つまり判定は「保存前のその行と同じだと確かめられたときだけ保つ」という保守側に
 * 倒してある。誤って保てば訂正が give-up の cooldown（最大 3 日）だけ効かなくなるのに対し、
 * 誤って白紙にしても次の取得が状態を書き直すだけで済む。
 */
final class OfferStatusReset {

	/**
	 * 保存前の listings と突き合わせ、その行が持っていたと確かめられない `fetch_status`
	 * だけを空にする。
	 *
	 * 保つのは「保存前に同じ身元の行があり、その行の `fetch_status` とも一致する」
	 * ときだけである（{@see self::carriedByStoredRow()}）。身元だけを突き合わせると、
	 * 打ち替えや入れ替えで行と身元の対応が変わっても集合が同じなので素通りしてしまう。
	 *
	 * 突き合わせは platform ごとに行う（`external_id` はストア内でしか意味を持たない）。
	 * 保存前の listing が v3 以前の flat な形でも
	 * {@see LegacyOffer::offersWithFallback()} で身元と取得状態を拾う——拾えないと
	 * 「既知の身元」が空になり、移行前の商品を保存するたびに全 offer の状態を
	 * 白紙にしてしまう。
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
				// と同じ写像を通し、こちらも同じ規則（身元＋その行の取得状態）で判定する。
				$incoming[ $index ] = self::resetFlatListing( $listing, $seen );
				continue;
			}

			foreach ( $listing['offers'] as $offerIndex => $offer ) {
				if ( ! is_array( $offer ) ) {
					continue;
				}
				$status = ScalarField::string( $offer, 'fetch_status' );
				if ( FetchStatus::NONE === $status ) {
					continue;
				}
				if ( self::carriedByStoredRow( $seen, $offer, $status ) ) {
					// 保存前と同じ身元・同じ取得状態＝この行が取得で得たもの。触らない。
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
	 * flat な（offers を持たない）listing の取得状態を、保存前のその行が持っていたと
	 * 確かめられなければ白紙に戻す。
	 *
	 * **`fetch_status` と `fetch_error` の両方を空にする。** 畳み込みは fetch_status が
	 * 空なら旧 `fetch_error`（v3 以前が保存していた文言）から status を復元するため
	 * （{@see LegacyOffer::toOffer()} → {@see FetchStatus::fromLegacyMessage()}）、
	 * 片方だけ消しても畳まれた offer に terminal が蘇る。
	 *
	 * 取得結果フィールドを 1 つも持たない listing（設定だけ）は畳み込みの対象にならない
	 * ので、キーを足さずそのまま返す。
	 *
	 * @param array<string, mixed>  $listing
	 * @param array<string, string> $seen    同じ platform の保存前の身元 → `fetch_status`。
	 * @return array<string, mixed>
	 */
	private static function resetFlatListing( array $listing, array $seen ): array {
		$folded = LegacyOffer::offersWithFallback( $listing );
		if ( array() === $folded ) {
			return $listing;
		}

		$offer  = $folded[0];
		$status = ScalarField::string( $offer, 'fetch_status' );
		if ( FetchStatus::NONE === $status ) {
			return $listing;
		}
		if ( self::carriedByStoredRow( $seen, $offer, $status ) ) {
			// 保存前と同じ身元・同じ取得状態＝この行が取得で得たもの。触らない。
			return $listing;
		}

		$listing['fetch_status'] = FetchStatus::NONE;
		$listing['fetch_error']  = '';

		return $listing;
	}

	/**
	 * この取得状態が「保存前の同じ身元の行」から来たものか。
	 *
	 * 身元が保存前に居ただけでは足りない（打ち替えや入れ替えでも集合は変わらない）。
	 * その身元の行が保存前に持っていた `fetch_status` と一致して初めて、状態が
	 * その行自身の取得で得られたものだと確かめられる。一致しなければ、別の行から
	 * 運ばれてきたか古い写しなので白紙に倒す（理由と安全性はクラスの docblock）。
	 *
	 * 値は保存前・保存しようとしている側のどちらも、実際に格納される文字列そのもので
	 * 突き合わせる（正規化を挟まない）。ここを通る書き手はいずれも保存前の値を
	 * そのまま送り返すため、表記ゆれで食い違うときは「送り返したものではない」と
	 * 見なして白紙にする側が保守的である。
	 *
	 * @param array<string, string> $seen   同じ platform の保存前の身元 → `fetch_status`。
	 * @param array<string, mixed>  $offer  判定する購入リンク。
	 * @param string                $status $offer の `fetch_status`（NONE でないことは呼び出し側が確認済み）。
	 */
	private static function carriedByStoredRow( array $seen, array $offer, string $status ): bool {
		$identity = OfferIdentity::of( $offer );
		return isset( $seen[ $identity ] ) && $seen[ $identity ] === $status;
	}

	/**
	 * 保存前の listings が持つ身元を platform ごとに集める。
	 *
	 * @param array<int, mixed> $stored
	 * @return array<string, array<string, string>> platform → 身元 → その行の `fetch_status`。
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
				// 身元が重複していたら後勝ち（ProductSchema::sanitizeOffers() が保存時に
				// 重複を畳むときと同じ規則。保存済みデータが重複を持つのは移行の
				// 温存モードを通った場合だけだが、規則をずらす理由が無い）。
				$known[ $platform ][ OfferIdentity::of( $offer ) ] = ScalarField::string( $offer, 'fetch_status' );
			}
		}
		return $known;
	}
}
