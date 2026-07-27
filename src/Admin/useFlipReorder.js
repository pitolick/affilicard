/**
 * 並べ替え時に行が上下へ滑って入れ替わるアニメーション（FLIP）。
 *
 * FLIP（First-Last-Invert-Play）の "First"（移動前の座標）は、並べ替えを実行する
 * 瞬間に呼び出し側が `capturePositions()` で明示的に測る。所有コンポーネントの
 * 再描画タイミングに依存すると、アコーディオン開閉のように親を再描画しない DOM
 * 変化で座標が古くなる（stale になる）ため、この経路には頼らない。
 *
 * アニメーションは装飾であり、機能の前提にしない。Web Animations API が無い環境
 * （jsdom を含む）や prefers-reduced-motion: reduce の環境では黙ってスキップする。
 */

import { useCallback, useLayoutEffect, useRef } from '@wordpress/element';

const DURATION_MS = 180;
const ROW_SELECTOR = '[data-platform-code]';

function prefersReducedMotion() {
	return (
		typeof window !== 'undefined' &&
		typeof window.matchMedia === 'function' &&
		window.matchMedia('(prefers-reduced-motion: reduce)').matches === true
	);
}

function measurePositions(container) {
	const rows = Array.from(container.querySelectorAll(ROW_SELECTOR));
	const positions = new Map();
	for (const row of rows) {
		positions.set(
			row.dataset.platformCode,
			row.getBoundingClientRect().top
		);
	}
	return positions;
}

/**
 * @param {Object} containerRef 行を含む要素の ref
 * @return {Function} capturePositions 並べ替え実行直前に呼ぶ、現在の座標を記録する関数
 */
export function useFlipReorder(containerRef) {
	const capturedPositionsRef = useRef(null);

	// 並べ替えを実行する瞬間（state 更新の直前）に呼ぶ。ここで測った座標が FLIP の First になる。
	const capturePositions = useCallback(() => {
		const container = containerRef.current;
		capturedPositionsRef.current = container
			? measurePositions(container)
			: null;
	}, [containerRef]);

	useLayoutEffect(() => {
		// 直前に capture された座標がある場合だけ Last を測ってアニメートする。
		// capture 無しの再描画（アコーディオン開閉・データ再取得等）では何もしない。
		const previousPositions = capturedPositionsRef.current;
		capturedPositionsRef.current = null;
		if (!previousPositions) {
			return;
		}

		const container = containerRef.current;
		if (!container) {
			return;
		}
		if (prefersReducedMotion()) {
			return;
		}

		const rows = Array.from(container.querySelectorAll(ROW_SELECTOR));
		for (const row of rows) {
			if (typeof row.animate !== 'function') {
				return;
			}
			const from = previousPositions.get(row.dataset.platformCode);
			const to = row.getBoundingClientRect().top;
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

	return capturePositions;
}
