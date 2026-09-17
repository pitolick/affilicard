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
 * 形も同じものを使う——メッセージは投げる側が組み立て、この型は握り潰す範囲を
 * 区切るためだけに存在する。
 */
final class ProductListingsWriteFailure extends \RuntimeException {
}
