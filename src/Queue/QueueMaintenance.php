<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductPostType;
use Affilicard\Pricing\ListingEligibility;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Repository\ProductRepositoryInterface;
use Affilicard\Settings\GeneralSettings;

/**
 * 掃引（sweep）: 公開中の全商品を走査し、自動更新対象 listing を Enqueuer 経由で
 * Action Scheduler へ積む。affilicard_refresh_all（全体単一 WP-Cron イベント）から
 * 呼ばれる想定。
 *
 * ここで行うのは enqueue 時点の対象フィルタ（update_mode=auto && enabled && auto_update
 * && platform 定義が既知）のみ。鮮度スキップ（fresh は積まない）・depth cap・jitter は
 * Enqueuer::enqueueSweep() 側の責務。
 *
 * 注意: ListingRefresher::refreshOne()（ハンドラが実行時に呼ぶ）は v2.4.0 で
 * update_mode/enabled のみを再チェックするが、auto_update は見ない（force enqueue との
 * 両立のため）。よって auto_update=false の listing を積まないことは、enqueue 時点の
 * このフィルタが引き続き唯一のゲートになる。
 *
 * registerRetentionFilters(): Action Scheduler の完了/失敗アクション保持期間を
 * GeneralSettings（管理画面で設定した done 時間 / failed 日数）へ連動させる。
 * AS 自身の掃除 cron（action_scheduler_run_canceller 等）がこのフィルタ値を使って
 * 古い completed/failed アクションを purge するため、reconcile（取りこぼし回収）は
 * sweep（stale listing の再 enqueue）と AS 自身の recurring-action 安全策で実質的に
 * カバーされる。
 */
final class QueueMaintenance {

	public function __construct(
		private ProductRepositoryInterface $repository,
		private Enqueuer $enqueuer,
		private ProviderRegistry $providerRegistry
	) {}

	public function sweep(): void {
		$ids = get_posts(
			array(
				'post_type'      => ProductPostType::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		if ( ! is_array( $ids ) ) {
			return;
		}

		$now = time();
		foreach ( $ids as $id ) {
			$product = $this->repository->find( (int) $id );
			if ( null === $product || ! is_array( $product['listings'] ?? null ) ) {
				continue;
			}

			foreach ( $product['listings'] as $listing ) {
				if ( ! is_array( $listing ) ) {
					continue;
				}
				$platform = (string) ( $listing['platform'] ?? '' );
				$def      = PlatformConfig::find( $platform );
				if ( null === $def ) {
					continue;
				}

				// auto listing のみ（update_mode=auto・enabled・auto_update）。
				if ( ! ListingEligibility::isAutoEligible( $listing ) ) {
					continue;
				}

				// v2.4.0: enqueueSweep の group も account コード単位。account が解決できない
				// （provider 未登録、または手動系で accountCode() が null）listing は積まない。
				$account = $this->providerRegistry->get( $def->provider )?->accountCode();
				if ( null === $account ) {
					continue;
				}

				$this->enqueuer->enqueueSweep( (int) $id, $platform, $account, $def, $listing, $now );
			}
		}
	}

	/**
	 * Action Scheduler の completed/failed アクション保持期間フィルタを
	 * GeneralSettings の値に連動させる。
	 */
	public static function registerRetentionFilters(): void {
		add_filter( 'action_scheduler_retention_period', array( self::class, 'doneRetentionSeconds' ) );
		add_filter( 'action_scheduler_retention_period_for_failed', array( self::class, 'failedRetentionSeconds' ) );
	}

	/** completed アクションの保持期間（秒）。 */
	public static function doneRetentionSeconds(): int {
		return GeneralSettings::retentionDoneHours() * HOUR_IN_SECONDS;
	}

	/** failed アクションの保持期間（秒）。 */
	public static function failedRetentionSeconds(): int {
		return GeneralSettings::retentionFailedDays() * DAY_IN_SECONDS;
	}
}
