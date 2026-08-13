/**
 * E2E spec: affilicard/product-card ブロック描画テスト
 *
 * global-setup が seed.php 経由で作成したブロック投稿のフロントエンドを検証する。
 * wp-cli / ブロックエディタ UI は一切使用しない。
 */

'use strict';

const { test, expect } = require('@playwright/test');
const fs = require('fs');

const seed = JSON.parse(fs.readFileSync('artifacts/seed.json', 'utf8'));

test('在庫ありの商品ブロックは CTA と色が描画される', async ({ page }) => {
	await page.goto(`/?p=${seed.availablePostId}`);
	const card = page.locator('.affilicard-card').first();
	await expect(card).toBeVisible();
	const cta = page.locator('a.affilicard-card__cta').first();
	await expect(cta).toHaveAttribute('href', 'https://example.com/aff-a');
	await expect(cta).toHaveAttribute('rel', 'nofollow sponsored noopener');

	// 計測用 data-affilicard-* 属性（CTA）。platform / product-id は seed.php で
	// 確定できる値なので正規表現ではなく具体値でアサートする。
	await expect(cta).toHaveAttribute('data-affilicard-platform', 'dmm-books');
	await expect(cta).toHaveAttribute(
		'data-affilicard-product-id',
		String(seed.availableProductId)
	);

	// 計測用 data-affilicard-* 属性（カードのルート要素）。CTA と同じ商品 ID を持つ。
	await expect(card).toHaveAttribute(
		'data-affilicard-product-id',
		String(seed.availableProductId)
	);
	// ルート要素には platform を出さない（1 カードに複数ストアが並び得るため単一値にならない）。
	// .not.toHaveAttribute() は locator が要素を見つけられない場合にも成立してしまうため
	// （直前の toBeVisible() で存在は確認済みだが）、実際に getAttribute して
	// 属性が存在しないこと（null）を直接検証する。
	expect(await card.getAttribute('data-affilicard-platform')).toBeNull();

	await expect(card).toHaveAttribute('style', /--affilicard-cta-bg:#123456/);

	// 新カードデザイン構造の検証
	await expect(page.locator('.affilicard-card__inner').first()).toBeVisible();
	await expect(page.locator('.affilicard-card__meta').first()).toBeVisible();
	await expect(page.locator('li.affilicard-card__row').first()).toBeVisible();
	await expect(page.locator('.affilicard-card__tax').first()).toContainText(
		'税込'
	);
	await expect(
		page.locator('.affilicard-card__discount').first()
	).toContainText('40%OFF');
	await expect(
		page.locator('.affilicard-card__timestamp').first()
	).toContainText('時点の価格');

	// 専門型（ebook）では ISBN はカード非表示
	await expect(page.locator('.affilicard-card').first()).not.toContainText(
		'978-4-00-000000-0'
	);
});

test('在庫切れの商品ブロックは CTA 非表示でバッジが出る', async ({ page }) => {
	await page.goto(`/?p=${seed.outOfStockPostId}`);
	await expect(
		page.locator('.affilicard-card__badge--out_of_stock').first()
	).toBeVisible();
	await expect(page.locator('a.affilicard-card__cta')).toHaveCount(0);
});
