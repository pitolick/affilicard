<?php
declare(strict_types=1);

namespace Affilicard\Platform;

use InvalidArgumentException;

/**
 * 1 つのアフィリエイトプラットフォーム（DMM ブックス・Amazon Kindle 等）を表す immutable value object。
 */
final class PlatformDefinition {

	/**
	 * リンクテンプレートの差し込み口。
	 *
	 * - `{url}`     作品 URL（regular_url）そのまま
	 * - `{url_enc}` 作品 URL を rawurlencode() したもの
	 * - `{sep}`     作品 URL にクエリがあれば `&`、無ければ `?`（`?` / `&` で終わっていれば空）
	 */
	public const PLACEHOLDER_URL     = '{url}';
	public const PLACEHOLDER_URL_ENC = '{url_enc}';
	public const PLACEHOLDER_SEP     = '{sep}';

	/**
	 * @param list<string> $applicableTypes 適用可能な商品タイプコード（例: ['ebook'], ['generic', 'ebook']）
	 * @param string       $linkTemplate    アフィリエイトリンクの組み立て方（空＝使わない）。{@see self::applyLinkTemplate()}
	 * @param list<string> $linkHosts       テンプレートを適用してよい作品 URL のホスト（空＝使わない）
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $name,
		public readonly string $provider,
		public readonly int $displayOrder,
		public readonly bool $enabled,
		public readonly array $applicableTypes,
		public readonly string $buttonLabel,
		public readonly string $brandColor,
		public readonly string $buttonTextColor,
		public readonly string $eligibleProvider = '',
		public readonly int $priceTtlHours = 24,
		public readonly string $linkTemplate = '',
		public readonly array $linkHosts = array()
	) {
		if ( '' === $this->code ) {
			throw new InvalidArgumentException( 'PlatformDefinition: code must not be empty.' );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'code'             => $this->code,
			'name'             => $this->name,
			'provider'         => $this->provider,
			'displayOrder'     => $this->displayOrder,
			'enabled'          => $this->enabled,
			'applicableTypes'  => $this->applicableTypes,
			'buttonLabel'      => $this->buttonLabel,
			'brandColor'       => $this->brandColor,
			'buttonTextColor'  => $this->buttonTextColor,
			'eligibleProvider' => $this->eligibleProvider,
			'priceTtlHours'    => $this->priceTtlHours,
			'linkTemplate'     => $this->linkTemplate,
			'linkHosts'        => $this->linkHosts,
		);
	}

	/**
	 * 作品 URL（regular_url）からアフィリエイトリンクを組み立てる。
	 *
	 * 組み立てられないときは**空文字を返す**（呼び出し側は regular_url に倒す）:
	 *
	 * - テンプレートまたは対象ホストが空
	 * - テンプレートが差し込み口（`{url}` / `{url_enc}`）を持たない
	 * - 作品 URL のホストが対象ホストに一致しない（別サイトの URL を誤って登録したとき、
	 *   それがアフィリエイトリンクとして出るのを防ぐ）
	 *
	 * 結果の検証（危険スキームの排除）は呼び出し側（{@see \Affilicard\Pricing\OfferUrl}）が行う。
	 */
	public function applyLinkTemplate( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || ! self::isUsableTemplate( $this->linkTemplate ) || array() === $this->linkHosts ) {
			return '';
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || ! self::hostMatches( strtolower( $host ), $this->linkHosts ) ) {
			return '';
		}
		// フラグメント（#以降）はクエリより後ろに来るため、区切り文字の判定から外す。
		$hash_pos = strpos( $url, '#' );
		$base     = false === $hash_pos ? $url : substr( $url, 0, $hash_pos );
		$fragment = false === $hash_pos ? '' : substr( $url, $hash_pos );

		// クエリ追記型（`{url}{sep}tag=…`）は、テンプレートが作品 URL で始まる。
		// このときフラグメントの後ろにパラメータを足すとクエリにならない（サーバへ送られない）
		// ため、作品 URL はフラグメント抜きで差し込み、フラグメントは最後に戻す。
		// 包む型（`https://…?url={url_enc}`）は作品 URL をフラグメントごと渡す。
		$appends = str_starts_with( $this->linkTemplate, self::PLACEHOLDER_URL );

