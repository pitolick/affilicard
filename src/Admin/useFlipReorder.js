/**
 * 並べ替え時に行が上下へ滑って入れ替わるアニメーション（FLIP）。
 *
 * アコーディオンの開閉で行の高さが変わるため、固定高を前提とした CSS transition では
 * 破綻する。FLIP（First-Last-Invert-Play）なら任意の高さで成立する。
 *
 * アニメーションは装飾であり、機能の前提にしない。Web Animations API が無い環境
 * （jsdom を含む）や prefers-reduced-motion: reduce の環境では黙ってスキップする。
 */

import { useLayoutEffect, useRef } from '@wordpress/element';

const DURATION_MS = 180;
const ROW_SELECTOR = '[data-platform-code]';

function prefersReducedMotion() {
	return (
		typeof window !== 'undefined' &&
		typeof window.matchMedia === 'function' &&
		window.matchMedia('(prefers-reduced-motion: reduce)').matches === true
	);
}

/**
 * @param {Object} containerRef 行を含む要素の ref
 */
export function useFlipReorder(containerRef) {
	const positionsRef = useRef(null);
	const orderRef = useRef(null);

	// 依存配列を付けない＝毎描画で位置を測り直す。アコーディオンの開閉でも高さが
	// 変わるため、並べ替え時だけ測ると直前の位置が古くなって不自然に飛ぶ。
	useLayoutEffect(() => {
		const container = containerRef.current;
		if (!container) {
			return;
		}

		const rows = Array.from(container.querySelectorAll(ROW_SELECTOR));
		const previousPositions = positionsRef.current;
		const previousOrder = orderRef.current;

		const positions = new Map();
		for (const row of rows) {
			positions.set(
				row.dataset.platformCode,
				row.getBoundingClientRect().top
			);
		}
		const order = rows.map((row) => row.dataset.platformCode).join(',');

		positionsRef.current = positions;
		orderRef.current = order;

		// 初回描画、または並び順が変わっていない再描画ではアニメートしない。
		if (previousOrder === null || previousOrder === order) {
			return;
		}
		if (prefersReducedMotion()) {
			return;
		}

		for (const row of rows) {
			if (typeof row.animate !== 'function') {
				return;
			}
			const from = previousPositions.get(row.dataset.platformCode);
			const to = positions.get(row.dataset.platformCode);
			if (from === undefined || from === to) {
				continue;
			}
			row.animate(
				[
					{ transform: `translateY(${from - to}px)` },
					{ transform: 'translateY(0)' },
				],
				{ duration: DURATION_MS, easing: 'ease-in-out' }
			);
		}
	});
}
