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
		if ( ! self::hasRequiredCredentials( $credentials ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'アプリID・アクセスキー・アフィリエイトIDを入力してください', 'affilicard' ),
			);
		}

		$query = array(
			'applicationId' => $credentials['application_id'],
			'affiliateId'   => $credentials['affiliate_id'],
			'format'        => 'json',
			'formatVersion' => '2',
			'hits'          => '1',
			'keyword'       => '本',
		);

		$response = wp_remote_get(
			self::ENDPOINT . '?' . http_build_query( $query ),
			$this->requestArgs( $credentials )
		);
		if ( self::isWpError( $response ) ) {
			return array(
				'ok'      => false,
				'message' => __( '楽天APIへの接続に失敗しました', 'affilicard' ),
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return array(
				'ok'      => false,
				'message' => self::errorMessage( $code ),
			);
		}
		if ( ! is_array( $decoded ) || isset( $decoded['errors'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( '楽天APIがエラーを返しました', 'affilicard' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( '楽天APIへの接続に成功しました', 'affilicard' ),
		);
	}

	/**
	 * @param array<string, string> $credentials
	 */
	private static function hasRequiredCredentials( array $credentials ): bool {
		return ! empty( $credentials['application_id'] )
			&& ! empty( $credentials['access_key'] )
			&& ! empty( $credentials['affiliate_id'] );
	}

	/**
	 * accessKey はヘッダーで送る（クエリ露出回避）。Origin/Referer は許可ドメイン。
	 *
	 * @param array<string, string> $credentials
	 * @return array{timeout: int, headers: array<string, string>}
	 */
	private function requestArgs( array $credentials ): array {
		$origin = self::toOrigin( $this->resolveDomain( $credentials ) );
		return array(
			'timeout' => 10,
			'headers' => array(
				'accessKey' => (string) ( $credentials['access_key'] ?? '' ),
				'Origin'    => $origin,
				'Referer'   => $origin . '/',
			),
		);
	}

	/**
	 * @param array<string, string> $credentials
	 */
	private function resolveDomain( array $credentials ): string {
		$domain = trim( (string) ( $credentials['allowed_domain'] ?? '' ) );
		if ( '' === $domain ) {
			$domain = (string) home_url();
		}
		return $domain;
	}

	private static function toOrigin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( is_array( $parts ) && isset( $parts['host'] ) ) {
			$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https';
			return $scheme . '://' . (string) $parts['host'];
		}
		return rtrim( $url, '/' );
	}

	private static function errorMessage( int $code ): string {
		if ( 429 === $code ) {
			return __( 'レート制限に達しました。時間をおいて再試行してください', 'affilicard' );
		}
		if ( 403 === $code ) {
			return __( '許可ドメイン（Origin）が楽天アプリの登録と一致しているか確認してください', 'affilicard' );
		}
		if ( 400 === $code ) {
			return __( 'アクセスキー・アプリIDを確認してください', 'affilicard' );
		}
		/* translators: %d: HTTP status code */
		return sprintf( __( '楽天APIが HTTP %d を返しました', 'affilicard' ), $code );
	}

	/**
	 * @param mixed $value
	 */
	private static function isWpError( $value ): bool {
		if ( function_exists( 'is_wp_error' ) ) {
			return (bool) is_wp_error( $value );
		}
		return $value instanceof \WP_Error;
	}
}
