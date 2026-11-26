<?php
declare(strict_types=1);

namespace Affilicard\Provider\Amazon;

use Affilicard\Util\Crypto;

/**
 * Amazon Creators API への HTTP transport。
 *
 * - アクセストークン（OAuth2 client credentials・1 時間有効）を取得し、WP transient に
 *   暗号化して使い回す。公式の Best Programming Practices が「期限まで使い回し、
 *   プロセス間で共有する」ことを求めており、毎回取り直すと token endpoint 側で throttle される。
 * - `getItems` を POST する。401（トークン失効）は 1 回だけトークンを取り直して再送する。
 *
 * テストで差し替えられるよう final にしない。
 */
class AmazonCreatorsClient {

	public const DEFAULT_MARKETPLACE = 'www.amazon.co.jp';

	private const API_BASE         = 'https://creatorsapi.amazon';
	private const GET_ITEMS_PATH   = '/catalog/v1/getItems';
	private const TOKEN_PATH       = '/auth/o2/token';
	private const TOKEN_SCOPE      = 'creatorsapi::default';
	private const TRANSIENT_PREFIX = 'affilicard_amazon_token_';
	private const TIMEOUT_SECONDS  = 10;

	/** トークンの有効期限より何秒早く取り直すか。 */
	private const EXPIRY_MARGIN_SECONDS = 60;

	/**
	 * 認証情報のリージョン（＝トークン取得先）。Creators API の Using cURL に載っている表のとおり。
	 * NA=3.1 / EU=3.2 / FE=3.3。マーケットプレイスから引く（ストアのホームリージョンと一致する前提）。
	 *
	 * @var array<string, string>
	 */
	private const FE_MARKETPLACES = array(
		'www.amazon.co.jp'  => 'api.amazon.co.jp',
		'www.amazon.sg'     => 'api.amazon.co.jp',
		'www.amazon.com.au' => 'api.amazon.co.jp',
	);

	/** @var list<string> */
	private const NA_MARKETPLACES = array(
		'www.amazon.com',
		'www.amazon.ca',
		'www.amazon.com.mx',
		'www.amazon.com.br',
	);

	/**
	 * getItems を呼ぶ。
	 *
	 * 戻り値の error=true は「HTTP の手前で失敗した」（通信断・トークン取得失敗）ことを表し、
	 * そのとき code は 0。HTTP 応答を得たら error=false で code に status を入れる。
	 * requests は API から応答を受け取った回数（401 でトークンを取り直して再送し、また応答があれば 2）。
	 * 呼び出し回数の集計に使う。error=true でも、その前に 401 を受けていれば 1 になる。
	 *
	 * @param array<string, string> $credentials
	 * @param list<string>          $itemIds
	 * @param list<string>          $resources
	 * @return array{error: bool, code: int, decoded: array<string, mixed>|null, requests: int}
	 */
	public function getItems( array $credentials, array $itemIds, array $resources ): array {
		$marketplace = self::marketplace( $credentials );
		$body        = (string) wp_json_encode(
			array(
				'itemIds'     => array_values( $itemIds ),
				'itemIdType'  => 'ASIN',
				'marketplace' => $marketplace,
				'partnerTag'  => (string) ( $credentials['partner_tag'] ?? '' ),
				'resources'   => array_values( $resources ),
			)
		);

		$token = $this->token( $credentials, false );
		if ( null === $token ) {
			return self::failure() + array( 'requests' => 0 );
		}
		$res      = $this->post( $token, $marketplace, $body );
		$requests = $res['error'] ? 0 : 1;
		if ( ! $res['error'] && 401 === $res['code'] ) {
			// 期限内のはずのトークンが失効していた（取り消し・認証情報の差し替え等）。1 回だけ取り直す。
			$token = $this->token( $credentials, true );
			if ( null === $token ) {
				return self::failure() + array( 'requests' => $requests );
			}
			$res       = $this->post( $token, $marketplace, $body );
			$requests += $res['error'] ? 0 : 1;
		}
		return $res + array( 'requests' => $requests );
	}

