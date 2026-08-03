<?php
declare(strict_types=1);

namespace Affilicard\Provider\Dmm;

use Affilicard\Account\AccountCredentials;
use Affilicard\Provider\FetchResult;
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

	public function accountCode(): ?string {
		return 'dmm';
	}

	/**
	 * 結果は FetchResult の3値で分類する:
	 * - credentials 未設定 = error()（transient・後で設定され得る）
	 * - external_id 空 = miss()（terminal・データ不備。リトライで解決しない）
	 * - API 到達不可（wp_error/非200/非JSON）= error()（transient）
	 * - items 空（該当なし）= miss()（terminal）
	 * - 成功 = hit(data)
	 *
	 * @param array<string, mixed> $platformConfig
	 */
	public function fetch( string $externalId, array $platformConfig ): FetchResult {
		$credentials = AccountCredentials::get( (string) $this->accountCode() );
		if ( empty( $credentials['api_id'] ) || empty( $credentials['affiliate_id'] ) ) {
			// 認証未設定は一時失敗（transient）。後で登録され得るため give-up しない。
			return FetchResult::error();
		}
		if ( '' === $externalId ) {
			// external_id 空はデータ不備（無効）＝リトライで解決しない恒久失敗（terminal）。
			return FetchResult::miss();
		}

		// **`cid`（商品 ID 直引き）を使う。`keyword` で content_id を渡してはいけない**
		// ——DMM の keyword 検索は**シリーズごとに最新巻 1 件だけ**を返すため、30 巻の
		// content_id で検索すると 39 巻（＝そのシリーズの最新巻）が返る（2026-08-03 実測）。
		// その結果を listing に書き戻すと、価格・表紙・商品 URL・アフィリエイト URL が
		// すべて別の巻のものに置き換わり、読者は 30 巻のカードから 39 巻を買わされる。
		$url = $this->buildUrl(
			array(
				'api_id'       => $credentials['api_id'],
				'affiliate_id' => $credentials['affiliate_id'],
				'site'         => 'DMM.com',
				'service'      => 'ebook',
				'floor'        => 'comic',
				'hits'         => '1',
				'cid'          => $externalId,
				'output'       => 'json',
			)
		);

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( self::isWpError( $response ) ) {
			return FetchResult::error();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return FetchResult::error();
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return FetchResult::error();
		}

		$item = self::firstItem( $decoded );
		if ( null === $item ) {
			// API 到達し 200 を得たが該当商品なし＝恒久失敗（terminal）。
			return FetchResult::miss();
		}

		return FetchResult::hit(
			self::normalizeItem( $item, (string) ( $credentials['affiliate_link_id'] ?? '' ) )
		);
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
	 * アフィリエイトリンクのリダイレクタ。`?lurl=<encoded>&af_id=<linkId>&ch=api` の形になる。
	 */
	private const AFFILIATE_BASE = 'https://al.dmm.com/';

	/**
	 * 商品ページ URL とリンク埋め込み用 ID からアフィリエイト URL を組み立てる。
	 *
	 * **API 応答の `affiliateURL` を使ってはいけない**——ItemList はリクエストに使った
	 * `affiliate_id`（末尾 990〜999 の API 用 ID）をそのまま `af_id` に埋めて返すため、
	 * そのリンクは `al.dmm.com` が HTTP 400「無効リンク」を返す（2026-08-03 実測）。
	 * 実際にリンクへ載せてよいのはサイト単位で発行される別 ID（`affiliate_link_id`）。
	 *
	 * リンク用 ID が未設定なら**空文字を返す**。空を返すと `ListingRefresher` は
	 * 既存の `affiliate_url` を保持する（非空のときだけ上書きする実装）ため、
	 * 手で登録した正しいリンクを壊さない。カード側も `affiliate_url ?: regular_url` で
	 * 通常 URL にフォールバックするので、リンクが死ぬことはない。
	 */
	private static function buildAffiliateUrl( string $productUrl, string $linkId ): string {
		if ( '' === $productUrl || '' === $linkId ) {
			return '';
		}
		return self::AFFILIATE_BASE . '?lurl=' . rawurlencode( $productUrl )
			. '&af_id=' . rawurlencode( $linkId ) . '&ch=api';
	}

	/**
	 * @param array<string, mixed> $item
	 * @param string               $linkId リンク埋め込み用アフィリエイト ID（API 用とは別値）。
	 * @return array<string, mixed>
	 */
	private static function normalizeItem( array $item, string $linkId = '' ): array {
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

		$regular_url = isset( $item['URL'] ) ? (string) $item['URL'] : '';
		// API 応答の affiliateURL フィールドは意図的に使わない（buildAffiliateUrl の説明を参照）。
		$affiliate_url = self::buildAffiliateUrl( $regular_url, $linkId );

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

	public function minRequestIntervalMs(): int {
		return 1000; // 暫定・公式/実測で確定
	}
}
