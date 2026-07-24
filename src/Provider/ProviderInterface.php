<?php
declare(strict_types=1);

namespace Affilicard\Provider;

/**
 * 価格・在庫情報の取得元（DMM API / 楽天 API / 手動入力など）が実装する契約。
 */
interface ProviderInterface {

	/**
	 * 内部コード（例: 'manual', 'dmm-ebook'）。
	 */
	public function code(): string;

	/**
	 * UI 表示用のラベル。
	 */
	public function label(): string;

	/**
	 * 自動取得 Provider か否か（false の場合は手動入力扱い）。
	 */
	public function isAutomatic(): bool;

	/**
	 * この provider が認証情報を引く account のコード（例: 'rakuten'）。手動入力は null。
	 */
	public function accountCode(): ?string;

	/**
	 * 商品 ID から API/スクレイピング等で raw 商品情報を取得する。
	 *
	 * 結果は3値で分類して返す:
	 * - 成功           = FetchResult::hit( $data )  取得データ（title/price/... の連想配列）
	 * - 恒久失敗(terminal) = FetchResult::miss()   API 到達したが該当商品が無い・無効 ID。
	 *                                              リトライしても成功しないため give-up してよい。
	 * - 一時失敗(transient) = FetchResult::error() API 到達不可・エラー・認証未設定等。
	 *                                              後で成功し得るため give-up せずリトライする。
	 *
	 * @param array<string, mixed> $platformConfig 対象 platform の追加設定
	 * @return FetchResult 成功=hit(data)／恒久失敗(該当なし・無効ID)=miss()／一時失敗(API到達不可・エラー)=error()
	 */
	public function fetch( string $externalId, array $platformConfig ): FetchResult;

	/**
	 * credentials を使った疎通確認。
	 *
	 * @param array<string, string> $credentials
	 * @return array{ok: bool, message: string}
	 */
	public function testConnection( array $credentials ): array;

	/**
	 * この provider の安全な最小リクエスト間隔（ミリ秒）。手動入力は 0。
	 * RateLimiter が provider 別 throttle の下限として使う。
	 */
	public function minRequestIntervalMs(): int;
}
