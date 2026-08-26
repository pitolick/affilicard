<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * sweep の走査位置（最後に処理した商品 post_id）を永続化する。
 *
 * 継続ジョブの投入に失敗しても、次回の sweep が途中から再開できるようにするための
 * 保険（spec §4-2）。カーソルが無いまま継続ジョブだけが失われると、その位置以降の
 * 商品が次の WP-Cron まで丸ごと更新されない。
 */
final class SweepCursor {

	public const OPTION_KEY = 'affilicard_sweep_cursor';

	/** 0 は「先頭から」を表す。 */
	public function get(): int {
		return (int) get_option( self::OPTION_KEY, 0 );
	}

	public function set( int $postId ): void {
		update_option( self::OPTION_KEY, $postId, false );
	}

	public function clear(): void {
		delete_option( self::OPTION_KEY );
	}
}
