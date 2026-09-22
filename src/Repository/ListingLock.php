<?php
declare(strict_types=1);

namespace Affilicard\Repository;

/**
 * listing メタ（{@see \Affilicard\PostType\ProductPostType::META_LISTINGS}）の
 * read-modify-write を商品単位で直列化する MySQL 名前付きロック。
 *
 * META_LISTINGS は「読んで・一部を書き換えて・全体を書き戻す」形でしか更新できない
 * （1 つの meta 行に全 platform の listing が入っている）。したがって読みと書きの
 * あいだに別の書き手が入ると、後着の書き戻しが先着の変更を丸ごと消す（lost update）。
 * 書き手ごとにロックを書くとやり方が少しずつずれるため、名前・待ち時間・解放の作法を
 * ここ 1 箇所に集める。
 *
 * **ロックを取れなかったときにどうするかは、ここでは決めない。** 呼び出し側の事情で
 * 正解が逆になるためである（下の 5 つの呼び出し側を参照）。だから {@see self::around()}
 * は取得の成否をコールバックへ渡すだけにして、続行するか諦めるかは呼び出し側に書かせる。
 *
 * **ただし「黙って古い読みを書き戻す」だけは、どの呼び出し側にも許さない。** それは
 * lost update そのもので、失われたことが誰にも分からない。選べるのは「書かずに報告する」
 * か「書いてよい理由がある」かの二択である。
 *
 * | 呼び出し側 | 取れなかったら |
 * | --- | --- |
 * | {@see ProductRepository::updateListing()} | best-effort で続行する（取得済みの値を捨てない。production からの呼び出しは無い） |
 * | {@see ProductRepository::updateListingOffer()} | 何も書かず false（呼び出し側が再投入する） |
 * | {@see ProductRepository::saveMeta()} | 何も書かず {@see ProductLockUnavailable} を投げる（REST は 409・自動作成は再投入） |
 * | {@see ProductRepository::syncDerivedMeta()} | ミラーを作り直さず false（{@see DerivedMetaSync} が再投入する） |
 * | {@see \Affilicard\Upgrade\PluginUpgrade::migrateOneProduct()} | 移行の失敗として差し戻す（数回で諦める） |
 *
 * 外部 API の fetch をロックの中へ入れてはならない。ロックは「メモリ上の変換と
 * meta の読み書き」だけを囲む前提の待ち時間（{@see self::TIMEOUT}）で設計している。
 *
 * **ブロックエディタのサイドバー保存（コアの `wp/v2` meta 経路）はここを通らない。**
 * 意図的に通していない——理由と残る競合は {@see \Affilicard\Rest\ListingsEditFilter} の
 * クラス docblock に書いた。
 */
final class ListingLock {

	/**
	 * このリクエストで既に握っている商品（post ID をキーに持つだけ）。
	 *
	 * **入れ子で呼ばれるため要る。** {@see ProductRepository::syncDerivedMeta()} は
	 * 単独でも呼ばれる（`rest_after_insert_affilicard_product`）が、
	 * {@see \Affilicard\Upgrade\PluginUpgrade::migrateOneProductLocked()} の中——
	 * つまり既にこのロックを握った状態——からも呼ばれる。
	 *
	 * @var array<int, true>
	 */
	private static array $held = array();

	/**
	 * ロック取得の待ち時間（秒）。
	 *
	 * クリティカルセクションは meta の読み書きだけ（外部 I/O は含めない）なので、
	 * 通常はミリ秒で空く。ここまで待って取れないのは異常事態であり、待ち続けるより
	 * 呼び出し側の判断へ返した方がよい。
	 */
	public const TIMEOUT = 10;

	/**
	 * 商品 1 件ぶんのロック名。
	 *
	 * **サイトを区別する印を必ず混ぜる。** MySQL の名前付きロックは接続単位ではなく
	 * **サーバ全体**で共有される。共有ホスティングのように 1 つの MySQL に複数の
	 * WordPress が同居していると、`affilicard_listing_123` だけではサイト A の
	 * 商品 123 がサイト B の商品 123 を待たせてしまう（同じ理由でマルチサイトの
	 * テーブル prefix 違いも衝突する）。DB 名と prefix のハッシュを挟んで隔離する。
	 *
	 * GET_LOCK の名前は 64 バイト以内。19 + 8 + 1 + post ID の桁数なので超えない。
	 */
	public static function name( int $postId ): string {
		return LockName::build( 'affilicard_listing', (string) $postId );
	}

	/**
	 * $critical をロックの中で実行し、その戻り値をそのまま返す。
	 *
	 * $critical には**ロックを取得できたか**が渡る。取得できなかった場合も
	 * $critical は呼ばれる——「続行するか諦めるか」は呼び出し側の判断だからである
	 * （クラスの PHPDoc の表を参照）。
	 *
	 * 解放は finally で行うため、$critical が例外を投げてもロックは残らない。
	 * 取得できなかったときは解放しない（他のセッションが握っているロックへ
	 * RELEASE_LOCK を撃っても解放はされず、クエリを 1 本増やすだけ）。
	 *
	 * **同じ商品の入れ子では取り直さない（再入可能）。** 既に握っているなら
	 * $critical をそのまま `true` で呼ぶ。MySQL の名前付きロックが同一セッションからの
	 * 再取得をどう数えるかに依存せず、GET_LOCK と RELEASE_LOCK を 1 対 1 に保つため
	 * である。印を立てるのは**取得できたときだけ**で、取れなかった外側の中の内側は
	 * 自分で取りに行く（誰も握っていない区間を「守られている」と誤認させない）。
	 * 印は最も外側の finally で必ず落ちるため、$critical が例外を投げても残らない。
	 *
	 * @param callable(bool): mixed $critical ロック内で実行する処理。引数は取得の成否。
	 * @return mixed $critical の戻り値。
	 */
	public static function around( int $postId, callable $critical ) {
		global $wpdb;

		if ( isset( self::$held[ $postId ] ) ) {
			// 外側のフレームが握ったまま自分の finally で解放するので、ここでは
			// 印にも解放にも触らない。
			return $critical( true );
		}

		$lock = self::name( $postId );
		$got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::TIMEOUT ) );

		if ( $got <= 0 ) {
			return $critical( false );
		}

		self::$held[ $postId ] = true;
		try {
			return $critical( true );
		} finally {
			unset( self::$held[ $postId ] );
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
