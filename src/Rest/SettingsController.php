<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\Settings\GeneralSettings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/affilicard/v1/settings` エンドポイントの実装。
 *
 * 書き込み（PUT）は `manage_options` を要求する。読み取り（GET）は
 * `edit_posts` まで緩めている——商品カード編集画面（ProductSettingsPanel）が
 * `GeneralSettings::fallbackOnTerminal()` をここ経由で読む必要があり、
 * affilicard_product の capability_type は 'post' で Editor は
 * manage_options を持たないため。ProductRestController が商品自体の
 * read 許可を edit_posts に合わせているのと同じ理由・同じ capability。
 * `manage_options` のままだと Editor には 403 が返り、呼び出し側が
 * 既定 false へ黙って倒れて「使用中」の印が ON 設定なのに間違う。
 * ただし返す中身は権限で絞る（{@see self::get()}）——読み取り許可を緩めた
 * ことが、一般設定オブジェクト全体の公開に化けないようにするため。
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
					'permission_callback' => array( $this, 'canReadSettings' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'canManageOptions' ),
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

	/**
	 * 一般設定を返す。
	 *
	 * **返す範囲は権限で切り替える。** GET の許可は edit_posts まで緩めてあるが、
	 * それは商品編集画面（ProductSettingsPanel）が `fallback_on_terminal` 1 つを
	 * 読むためであって、一般設定オブジェクト全体（キューの状態・保持期間・
	 * スロットル上書き等。認証情報は含まない）を渡す理由にはならない。
	 * 公開プラグインでは一度広げた endpoint はそのまま固定化するため、
	 * URL は変えずに中身だけ必要最小限へ絞る。
	 */
	public function get( WP_REST_Request $request ): WP_REST_Response {
		$settings = GeneralSettings::get();

		if ( ! $this->canManageOptions() ) {
			return new WP_REST_Response(
				array( 'fallback_on_terminal' => (bool) ( $settings['fallback_on_terminal'] ?? false ) ),
				200
			);
		}

		return new WP_REST_Response( $settings, 200 );
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
