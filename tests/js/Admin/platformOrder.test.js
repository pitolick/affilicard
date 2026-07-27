/**
 * Tests for src/Admin/platformOrder.js
 */

import {
	platformsOfType,
	enabledRanks,
	movePlatform,
	renumberDisplayOrder,
} from '../../../src/Admin/platformOrder';

const make = ( code, displayOrder, enabled = true, types = [ 'ebook' ] ) => ( {
	code,
	name: code.toUpperCase(),
	enabled,
	displayOrder,
	applicableTypes: types,
} );

// a(1) / b(2, 無効) / c(3) / v(4, vod)
const platforms = [
	make( 'a', 1 ),
	make( 'b', 2, false ),
	make( 'c', 3 ),
	make( 'v', 4, true, [ 'vod' ] ),
];

describe( 'platformsOfType', () => {
	test( 'そのタイプの platform だけを配列順で返す', () => {
		expect( platformsOfType( platforms, 'ebook' ).map( ( p ) => p.code ) ).toEqual(
			[ 'a', 'b', 'c' ]
		);
		expect( platformsOfType( platforms, 'vod' ).map( ( p ) => p.code ) ).toEqual(
			[ 'v' ]
		);
	} );

	test( 'applicableTypes が配列でない platform は含めない', () => {
		const broken = [ { code: 'x', enabled: true, displayOrder: 1 } ];
		expect( platformsOfType( broken, 'ebook' ) ).toEqual( [] );
	} );
} );

describe( 'enabledRanks', () => {
	test( '有効な platform にだけ 1 始まりの順位を振る（無効は飛ばす）', () => {
		expect( enabledRanks( platforms, 'ebook' ) ).toEqual( { a: 1, c: 2 } );
	} );

	test( 'タイプごとに 1 から数え直す', () => {
		expect( enabledRanks( platforms, 'vod' ) ).toEqual( { v: 1 } );
	} );
} );

describe( 'movePlatform', () => {
	test( '下へ移動すると次の有効な platform と入れ替わる', () => {
		const next = movePlatform( platforms, 'ebook', 'a', 'down' );
		expect( next.map( ( p ) => p.code ) ).toEqual( [ 'c', 'b', 'a', 'v' ] );
	} );

	test( '上へ移動すると前の有効な platform と入れ替わる', () => {
		const next = movePlatform( platforms, 'ebook', 'c', 'up' );
		expect( next.map( ( p ) => p.code ) ).toEqual( [ 'c', 'b', 'a', 'v' ] );
	} );

	test( '間に挟まる無効な platform の位置は動かない', () => {
		const next = movePlatform( platforms, 'ebook', 'a', 'down' );
		expect( next[ 1 ].code ).toBe( 'b' );
	} );

	test( '他タイプの platform は動かない', () => {
		const next = movePlatform( platforms, 'ebook', 'a', 'down' );
		expect( next[ 3 ].code ).toBe( 'v' );
	} );

	test( '移動後は displayOrder が配列順の 1..N に振り直される', () => {
		const next = movePlatform( platforms, 'ebook', 'a', 'down' );
		expect( next.map( ( p ) => p.displayOrder ) ).toEqual( [ 1, 2, 3, 4 ] );
		expect( next.find( ( p ) => p.code === 'a' ).displayOrder ).toBe( 3 );
	} );

	test( '先頭の platform を上へ移動しようとしても何も起きない（同一参照）', () => {
		expect( movePlatform( platforms, 'ebook', 'a', 'up' ) ).toBe( platforms );
	} );

	test( '末尾の platform を下へ移動しようとしても何も起きない（同一参照）', () => {
		expect( movePlatform( platforms, 'ebook', 'c', 'down' ) ).toBe( platforms );
	} );

	test( '未知の code は何も起きない（同一参照）', () => {
		expect( movePlatform( platforms, 'ebook', 'zzz', 'down' ) ).toBe( platforms );
	} );

	test( '元の配列を破壊しない', () => {
		movePlatform( platforms, 'ebook', 'a', 'down' );
		expect( platforms.map( ( p ) => p.code ) ).toEqual( [ 'a', 'b', 'c', 'v' ] );
	} );
} );

describe( 'renumberDisplayOrder', () => {
	test( '重複値・欠番を配列順の 1..N に正規化する', () => {
		const messy = [ make( 'a', 9 ), make( 'b', 9 ), make( 'c', 40 ) ];
		expect(
			renumberDisplayOrder( messy ).map( ( p ) => p.displayOrder )
		).toEqual( [ 1, 2, 3 ] );
	} );

	test( 'displayOrder 以外のプロパティは保持する', () => {
		const [ first ] = renumberDisplayOrder( [ make( 'a', 9 ) ] );
		expect( first.code ).toBe( 'a' );
		expect( first.applicableTypes ).toEqual( [ 'ebook' ] );
	} );
} );
