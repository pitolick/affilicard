<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\PostType\ProductPostType;
use Affilicard\Repository\ProductLockUnavailable;
use Affilicard\Repository\ProductRepositoryInterface;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/affilicard/v1/products` 系エンドポイントの実装。
 *
 * 入出力は ProductRepository に委譲する。
 */
final class ProductsController {

	/**
	 * bulk endpoint の 1 リクエストあたり最大商品件数。
	 */
	private const MAX_BULK_ITEMS = 100;

	/**
	 * listings の書き込みを見送ったことを表す応答コード。
	 *
	 * `affilicard_save_failed`（500）とは分ける。あちらは「保存できなかった」、こちらは
	 * 「保存しなかった（listings は古い値のまま無傷）」であり、前者はやり直しても
	 * たいてい同じ結果になるが、後者はやり直せば通る。呼び出し側が自動で積み直して
	 * よいかどうかが逆になるので、同じコードに潰してはならない。
	 */
	private const CODE_LISTING_LOCKED = 'affilicard_listing_locked';

	public function __construct( private ProductRepositoryInterface $repository ) {}

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
			'/products/bulk',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'bulkCreate' ),
					'permission_callback' => array( $this, 'canEditPosts' ),
					'args'                => array(
						'products' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array(
								'type' => 'object',
							),
						),
					),
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

	/**
	 * 「listings を書かずに見送った」ことを運用者へ返す応答。
	 *
	 * **409 Conflict を使う。** 500 ではない——サーバは壊れていないし、要求も正しい。
	 * 起きたのは「同じ商品をいま別の誰か／別の処理が書き換えている」という衝突であり、
	 * 409 はまさにそれを表す。運用者にとっての違いは実務的で、409 なら**そのまま
	 * もう一度保存すれば通る**（{@see \Affilicard\Repository\ListingLock::TIMEOUT} は
	 * 10 秒なので、衝突相手はすぐ抜ける）。
	 *
	 * **この経路を使うのは外部クライアントである。** WP 管理画面の商品編集はブロック
	 * エディタのサイドバー（コアの `wp/v2` meta 経路）で、{@see ProductsController} を
	 * 通らない——だからここの 409 を読むのは、記事投稿の自動化のように
	 * `affilicard/v1/products` を叩く側であり、そこで失敗として記録されることが
	 * 運用者への通知になる。code を専用にしているのはそのためで、
	 * `affilicard_save_failed`（＝諦めてよい）と取り違えると積み直しの判断を誤る。
	 */
	private static function lockedResponse(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code'    => self::CODE_LISTING_LOCKED,
				'message' => self::lockedMessage(),
			),
			409
		);
	}

	/**
	 * 「やり直せば通る」ところまで書いた文言。何が保存されなかったかを明示する。
	 */
	private static function lockedMessage(): string {
		return __(
			'ほかの処理がこの商品の購入リンクを更新中のため、購入リンクは保存しませんでした（既存の内容はそのまま残っています）。少し待ってからもう一度保存してください。',
			'affilicard'
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

	public function bulkCreate( WP_REST_Request $request ): WP_REST_Response {
		$products = $request->get_param( 'products' );
		if ( ! is_array( $products ) || array() === $products ) {
			return new WP_REST_Response(
				array(
					'code'    => 'affilicard_invalid_bulk',
					'message' => __( 'products は 1 件以上の配列で指定してください。', 'affilicard' ),
				),
				400
			);
		}

		if ( count( $products ) > self::MAX_BULK_ITEMS ) {
			return new WP_REST_Response(
				array(
					'code'    => 'affilicard_bulk_too_many',
					'message' => sprintf(
						/* translators: %d: 1 リクエストあたりの最大件数 */
						__( '一括作成は最大 %d 件までです。', 'affilicard' ),
						self::MAX_BULK_ITEMS
					),
				),
				400
			);
		}

		$results = array();
		$created = 0;
		$failed  = 0;

		foreach ( array_values( $products ) as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				++$failed;
				$results[] = array(
					'index'   => $index,
					'status'  => 'error',
					'message' => __( 'アイテムは配列である必要があります。', 'affilicard' ),
				);
				continue;
			}

			$data = $this->enforcePublishCapability( ProductSchema::sanitizeItem( $raw ) );
			if ( '' === $data['title'] ) {
				++$failed;
				$results[] = array(
					'index'   => $index,
					'status'  => 'error',
					'message' => __( 'title は必須です。', 'affilicard' ),
				);
				continue;
			}

			try {
				$id = $this->repository->save( $data );
			} catch ( ProductLockUnavailable $e ) {
				// listings を書かずに見送った（同じ商品を別経路が書き換え中）。
				// 207 の該当 item だけを error にして、他の item は通す。呼び出し側は
				// この item だけ積み直せばよい——listings は古い値のまま無傷である。
				++$failed;
				$results[] = array(
					'index'   => $index,
					'status'  => 'error',
					'code'    => self::CODE_LISTING_LOCKED,
					'message' => self::lockedMessage(),
				);
				continue;
			}
			if ( $id <= 0 ) {
				++$failed;
				$results[] = array(
					'index'   => $index,
					'status'  => 'error',
					'message' => __( '保存に失敗しました。', 'affilicard' ),
				);
				continue;
			}

			++$created;
			$results[] = array(
				'index'  => $index,
				'status' => 'created',
				'id'     => $id,
			);
		}

		return new WP_REST_Response(
			array(
				'results' => $results,
				'created' => $created,
				'failed'  => $failed,
			),
			207
		);
	}

	public function list( WP_REST_Request $request ): WP_REST_Response {
		$search   = (string) ( $request->get_param( 'search' ) ?? '' );
		$per_page = (int) ( $request->get_param( 'per_page' ) ?? 20 );
		$page     = (int) ( $request->get_param( 'page' ) ?? 1 );

		$result      = $this->repository->search( $search, $per_page, $page );
		$total       = (int) ( $result['total'] ?? 0 );
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		$response = new WP_REST_Response( $result['items'] ?? array(), 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );
		return $response;
	}

	public function create( WP_REST_Request $request ): WP_REST_Response {
		$data = $this->enforcePublishCapability( $this->extractProductData( $request ) );

		try {
			$id = $this->repository->save( $data );
		} catch ( ProductLockUnavailable $e ) {
			return self::lockedResponse();
		}
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
		$data       = array_merge( $existing, $this->enforcePublishCapability( $this->extractProductData( $request ) ) );
		$data['id'] = $id;

		try {
			$saved_id = $this->repository->save( $data );
		} catch ( ProductLockUnavailable $e ) {
			return self::lockedResponse();
		}
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
	 * publish_posts 権限を持たないユーザーが status=publish|future を要求した場合、
	 * status を pending に降格する（公開権限バイパスの防止・非破壊）。
	 *
	 * status が未指定、または publish/future 以外なら何もしない。
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function enforcePublishCapability( array $data ): array {
		if ( ! isset( $data['status'] ) ) {
			return $data;
		}

		$status = (string) $data['status'];
		if ( 'publish' !== $status && 'future' !== $status ) {
			return $data;
		}

		if ( ! current_user_can( 'publish_posts' ) ) {
			$data['status'] = 'pending';
		}

		return $data;
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
