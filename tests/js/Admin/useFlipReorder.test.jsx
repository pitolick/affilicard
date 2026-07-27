/**
 * Tests for src/Admin/useFlipReorder.js
 *
 * jsdom はレイアウトを持たず getBoundingClientRect が常に 0 を返すため、
 * 「実際に何 px 動いたか」は素の状態では検証できない。位置だけを差し替えた
 * スタブを当てて FLIP の呼び出し契約を検証し、それ以外は degrade を守る。
 */

import { useRef, useState } from '@wordpress/element';
import { render, fireEvent, screen } from '@testing-library/react';
import { useFlipReorder } from '../../../src/Admin/useFlipReorder';

function Rows( { codes, onSwap, skipCapture = false } ) {
	const ref = useRef( null );
	const capturePositions = useFlipReorder( ref );
	const handleSwap = () => {
		if ( ! skipCapture ) {
			capturePositions();
		}
		onSwap();
	};
	return (
		<>
			<button type="button" onClick={ handleSwap }>
				swap
			</button>
			<div ref={ ref }>
				{ codes.map( ( code ) => (
					<div key={ code } data-platform-code={ code }>
						{ code }
					</div>
				) ) }
			</div>
		</>
	);
}

function Harness( { initial, skipCapture = false } ) {
	const [ codes, setCodes ] = useState( initial );
	return (
		<Rows
			codes={ codes }
			skipCapture={ skipCapture }
			onSwap={ () => setCodes( ( prev ) => [ ...prev ].reverse() ) }
		/>
	);
}

const originalMatchMedia = window.matchMedia;
const originalGetRect = Element.prototype.getBoundingClientRect;

/** 行の top を「親の中での並び順 × 100px」として返すスタブを当てる。 */
function stubLayout() {
	Element.prototype.getBoundingClientRect = function () {
		const siblings = Array.from( this.parentElement?.children ?? [] );
		return { top: siblings.indexOf( this ) * 100 };
	};
}

describe( 'useFlipReorder', () => {
	afterEach( () => {
		delete Element.prototype.animate;
		window.matchMedia = originalMatchMedia;
		Element.prototype.getBoundingClientRect = originalGetRect;
	} );

	test( 'animate が無い環境でも並べ替えで例外を投げない', () => {
		expect( typeof Element.prototype.animate ).toBe( 'undefined' );
		render( <Harness initial={ [ 'a', 'b' ] } /> );
		expect( () =>
			fireEvent.click( screen.getByRole( 'button', { name: 'swap' } ) )
		).not.toThrow();
	} );

	test( '並び順が変わると移動量ぶんの translateY からアニメートする', () => {
		const animate = jest.fn();
		Element.prototype.animate = animate;
		window.matchMedia = jest.fn().mockReturnValue( { matches: false } );
		stubLayout();

		render( <Harness initial={ [ 'a', 'b' ] } /> );
		expect( animate ).not.toHaveBeenCalled(); // 初回描画では動かさない

		fireEvent.click( screen.getByRole( 'button', { name: 'swap' } ) );

		expect( animate ).toHaveBeenCalledTimes( 2 );
		const offsets = animate.mock.calls.map(
			( [ keyframes ] ) => keyframes[ 0 ].transform
		);
		// a は 0px→100px（-100px から戻す）、b は 100px→0px（+100px から戻す）
		expect( offsets ).toContain( 'translateY(-100px)' );
		expect( offsets ).toContain( 'translateY(100px)' );
		expect( animate.mock.calls[ 0 ][ 1 ] ).toEqual( {
			duration: 180,
			easing: 'ease-in-out',
		} );
	} );

	test( '並び順が変わらない再描画ではアニメートしない', () => {
		const animate = jest.fn();
		Element.prototype.animate = animate;
		window.matchMedia = jest.fn().mockReturnValue( { matches: false } );
		stubLayout();

		const { rerender } = render( <Harness initial={ [ 'a', 'b' ] } /> );
		rerender( <Harness initial={ [ 'a', 'b' ] } /> );
		expect( animate ).not.toHaveBeenCalled();
	} );

	test( 'capture せずに再描画した場合はアニメートしない', () => {
		const animate = jest.fn();
		Element.prototype.animate = animate;
		window.matchMedia = jest.fn().mockReturnValue( { matches: false } );
		stubLayout();

		// capturePositions() を呼ばずに並び順だけ変える（アコーディオン開閉等で
		// 親が再描画されるだけのケースを模す）。First が無いのでアニメートしない。
		render( <Harness initial={ [ 'a', 'b' ] } skipCapture /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'swap' } ) );
		expect( animate ).not.toHaveBeenCalled();
	} );

	test( 'prefers-reduced-motion: reduce のとき animate を呼ばない', () => {
		const animate = jest.fn();
		Element.prototype.animate = animate;
		window.matchMedia = jest.fn().mockReturnValue( { matches: true } );
		stubLayout();

		render( <Harness initial={ [ 'a', 'b' ] } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'swap' } ) );
		expect( animate ).not.toHaveBeenCalled();
	} );
} );
