<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\Pricing\OfferSelector;
use Affilicard\Stock\StockStatus;

/**
 * `/products` 系エンドポイントの input schema を返す。
 *
 * register_rest_route の args として渡すことを想定。
 */
final class ProductSchema {

	/**
	 * 移行中だけ「regular_url が空の offer を弾く」ルールを外すフラグ。
	 *
	 * 既定は false（＝新規保存では必ず弾く）。{@see self::withLegacyOfferPreservation()}
	 * の実行中だけ true になる。
	 *
	 * @var bool
	 */
	private static bool $preserveOffersWithoutRegularUrl = false;

	/**
	 * 「regular_url が空の offer を弾く」ルールを外して $callback を実行する。
	 *
	 * このルールは新規入力向けである（生死を判定できない offer を今後は作らせない）。
	 * v3 以前のインストールには手入力で affiliate_url だけを持つ listing が実在し得るため、
	 * 移行に遡って適用すると復元不能な形でデータが消える。
	 *
	 * **移行の書き込みが `update_post_meta()` を通ると、WordPress は `update_metadata()`
	 * の中で `sanitize_meta()` を走らせる。** ProductMeta::register() が META_LISTINGS に
	 * `sanitize_callback => ProductSchema::sanitizeListings` を登録しているため、移行が
	 * メモリ上でどれだけ丁寧に温存しても、保存の瞬間に sanitizeOffers() が同じ offer を
	 * 落としてしまう。この窓はその一点だけを無効化するためにある。
	 *
	 * sanitize 自体は止めない（esc_url_raw / sanitize_text_field / whitelist はそのまま
	 * 通す）。フィルタを外して生値を書く方式より窓が狭く、移行が壊れた値を保存する
	 * 余地を残さないため。窓は移行の書き込み 1 回分に限定し、finally で必ず戻す。
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public static function withLegacyOfferPreservation( callable $callback ) {
		$previous                              = self::$preserveOffersWithoutRegularUrl;
		self::$preserveOffersWithoutRegularUrl = true;
		try {
			return $callback();
		} finally {
			self::$preserveOffersWithoutRegularUrl = $previous;
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function args(): array {
		return array(
			'title'        => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'content'      => array(
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'wp_kses_post',
			),
			'status'       => array(
				'type'    => 'string',
				'enum'    => array( 'publish', 'draft', 'pending', 'future' ),
				'default' => 'publish',
			),
			'product_type' => array(
				'type'              => 'string',
				'default'           => 'generic',
				'sanitize_callback' => 'sanitize_key',
			),
			'stock_status' => array(
				'type'    => 'string',
				'enum'    => StockStatus::all(),
				'default' => StockStatus::AVAILABLE,
			),
			'extras'       => array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( self::class, 'sanitizeExtras' ),
			),
			'release_date' => array(
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitizeReleaseDate' ),
			),
			'listings'     => array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( self::class, 'sanitizeListings' ),
			),
		);
	}

	/**
	 * PATCH（部分更新）用の args。
	 *
	 * create 用 {@see self::args()} から `required` と `default` を取り除く。
	 * これにより未送信フィールドは `WP_REST_Request::get_param()` で null となり、
	 * ProductsController::update() のマージで既存値が保持される（真の部分更新）。
	 * 例えば metabox は title を送らないため、title を必須にすると 400 になり保存できない。
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function updateArgs(): array {
		$args = self::args();
		foreach ( $args as $key => $definition ) {
			unset( $definition['required'] );
			unset( $definition['default'] );
			$args[ $key ] = $definition;
		}
		return $args;
	}

	/**
	 * bulk 用に 1 商品アイテムをサニタイズする。
	 *
	 * register_rest_route の per-arg sanitize_callback を通らない bulk 入力に対し、
	 * 単品 create と同等のサニタイズを適用する。extras/listings は既存 sanitizer を再利用。
	 *
	 * @param array<string, mixed> $item
	 * @return array{title: string, content: string, status: string, product_type: string, stock_status: string, release_date: string, extras: list<array<string, string>>, listings: list<array<string, mixed>>}
	 */
	public static function sanitizeItem( array $item ): array {
		$status = isset( $item['status'] ) ? (string) $item['status'] : 'publish';
		if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'future' ), true ) ) {
			$status = 'publish';
		}

		$product_type = isset( $item['product_type'] ) && '' !== (string) $item['product_type']
			? (string) sanitize_key( (string) $item['product_type'] )
			: 'generic';

		$stock_status = isset( $item['stock_status'] ) ? (string) $item['stock_status'] : StockStatus::AVAILABLE;
		if ( ! in_array( $stock_status, StockStatus::all(), true ) ) {
			$stock_status = StockStatus::AVAILABLE;
		}

		return array(
			'title'        => isset( $item['title'] ) ? (string) sanitize_text_field( (string) $item['title'] ) : '',
			'content'      => isset( $item['content'] ) ? (string) wp_kses_post( (string) $item['content'] ) : '',
			'status'       => $status,
			'product_type' => $product_type,
			'stock_status' => $stock_status,
			'release_date' => self::sanitizeReleaseDate( $item['release_date'] ?? '' ),
			'extras'       => self::sanitizeExtras( $item['extras'] ?? array() ),
			'listings'     => self::sanitizeListings( $item['listings'] ?? array() ),
		);
	}

	/**
	 * Hybrid extras を sanitize する。
	 *
	 * - 各エントリは ['label' => string, 'value' => string, 'key' => string?] を期待
	 * - label / value が両方空のエントリは除外する
	 *
	 * @param mixed $extras
	 * @return list<array<string, string>>
	 */
	public static function sanitizeExtras( $extras ): array {
		if ( ! is_array( $extras ) ) {
			return array();
		}

		$result = array();
		foreach ( $extras as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$label = isset( $entry['label'] ) ? (string) sanitize_text_field( (string) $entry['label'] ) : '';
			$value = isset( $entry['value'] ) ? (string) sanitize_text_field( (string) $entry['value'] ) : '';

			if ( '' === $label && '' === $value ) {
				continue;
			}

			$row = array(
				'label' => $label,
				'value' => $value,
			);

			if ( isset( $entry['key'] ) && '' !== (string) $entry['key'] ) {
				$row['key'] = (string) sanitize_key( (string) $entry['key'] );
			}

			$result[] = $row;
		}

		return $result;
	}

	/**
	 * release_date を `YYYY-MM-DD` のみ許可する。不正・空は空文字。
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function sanitizeReleaseDate( $value ): string {
		$str = is_string( $value ) ? trim( $value ) : '';
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $str ) ? $str : '';
	}

	/**
	 * listings を sanitize する。
	 *
	 * - 各エントリの platform は文字列必須（空文字なら除外）
	 * - enabled / auto_update は bool キャスト
	 * - listing が持つのは設定 6 フィールド（platform / enabled / update_mode /
	 *   auto_update / button_label_override / platform_extras）のみで、
	 *   購入リンクは {@see self::sanitizeOffers()} が `offers[]` へまとめる
	 *
	 * @param mixed $listings
	 * @return list<array<string, mixed>>
	 */
	public static function sanitizeListings( $listings ): array {
		if ( ! is_array( $listings ) ) {
			return array();
		}

		$result = array();
		foreach ( $listings as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$platform = isset( $entry['platform'] ) ? (string) sanitize_key( (string) $entry['platform'] ) : '';
			if ( '' === $platform ) {
				continue;
			}

			$platform_extras = array();
			if ( isset( $entry['platform_extras'] ) && is_array( $entry['platform_extras'] ) ) {
				foreach ( $entry['platform_extras'] as $k => $v ) {
					if ( ! is_string( $k ) ) {
						continue;
					}
					$platform_extras[ $k ] = is_scalar( $v ) ? (string) $v : '';
				}
			}

			$row = array(
				'platform'              => $platform,
				'enabled'               => isset( $entry['enabled'] ) ? (bool) $entry['enabled'] : true,
				'update_mode'           => isset( $entry['update_mode'] ) ? (string) sanitize_key( (string) $entry['update_mode'] ) : 'auto',
				'auto_update'           => isset( $entry['auto_update'] ) ? (bool) $entry['auto_update'] : true,
				'button_label_override' => isset( $entry['button_label_override'] ) ? (string) sanitize_text_field( (string) $entry['button_label_override'] ) : '',
				'platform_extras'       => $platform_extras,
				'offers'                => self::sanitizeOffers( $entry ),
			);

			$result[] = $row;
		}

		return $result;
	}

	/**
	 * listing から購入リンク（offers）を取り出して正規化する。
	 *
	 * `offers` が無い場合は、v3 以前の flat な取得結果フィールドを offers[0] へ
	 * 畳む。これにより外部の投稿パイプラインの改修を待たずにリリースできる。
	 *
	 * @param array<string, mixed> $entry
	 * @return list<array<string, mixed>>
	 */
	private static function sanitizeOffers( array $entry ): array {
		$raw = array();
		if ( isset( $entry['offers'] ) && is_array( $entry['offers'] ) ) {
			$raw = $entry['offers'];
		} elseif ( self::hasFlatFetchFields( $entry ) ) {
			$raw = array( $entry );
		}

		$byKey = array();
		foreach ( $raw as $index => $offer ) {
			if ( ! is_array( $offer ) ) {
				continue;
			}

			$regular = isset( $offer['regular_url'] ) ? (string) esc_url_raw( (string) $offer['regular_url'] ) : '';
			if ( '' === $regular && ! self::$preserveOffersWithoutRegularUrl ) {
				// 生死を判定できない offer は棚卸しの対象外になり永久に残るため弾く。
				// 移行中（withLegacyOfferPreservation）だけはこのルールを外す——既存データを
				// アップグレードで消さないため。
				continue;
			}

			$externalId = isset( $offer['external_id'] ) ? (string) sanitize_text_field( (string) $offer['external_id'] ) : '';
			if ( '' !== $externalId ) {
				$key = 'id:' . $externalId;
			} elseif ( '' !== $regular ) {
				$key = 'url:' . $regular;
			} else {
				// 温存された offer は識別子を 1 つも持たないことがある。'url:' で
				// 束ねると複数の温存 offer が 1 件に潰れて消えるため、位置で分ける。
				$key = 'idx:' . $index;
			}

			// 識別子が重複したら後勝ち（同じ SKU を 2 つ並べない）。
			$byKey[ $key ] = array(
				'display_order'    => isset( $offer['display_order'] ) ? (int) $offer['display_order'] : OfferSelector::DEFAULT_ORDER,
				'external_id'      => $externalId,
				'regular_url'      => $regular,
				'affiliate_url'    => isset( $offer['affiliate_url'] ) ? (string) esc_url_raw( (string) $offer['affiliate_url'] ) : '',
				'price'            => isset( $offer['price'] ) ? (string) sanitize_text_field( (string) $offer['price'] ) : '',
				'list_price'       => isset( $offer['list_price'] ) ? (string) sanitize_text_field( (string) $offer['list_price'] ) : '',
				'badge'            => isset( $offer['badge'] ) ? (string) sanitize_text_field( (string) $offer['badge'] ) : '',
				'image_url'        => isset( $offer['image_url'] ) ? (string) esc_url_raw( (string) $offer['image_url'] ) : '',
				'search_key'       => isset( $offer['search_key'] ) ? (string) sanitize_text_field( (string) $offer['search_key'] ) : '',
				'fetch_status'     => isset( $offer['fetch_status'] ) ? (string) sanitize_key( (string) $offer['fetch_status'] ) : '',
				'last_fetched_at'  => isset( $offer['last_fetched_at'] ) ? (string) sanitize_text_field( (string) $offer['last_fetched_at'] ) : '',
				'last_verified_at' => isset( $offer['last_verified_at'] ) ? (string) sanitize_text_field( (string) $offer['last_verified_at'] ) : '',
			);
		}

		return array_values( $byKey );
	}

	/**
	 * v3 以前の flat な取得結果フィールドを持っているか。
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function hasFlatFetchFields( array $entry ): bool {
		foreach ( array( 'external_id', 'regular_url', 'affiliate_url', 'price', 'image_url', 'search_key' ) as $key ) {
			if ( isset( $entry[ $key ] ) && '' !== (string) $entry[ $key ] ) {
				return true;
			}
		}
		return false;
	}
}
