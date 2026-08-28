<?php
declare(strict_types=1);

namespace Affilicard\Stocktake;

use Affilicard\Settings\GeneralSettings;
use Affilicard\Upgrade\PluginUpgrade;

/**
 * 棚卸し判定。
 *
 * 「棚卸し済み」フラグは持たず毎回評価する。これにより設定期間を延ばすだけで
 * 対象から復帰でき、フラグの整合性管理もマイグレーションも不要になる（spec §5-2）。
 *
 * 適用範囲は QueueMaintenance::sweep()（継続更新）のみ。手動更新・強制更新
 * （管理画面ボタン・REST 経由の Enqueuer::enqueueProductListings()）には適用しない
 * （明示操作は常に実行する。spec §5-5）。
 */
final class StocktakePolicy {

	public function __construct( private PublicationDate $dates = new PublicationDate() ) {}

	public function isRetired( int $postId, int $nowTs ): bool {
		if ( ! GeneralSettings::isStocktakeEnabled() ) {
			return false;
		}

		$base = $this->dates->get( $postId ) ?? $this->baselineTs();
		if ( null === $base ) {
			// 基準日すら無い（移行前）＝判定不能。安全側に倒して棚卸ししない。
			return false;
		}

		return ( $base + GeneralSettings::stocktakeDays() * DAY_IN_SECONDS ) < $nowTs;
	}

	/** 棚卸し基準日（UTC epoch 秒）。無効値は null。 */
	private function baselineTs(): ?int {
		$raw = get_option( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, '' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}
		$ts = strtotime( trim( $raw ) );
		return false === $ts ? null : (int) $ts;
	}
}
