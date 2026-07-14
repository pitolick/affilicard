<?php
declare(strict_types=1);

namespace Affilicard\Account;

/**
 * 楽天デベロッパーアカウント。楽天系 provider（楽天Kobo 等）が共有する鍵を保有する。
 */
final class RakutenAccount implements AccountInterface {

	public function code(): string {
		return 'rakuten';
	}

	public function label(): string {
		return __( '楽天', 'affilicard' );
	}

	/**
	 * @return list<array{key: string, label: string, type: 'text'|'password', required: bool}>
	 */
	public function credentialsSchema(): array {
		return array(
			array(
				'key'      => 'application_id',
				'label'    => __( 'アプリID', 'affilicard' ),
				'type'     => 'text',
				'required' => true,
			),
			array(
				'key'      => 'access_key',
				'label'    => __( 'アクセスキー', 'affilicard' ),
				'type'     => 'password',
				'required' => true,
			),
			array(
				'key'      => 'affiliate_id',
				'label'    => __( 'アフィリエイトID', 'affilicard' ),
				'type'     => 'text',
				'required' => true,
			),
			array(
				'key'      => 'allowed_domain',
				'label'    => __( '許可ドメイン（Origin。空ならサイトURL）', 'affilicard' ),
				'type'     => 'text',
				'required' => false,
			),
		);
	}
}