		$result = strtr(
			$this->linkTemplate,
			array(
				self::PLACEHOLDER_URL_ENC => rawurlencode( $url ),
				self::PLACEHOLDER_URL     => $appends ? $base : $url,
				self::PLACEHOLDER_SEP     => self::querySeparator( $base ),
			)
		);
		return $appends ? $result . $fragment : $result;
	}

	/**
	 * 作品 URL（フラグメント抜き）にクエリを足すときの区切り文字。
	 *
	 * - クエリが無い → `?`
	 * - クエリが空（最初の `?` の後ろに何も無い）か `&` で終わっている → 区切り文字なし（`??` を作らない）
	 * - クエリがある → `&`（クエリ内の `?` はデータなので、末尾が `?` でも `&` を足す）
	 */
	private static function querySeparator( string $base ): string {
		$query_pos = strpos( $base, '?' );
		if ( false === $query_pos ) {
			return '?';
		}
		$query = substr( $base, $query_pos + 1 );
		return '' === $query || str_ends_with( $query, '&' ) ? '' : '&';
	}

	/**
	 * テンプレートとして使える形か（https:// か {url} で始まり、作品 URL の差し込み口を持つ）。
	 */
	public static function isUsableTemplate( string $template ): bool {
		if ( '' === $template ) {
			return false;
		}
		$starts_ok = str_starts_with( $template, 'https://' ) || str_starts_with( $template, self::PLACEHOLDER_URL );
		$has_url   = str_contains( $template, self::PLACEHOLDER_URL ) || str_contains( $template, self::PLACEHOLDER_URL_ENC );
		return $starts_ok && $has_url;
	}

	/**
	 * テンプレートを正規化する。使えない形なら空文字（保存はするが使わない＝安全側）。
	 */
	public static function normaliseLinkTemplate( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$template = trim( $value );
		return self::isUsableTemplate( $template ) ? $template : '';
	}

	/**
	 * 対象ホストを正規化する。文字列の配列（またはカンマ区切り文字列）を受け、
	 * 小文字化・前後空白除去・スキームやパスの除去・空要素と重複の除去を行う。
	 *
	 * @return list<string>
	 */
	public static function normaliseLinkHosts( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$hosts = array();
		foreach ( $value as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}
			$host = strtolower( trim( $entry ) );
			if ( '' === $host ) {
				continue;
			}
			if ( str_contains( $host, '://' ) ) {
				$parsed = wp_parse_url( $host, PHP_URL_HOST );
				$host   = is_string( $parsed ) ? $parsed : '';
			} else {
				// 「hulu.jp/foo」のようにパスが付いていればホストだけ残す。
				$host = (string) preg_replace( '#[/?\#].*$#', '', $host );
			}
			$host = trim( $host, '. ' );
			if ( '' === $host || 1 !== preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
				continue;
			}
			$hosts[ $host ] = true;
		}
		return array_keys( $hosts );
	}

	/**
	 * ホストが対象ホストのいずれかに一致するか。`hulu.jp` は `www.hulu.jp` にも当たる。
	 *
	 * @param list<string> $allowed
	 */
	private static function hostMatches( string $host, array $allowed ): bool {
		foreach ( $allowed as $candidate ) {
			if ( $host === $candidate || str_ends_with( $host, '.' . $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 配列から PlatformDefinition を生成する。
	 *
	 * 欠損キーは妥当なデフォルトで補完する。空 code は例外。
	 *
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): self {
		$code = isset( $data['code'] ) ? (string) $data['code'] : '';
		if ( '' === $code ) {
			throw new InvalidArgumentException( 'PlatformDefinition::fromArray: code is required.' );
		}

		$applicable_types = array();
		if ( isset( $data['applicableTypes'] ) && is_array( $data['applicableTypes'] ) ) {
			foreach ( $data['applicableTypes'] as $type ) {
				$applicable_types[] = (string) $type;
			}
		}
		if ( array() === $applicable_types ) {
			$applicable_types = array( 'generic' );
		}

		$price_ttl = isset( $data['priceTtlHours'] ) ? (int) $data['priceTtlHours'] : 24;
		if ( $price_ttl < 1 ) {
			$price_ttl = 24;
		}

		return new self(
			$code,
			isset( $data['name'] ) ? (string) $data['name'] : $code,
			isset( $data['provider'] ) ? (string) $data['provider'] : 'manual',
			isset( $data['displayOrder'] ) ? (int) $data['displayOrder'] : 999,
			isset( $data['enabled'] ) ? (bool) $data['enabled'] : true,
			$applicable_types,
			isset( $data['buttonLabel'] ) && '' !== (string) $data['buttonLabel'] ? (string) $data['buttonLabel'] : '購入する',
			isset( $data['brandColor'] ) && '' !== (string) $data['brandColor'] ? (string) $data['brandColor'] : '#444444',
			isset( $data['buttonTextColor'] ) && '' !== (string) $data['buttonTextColor'] ? (string) $data['buttonTextColor'] : '#ffffff',
			isset( $data['eligibleProvider'] ) ? (string) $data['eligibleProvider'] : '',
			$price_ttl,
			self::normaliseLinkTemplate( $data['linkTemplate'] ?? '' ),
			self::normaliseLinkHosts( $data['linkHosts'] ?? array() )
		);
	}
}
