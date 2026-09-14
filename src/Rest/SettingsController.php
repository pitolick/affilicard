<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\Settings\GeneralSettings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/affilicard/v1/settings` と `/affilicard/v1/editor-settings` の実装。
 *
 * **`/settings` は読み書きとも `manage_options` を要求する。** 一般設定は
 * キューの状態・保持期間・スロットル上書き等を含む管理者向けのオブジェクトで、
 * 編集者に見せる理由がない。
 *
 * 商品カード編集画面（ProductSettingsPanel）は `fallback_on_terminal` 1 つだけを
 * 必要とする。affilicard_product の capability_type は 'post' で Editor は
 * manage_options を持たないため、`/settings` を読ませると 403 になり、呼び出し側が
 * 既定 false へ黙って倒れて「使用中」の印が ON 設定なのに間違う。そのために
 * **その 1 項目だけを返す `/editor-settings` を別に置き**、`edit_posts` で読ませる。
 * ProductRestController が商品自体の read 許可を edit_posts に合わせているのと
 * 同じ capability である。
 *
 * 1 つの endpoint で権限により中身を出し分けると、「管理者専用の URL」なのか
 * 「誰でも読める URL」なのかが呼び出し側から判別できない。URL を分けることで
 * 権限の契約が URL 単位で固定される。
 */
final class SettingsController {

	public function registerRoutes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/editor-settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'getEditorSettings' ),
					'permission_callback' => array( $this, 'canReadSettings' ),
				),
			)
		);
	}

	public function canManageOptions(): bool {
		return (bool) current_user_can( 'manage_options' );
	}

	/**
	 * 読み取り専用の許可判定。書き込み（canManageOptions）より緩い。
	 */
	public function canReadSettings(): bool {
		return (bool) current_user_can( 'edit_posts' );
	}

	/** 一般設定を返す（`manage_options` 必須）。 */
	public function get( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( GeneralSettings::get(), 200 );
	}

	/**
	 * 商品編集画面が必要とする設定だけを返す（`edit_posts` で読める）。
	 *
	 * **増やさないこと。** ここへ項目を足すたびに、編集者に見せる範囲が広がる。
	 * 管理者向けの設定は `/settings` にある。
	 */
	public function getEditorSettings( WP_REST_Request $request ): WP_REST_Response {
		$settings = GeneralSettings::get();

		return new WP_REST_Response(
			array( 'fallback_on_terminal' => (bool) ( $settings['fallback_on_terminal'] ?? false ) ),
			200
		);
	}

	public function update( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$updated = GeneralSettings::update( $params );
		return new WP_REST_Response( $updated, 200 );
	}
}
