/**
 * E2E spec（Task 16 — claim 2）: `affilicard_extid_<platform>` ミラーが
 * 本当に複数値として振る舞うことを実 WP（実 MySQL）で確認する。
 *
 * `add_post_meta( ..., $unique = false )` の複数値保持、`delete_post_meta( ..., $value )`
 * のキー単位ではなく値単位の削除、`meta_query` の `=` 比較が複数行の中から
 * 目的の 1 行に一致すること——これらはすべて WP_Mock ではスタブでしか
 * 再現できず、unit test は「複数値として呼ばれた」ことしか確認できない。
 * ここでは実際に 2 件の購入リンクを持つ商品を作り、DB に本当に 2 行入るか、
 * 1 件消したときに巻き添えが起きないか、2 件目の external_id だけでも
 * 商品が見つかるかを直接確認する。
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const {
	createProductWithOffers,
	updateOffers,
	readMetaValues,
	findByExternalId,
} = require( './helpers/rest-offers' );

const PLATFORM = 'rakuten-kobo';
const MIRROR_META_KEY = `affilicard_extid_${ PLATFORM }`;

test.describe( 'affilicard_extid_<platform> の複数値ミラー', () => {
	test( '2 件の購入リンクの external_id が両方ミラーされる', async () => {
		const id = await createProductWithOffers(
			[
				{ display_order: 10, external_id: 'sale', regular_url: 'https://example.test/sale' },
				{ display_order: 100, external_id: 'normal', regular_url: 'https://example.test/normal' },
			],
			{ platform: PLATFORM, title: 'E2E ミラー複数値' }
		);

		const mirrored = readMetaValues( id, MIRROR_META_KEY );

		expect( mirrored.slice().sort() ).toEqual( [ 'normal', 'sale' ] );
	} );

	test( '購入リンクを 1 件消すと、その値だけがミラーから消える', async () => {
		const id = await createProductWithOffers(
			[
				{ display_order: 10, external_id: 'sale', regular_url: 'https://example.test/sale' },
				{ display_order: 100, external_id: 'normal', regular_url: 'https://example.test/normal' },
			],
			{ platform: PLATFORM, title: 'E2E ミラー部分削除' }
		);

		// sale を落として normal だけを残す（listings は丸ごと置き換え＝差分は syncExternalIdMirror が処理する）。
		await updateOffers(
			id,
			[ { display_order: 100, external_id: 'normal', regular_url: 'https://example.test/normal' } ],
			{ platform: PLATFORM }
		);

		expect( readMetaValues( id, MIRROR_META_KEY ) ).toEqual( [ 'normal' ] );
	} );

	test( '2 件目（後から追加した）の external_id でも商品が見つかる', async () => {
		const id = await createProductWithOffers(
			[
				{ display_order: 10, external_id: 'sale-findable', regular_url: 'https://example.test/sale-findable' },
				{ display_order: 100, external_id: 'normal-findable', regular_url: 'https://example.test/normal-findable' },
			],
			{ platform: PLATFORM, title: 'E2E ミラー検索性' }
		);

		// 1 件目（sale-findable）だけでなく、2 件目（normal-findable）だけでも
		// meta_query の `=` が同じ post を引けることを確認する。1 件目しか
		// ミラーされていない・meta_query が最初の行にしか一致しない、といった
		// 退行があれば null が返る。
		expect( findByExternalId( PLATFORM, 'sale-findable' ) ).toBe( id );
		expect( findByExternalId( PLATFORM, 'normal-findable' ) ).toBe( id );
		expect( findByExternalId( PLATFORM, 'does-not-exist' ) ).toBeNull();
	} );
} );
