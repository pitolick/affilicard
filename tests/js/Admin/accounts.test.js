/**
 * Tests for src/Admin/accounts.js
 */

describe( 'accounts（window.affilicardAccounts からの導出）', () => {
	const load = () => {
		jest.resetModules();
		return require( '../../../src/Admin/accounts' );
	};

	afterEach( () => {
		delete window.affilicardAccounts;
	} );

	it( 'window から ACCOUNTS を導出する', () => {
		window.affilicardAccounts = [
			{
				code: 'rakuten',
				label: '楽天',
				credentialsSchema: [
					{
						key: 'access_key',
						label: 'AK',
						type: 'password',
						required: true,
					},
				],
			},
		];
		const { ACCOUNTS } = load();
		expect( ACCOUNTS[ 0 ].code ).toBe( 'rakuten' );
		expect( ACCOUNTS[ 0 ].credentialsSchema[ 0 ].type ).toBe( 'password' );
	} );

	it( '未定義なら空配列にフォールバック', () => {
		const { ACCOUNTS } = load();
		expect( ACCOUNTS ).toEqual( [] );
	} );
} );
