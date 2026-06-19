<?php
declare(strict_types=1);

namespace Affilicard\Repository;

use Affilicard\PostType\ProductPostType;
use Affilicard\Schema\SchemaVersion;
use Affilicard\Stock\StockStatus;
use Affilicard\Util\JsonField;

/**
 * `affilicard_product` CPT に対する CRUD ラッパ。
 *
 * 値の入出力はすべて配列で行う。listings/extras メタは JSON 文字列として保存し、JsonField で decode/encode する。
 */
final class ProductRepository implements ProductRepositoryInterface {

	/**
	 * 投稿 ID から商品データを取得する。
	 *
	 * @return array{
	 *   id: int,
	 *   title: string,
	 *   content: string,
	 *   status: string,
	 *   product_type: string,
	 *   stock_status: string,
	 *   extras: array<int, mixed>,
	 *   listings: array<int, mixed>,
	 *   schema_version: string,
	 *   modified: string,
	 * }|null
	 */
	public function find( int $postId ): ?array {
		$post = get_post( $postId );
		if ( null === $post ) {
			return null;
		}
		if ( ! isset( $post->post_type ) || ProductPostType::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$extras_raw   = get_post_meta( $postId, ProductPostType::META_EXTRAS, true );
		$listings_raw = get_post_meta( $postId, ProductPostType::META_LISTINGS, true );

		$extras   = is_string( $extras_raw )
			? JsonField::decode( $extras_raw, array() )
			: ( is_array( $extras_raw ) ? $extras_raw : array() );
		$listings = is_string( $listings_raw )
			? JsonField::decode( $listings_raw, array() )
			: ( is_array( $listings_raw ) ? $listings_raw : array() );

		return array(
			'id'             => (int) $post->ID,
			'title'          => (string) ( $post->post_title ?? '' ),
			'content'        => (string) ( $post->post_content ?? '' ),
			'status'         => (string) ( $post->post_status ?? '' ),
			'product_type'   => (string) get_post_meta( $postId, ProductPostType::META_PRODUCT_TYPE, true ),
			'stock_status'   => StockStatus::normalize( (string) get_post_meta( $postId, ProductPostType::META_STOCK_STATUS, true ) ),
			'extras'         => $extras,
			'listings'       => $listings,
			'schema_version' => (string) get_post_meta( $postId, ProductPostType::META_SCHEMA_VERSION, true ),
			'modified'       => (string) ( $post->post_modified ?? '' ),
		);
	}

	/**
	 * 外部 ID（platform 別）を元に商品を 1 件検索する。
	 */
	public function findByExternalId( string $platformCode, string $externalId ): ?array {
		$meta_key = ProductPostType::externalIdMetaKey( $platformCode );

		$posts = get_posts(
			array(
				'post_type'      => ProductPostType::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => $meta_key,
						'value'   => $externalId,
						'compare' => '=',
					),
				),
			)
		);

		if ( ! is_array( $posts ) || array() === $posts ) {
			return null;
		}

		$first = $posts[0];
		$id    = is_object( $first ) && isset( $first->ID ) ? (int) $first->ID : (int) $first;

