/**
 * E2E spec: プラットフォーム表示順
 *
 * 1. listing を displayOrder の逆順で登録した商品でも、CTA は displayOrder 順に並ぶ
 * 2. 設定画面の ↑ / ↓ で並べ替えて保存すると、公開済み記事のカードに反映される
 *
 * 2 は affilicard_platforms オプションを書き換えるため、他 spec に影響しないよう
 * 最後に元の並びへ戻す。
 */

'use strict';

const { test, expect } = require('@playwright/test');
const fs = require('fs');

const seed = JSON.parse(fs.readFileSync('artifacts/seed.json', 'utf8'));

const SETTINGS_URL =
	'/wp-admin/edit.php?post_type=affilicard_product&page=affilicard-settings';

/** 先頭カード内の CTA ボタンのラベルを上から順に返す。 */
async function ctaLabels(page) {
	return page
		.locator('.affilicard-card')
		.first()
		.locator('a.affilicard-card__cta')
		.allTextContents();
}

/** 設定 → プラットフォーム → 電子書籍タブを開く。 */
async function openEbookTab(page) {
	await page.goto(SETTINGS_URL);
	await page.getByRole('tab', { name: 'プラットフォーム' }).click();
	await page.getByRole('tab', { name: '電子書籍' }).click();
	await expect(
		page.getByText(/この順番で商品カードのボタンが上から並びます/)
	).toBeVisible({ timeout: 15_000 });
}

test('listing の登録順が逆でも CTA は displayOrder 順に並ぶ', async ({
	page,
}) => {
	await page.goto(`/?p=${seed.displayOrderPostId}`);
	await expect(page.locator('.affilicard-card').first()).toBeVisible();
	expect(await ctaLabels(page)).toEqual([
		'DMMブックスで読む',
		'楽天Koboで読む',
	]);
});

test('設定画面で並べ替えて保存すると公開記事のカードに反映される', async ({
	page,
}) => {
	// 電子書籍タブの既定は DMMブックス(1) / Amazon Kindle(2) / 楽天Kobo(3)。
	// この商品は DMM と楽天Kobo の listing しか持たないため、DMM を 1 回だけ下げても
	// 入れ替わる相手は Amazon で CTA の並びは変わらない。2 回下げて末尾へ動かす。
	await openEbookTab(page);
	const down = page.getByRole('button', { name: 'DMMブックスを下へ移動' });
	await down.click();
	await down.click();
	await page.getByRole('button', { name: '保存' }).click();
	await expect(
		page.locator('.components-notice__content').getByText('保存しました')
	).toBeVisible();

	await page.goto(`/?p=${seed.displayOrderPostId}`);
	expect(await ctaLabels(page)).toEqual([
		'楽天Koboで読む',
		'DMMブックスで読む',
	]);

	// 他 spec に影響しないよう元の並びへ戻す（2 回上げて先頭へ）
	await openEbookTab(page);
	const up = page.getByRole('button', { name: 'DMMブックスを上へ移動' });
	await up.click();
	await up.click();
	await page.getByRole('button', { name: '保存' }).click();
	await expect(
		page.locator('.components-notice__content').getByText('保存しました')
	).toBeVisible();

	await page.goto(`/?p=${seed.displayOrderPostId}`);
	expect(await ctaLabels(page)).toEqual([
		'DMMブックスで読む',
		'楽天Koboで読む',
	]);
});
