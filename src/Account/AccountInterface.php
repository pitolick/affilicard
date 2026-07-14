<?php
declare(strict_types=1);

namespace Affilicard\Account;

/**
 * API 認証情報の保有単位（楽天 / DMM / Amazon 等のアカウント）。
 *
 * 1 つの account を複数 provider（例: 楽天Kobo・楽天ブックス）が共有する。
 * credentials スキーマの単一情報源（SSOT）。testConnection は API 単位のため provider が持つ。
 */
interface AccountInterface {

	/** 内部コード（例: 'rakuten'）。保存キー affilicard_account_<code>_credentials に使う。 */
	public function code(): string;

	/** UI 表示ラベル（例: '楽天'）。 */
	public function label(): string;

	/**
	 * 管理画面に表示する credentials フィールド定義。
	 *
	 * @return list<array{key: string, label: string, type: 'text'|'password', required: bool}>
	 */
	public function credentialsSchema(): array;
}
