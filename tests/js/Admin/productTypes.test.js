/**
 * Tests for src/Admin/productTypes.js
 *
 * window.affilicardProductTypes は tests/js/setup.js が PHP と同じ fixture を入れる。
 */
import {
	productTypes,
	productTypeLabel,
	productTypeOptions,
	extrasSchemaFor,
	typeAllowsOfferField,
} from '../../../src/Admin/productTypes';

describe( 'productTypes', () => {
	test( 'PHP が注入したタイプを並び順どおりに選択肢にする', () => {
		expect( productTypeOptions( 'generic' ) ).toEqual( [
			{ label: '汎用', value: 'generic' },
			{ label: '電子書籍', value: 'ebook' },
			{ label: '動画配信', value: 'vod' },
		] );
	} );

	test( '登録に無い保存値は「未登録のタイプ」として残す', () => {
		const options = productTypeOptions( 'gone' );
		expect( options.at( -1 ) ).toEqual( {
			label: '未登録のタイプ（gone）',
			value: 'gone',
		} );
	} );

	test( '注入が無いときは汎用だけにする', () => {
		delete window.affilicardProductTypes;
		expect( productTypes().map( ( t ) => t.code ) ).toEqual( [
			'generic',
		] );
	} );

	test( 'ラベル・追加情報・購入リンクの項目をタイプから引く', () => {
		expect( productTypeLabel( 'vod' ) ).toBe( '動画配信' );
		expect( productTypeLabel( 'gone' ) ).toBe( 'gone' );
		expect( extrasSchemaFor( 'ebook' ).map( ( s ) => s.key ) ).toEqual( [
			'author',
			'publisher',
			'isbn',
		] );
		expect( extrasSchemaFor( 'gone' ) ).toEqual( [] );
		expect( typeAllowsOfferField( 'vod', 'plan_label' ) ).toBe( true );
		expect( typeAllowsOfferField( 'ebook', 'plan_label' ) ).toBe( false );
		expect( typeAllowsOfferField( 'gone', 'plan_label' ) ).toBe( false );
	} );
} );
