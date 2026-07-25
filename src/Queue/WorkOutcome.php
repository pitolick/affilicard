<?php
declare(strict_types=1);

namespace Affilicard\Queue;

/**
 * ThrottledActionHandler::performWork() の結果を3値で表す。
 *
 * give-up 機構が「恒久失敗（terminal）のみ give-up し、一時失敗（transient）はリトライして
 * give-up しない」を判定するために、単なる成否（bool）ではなく失敗を2種に分ける。
 */
enum WorkOutcome {
	/** 成功、または対象なし（no-op）。attempts/give-up マーカーをクリアする。 */
	case SUCCESS;

	/** 一時失敗。backoff でリトライする。上限で failed 化するが give-up はしない。 */
	case TRANSIENT_FAILURE;

	/** 恒久失敗。即 give-up・リトライせず complete する。 */
	case TERMINAL_FAILURE;
}
