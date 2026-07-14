/**
 * Tests for src/Admin/providers.js
 */

describe( 'providers（window.affilicardProviders からの導出）', () => {
	const load = () => {
		jest.resetModules();
		return require( '../../../src/Admin/providers' );
	};

	afterEach( () => {
		delete window.affilicardProviders;
	} );

	it( 'PROVIDER_OPTIONS と providerAccount を導出する', () => {
		window.affilicardProviders = [
			{
				code: 'manual',
				label: '手動入力',
				isAutomatic: false,
				accountCode: null,
			},
			{
				code: 'rakuten-kobo',
				label: '楽天Kobo',
				isAutomatic: true,
				accountCode: 'rakuten',
			},
		];
		const { PROVIDER_OPTIONS, providerAccount } = load();
		expect( PROVIDER_OPTIONS ).toEqual( [
			{ label: '手動入力', value: 'manual' },
			{ label: '楽天Kobo', value: 'rakuten-kobo' },
		] );
		expect( providerAccount( 'rakuten-kobo' ) ).toBe( 'rakuten' );
		expect( providerAccount( 'manual' ) ).toBeNull();
	} );

	it( '未定義なら空にフォールバック', () => {
		const { PROVIDER_OPTIONS } = load();
		expect( PROVIDER_OPTIONS ).toEqual( [] );
	} );
} );
