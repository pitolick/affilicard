<?php
declare(strict_types=1);

namespace Affilicard\Repository;

use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductPostType;
use Affilicard\Schema\SchemaVersion;
use Affilicard\Stock\StockStatus;
use Affilicard\Util\JsonField;

/**
 * `affilicard_product` CPT に対する CRUD ラッパ。
 *
 * 値の入出力はすべて配列で行う。listings/extras メタはネイティブ配列メタとして保存する
 * （register_post_meta type=array）。読み出し時は後方互換で旧 JSON 文字列も JsonField で decode する。
 */
final class ProductRepository implements ProductRepositoryInterface {

	/**
	 * 投稿 ID から商品データを取得する。
	 *
	 * @return array{
	 *   id: int,
	 *   slug: string,
	 *   title: string,
	 *   content: string,
	 *   status: string,
	 *   product_type: string,
	 *   stock_status: string,
	 *   release_date: string,
	 *   mask_blur: bool,
	 *   mask_r18: bool,
	 *   mask_label: string,
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
			'slug'           => (string) ( $post->post_name ?? '' ),
			'title'          => (string) ( $post->post_title ?? '' ),
			'content'        => (string) ( $post->post_content ?? '' ),
			'status'         => (string) ( $post->post_status ?? '' ),
			'product_type'   => (string) get_post_meta( $postId, ProductPostType::META_PRODUCT_TYPE, true ),
			'stock_status'   => StockStatus::normalize( (string) get_post_meta( $postId, ProductPostType::META_STOCK_STATUS, true ) ),
			'release_date'   => (string) get_post_meta( $postId, ProductPostType::META_RELEASE_DATE, true ),
			'mask_blur'      => (bool) get_post_meta( $postId, ProductPostType::META_MASK_BLUR, true ),
			'mask_r18'       => (bool) get_post_meta( $postId, ProductPostType::META_MASK_R18, true ),
			'mask_label'     => (string) get_post_meta( $postId, ProductPostType::META_MASK_LABEL, true ),
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
	 * 指定 platform の listing だけを差し替え、他 listing を保持して原子的に保存する。
	 *
	 * find()→save() の全 listings 上書きは、同一商品の別 platform listing を別 account
	 * group（affilicard-rakuten / affilicard-dmm 等）で並行更新すると後着の save が先着の
	 * 変更を消す（lost update）。ここでは MySQL 名前付きロックで read-modify-write を
	 * クリティカルセクション化し、META_LISTINGS をその場で再読込→対象 platform の listing
	 * だけを $listingFields で丸ごと置換して update_post_meta することで、並行更新された
	 * 他 platform listing を失わない。external_id は refresh で変わらないため extid ミラー
	 * 同期（syncExternalIdMirror）は呼ばない。
	 *
	 * ロック取得に失敗（0/null）しても RMW は best-effort で続行する（fetch は既に成功済みで、
	 * ロック不能を理由に更新を捨てる方が有害。取得可否は挙動を変えない安全弁）。
	 *
	 * @param array<string, mixed> $listingFields refreshListing() が返すフィールド完全形の listing。
	 */
	public function updateListing( int $postId, string $platform, array $listingFields ): bool {
		global $wpdb;

		// GET_LOCK の名前は 64 バイト以内。post ID は整数なので prefix 込みで超えない。
		$lock = "affilicard_listing_{$postId}";
		$got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 10 ) );

		try {
			$raw      = get_post_meta( $postId, ProductPostType::META_LISTINGS, true );
			$listings = is_string( $raw )
				? JsonField::decode( $raw, array() )
				: ( is_array( $raw ) ? $raw : array() );

			$found = false;
			foreach ( $listings as $index => $listing ) {
				if ( ! is_array( $listing ) || ( $listing['platform'] ?? '' ) !== $platform ) {
					continue;
				}
				$listings[ $index ] = $listingFields;
				$found              = true;
				break;
			}

			if ( ! $found ) {
				return false;
			}

			update_post_meta( $postId, ProductPostType::META_LISTINGS, array_values( $listings ) );
			return true;
		} finally {
			if ( $got > 0 ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
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
		$release_date = isset( $data['release_date'] ) ? (string) $data['release_date'] : '';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $release_date ) ) {
			$release_date = '';
		}
		$mask_blur  = ! empty( $data['mask_blur'] );
		$mask_r18   = ! empty( $data['mask_r18'] );
		$mask_label = isset( $data['mask_label'] ) ? sanitize_text_field( (string) $data['mask_label'] ) : '';

		update_post_meta( $postId, ProductPostType::META_PRODUCT_TYPE, $product_type );
		update_post_meta( $postId, ProductPostType::META_STOCK_STATUS, $stock_status );
		update_post_meta( $postId, ProductPostType::META_EXTRAS, $extras );
		update_post_meta( $postId, ProductPostType::META_LISTINGS, $listings );
		update_post_meta( $postId, ProductPostType::META_SCHEMA_VERSION, SchemaVersion::CURRENT );
		update_post_meta( $postId, ProductPostType::META_RELEASE_DATE, $release_date );
		update_post_meta( $postId, ProductPostType::META_MASK_BLUR, $mask_blur );
		update_post_meta( $postId, ProductPostType::META_MASK_R18, $mask_r18 );
		update_post_meta( $postId, ProductPostType::META_MASK_LABEL, $mask_label );

		$this->syncExternalIdMirror( $postId, $listings );
	}

	/**
	 * 商品を検索し、各 item に thumbnail/price/platform を付与して返す。
	 *
	 * - $term 空: modified 降順の最近商品（ページ処理あり）
	 * - $term 非空: title/content 全文検索 + external_id ミラー OR meta_query の和集合、一意化・modified 降順
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function search( string $term, int $perPage, int $page ): array {
		$term = trim( $term );

		if ( '' === $term ) {
			$posts = get_posts(
				array(
					'post_type'      => ProductPostType::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => $perPage,
					'paged'          => $page,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			);
			return $this->buildSearchResult( is_array( $posts ) ? $posts : array() );
		}

		$by_title = get_posts(
			array(
				'post_type'      => ProductPostType::POST_TYPE,
				'post_status'    => 'any',
				's'              => $term,
				'posts_per_page' => -1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$enabled_codes = array();
		foreach ( PlatformConfig::all() as $platform ) {
			if ( $platform->enabled ) {
				$enabled_codes[] = (string) $platform->code;
			}
		}

		$by_extid = array();
		if ( array() !== $enabled_codes ) {
			$meta_query = array( 'relation' => 'OR' );
			foreach ( $enabled_codes as $code ) {
				$meta_query[] = array(
					'key'     => ProductPostType::externalIdMetaKey( $code ),
					'value'   => $term,
					'compare' => 'LIKE',
				);
			}

			$by_extid = get_posts(
				array(
					'post_type'      => ProductPostType::POST_TYPE,
					'post_status'    => 'any',
					'meta_query'     => $meta_query,
					'posts_per_page' => -1,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			);
		}

		$merged = array();
		foreach ( array_merge( is_array( $by_title ) ? $by_title : array(), is_array( $by_extid ) ? $by_extid : array() ) as $post ) {
			if ( is_object( $post ) && isset( $post->ID ) ) {
				$merged[ (int) $post->ID ] = $post;
			}
		}

		// modified 降順で安定化。
		uasort(
			$merged,
			static fn( $a, $b ) => strcmp( (string) ( $b->post_modified ?? '' ), (string) ( $a->post_modified ?? '' ) )
		);

		$total = count( $merged );
		$page  = max( 1, $page );
		$slice = array_slice( array_values( $merged ), ( $page - 1 ) * $perPage, $perPage );

		return array(
			'items' => array_map( array( $this, 'mapSearchItem' ), $slice ),
			'total' => $total,
		);
	}

	/**
	 * 先頭の有効 listing から代表価格と platform 名を返す（無ければ空文字）。
	 *
	 * @return array{price: string, platform: string}
	 */
	public function listingSummary( int $postId ): array {
		$raw      = get_post_meta( $postId, ProductPostType::META_LISTINGS, true );
		$listings = is_string( $raw ) ? JsonField::decode( $raw, array() ) : ( is_array( $raw ) ? $raw : array() );

		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$price    = isset( $listing['price'] ) ? trim( (string) $listing['price'] ) : '';
			$platform = isset( $listing['platform'] ) ? (string) $listing['platform'] : '';
			if ( '' !== $platform ) {
				return array(
					'price'    => $price,
					'platform' => $platform,
				);
			}
		}
		return array(
			'price'    => '',
			'platform' => '',
		);
	}

	/**
	 * term 空時の検索結果を構築し wp_count_posts で総件数を取得して返す。
	 *
	 * @param list<object> $posts
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	private function buildSearchResult( array $posts ): array {
		$items = array();
		foreach ( $posts as $post ) {
			if ( is_object( $post ) && isset( $post->ID ) ) {
				$items[] = $this->mapSearchItem( $post );
			}
		}

		$counts = wp_count_posts( ProductPostType::POST_TYPE );
		$total  = 0;
		if ( is_object( $counts ) ) {
			foreach ( get_object_vars( $counts ) as $status_count ) {
				$total += (int) $status_count;
			}
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * WP_Post オブジェクトを検索結果 item 配列にマップする。
	 *
	 * @return array<string, mixed>
	 */
	private function mapSearchItem( object $post ): array {
		$post_id      = (int) ( $post->ID ?? 0 );
		$product_type = get_post_meta( $post_id, ProductPostType::META_PRODUCT_TYPE, true );
		$summary      = $this->listingSummary( $post_id );
		$thumb_url    = get_the_post_thumbnail_url( $post_id, 'thumbnail' );

		return array(
			'id'           => $post_id,
			'title'        => (string) ( $post->post_title ?? '' ),
			'status'       => (string) ( $post->post_status ?? '' ),
			'product_type' => is_string( $product_type ) ? $product_type : '',
			'modified'     => (string) ( $post->post_modified ?? '' ),
			'thumbnail'    => is_string( $thumb_url ) ? $thumb_url : '',
			'price'        => $summary['price'],
			'platform'     => $summary['platform'],
		);
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
	 * 再書き込み時、今回の listing 集合に含まれない既存の extid mirror meta は削除する。
	 * これにより platform 変更・削除後に stale な `affilicard_extid_<platform>` が残り、
	 * findByExternalId が誤 hit して誤 upsert する問題を防ぐ。
	 *
	 * @param array<int, mixed> $listings
	 */
	private function syncExternalIdMirror( int $postId, array $listings ): void {
		$desired_keys = array();
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$platform    = isset( $listing['platform'] ) ? (string) $listing['platform'] : '';
			$external_id = isset( $listing['external_id'] ) ? (string) $listing['external_id'] : '';
			if ( '' === $platform || '' === $external_id ) {
				continue;
			}
			$meta_key                  = ProductPostType::externalIdMetaKey( $platform );
			$desired_keys[ $meta_key ] = $external_id;
		}

		$this->purgeStaleExternalIdMirror( $postId, array_keys( $desired_keys ) );

		foreach ( $desired_keys as $meta_key => $external_id ) {
			update_post_meta( $postId, $meta_key, $external_id );
		}
	}

	/**
	 * 投稿の既存 extid mirror meta のうち、$keepKeys に含まれないものを削除する。
	 *
	 * @param array<int, string> $keepKeys 維持する extid mirror meta キー。
	 */
	private function purgeStaleExternalIdMirror( int $postId, array $keepKeys ): void {
		$all_meta = get_post_meta( $postId );
		if ( ! is_array( $all_meta ) ) {
			return;
		}

		$keep = array_flip( $keepKeys );
		foreach ( array_keys( $all_meta ) as $meta_key ) {
			$meta_key = (string) $meta_key;
			if ( 0 !== strpos( $meta_key, ProductPostType::META_EXTID_PREFIX ) ) {
				continue;
			}
			if ( isset( $keep[ $meta_key ] ) ) {
				continue;
			}
			delete_post_meta( $postId, $meta_key );
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
