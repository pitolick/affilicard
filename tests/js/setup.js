import '@testing-library/jest-dom';
import { act } from '@testing-library/react';

/**
 * After each test, flush any pending promise-based state updates inside act()
 * so that @wordpress/jest-console's afterEach spy assertion does not see
 * spurious "not wrapped in act()" warnings from in-flight microtasks.
 */
afterEach( async () => {
	await act( async () => {} );
} );
