<?php
declare(strict_types=1);

namespace Affilicard\Provider\Dmm;

use Affilicard\Provider\ProviderCredentials;
use Affilicard\Provider\ProviderInterface;

/**
 * DMM Web Service API v3 を使った電子書籍の自動取得 Provider。
 *
 * 価格・割引率・サムネイル・アフィリエイト URL を ItemList エンドポイントから抽出する。
 */
final class DmmProvider implements ProviderInterface {

	private const ENDPOINT = 'https://api.dmm.com/affiliate/v3/ItemList';

	public function code(): string {
		return 'dmm-ebook';
	}

	public function label(): string {
		return __( 'DMM ebook API', 'affilicard' );
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
				'key'      => 'api_id',
				'label'    => __( 'API ID', 'affilicard' ),
				'type'     => 'password',
				'required' => true,
			),
			array(
				'key'      => 'affiliate_id',
				'label'    => __( 'アフィリエイト ID', 'affilicard' ),
				'type'     => 'password',
				'required' => true,
			),
		);
	}

	/**
	 * @param array<string, mixed> $platformConfig
	 * @return array<string, mixed>|null
	 */
	public function fetch( string $externalId, array $platformConfig ): ?array {
		$credentials = ProviderCredentials::get( $this->code() );
		if ( empty( $credentials['api_id'] ) || empty( $credentials['affiliate_id'] ) ) {
			return null;
		}
		if ( '' === $externalId ) {
			return null;
		}

		$url = $this->buildUrl(
			array(
				'api_id'       => $credentials['api_id'],
				'affiliate_id' => $credentials['affiliate_id'],
				'site'         => 'DMM.com',
				'service'      => 'ebook',
				'floor'        => 'comic',
				'hits'         => '1',
				'keyword'      => $externalId,
				'output'       => 'json',
			)
		);

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( self::isWpError( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return null;
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$item = self::firstItem( $decoded );
		if ( null === $item ) {
			return null;
		}

		return self::normalizeItem( $item );
	}

	/**
	 * @param array<string, string> $credentials
	 * @return array{ok: bool, message: string}
	 */
	public function testConnection( array $credentials ): array {
		if ( empty( $credentials['api_id'] ) || empty( $credentials['affiliate_id'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'API ID とアフィリエイト ID を入力してください', 'affilicard' ),
			);
		}

		$url = $this->buildUrl(
			array(
				'api_id'       => $credentials['api_id'],
				'affiliate_id' => $credentials['affiliate_id'],
				'site'         => 'DMM.com',
				'service'      => 'ebook',
				'floor'        => 'comic',
				'hits'         => '1',
				'output'       => 'json',
			)
		);

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( self::isWpError( $response ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'DMM API への接続に失敗しました', 'affilicard' ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %d: HTTP status code */
					__( 'DMM API が HTTP %d を返しました', 'affilicard' ),
					$code
				),
			);
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'DMM API のレスポンスを解釈できません', 'affilicard' ),
			);
		}

		$status = isset( $decoded['result']['status'] ) ? (int) $decoded['result']['status'] : 0;
		if ( 200 !== $status && 0 !== $status ) {
			return array(
				'ok'      => false,
				/* translators: %d: API status code returned in body */
				'message' => sprintf( __( 'DMM API がエラーステータス %d を返しました', 'affilicard' ), $status ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'DMM API への接続に成功しました', 'affilicard' ),
		);
	}

	/**
	 * @param array<string, string> $params
	 */
	private function buildUrl( array $params ): string {
		return self::ENDPOINT . '?' . http_build_query( $params );
	}

	/**
	 * @param array<string, mixed> $decoded
	 * @return array<string, mixed>|null
	 */
	private static function firstItem( array $decoded ): ?array {
		if ( ! isset( $decoded['result']['items'] ) || ! is_array( $decoded['result']['items'] ) ) {
			return null;
		}
		$items = $decoded['result']['items'];
		if ( array() === $items ) {
			return null;
		}
		$first = $items[0] ?? null;
		return is_array( $first ) ? $first : null;
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private static function normalizeItem( array $item ): array {
		$prices     = isset( $item['prices'] ) && is_array( $item['prices'] ) ? $item['prices'] : array();
		$price      = isset( $prices['price'] ) ? (string) $prices['price'] : '';
		$list_price = isset( $prices['list_price'] ) ? (string) $prices['list_price'] : '';
		$badge      = '';
		if ( '' !== $price && '' !== $list_price && is_numeric( $price ) && is_numeric( $list_price ) ) {
			$p = (float) $price;
			$l = (float) $list_price;
			if ( $l > 0 && $p < $l ) {
				$badge = sprintf( '%d%%OFF', (int) floor( ( ( $l - $p ) / $l ) * 100 ) );
			}
		}

		$image_url = '';
		if ( isset( $item['imageURL'] ) && is_array( $item['imageURL'] ) ) {
			foreach ( array( 'large', 'list', 'small' ) as $key ) {
				if ( isset( $item['imageURL'][ $key ] ) && is_string( $item['imageURL'][ $key ] ) && '' !== $item['imageURL'][ $key ] ) {
					$image_url = $item['imageURL'][ $key ];
					break;
				}
			}
		}

		$regular_url   = isset( $item['URL'] ) ? (string) $item['URL'] : '';
		$affiliate_url = isset( $item['affiliateURL'] ) ? (string) $item['affiliateURL'] : '';

		return array(
			'title'           => isset( $item['title'] ) ? (string) $item['title'] : '',
			'price'           => $price,
			'list_price'      => $list_price,
			'badge'           => $badge,
			'image_url'       => $image_url,
			'regular_url'     => $regular_url,
			'affiliate_url'   => $affiliate_url,
			'platform_extras' => array(),
			'raw'             => $item,
		);
	}

	/**
	 * WP_Mock 環境で is_wp_error が定義されていないケースに備えるラッパ。
	 *
	 * @param mixed $value
	 */
	private static function isWpError( $value ): bool {
		if ( function_exists( 'is_wp_error' ) ) {
			return (bool) is_wp_error( $value );
		}
		return $value instanceof \WP_Error;
	}
}
