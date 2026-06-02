<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\PostType\ProductPostType;
use Affilicard\Repository\ProductRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/affilicard/v1/products` 系エンドポイントの実装。
 *
 * 入出力は ProductRepository に委譲する。
 */
final class ProductsController {

	public function __construct( private ProductRepository $repository ) {}

	public function registerRoutes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/products',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list' ),
					'permission_callback' => array( $this, 'canEditPosts' ),
					'args'                => array(
						'search'   => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'canEditPosts' ),
					'args'                => ProductSchema::args(),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/products/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'canEditPostFromRequest' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'canEditPostFromRequest' ),
					'args'                => ProductSchema::updateArgs(),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'canDeletePostFromRequest' ),
				),
			)
		);
	}

	public function canEditPosts(): bool {
		return (bool) current_user_can( 'edit_posts' );
	}

	public function canEditPostFromRequest( WP_REST_Request $request ): bool {
		$id = (int) $request->get_param( 'id' );
		return $this->canEditPost( $id );
	}

	public function canEditPost( int $id ): bool {
		return (bool) current_user_can( 'edit_post', $id );
	}

	public function canDeletePostFromRequest( WP_REST_Request $request ): bool {
		$id = (int) $request->get_param( 'id' );
		return (bool) current_user_can( 'delete_post', $id );
	}

	public function list( WP_REST_Request $request ): WP_REST_Response {
		$search   = (string) ( $request->get_param( 'search' ) ?? '' );
		$per_page = (int) ( $request->get_param( 'per_page' ) ?? 20 );
		$page     = (int) ( $request->get_param( 'page' ) ?? 1 );

		$posts = get_posts(
			array(
				'post_type'      => ProductPostType::POST_TYPE,
				'post_status'    => 'any',
				's'              => $search,
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$items = array();
		if ( is_array( $posts ) ) {
			foreach ( $posts as $post ) {
				if ( ! is_object( $post ) ) {
					continue;
				}
				$post_id = isset( $post->ID ) ? (int) $post->ID : 0;
				if ( 0 === $post_id ) {
					continue;
				}

				$product_type = get_post_meta( $post_id, ProductPostType::META_PRODUCT_TYPE, true );
				$items[]      = array(
					'id'           => $post_id,
					'title'        => (string) ( $post->post_title ?? '' ),
					'status'       => (string) ( $post->post_status ?? '' ),
					'product_type' => is_string( $product_type ) ? $product_type : '',
					'modified'     => (string) ( $post->post_modified ?? '' ),
				);
			}
		}

		$counts = wp_count_posts( ProductPostType::POST_TYPE );
		$total  = 0;
		if ( is_object( $counts ) ) {
			foreach ( get_object_vars( $counts ) as $status_count ) {
				$total += (int) $status_count;
			}
		}
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );
		return $response;
	}

	public function create( WP_REST_Request $request ): WP_REST_Response {
		$data = $this->extractProductData( $request );

		$id = $this->repository->save( $data );
		if ( $id <= 0 ) {
			return new WP_REST_Response(
				array(
					'code'    => 'affilicard_save_failed',
					'message' => __( '商品の保存に失敗しました。', 'affilicard' ),
				),
				500
			);
		}

		$saved = $this->repository->find( $id );
		return new WP_REST_Response( $saved, 201 );
	}

	public function get( WP_REST_Request $request ): WP_REST_Response {
		$id      = (int) $request->get_param( 'id' );
		$product = $this->repository->find( $id );
		if ( null === $product ) {
			return new WP_REST_Response(
				array(
					'code'    => 'affilicard_not_found',
					'message' => __( '商品が見つかりません。', 'affilicard' ),
				),
				404
			);
		}

		return new WP_REST_Response( $product, 200 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response {
		$id       = (int) $request->get_param( 'id' );
		$existing = $this->repository->find( $id );
		if ( null === $existing ) {
			return new WP_REST_Response(
				array(
					'code'    => 'affilicard_not_found',
					'message' => __( '商品が見つかりません。', 'affilicard' ),
				),
				404
			);
		}

		// PATCH は部分更新。送信されたフィールドだけを既存値の上に重ね、
		// 未送信フィールド（metabox では title / content / status 等）は既存値を保持する。
		$data       = array_merge( $existing, $this->extractProductData( $request ) );
		$data['id'] = $id;

		$saved_id = $this->repository->save( $data );
		if ( $saved_id <= 0 ) {
			return new WP_REST_Response(
				array(
					'code'    => 'affilicard_save_failed',
					'message' => __( '商品の更新に失敗しました。', 'affilicard' ),
				),
				500
			);
		}

		$saved = $this->repository->find( $saved_id );
		return new WP_REST_Response( $saved, 200 );
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request->get_param( 'id' );
		if ( null === $this->repository->find( $id ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'affilicard_not_found',
					'message' => __( '商品が見つかりません。', 'affilicard' ),
				),
				404
			);
		}

		$ok = $this->repository->delete( $id );
		if ( ! $ok ) {
			return new WP_REST_Response(
				array(
					'code'    => 'affilicard_delete_failed',
					'message' => __( '商品の削除に失敗しました。', 'affilicard' ),
				),
				500
			);
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function extractProductData( WP_REST_Request $request ): array {
		$keys = array(
			'title',
			'content',
			'status',
			'product_type',
			'stock_status',
			'extras',
			'listings',
		);

		$data = array();
		foreach ( $keys as $key ) {
			$value = $request->get_param( $key );
			if ( null === $value ) {
				continue;
			}
			$data[ $key ] = $value;
		}

		return $data;
	}
}
