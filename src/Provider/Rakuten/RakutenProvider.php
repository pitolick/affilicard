<?php
declare(strict_types=1);

namespace Affilicard\Provider\Rakuten;

use Affilicard\Account\AccountCredentials;
use Affilicard\Provider\FetchResult;
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
	 * external_id と突き合わせて厳密同定する。
	 *
	 * 結果は FetchResult の3値で分類する:
	 * - credentials 未設定 = error()（transient・後で設定され得る）
	 * - search_key と external_id が両方空 = miss()（terminal・データ不備。リトライで解決しない）
	 * - API 到達不可・エラー応答（error/非200/errors/非JSON）= error()（transient）
	 * - 200 だが Items 空・ハッシュ一致 0 件・曖昧（複数一致）・該当なし = miss()（terminal・非破壊）
	 * - ハッシュ一致 1 件 / 数字 external_id の先頭ヒット = hit()
	 *
	 * @param array<string, mixed> $platformConfig
	 */
	public function fetch( string $externalId, array $platformConfig ): FetchResult {
		$credentials = AccountCredentials::get( (string) $this->accountCode() );
		if ( ! self::hasRequiredCredentials( $credentials ) ) {
			return FetchResult::error();
		}

		$search_key = isset( $platformConfig['search_key'] ) ? trim( (string) $platformConfig['search_key'] ) : '';
		$is_numeric = ( '' === $search_key ) && 1 === preg_match( '/^\d+$/', $externalId );

		if ( '' === $search_key && '' === $externalId ) {
			return FetchResult::miss();
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
			// API 到達不可・エラー応答は一時失敗（transient）。後で回復し得るため give-up しない。
			return FetchResult::error();
		}
		$items = ( isset( $res['decoded']['Items'] ) && is_array( $res['decoded']['Items'] ) ) ? $res['decoded']['Items'] : array();
		if ( array() === $items ) {
			// 200 で応答したが該当なし＝恒久失敗（terminal）。
			return FetchResult::miss();
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
				return FetchResult::hit( self::normalizeItem( $matches[0] ) );
			}
			if ( count( $matches ) > 1 ) {
				// 曖昧（複数一致）→ 非破壊。リトライしても曖昧さは解消しないため恒久失敗（terminal）。
				return FetchResult::miss();
			}
		}

		// ハッシュ一致なし: 数字 external_id（itemNumber 検索）は先頭ヒットを採用。
		if ( $is_numeric ) {
			$first = self::firstItem( $res['decoded'] );
			return null === $first ? FetchResult::miss() : FetchResult::hit( self::normalizeItem( $first ) );
		}
		// 該当なし（非数字・ハッシュ不一致）＝恒久失敗（terminal・非破壊）。
		return FetchResult::miss();
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

	public function minRequestIntervalMs(): int {
		return 1100; // 楽天 openapi ≈ 1 req/sec/app ＋余裕
	}
}
