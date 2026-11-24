<?php
declare(strict_types=1);

namespace Affilicard\Account;

/**
 * Amazon アソシエイト（Creators API）アカウント。Amazon 系 provider が共有する鍵を保有する。
 */
final class AmazonAccount implements AccountInterface {

	public function code(): string {
		return 'amazon';
	}

	public function label(): string {
		return __( 'Amazon', 'affilicard' );
	}

	/**
	 * @return list<array{key: string, label: string, type: 'text'|'password', required: bool}>
	 */
	public function credentialsSchema(): array {
		return array(
			array(
				'key'      => 'credential_id',
				'label'    => __( 'Credential ID（Creators API）', 'affilicard' ),
				'type'     => 'text',
				'required' => true,
			),
			array(
				'key'      => 'credential_secret',
				'label'    => __( 'Credential Secret', 'affilicard' ),
				'type'     => 'password',
				'required' => true,
			),
			array(
				'key'      => 'partner_tag',
				'label'    => __( 'トラッキングID（Partner Tag）', 'affilicard' ),
				'type'     => 'text',
				'required' => true,
			),
			array(
				'key'      => 'marketplace',
				'label'    => __( 'マーケットプレイス（空なら www.amazon.co.jp）', 'affilicard' ),
				'type'     => 'text',
				'required' => false,
			),
		);
	}
}
