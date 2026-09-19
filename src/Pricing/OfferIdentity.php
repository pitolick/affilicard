<?php
declare(strict_types=1);

namespace Affilicard\Pricing;

use Affilicard\Util\ScalarField;

/**
 * 購入リンク（offer）の身元を 1 つの文字列で表す。
 *
 * **配列の添字は身元ではない。** offers は listing 編集・自動作成・価格更新など複数の
 * 独立した書き込み元から届くため、配列内の位置が安定しているとは限らない。位置で
 * 突き合わせると、並べ替えや追加が挟まった瞬間に別の購入リンクを誤って上書きする。
 *
 * 判定規則は spec §3-4 と同じ——`external_id`、空なら `regular_url`。
 * プレフィックスを付けるのは、一方が external_id 側の値、もう一方が regular_url 側の
 * 値と偶然同じ文字列になった場合の衝突を避けるため。
 *
 * **値は {@see ScalarField::string()} で読む。** `(string)` で直にキャストすると、
 * 配列は「Array to string conversion」の警告のうえ `'Array'` になり、`__toString` を
 * 持たないオブジェクトは Error になる。ここは「取得結果をどの offer へ書き戻すか」を
 * 決める関数なので、でたらめな身元の害がとりわけ大きい——身元を持たない壊れた offer が
 * 揃って `external_id:Array` を名乗り、ListingRefresher の書き戻しが最初に一致した
 * 別の購入リンクへ価格を書き込む。非スカラーは「値なし」に倒し、規則どおり
 * regular_url 側へ落とす。
 *
 * WP 関数を一切呼ばない（配列を受けて文字列を返すだけ）。
 */
final class OfferIdentity {

	/**
	 * @param array<string, mixed> $offer
	 */
	public static function of( array $offer ): string {
		$externalId = ScalarField::string( $offer, 'external_id' );
		if ( '' !== $externalId ) {
			return 'external_id:' . $externalId;
		}
		return 'regular_url:' . ScalarField::string( $offer, 'regular_url' );
	}
}
