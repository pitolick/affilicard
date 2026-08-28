<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * AS ジョブの実行期限を扱う。
 *
 * バッチハンドラは「待機に入る前に」残り時間を確認し、賄えない場合は待たずに
 * 未処理分を積み直して終了しなければならない（spec §4-1）。待機に入ってから
 * 期限を超えると AS ランナーの時間予算を食い潰したうえ、積み直しも行われず
 * そのバッチの残りが失われる。
 */
final class JobDeadline {

	private int $deadlineTs;

	public function __construct( int $startedAt, int $timeLimitSeconds, int $safetyMarginSeconds ) {
		$this->deadlineTs = $startedAt + $timeLimitSeconds - $safetyMarginSeconds;
	}

	/** 期限までの残り秒（負にはならない）。 */
	public function remaining( int $nowTs ): int {
		return max( 0, $this->deadlineTs - $nowTs );
	}

	/** $needSeconds を期限内に賄えるか。 */
	public function canAfford( int $nowTs, int $needSeconds ): bool {
		return $this->remaining( $nowTs ) >= $needSeconds;
	}

	/** 待機秒を残り時間で切り詰める。 */
	public function clampWait( int $nowTs, int $waitSeconds ): int {
		return min( max( 0, $waitSeconds ), $this->remaining( $nowTs ) );
	}
}
