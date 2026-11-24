<?php
declare(strict_types=1);

namespace Affilicard\Provider;

/**
 * Provider のライセンスが求める「カード上の表示の決まり」。
 *
 * カード（CardRenderer）はストア固有の文言を持たず、listing の platform に紐づく Provider が
 * 申告したこの値に従う（設計 2026-11-24 §5）。
 */
final class DisplayPolicy {

	/**
	 * @param string   $priceDisclaimer    価格の時点表示に添える免責文言（空なら出さない）
	 * @param string   $contentAttribution そのストアの情報を表示しているカードに出す提供元の表記（空なら出さない）
	 * @param int|null $imageTtlHours      取得から何時間まで書影を表示してよいか（null は制限なし）
	 */
	public function __construct(
		public readonly string $priceDisclaimer = '',
		public readonly string $contentAttribution = '',
		public readonly ?int $imageTtlHours = null
	) {}
}
