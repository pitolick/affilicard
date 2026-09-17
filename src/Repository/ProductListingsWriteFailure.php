<?php
declare(strict_types=1);

namespace Affilicard\Repository;

/**
 * listings（META_LISTINGS）の書き込みが効かなかった——書いた値が読み戻らなかった——
 * ことを表す例外。
 *
 * **{@see ProductLockUnavailable} とは別の事象である。** あちらは「書かなかった」で、
 * 運用者がやり直せばそのまま通る（競合相手が抜けるのを待つだけ）。こちらは
 * 「書いたのに入らなかった」で、やり直しても同じ結果になる見込みが高い——別プラグインの
 * `update_post_metadata` フィルタ、壊れた meta 行、DB の書き込み失敗といった、
 * 取り除かない限り消えない原因を指す。**手当てが違うので型も分ける**（REST は前者を
 * 409、こちらを 500 で返す）。
 *
 * **専用の型にして握り潰す範囲を閉じるのは
 * {@see \Affilicard\Upgrade\OffersMigrationWriteFailure} と同じ理由である。** 素の
 * `\RuntimeException` で捕まえると、単体テストの
 * `Mockery\Exception\NoMatchingExpectationException`（これも RuntimeException を継承する）
 * のような無関係な例外まで「保存の失敗」として扱われ、本当のバグが見えなくなる。
 *
 * **post ID を運ぶのは {@see ProductLockUnavailable} と同じ理由である。**
 * {@see ProductRepository::save()} は `wp_insert_post()` が通ってから
 * {@see ProductRepository::saveMeta()} を呼ぶため、**新規作成の途中でこれが投げられた
 * 時点で投稿行は既に存在する**。ID を運ばないと
 * {@see \Affilicard\Rest\ProductsController::create()} は ID を持たない 500 を返すしか
 * なく、呼び出し側は「商品ができたのかどうか」すら知れない——再試行のたびに POST し直し、
 * 同じ商品が増え続ける。ID があれば、呼び出し側は増やす代わりにその商品を PATCH できる。
 */
final class ProductListingsWriteFailure extends \RuntimeException {

	/**
	 * @param int    $postId  listings を保存できなかった商品の投稿 ID。**新規作成の
	 *                        途中で投げた場合、この ID の投稿行は既に作成されている**
	 *                        （呼び出し側はこれを使ってやり直しを PATCH に変えられる）。
	 * @param string $message 省略時は postId を含む既定文（テストが型だけでなく
	 *                        メッセージまで固定できるようにするため、必ず ID を含める）。
	 */
	public function __construct( private int $postId, string $message = '' ) {
		parent::__construct(
			'' !== $message
				? $message
				: sprintf( 'affilicard: 商品 %d の listings を保存できなかった（書き込んだ値が読み戻らない）。', $postId )
		);
	}

	public function postId(): int {
		return $this->postId;
	}
}
