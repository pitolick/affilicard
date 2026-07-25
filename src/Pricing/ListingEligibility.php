<?php
declare(strict_types=1);

namespace Affilicard\Pricing;

/**
 * listing の自動更新対象判定（update_mode/enabled/auto_update）を一元化する共有ヘルパー。
 *
 * QueueMaintenance::sweep()・PublishTrigger（forceEnqueueEligibleListings）・
 * Enqueuer::enqueueProductListings()・（旧）ListingRefresher::isListingEligible() で
 * 同一ロジックが重複していたため、ここに集約する。
 */
final class ListingEligibility {

	/**
	 * enqueue 判定: update_mode=auto && enabled(既定true) && ( $force || auto_update(既定true) )。
	 *
	 * $force=true は auto_update=false（ユーザーが手動上書き中）の listing も対象に含める
	 * （管理画面「強制更新」ボタン用）。update_mode=manual・enabled=false は $force でも救済しない。
	 *
	 * @param array<string, mixed> $listing
	 */
	public static function isAutoEligible( array $listing, bool $force = false ): bool {
		if ( ! self::isEnabledAuto( $listing ) ) {
			return false;
		}
		$autoUpdate = ! isset( $listing['auto_update'] ) || (bool) $listing['auto_update'];
		return $force || $autoUpdate;
	}

	/**
	 * 実行時 (runtime) 再チェック用: update_mode=auto && enabled(既定true) のみを見る
	 * （auto_update は無視する）。
	 *
	 * force enqueue された listing（enqueue 時点では auto_update=false でも対象に含まれた）を、
	 * ワーカー実行時（ListingRefresher::refreshOne）に auto_update だけを理由に取りこぼさない
	 * ための判定。DISABLED・manual への切り替えは enqueue 後でも安全のためここで弾く。
	 *
	 * @param array<string, mixed> $listing
	 */
	public static function isEnabledAuto( array $listing ): bool {
		$mode = isset( $listing['update_mode'] ) ? (string) $listing['update_mode'] : 'auto';
		if ( 'auto' !== $mode ) {
			return false;
		}
		return ! isset( $listing['enabled'] ) || (bool) $listing['enabled'];
	}
}
