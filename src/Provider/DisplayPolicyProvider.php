<?php
declare(strict_types=1);

namespace Affilicard\Provider;

/**
 * カード上の表示の決まり（免責文言・提供元の表記・書影の表示期限）を持つ Provider が
 * 追加で実装する任意インターフェース。
 */
interface DisplayPolicyProvider {

	public function displayPolicy(): DisplayPolicy;
}
