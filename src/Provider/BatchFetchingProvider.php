<?php
declare(strict_types=1);

namespace Affilicard\Provider;

/**
 * 1 リクエストで複数商品を取得できる Provider が追加で実装する任意インターフェース。
 *
 * `ProviderInterface::fetch()` は 1 商品ずつの契約のまま変えない。まとめて取れる Provider は
 * {@see self::prefetch()} で先に取得結果（hit / miss / error）を外部 ID ごとに覚えておき、
 * 続く `fetch()` はそれを返す（HTTP しない）。呼び出し側（BatchRefreshHandler）は
 * 1 回のレート枠で最大 {@see self::maxBatchSize()} 件ぶんを取得できる。
 *
 * 覚えた結果は 1 ジョブの処理中だけ使う（呼び出し側が開始時と終了時に
 * {@see self::clearPrefetched()} する）。古い結果を別ジョブへ持ち越さないため。
 */
interface BatchFetchingProvider {

	/**
	 * 1 リクエストに載せられる外部 ID の最大数。
	 */
	public function maxBatchSize(): int;

	/**
	 * 外部 ID をまとめて取得し、結果を外部 ID ごとに覚える。
	 *
	 * 一時失敗（API 到達不可・429 等）も「一時失敗」として覚える。覚えずにおくと、続く
	 * `fetch()` が 1 件ずつ叩き直してレート制限をすり抜けるため。
	 *
	 * @param list<string> $externalIds
	 */
	public function prefetch( array $externalIds ): void;

	/**
	 * この外部 ID の結果を覚えているか。
	 */
	public function hasPrefetched( string $externalId ): bool;

	/**
	 * 覚えた結果をすべて捨てる。
	 */
	public function clearPrefetched(): void;
}
