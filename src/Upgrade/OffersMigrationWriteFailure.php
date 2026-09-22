<?php
declare(strict_types=1);

namespace Affilicard\Upgrade;

/**
 * offers 移行の書き込みが効かなかった（書いた値が読み戻らなかった）ことを表す例外。
 *
 * **専用の型にしているのは、握り潰す範囲を「移行の保存失敗」だけに閉じるためである。**
 * {@see PluginUpgrade::migrateOneProduct()} はこの例外を捕まえて試行回数を数え、
 * 上限に達した商品を諦める。ここを素の `\RuntimeException` で捕まえると、
 * まったく無関係な RuntimeException（単体テストでは
 * `Mockery\Exception\NoMatchingExpectationException` が OutOfBoundsException 経由で
 * RuntimeException を継承している）まで「移行の保存失敗」として数えてしまい、
 * 本当のバグが見えなくなる。
 *
 * `\RuntimeException` を継承しているため、投げっぱなしにする従来の経路
 * （上限に達するまでは差し戻して次の実行で同じ商品からやり直す）は変わらない。
 */
final class OffersMigrationWriteFailure extends \RuntimeException {
}
