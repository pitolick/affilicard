<?php
declare(strict_types=1);

namespace Affilicard\Provider\Rakuten;

use Affilicard\Account\AccountCredentials;
use Affilicard\Provider\ProviderInterface;

/**
 * 楽天Kobo 電子書籍検索 API を使った電子書籍の自動取得 Provider。
 *
 * 2026 年の楽天 API 刷新に対応（openapi.rakuten.co.jp・accessKey ヘッダ・Origin 必須）。
 */
final class RakutenProvider implements ProviderInterface {

	private ?RakutenClient $client = null;

	public function code(): string {
		return 'rakuten-kobo';
	}

	public function label(): string {
		return __( '楽天Kobo API', 'affilicard' );
	}

	public function isAutomatic(): bool {
		return true;
	}

	public function accountCode(): ?string {
		return 'rakuten';
	}

	/**
	 * 楽天Kobo 検索 API は商品 ID 直引きができないため、keyword（search_key もしくは
	 * legacy な external_id）で検索し、各ヒットの itemUrl に含まれる `rk/<hash>` を
	 * external_id と突き合わせて厳密同定する。ハッシュ一致が 0 件・複数件の場合は
	 * 誤上書きを避けるため null を返す（非破壊）。
	 *
	 * @param array<string, mixed> $platformConfig
	 * @return array<string, mixed>|null
	 */
	public function fetch( string $externalId, array $platformConfig ): ?array {
		$credentials = AccountCredentials::get( (string) $this->accountCode() );
		if ( ! self::hasRequiredCredentials( $credentials ) ) {
			return null;
		}

		$search_key = isset( $platformConfig['search_key'] ) ? trim( (string) $platformConfig['search_key'] ) : '';
		$is_numeric = ( '' === $search_key ) && 1 === preg_match( '/^\d+$/', $externalId );

		if ( '' === $search_key && '' === $externalId ) {
			return null;
		}

		$query = array(
			'applicationId' => $credentials['application_id'],
			'affiliateId'   => $credentials['affiliate_id'],
			'format'        => 'json',
			'formatVersion' => '2',
			'hits'          => '30',
		);
		if ( '' !== $search_key ) {
			$query['keyword'] = $search_key;
		} elseif ( $is_numeric ) {
			$query['itemNumber'] = $externalId;
		} else {
			// legacy: search_key 無し＋非数字 external_id（URLハッシュ）。keyword に載せても一致し得ないが後方互換で叩く。
			$query['keyword'] = $externalId;
		}

		$res = $this->client()->request( $query, $credentials );
		if ( $res['error'] || 200 !== $res['code'] || null === $res['decoded'] || isset( $res['decoded']['errors'] ) ) {
			return null;
		}
		$items = ( isset( $res['decoded']['Items'] ) && is_array( $res['decoded']['Items'] ) ) ? $res['decoded']['Items'] : array();
		if ( array() === $items ) {
			return null;
		}

		// URLハッシュ一致で厳密同定（誤上書き防止）。
		if ( '' !== $externalId ) {
			$matches = array();
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$url = isset( $item['itemUrl'] ) ? (string) $item['itemUrl'] : '';
				if ( self::extractRkHash( $url ) === $externalId ) {
					$matches[] = $item;
				}
			}
			if ( 1 === count( $matches ) ) {
				return self::normalizeItem( $matches[0] );
			}
			if ( count( $matches ) > 1 ) {
				return null; // 曖昧 → 非破壊
			}
		}

		// ハッシュ一致なし: 数字 external_id（itemNumber 検索）は先頭ヒットを採用。それ以外は非破壊 null。
		if ( $is_numeric ) {
			$first = self::firstItem( $res['decoded'] );
			return null === $first ? null : self::normalizeItem( $first );
		}
		return null;
	}

	/**
	 * itemUrl に含まれる `rk/<hash>` を抽出する。無ければ空文字。
	 */
	private static function extractRkHash( string $itemUrl ): string {
		// delimiter に # を使うため、文字クラス内の # はエスケープする（さもなくば delimiter と衝突し preg_match が Unknown modifier エラーになる）。
		if ( 1 === preg_match( '#/rk/([^/?\#]+)#', $itemUrl, $m ) ) {
			return $m[1];
		}
		return '';
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

		$res = $this->client()->request( $query, $credentials );
		if ( $res['error'] ) {
			return array(
				'ok'      => false,
				'message' => __( '楽天APIへの接続に失敗しました', 'affilicard' ),
			);
		}

		if ( 200 !== $res['code'] ) {
			return array(
				'ok'      => false,
				'message' => self::errorMessage( $res['code'] ),
			);
		}
		if ( null === $res['decoded'] || isset( $res['decoded']['errors'] ) ) {
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

	private function client(): RakutenClient {
		return $this->client ??= new RakutenClient();
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
	 * @param array<string, mixed> $decoded
	 * @return array<string, mixed>|null
	 */
	private static function firstItem( array $decoded ): ?array {
		if ( ! isset( $decoded['Items'] ) || ! is_array( $decoded['Items'] ) || array() === $decoded['Items'] ) {
			return null;
		}
		$first = $decoded['Items'][0] ?? null;
		return is_array( $first ) ? $first : null;
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private static function normalizeItem( array $item ): array {
		$image_url = '';
		foreach ( array( 'largeImageUrl', 'mediumImageUrl', 'smallImageUrl' ) as $key ) {
			if ( isset( $item[ $key ] ) && is_string( $item[ $key ] ) && '' !== $item[ $key ] ) {
				$image_url = $item[ $key ];
				break;
			}
		}

		return array(
			'title'           => isset( $item['title'] ) ? (string) $item['title'] : '',
			'price'           => isset( $item['itemPrice'] ) ? (string) $item['itemPrice'] : '',
			'list_price'      => '',
			'badge'           => '',
			'image_url'       => $image_url,
			'regular_url'     => isset( $item['itemUrl'] ) ? (string) $item['itemUrl'] : '',
			'affiliate_url'   => isset( $item['affiliateUrl'] ) ? (string) $item['affiliateUrl'] : '',
			'platform_extras' => array(
				'release_date' => self::normalizeDate( isset( $item['salesDate'] ) ? (string) $item['salesDate'] : '' ),
				'series_name'  => isset( $item['seriesName'] ) ? (string) $item['seriesName'] : '',
				'author'       => isset( $item['author'] ) ? (string) $item['author'] : '',
				'publisher'    => isset( $item['publisherName'] ) ? (string) $item['publisherName'] : '',
			),
			'raw'             => $item,
		);
	}

	private static function normalizeDate( string $salesDate ): string {
		if ( 1 === preg_match( '/^(\d{4})年(\d{1,2})月(\d{1,2})日$/u', $salesDate, $m ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
		}
		return '';
	}
}
