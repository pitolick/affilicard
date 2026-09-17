<?php
declare(strict_types=1);

namespace Affilicard\Repository;

/**
 * 商品ロック（{@see ListingLock}）を取得できず、listings の書き込みを**見送った**ことを表す例外。
 *
 * **これは「保存に失敗した」ではなく「保存しなかった」である。** 投げた時点で
 * META_LISTINGS は触っていない——古い値のまま無傷で残っている。呼び出し側が
 * やり直せば、そのまま同じ編集を適用できる。
 *
 * **なぜ bool ではなく例外か。** {@see ProductRepository::saveMeta()} は `void` で、
 * その唯一の呼び出し元 {@see ProductRepository::save()} は post ID（int）を返す。
 * 失敗を 0 で表すと 2 つの嘘をつく——(1) 投稿行の作成/更新そのものは成功している
 * （`wp_insert_post()`/`wp_update_post()` は既に通っている）のに「保存できなかった」と
 * 見え、(2) 一時的な競合（やり直せば通る）と恒久的な失敗（やり直しても通らない）が
 * 同じ 0 に潰れる。専用の型なら呼び出し側ごとに違う正解を書き分けられ、
 * {@see ProductRepositoryInterface::save()} のシグネチャも変えずに済む。
 *
 * **専用の型にして握り潰す範囲を閉じるのは {@see \Affilicard\Upgrade\OffersMigrationWriteFailure}
 * と同じ理由である。** 素の `\RuntimeException` で捕まえると、単体テストの
 * `Mockery\Exception\NoMatchingExpectationException`（これも RuntimeException を継承する）
 * のような無関係な例外まで「ロック競合」として扱われ、本当のバグが見えなくなる。
 *
 * 捕まえる側（＝運用者へ伝える口を持つ側）は現状 3 つ:
 *
 * | 捕まえる側 | 伝え方 |
 * | --- | --- |
 * | {@see \Affilicard\Rest\ProductsController::create()}/`update()` | HTTP 409 + `affilicard_listing_locked`（読み手は外部クライアント。WP 管理画面の編集はこの経路を通らない） |
 * | {@see \Affilicard\Rest\ProductsController::bulkCreate()} | 207 の該当 item を `status=error` にする |
 * | {@see \Affilicard\AutoCreate\ProductAutoCreator::createLocked()} | {@see \Affilicard\Queue\WorkOutcome::TRANSIENT_FAILURE}（AS が再投入する） |
 *
 * {@see DerivedMetaSync::run()} も、再試行を使い切ったとき・**次の試行を積めなかったとき**の
 * 「諦めた」印としてこれを投げる（Action Scheduler が failed アクションとして記録する）。
 * どちらも結末は同じ——ミラーは古いまま、もう誰も直しに来ない——なので同じ型で報告し、
 * メッセージで区別する。
 */
final class ProductLockUnavailable extends \RuntimeException {

	/**
	 * @param int    $postId  ロックを取れなかった商品の投稿 ID。
	 * @param string $message 省略時は postId を含む既定文（テストが型だけでなく
	 *                        メッセージまで固定できるようにするため、必ず ID を含める）。
	 */
	public function __construct( private int $postId, string $message = '' ) {
		parent::__construct(
			'' !== $message
				? $message
				: sprintf( 'affilicard: 商品 %d の listing ロックを取得できず、listings を書き込まなかった。', $postId )
		);
	}

	public function postId(): int {
		return $this->postId;
	}
}