		return $this->find( $id );
	}

	/**
	 * post_name（スラッグ）から商品を 1 件検索する。
	 */
	public function findBySlug( string $slug ): ?array {
		if ( '' === $slug ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'      => ProductPostType::POST_TYPE,
				'post_status'    => 'any',
				'name'           => $slug,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);

		if ( ! is_array( $posts ) || array() === $posts ) {
			return null;
		}

		$first = $posts[0];
		$id    = is_object( $first ) && isset( $first->ID ) ? (int) $first->ID : (int) $first;

		return $this->find( $id );
	}

	/**
	 * 商品データを保存（新規 or 更新）し、post ID を返す。
	 *
	 * @param array<string, mixed> $data
	 */
	public function save( array $data ): int {
		$is_update = isset( $data['id'] ) && (int) $data['id'] > 0;

		$post_args = array(
			'post_type'    => ProductPostType::POST_TYPE,
			'post_title'   => isset( $data['title'] ) ? (string) $data['title'] : '',
			'post_content' => isset( $data['content'] ) ? (string) $data['content'] : '',
			'post_status'  => isset( $data['status'] ) ? (string) $data['status'] : 'publish',
		);

		if ( $is_update ) {
			$post_args['ID'] = (int) $data['id'];
			$post_id         = (int) wp_update_post( $post_args, true );
		} else {
			$post_id = (int) wp_insert_post( $post_args, true );
		}

		if ( $post_id <= 0 ) {
			return 0;
		}

		$this->saveMeta( $post_id, $data );

		return $post_id;
	}

	/**
	 * 投稿 ID とデータ配列からメタフィールドのみを保存する。
	 *
	 * `save()` の内部でも呼ばれるが、`save_post` ハンドラから直接呼ぶことも想定している。
	 *
	 * @param array<string, mixed> $data
	 */
	public function saveMeta( int $postId, array $data ): void {
		$product_type = isset( $data['product_type'] ) && '' !== (string) $data['product_type']
			? (string) $data['product_type']
			: 'generic';
		$stock_status = StockStatus::normalize(
			isset( $data['stock_status'] ) ? (string) $data['stock_status'] : null
		);
		$extras       = isset( $data['extras'] ) && is_array( $data['extras'] ) ? $data['extras'] : array();
		$listings     = isset( $data['listings'] ) && is_array( $data['listings'] ) ? $data['listings'] : array();

		update_post_meta( $postId, ProductPostType::META_PRODUCT_TYPE, $product_type );
		update_post_meta( $postId, ProductPostType::META_STOCK_STATUS, $stock_status );
		update_post_meta( $postId, ProductPostType::META_EXTRAS, JsonField::encode( $extras ) );
		update_post_meta( $postId, ProductPostType::META_LISTINGS, JsonField::encode( $listings ) );
		update_post_meta( $postId, ProductPostType::META_SCHEMA_VERSION, SchemaVersion::CURRENT );

		$this->syncExternalIdMirror( $postId, $listings );
	}

	public function delete( int $postId ): bool {
		$result = wp_delete_post( $postId, true );
		return false !== $result && null !== $result;
	}

	/**
	 * 「affiliate_url が空のまま regular_url のみ持つ」商品の件数を返す（フォールバック表示中の件数）。
	 *
	 * 全商品の listings メタを走査して計数する。
	 */
	public function countFallbackProducts(): int {
		$ids = get_posts(
			array(
				'post_type'      => ProductPostType::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);
		if ( ! is_array( $ids ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $ids as $id ) {
			$listings     = get_post_meta( (int) $id, ProductPostType::META_LISTINGS, true );
			$listings_arr = is_string( $listings ) ? JsonField::decode( $listings, array() ) : ( is_array( $listings ) ? $listings : array() );
			if ( self::hasFallbackListing( $listings_arr ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * REST（core-data）保存後に呼ぶ派生 meta 同期。
	 * listings 配列メタから external_id ミラーと schema_version を再構築する。
	 */
	public function syncDerivedMeta( int $postId ): void {
		$listings = get_post_meta( $postId, ProductPostType::META_LISTINGS, true );
		if ( is_string( $listings ) ) {
			$listings = JsonField::decode( $listings, array() );
		} elseif ( ! is_array( $listings ) ) {
			$listings = array();
		}
		$this->syncExternalIdMirror( $postId, $listings );
		update_post_meta( $postId, ProductPostType::META_SCHEMA_VERSION, SchemaVersion::CURRENT );
	}

	/**
	 * 各 listing の external_id を `affilicard_extid_<platform>` meta にミラーする。
	 *
	 * Phase 4a-1 では削除済み platform の stale meta クリーンアップは行わない（4a-3 で対応）。
	 *
	 * @param array<int, mixed> $listings
	 */
	private function syncExternalIdMirror( int $postId, array $listings ): void {
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$platform    = isset( $listing['platform'] ) ? (string) $listing['platform'] : '';
			$external_id = isset( $listing['external_id'] ) ? (string) $listing['external_id'] : '';
			if ( '' === $platform || '' === $external_id ) {
				continue;
			}
			update_post_meta(
				$postId,
				ProductPostType::externalIdMetaKey( $platform ),
				$external_id
			);
		}
	}

	/**
	 * 商品キャッシュ用 transient のキー。
	 */
	private function transient_key( int $postId ): string {
		return 'affilicard_product_' . $postId;
	}

	/**
	 * 1 件以上の listing が affiliate_url='' かつ regular_url!='' か判定する。
	 *
	 * @param array<int, mixed> $listings
	 */
	private static function hasFallbackListing( array $listings ): bool {
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$affiliate = isset( $listing['affiliate_url'] ) ? (string) $listing['affiliate_url'] : '';
			$regular   = isset( $listing['regular_url'] ) ? (string) $listing['regular_url'] : '';
			if ( '' === $affiliate && '' !== $regular ) {
				return true;
			}
		}
		return false;
	}
}
