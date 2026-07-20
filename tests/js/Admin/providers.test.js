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

	describe( 'providerOptionsFor（platform 向けに候補を絞る）', () => {
		beforeEach( () => {
			window.affilicardProviders = [
				{
					code: 'manual',
					label: '手動入力',
					isAutomatic: false,
					accountCode: null,
				},
				{
					code: 'dmm-ebook',
					label: 'DMM API',
					isAutomatic: true,
					accountCode: 'dmm',
				},
				{
					code: 'rakuten-kobo',
					label: '楽天Kobo API',
					isAutomatic: true,
					accountCode: 'rakuten',
				},
			];
		} );

		it( 'manual 選択中は自動 provider を一切出さない', () => {
			const { providerOptionsFor } = load();
			expect( providerOptionsFor( 'manual' ) ).toEqual( [
				{ label: '手動入力', value: 'manual' },
			] );
		} );

		it( '自動 provider 選択中は manual＋その provider のみ（無関係な自動 provider は出さない）', () => {
			const { providerOptionsFor } = load();
			expect( providerOptionsFor( 'dmm-ebook' ) ).toEqual( [
				{ label: '手動入力', value: 'manual' },
				{ label: 'DMM API', value: 'dmm-ebook' },
			] );
		} );

		it( 'provider 未指定は manual のみにフォールバックする', () => {
			const { providerOptionsFor } = load();
			expect( providerOptionsFor( undefined ) ).toEqual( [
				{ label: '手動入力', value: 'manual' },
			] );
		} );

		it( 'eligibleProvider を候補に含める（現在 manual でも切替可能に）', () => {
			const { providerOptionsFor } = load();
			const opts = providerOptionsFor( 'manual', 'rakuten-kobo' ).map(
				( o ) => o.value
			);
			expect( opts ).toContain( 'manual' );
			expect( opts ).toContain( 'rakuten-kobo' );
			expect( opts ).not.toContain( 'dmm-ebook' );
		} );

		it( 'eligibleProvider 無指定なら従来通り manual＋現在値のみ', () => {
			const { providerOptionsFor } = load();
			expect( providerOptionsFor( 'manual' ) ).toEqual( [
				{ label: '手動入力', value: 'manual' },
			] );
		} );
	} );
} );
