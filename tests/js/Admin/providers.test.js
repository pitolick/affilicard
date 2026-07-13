import { PROVIDER_OPTIONS, CRED_SCHEMAS } from '../../../src/Admin/providers';

describe( 'providers shared constants', () => {
	test( 'PROVIDER_OPTIONS lists manual, dmm-ebook and rakuten-kobo', () => {
		expect( PROVIDER_OPTIONS.map( ( o ) => o.value ) ).toEqual( [
			'manual',
			'dmm-ebook',
			'rakuten-kobo',
		] );
	} );

	test( 'CRED_SCHEMAS: manual empty, dmm-ebook has api_id + affiliate_id', () => {
		expect( CRED_SCHEMAS.manual ).toEqual( [] );
		expect( CRED_SCHEMAS[ 'dmm-ebook' ] ).toHaveLength( 2 );
		expect( CRED_SCHEMAS[ 'dmm-ebook' ][ 0 ].key ).toBe( 'api_id' );
		expect( CRED_SCHEMAS[ 'dmm-ebook' ][ 1 ].key ).toBe( 'affiliate_id' );
	} );

	test( 'CRED_SCHEMAS: rakuten-kobo has application_id, access_key, affiliate_id, allowed_domain', () => {
		expect( CRED_SCHEMAS[ 'rakuten-kobo' ] ).toHaveLength( 4 );
		expect( CRED_SCHEMAS[ 'rakuten-kobo' ].map( ( f ) => f.key ) ).toEqual( [
			'application_id',
			'access_key',
			'affiliate_id',
			'allowed_domain',
		] );
		expect( CRED_SCHEMAS[ 'rakuten-kobo' ][ 3 ].required ).toBe( false );
	} );
} );
