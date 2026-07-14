<?php
declare(strict_types=1);

namespace Affilicard\Provider\Rakuten;

/**
 * 楽天Kobo openapi への HTTP transport。accessKey ヘッダ・Origin/Referer を付与して GET する。
 */
final class RakutenClient {

	private const ENDPOINT = 'https://openapi.rakuten.co.jp/services/api/Kobo/EbookSearch/20170426';

	/**
	 * @param array<string, string> $query
	 * @param array<string, string> $credentials
	 * @return array{error: bool, code: int, decoded: array<string, mixed>|null}
	 */
	public function request( array $query, array $credentials ): array {
		$response = wp_remote_get(
			self::ENDPOINT . '?' . http_build_query( $query ),
			$this->requestArgs( $credentials )
		);
		if ( self::isWpError( $response ) ) {
			return array(
				'error'   => true,
				'code'    => 0,
				'decoded' => null,
			);
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array(
			'error'   => false,
			'code'    => $code,
			'decoded' => is_array( $decoded ) ? $decoded : null,
		);
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

	public static function toOrigin( string $url ): string {
		$url = trim( $url );
		// スキームが無ければ https を補い、wp_parse_url が host を認識できるようにする。
		if ( '' !== $url && 1 !== preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}
		$parts = wp_parse_url( $url );
		if ( is_array( $parts ) && isset( $parts['host'] ) ) {
			$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https';
			$origin = $scheme . '://' . (string) $parts['host'];
			if ( isset( $parts['port'] ) ) {
				$origin .= ':' . (int) $parts['port'];
			}
			return $origin;
		}
		return rtrim( $url, '/' );
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
