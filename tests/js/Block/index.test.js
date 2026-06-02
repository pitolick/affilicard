/**
 * Tests for src/Block/index.js — block registration side effect.
 */
jest.mock(
	'@wordpress/blocks',
	() => ( {
		__esModule: true,
		registerBlockType: jest.fn(),
	} ),
	{ virtual: true }
);

import { registerBlockType } from '@wordpress/blocks';

describe('block registration', () => {
	test('registers affilicard/product-card with an edit and null save', () => {
		require('../../../src/Block/index.js');
		expect(registerBlockType).toHaveBeenCalledTimes(1);
		const [name, settings] = registerBlockType.mock.calls[0];
		const resolvedName = typeof name === 'string' ? name : name.name;
		expect(resolvedName).toBe('affilicard/product-card');
		expect(typeof settings.edit).toBe('function');
		expect(settings.save()).toBeNull();
	});
});
