<?php
declare(strict_types=1);

namespace Affilicard\Provider\Rakuten;

use Affilicard\Provider\ProviderCredentials;
use Affilicard\Provider\ProviderInterface;

/**
 * 楽天Kobo 電子書籍検索 API を使った電子書籍の自動取得 Provider。
 *
 * 2026 年の楽天 API 刷新に対応（openapi.rakuten.co.jp・accessKey ヘッダ・Origin 必須）。
 */
final class RakutenProvider implements ProviderInterface {

	private const ENDPOINT = 'https://openapi.rakuten.co.jp/services/api/Kobo/EbookSearch/20170426';

	public function code(): string {
		return 'rakuten-kobo';
	}

	public function label(): string {
		return __( '楽天Kobo API', 'affilicard' );
	}

	public function isAutomatic(): bool {
		return true;
	}

	/**
	 * @return list<array{key: string, label: string, type: 'text'|'password', required: bool}>
	 */
	public function credentialsSchema(): array {
		return array(
			array(
				'key'      => 'application_id',
				'label'    => __( 'アプリID', 'affilicard' ),
				'type'     => 'password',
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
				'type'     => 'password',
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

	/**
	 * @param array<string, mixed> $platformConfig
	 * @return array<string, mixed>|null
	 */
	public function fetch( string $externalId, array $platformConfig ): ?array {
		return null; // Task 3 で実装
	}

	/**
	 * @param array<string, string> $credentials
	 * @return array{ok: bool, message: string}
	 */
	public function testConnection( array $credentials ): array {
		return array(
			'ok'      => false,
			'message' => '',
		); // Task 2 で実装
	}
}
