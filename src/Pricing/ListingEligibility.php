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
	 * 自動更新対象とみなす update_mode の値。
	 *
	 * 'api' は v3.3.0 より前の商品編集 UI が書いた旧表記。当時の UI は選択肢を
	 * 'manual'/'api' で出していたのに判定側は 'auto' しか見ておらず、ユーザーが
	 * 自動更新のつもりで「API」を選んだ listing が永久に更新されなかった。
	 * v3.3.0 で UI を「自動更新」トグル（auto_update）へ一本化した際、既存データの
	 * 意図を汲むため 'auto' の別表記として受け入れる。
	 */
	private const AUTO_MODES = array( 'auto', 'api' );

	/**
	 * enqueue 判定: update_mode ∈ AUTO_MODES（既定 'auto'）&& enabled(既定true)
	 * && ( $force || auto_update(既定true) )。
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
	 * 実行時 (runtime) 再チェック用: update_mode ∈ AUTO_MODES && enabled(既定true) のみを
	 * 見る（auto_update は無視する）。
	 *
	 * force enqueue された listing（enqueue 時点では auto_update=false でも対象に含まれた）を、
	 * ワーカー実行時（ListingRefresher::refreshOne）に auto_update だけを理由に取りこぼさない
	 * ための判定。DISABLED・manual への切り替えは enqueue 後でも安全のためここで弾く。
	 *
	 * @param array<string, mixed> $listing
	 */
	public static function isEnabledAuto( array $listing ): bool {
		$mode = isset( $listing['update_mode'] ) ? (string) $listing['update_mode'] : 'auto';
		if ( ! in_array( $mode, self::AUTO_MODES, true ) ) {
			return false;
		}
		return ! isset( $listing['enabled'] ) || (bool) $listing['enabled'];
	}
}
