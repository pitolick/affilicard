<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\PostType\ProductPostType;
use Affilicard\Repository\ProductMetaWriteFailure;
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

	/**
	 * 保存そのものが効かなかったときのコード（HTTP 500）。
	 *
	 * **ロック競合（409）と混ぜない。** 409 は「待てば通る」、こちらは「原因を取り除く
	 * まで直らない」で、運用者が取るべき行動が逆である。
	 */
	private const CODE_SAVE_FAILED = 'affilicard_save_failed';

	/**
	 * 保存を断った／保存できなかった応答に、対象商品の投稿 ID を載せるキー。
	 *
	 * **これが無いと作成（POST）の失敗から復帰できない。**
	 * {@see \Affilicard\Repository\ProductRepository::save()} は `wp_insert_post()` が
	 * 通ってから listings を書くため、409/500 で返る時点で**商品は既に作られている**。
	 * ID を返さないと呼び出し側はその商品に辿り着けず、再試行は POST のやり直しに
	 * なって同じ商品を増やす。ID があれば `PATCH /products/{id}` で続きをやり直せる。
	 *
	 * 成功応答（単体は商品オブジェクトの `id`、`/bulk` は `status=created` の item の
	 * `id`）と**同じキー名にそろえている**。呼び出し側が「商品の ID を読む場所」を
	 * 応答ごとに覚え直さずに済む。
	 *
	 * **規則は 1 つ——サーバが実在を知っている商品の ID だけを載せる。** 唯一の例外は
	 * 作成で `wp_insert_post()` 自体が失敗したときで、商品が 1 件も作られていないため
	 * キーごと落とす。**有無がそのまま「PATCH で直せるか／POST し直すか」を表す**ので、
	 * 存在しない ID を 0 などで埋めてはならない。
	 */
	private const ERROR_PRODUCT_ID_KEY = 'id';

	/**
	 * 「要求のうち、この項目だけが保存されなかった」を機械可読で名指しするキー。
	 *
	 * **`id` だけでは部分保存から復帰できない。**
	 * {@see \Affilicard\Repository\ProductRepository::save()} は投稿行
	 * （title / content / status）→ listings 以外のメタ → listings の順に書き、
	 * listings で失敗したときだけ例外を投げる。つまり 409 / 500 が返る時点で
	 * **要求の大半は既に保存されている**。`id` はその商品に辿り着く手段を与えるが、
	 * 「何が入って何が入らなかったのか」は伝えない。分からなければ呼び出し側は
	 * 「全部送り直す」か「何も直さない」の二択になり、前者は保存済みの編集を
	 * 無意味に上書きし、後者は購入リンクの無い商品を放置する。
	 *
	 * **だから残った差分だけを名指しする。** 値は保存されなかったフィールド名の配列で、
	 * 文面ではなく配列にするのは、呼び出し側が文言の一致ではなくフィールド名で分岐
	 * できるようにするためである（文言は翻訳で変わる）。
	 *
	 * **意味は「入らなかったと分かっているフィールド」であって、完全な一覧ではない。**
	 * ここに載らないフィールドが保存された保証は無い——
	 * {@see \Affilicard\Repository\ProductRepository::saveMeta()} が書いたあとに読み直す
	 * のは**要求が実際に運んでくるメタ**（`product_type` / `stock_status` / `extras` /
	 * `release_date` / `listings`）だけで、`mask_*` と `schema_version`、および投稿行の
	 * `title` / `content` / `status` は確かめていないからである（**確かめない理由**は
	 * あちらの PHPDoc の表——要約すると、どれも「確かめても失われた要求は見つからない」）。
	 * **以前はここが `array( 'listings' )` を固定で返していた**が、それは読み直しの範囲を
	 * 知らない側が保存の範囲を名乗る形で、名乗った内容が実際より広かった。いまは
	 * 例外が運んできた値（{@see \Affilicard\Repository\ProductMetaWriteFailure::unsavedFields()}）
	 * をそのまま返す。
	 *
	 * 呼び出し側の使い方は変わらない——**ここに載ったフィールドは必ず送り直す**。
	 * 全項目を送り直すよりは狭く、何も直さないよりは確実である。
	 *
	 * **載せるのは部分保存のときだけである。** 投稿行そのものを作れなかった・更新
	 * できなかった 500 は「1 つも保存されていない」ので、ここに `listings` と書くと
	 * 「listings 以外は入った」という嘘になる。載らないことが「部分保存ではない」を
	 * 表す（`id` の有無が「商品ができたか否か」を表すのと同じ流儀）。
	 */
	private const ERROR_UNSAVED_FIELDS_KEY = 'unsaved_fields';

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
	 *
	 * **`id` に商品の投稿 ID を載せる（{@see self::ERROR_PRODUCT_ID_KEY}）。** 作成
	 * （POST）でここへ来た時点で**投稿行は既に存在する**——
	 * {@see \Affilicard\Repository\ProductRepository::save()} は `wp_insert_post()` を
	 * 通してから listings を書くためである。ID を返さないと、呼び出し側は「商品が
	 * できたのかどうか」すら知れず、積み直しのたびに POST し直して**同じ商品を増やす**。
	 * ID があれば、やり直しを `PATCH /products/{id}` に変えられる。
	 *
	 * **`unsaved_fields` で「何が入らなかったか」まで返す**
	 * （{@see self::ERROR_UNSAVED_FIELDS_KEY}）。`id` は商品へ辿り着く手段を与えるが、
	 * その商品が**どこまで保存されているか**は伝えない。ロック競合なら普通は購入リンク
	 * だけが足りないが、**listings だけとは限らない**ので、値は例外が運んできたものを
	 * そのまま返す（ここで固定値を名乗ると、リポジトリ側の検証が変わったときに黙って
	 * 嘘になる）。
	 *
	 * @param int                $productId     listings を保存できなかった商品の投稿 ID（作成なら、できてしまった商品）.
	 * @param array<int, string> $unsavedFields 入らなかったと分かっているフィールド名.
	 */
	private static function lockedResponse( int $productId, array $unsavedFields ): WP_REST_Response {
		return self::partialSaveResponse(
			self::CODE_LISTING_LOCKED,
			self::lockedMessage(),
			$productId,
			$unsavedFields,
			409
		);
	}

	/**
	 * 書き込みが効かなかった（保存したのに入っていない）ときの応答。
	 *
	 * {@see \Affilicard\Repository\ProductMetaWriteFailure} を捕まえてここへ変える。
	 * 捕まえないと例外が REST の外まで抜け、WordPress の一般的な 500（あるいは致命的
	 * エラー）になって、何が起きたのかが呼び出し側にも運用者にも伝わらない。
	 *
	 * **409 と同じく `id` を載せる。** こちらは「やり直しても直らない」失敗なので
	 * なおさら必要で、ID が無ければ呼び出し側は POST を繰り返し、そのたびに listings の
	 * 無い商品が 1 つずつ増える。**同じ 500 でも `id` が無い場合がある**——
	 * `wp_insert_post()` 自体が失敗して商品が 1 件も作られなかったときで、その区別が
	 * そのまま「PATCH で直せるか／POST し直すか」の分かれ目になる。
	 *
	 * **`unsaved_fields` も 409 と同じ形で載せる。** こちらは「やり直しても直らない」
	 * 失敗なので、呼び出し側は原因を取り除いたあとに**足りない分だけ**を送り直すことに
	 * なる。何が足りないのかが応答に無ければ、全項目を送り直して保存済みの編集を
	 * 上書きするしかない。**listings 以外が入らなかっただけでもここへ来る**——
	 * 運用者が送った値が黙って消えるのは listings でも `stock_status` でも同じだからで、
	 * どれが消えたかは例外が運んでくる。
	 *
	 * @param int                $productId     meta を保存できなかった商品の投稿 ID（作成なら、できてしまった商品）.
	 * @param array<int, string> $unsavedFields 入らなかったと分かっているフィールド名.
	 */
	private static function saveFailedResponse( int $productId, array $unsavedFields ): WP_REST_Response {
		return self::partialSaveResponse(
			self::CODE_SAVE_FAILED,
			self::saveFailedMessage(),
			$productId,
			$unsavedFields,
			500
		);
	}

	/**
	 * 部分保存（商品はできている・要求の一部が入らなかった）の応答を組み立てる。
	 *
	 * 409 と 500 で形を変えないために 1 箇所へまとめる。**`unsaved_fields` は空なら
	 * 落とす**——キーの有無が「部分保存かどうか」を表すので、空配列を載せると
	 * 「部分保存だが失われたものは無い」という読めない状態を作る
	 * （{@see self::ERROR_UNSAVED_FIELDS_KEY}）。
	 *
	 * @param array<int, string> $unsavedFields 入らなかったと分かっているフィールド名.
	 */
	private static function partialSaveResponse( string $code, string $message, int $productId, array $unsavedFields, int $status ): WP_REST_Response {
		$body = array(
			'code'                     => $code,
			'message'                  => $message,
			self::ERROR_PRODUCT_ID_KEY => $productId,
		);
		if ( array() !== $unsavedFields ) {
			$body[ self::ERROR_UNSAVED_FIELDS_KEY ] = array_values( $unsavedFields );
		}

		return new WP_REST_Response( $body, $status );
	}

	/**
	 * `/bulk` の 1 item ぶんの部分保存報告を組み立てる。
	 *
	 * **単体の応答と形をそろえる**（`code` / `message` / `id` / `unsaved_fields`）。
	 * 呼び出し側が「単体か bulk か」で読み替えずに済むのが狙いで、`unsaved_fields` を
	 * 空なら落とす規則も {@see self::partialSaveResponse()} と同じにする。
	 *
	 * @param array<int, string> $unsavedFields 入らなかったと分かっているフィールド名.
	 * @return array<string, mixed>
	 */
	private static function partialSaveItem( int $index, string $code, string $message, int $productId, array $unsavedFields ): array {
		$item = array(
			'index'                    => $index,
			'status'                   => 'error',
			'code'                     => $code,
			'message'                  => $message,
			self::ERROR_PRODUCT_ID_KEY => $productId,
		);
		if ( array() !== $unsavedFields ) {
			$item[ self::ERROR_UNSAVED_FIELDS_KEY ] = array_values( $unsavedFields );
		}

		return $item;
	}

	/**
	 * 「やり直しても直らない」ことまで書いた文言（409 の文言と対になる）。
	 */
	private static function saveFailedMessage(): string {
		return __(
			'購入リンクを保存できませんでした（書き込んだ内容がデータベースに入っていません）。時間を置いても解消しません。別のプラグインが meta の保存に介入していないか確認してください。',
			'affilicard'
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
				// **どの商品ができてしまったかを名指しする。** 作成の途中で断られて
				// いるため、この item の商品は既に存在する。ID を返さないと、呼び出し
				// 側はこの item を積み直すたびに同じ商品を作り続ける。
				++$failed;
				$results[] = self::partialSaveItem(
					$index,
					self::CODE_LISTING_LOCKED,
					self::lockedMessage(),
					$e->postId(),
					$e->unsavedFields()
				);
				continue;
			} catch ( ProductMetaWriteFailure $e ) {
				// listings を書いたのに入らなかった。**捕まえないとループの外まで抜け、
				// 既に作成できた item の結果ごと 500 で消える**（呼び出し側はどれが
				// 入ったのか判別できない）。ロック競合と同じく per-item の報告に混ぜるが、
				// コードは分ける——積み直しても直らない失敗である。
				// ロック競合と同じく、できてしまった商品の ID を添える（積み直しを
				// POST のやり直しではなく PATCH にできるようにするため）。
				++$failed;
				$results[] = self::partialSaveItem(
					$index,
					self::CODE_SAVE_FAILED,
					self::saveFailedMessage(),
					$e->postId(),
					$e->unsavedFields()
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
			return self::lockedResponse( $e->postId(), $e->unsavedFields() );
		} catch ( ProductMetaWriteFailure $e ) {
			return self::saveFailedResponse( $e->postId(), $e->unsavedFields() );
		}
		if ( $id <= 0 ) {
			// **ここだけは id を載せない。** `wp_insert_post()` そのものが失敗して
			// いて、商品は 1 件も作られていない（listings を書く手前で戻っている）。
			// 存在しない ID を返すと、呼び出し側はそれを PATCH しに行って 404 を踏む。
			// id の有無がそのまま「PATCH で直せるか／POST し直すか」の signal である
			// （{@see self::ERROR_PRODUCT_ID_KEY}）。
			return new WP_REST_Response(
				array(
					'code'    => self::CODE_SAVE_FAILED,
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
			return self::lockedResponse( $e->postId(), $e->unsavedFields() );
		} catch ( ProductMetaWriteFailure $e ) {
			return self::saveFailedResponse( $e->postId(), $e->unsavedFields() );
		}
		if ( $saved_id <= 0 ) {
			// 更新なので商品は実在する（直前の find() で引けている）。作成側の同じ
			// 分岐が id を載せないのと**対照的だが矛盾しない**——規則は「サーバが
			// 実在を知っている商品の ID だけを載せる」であり、ここは実在する。
			return new WP_REST_Response(
				array(
					'code'                     => self::CODE_SAVE_FAILED,
					'message'                  => __( '商品の更新に失敗しました。', 'affilicard' ),
					self::ERROR_PRODUCT_ID_KEY => $id,
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
