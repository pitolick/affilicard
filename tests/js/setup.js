import '@testing-library/jest-dom';
import { act } from '@testing-library/react';
import productTypes from '../fixtures/product-types.json';

/**
 * 商品タイプは本番では PHP が window.affilicardProductTypes に入れる
 * （Affilicard\Types\ProductTypeUiList）。テストでも同じ形を入れておく。
 * fixture は PHP のテスト（ProductTypeUiListTest）が実際の出力と突き合わせている。
 */
beforeEach( () => {
	window.affilicardProductTypes = productTypes;
} );

/**
 * After each test, flush any pending promise-based state updates inside act()
 * so that @wordpress/jest-console's afterEach spy assertion does not see
 * spurious "not wrapped in act()" warnings from in-flight microtasks.
 */
afterEach( async () => {
	await act( async () => {} );
} );
