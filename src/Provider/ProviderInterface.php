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
	 * 商品 ID から API/スクレイピング等で raw 商品情報を取得する。
	 *
	 * 取得不可・credentials 未設定の場合は null を返す。
	 *
	 * @param array<string, mixed> $platformConfig 対象 platform の追加設定
	 * @return array<string, mixed>|null
	 */
	public function fetch( string $externalId, array $platformConfig ): ?array;

	/**
	 * 管理画面に表示する credentials フィールド定義。
	 *
	 * @return list<array{key: string, label: string, type: 'text'|'password', required: bool}>
	 */
	public function credentialsSchema(): array;

	/**
	 * credentials を使った疎通確認。
	 *
	 * @param array<string, string> $credentials
	 * @return array{ok: bool, message: string}
	 */
	public function testConnection( array $credentials ): array;
}
