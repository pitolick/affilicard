<?php
declare(strict_types=1);

namespace Affilicard;

/**
 * プラグインのブートストラップ。
 *
 * Phase 4a-0 では空 boot。後続 Phase で CPT/REST/Settings/Block を配線する。
 */
final class Plugin {

	public static function boot(): void {
		// Phase 4a-1 以降で hook を追加する。
	}
}
