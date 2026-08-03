<?php
declare(strict_types=1);

namespace Affilicard\Account;

/**
 * DMM アカウント。DMM 系 provider（DMM ebook 等）が共有する鍵を保有する。
 */
final class DmmAccount implements AccountInterface {

	public function code(): string {
		return 'dmm';
	}

	public function label(): string {
		return __( 'DMM', 'affilicard' );
	}

	/**
	 * @return list<array{key: string, label: string, type: 'text'|'password', required: bool}>
	 */
	public function credentialsSchema(): array {
		return array(
			array(
				'key'      => 'api_id',
				'label'    => __( 'API ID', 'affilicard' ),
				'type'     => 'password',
				'required' => true,
			),
			array(
				'key'      => 'affiliate_id',
				'label'    => __( 'アフィリエイト ID（API リクエスト用・末尾 990〜999）', 'affilicard' ),
				'type'     => 'text',
				'required' => true,
			),
			// DMM のアフィリエイト ID は用途で 2 つに分かれる。API リクエストに使えるのは
			// 末尾 990〜999 の ID だけ（DMM 側の制限）で、実際のリンクに載せる af_id は
			// サイト単位で発行される別 ID（例 `xxxxx-007`）。ItemList は**リクエストに使った
			// affiliate_id をそのまま応答の affiliateURL に埋めて返す**ため、応答の
			// affiliateURL をそのまま使うとリンクが「無効リンク」（HTTP 400）になる。
			array(
				'key'      => 'affiliate_link_id',
				'label'    => __( 'アフィリエイト ID（リンク埋め込み用）', 'affilicard' ),
				'type'     => 'text',
				'required' => true,
			),
		);
	}
}
