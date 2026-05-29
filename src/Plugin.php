<?php
declare(strict_types=1);

namespace Affilicard;

use Affilicard\PostType\ProductPostType;

/**
 * プラグインのブートストラップ。
 *
 * Phase 4a-0 では CPT 登録のみ。後続 Phase で REST/Settings/Block を配線する。
 */
final class Plugin {

	public static function boot(): void {
		add_action( 'init', array( ProductPostType::class, 'register' ) );
	}
}
