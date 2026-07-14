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
				'label'    => __( 'アフィリエイト ID', 'affilicard' ),
				'type'     => 'text',
				'required' => true,
			),
		);
	}
}
