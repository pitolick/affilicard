<?php
declare(strict_types=1);

namespace Affilicard\Pricing;

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
 * WP 関数を一切呼ばない（配列を受けて文字列を返すだけ）。
 */
final class OfferIdentity {

	/**
	 * @param array<string, mixed> $offer
	 */
	public static function of( array $offer ): string {
		$externalId = isset( $offer['external_id'] ) ? (string) $offer['external_id'] : '';
		if ( '' !== $externalId ) {
			return 'external_id:' . $externalId;
		}
		return 'regular_url:' . ( isset( $offer['regular_url'] ) ? (string) $offer['regular_url'] : '' );
	}
}