	/**
	 * トークンを取得できるかだけを確かめる（疎通確認用）。
	 *
	 * @param array<string, string> $credentials
	 */
	public function canAuthenticate( array $credentials ): bool {
		return null !== $this->token( $credentials, true );
	}

	/**
	 * @param array<string, string> $credentials
	 */
	public static function marketplace( array $credentials ): string {
		$marketplace = strtolower( trim( (string) ( $credentials['marketplace'] ?? '' ) ) );
		$marketplace = (string) preg_replace( '#^https?://#', '', $marketplace );
		$marketplace = rtrim( $marketplace, '/' );
		return '' === $marketplace ? self::DEFAULT_MARKETPLACE : $marketplace;
	}

	public static function tokenHost( string $marketplace ): string {
		if ( isset( self::FE_MARKETPLACES[ $marketplace ] ) ) {
			return self::FE_MARKETPLACES[ $marketplace ];
		}
		if ( in_array( $marketplace, self::NA_MARKETPLACES, true ) ) {
			return 'api.amazon.com';
		}
		return 'api.amazon.co.uk';
	}

	/**
	 * @param array<string, string> $credentials
	 */
	public static function tokenTransientKey( array $credentials ): string {
		$id   = (string) ( $credentials['credential_id'] ?? '' );
		$host = self::tokenHost( self::marketplace( $credentials ) );
		// 認証情報を差し替えたら別キーになり、古いトークンを使わない。
		return self::TRANSIENT_PREFIX . substr( hash( 'sha256', $id . '|' . $host ), 0, 16 );
	}

	/**
	 * @param array<string, string> $credentials
	 */
	private function token( array $credentials, bool $forceRefresh ): ?string {
		$key = self::tokenTransientKey( $credentials );
		if ( $forceRefresh ) {
			delete_transient( $key );
		} else {
			$cached = get_transient( $key );
			if ( is_string( $cached ) && '' !== $cached ) {
				$token = Crypto::decrypt( $cached );
				if ( '' !== $token ) {
					return $token;
				}
			}
		}

		$host     = self::tokenHost( self::marketplace( $credentials ) );
		$response = wp_remote_post(
			'https://' . $host . self::TOKEN_PATH,
			array(
				'timeout' => self::TIMEOUT_SECONDS,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'grant_type'    => 'client_credentials',
						'client_id'     => (string) ( $credentials['credential_id'] ?? '' ),
						'client_secret' => (string) ( $credentials['credential_secret'] ?? '' ),
						'scope'         => self::TOKEN_SCOPE,
					)
				),
			)
		);
		if ( self::isWpError( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['access_token'] ) || ! is_string( $decoded['access_token'] ) || '' === $decoded['access_token'] ) {
			return null;
		}
		$token     = $decoded['access_token'];
		$expiresIn = isset( $decoded['expires_in'] ) && is_numeric( $decoded['expires_in'] ) ? (int) $decoded['expires_in'] : 3600;
		$ttl       = $expiresIn - self::EXPIRY_MARGIN_SECONDS;
		if ( $ttl > 0 ) {
			set_transient( $key, Crypto::encrypt( $token ), $ttl );
		}
		return $token;
	}

	/**
	 * @return array{error: bool, code: int, decoded: array<string, mixed>|null}
	 */
	private function post( string $token, string $marketplace, string $body ): array {
		$response = wp_remote_post(
			self::API_BASE . self::GET_ITEMS_PATH,
			array(
				'timeout' => self::TIMEOUT_SECONDS,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'x-marketplace' => $marketplace,
				),
				'body'    => $body,
			)
		);
		if ( self::isWpError( $response ) ) {
			return self::failure();
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array(
			'error'   => false,
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'decoded' => is_array( $decoded ) ? $decoded : null,
		);
	}

	/**
	 * @return array{error: bool, code: int, decoded: array<string, mixed>|null}
	 */
	private static function failure(): array {
		return array(
			'error'   => true,
			'code'    => 0,
			'decoded' => null,
		);
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
