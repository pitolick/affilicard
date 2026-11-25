/**
 * Tests for src/Admin/linkTemplate.js
 *
 * PHP 側（PlatformDefinition::applyLinkTemplate / isUsableTemplate）と同じ規則であること。
 */

import {
	isUsableLinkTemplate,
	canApplyLinkTemplate,
} from '../../../src/Admin/linkTemplate';

const hulu = {
	linkTemplate: 'https://h.example.test/?url={url_enc}',
	linkHosts: [ 'hulu.jp' ],
};

describe( 'isUsableLinkTemplate', () => {
	test.each( [
		[ 'https://h.example.test/?url={url_enc}', true ],
		[ '{url}{sep}tag=example-22', true ],
		[ 'https://h.example.test/?rk=1', false ],
		[ 'http://h.example.test/?url={url}', false ],
		[ '/go?url={url_enc}', false ],
		[ '', false ],
		[ undefined, false ],
	] )( '%s → %s', ( template, expected ) => {
		expect( isUsableLinkTemplate( template ) ).toBe( expected );
	} );
} );

describe( 'canApplyLinkTemplate', () => {
	test( '対象ドメインとそのサブドメインに当たる', () => {
		expect( canApplyLinkTemplate( hulu, 'https://www.hulu.jp/nny' ) ).toBe(
			true
		);
		expect( canApplyLinkTemplate( hulu, 'https://hulu.jp/nny' ) ).toBe(
			true
		);
	} );

	test( 'ドット境界でない末尾一致や別ドメインには当たらない', () => {
		expect( canApplyLinkTemplate( hulu, 'https://evilhulu.jp/x' ) ).toBe(
			false
		);
		expect( canApplyLinkTemplate( hulu, 'https://example.test/x' ) ).toBe(
			false
		);
	} );

	test( 'テンプレート・対象ドメイン・URL のどれかが欠けたら当てない', () => {
		expect(
			canApplyLinkTemplate(
				{ ...hulu, linkTemplate: '' },
				'https://www.hulu.jp/nny'
			)
		).toBe( false );
		expect(
			canApplyLinkTemplate(
				{ ...hulu, linkHosts: [] },
				'https://www.hulu.jp/nny'
			)
		).toBe( false );
		expect( canApplyLinkTemplate( hulu, '' ) ).toBe( false );
		expect( canApplyLinkTemplate( hulu, 'not a url' ) ).toBe( false );
		expect( canApplyLinkTemplate( undefined, 'https://www.hulu.jp/nny' ) ).toBe(
			false
		);
	} );
} );
