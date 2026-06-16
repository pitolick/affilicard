import { PROVIDER_OPTIONS, CRED_SCHEMAS } from '../../../src/Admin/providers';

describe( 'providers shared constants', () => {
	test( 'PROVIDER_OPTIONS lists manual and dmm-ebook', () => {
		expect( PROVIDER_OPTIONS.map( ( o ) => o.value ) ).toEqual( [
			'manual',
			'dmm-ebook',
		] );
	} );

	test( 'CRED_SCHEMAS: manual empty, dmm-ebook has api_id + affiliate_id', () => {
		expect( CRED_SCHEMAS.manual ).toEqual( [] );
		expect( CRED_SCHEMAS[ 'dmm-ebook' ] ).toHaveLength( 2 );
		expect( CRED_SCHEMAS[ 'dmm-ebook' ][ 0 ].key ).toBe( 'api_id' );
		expect( CRED_SCHEMAS[ 'dmm-ebook' ][ 1 ].key ).toBe( 'affiliate_id' );
	} );
} );
