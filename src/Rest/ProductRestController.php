<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use WP_Error;
use WP_REST_Posts_Controller;
use WP_REST_Request;

/**
 * affilicard_product 用のカスタム REST コントローラ。
 *
 * コア WP_REST_Posts_Controller は publish 投稿を未認証でも read 可能にする
 * （check_read_permission が post_status==='publish' で無条件 true）。商品カタログを
 * 未認証に露出させないため、read 系 permission を edit_posts 必須に上書きする。
 * Gutenberg の認証済み編集（編集者は edit_posts を持つ）と書き込み系は親実装のまま不変。
 */
final class ProductRestController extends WP_REST_Posts_Controller {

	/**
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		$denied = $this->requireEditPosts();
		return null === $denied ? parent::get_items_permissions_check( $request ) : $denied;
	}

	/**
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$denied = $this->requireEditPosts();
		return null === $denied ? parent::get_item_permissions_check( $request ) : $denied;
	}

	/**
	 * 未認証/低権限を拒否。許可なら null を返し親 permission に委譲させる。
	 *
	 * @return WP_Error|null
	 */
	private function requireEditPosts() {
		if ( current_user_can( 'edit_posts' ) ) {
			return null;
		}
		return new WP_Error(
			'affilicard_rest_forbidden',
			__( 'この商品データへのアクセス権限がありません。', 'affilicard' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
