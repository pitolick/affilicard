/**
 * Jest configuration for the affilicard plugin's JS test suite.
 *
 * Extends @wordpress/scripts' unit test config so the babel transform
 * (with @wordpress/babel-preset-default) is applied, then adds our
 * setup file and test path overrides.
 */
const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	setupFilesAfterEnv: [
		...( defaultConfig.setupFilesAfterEnv || [] ),
		require.resolve(
			'@wordpress/jest-preset-default/scripts/setup-test-framework.js'
		),
		'<rootDir>/tests/js/setup.js',
	],
	moduleNameMapper: {
		...( defaultConfig.moduleNameMapper || {} ),
		'^@wordpress/components$':
			'<rootDir>/tests/js/__mocks__/wordpress-components.js',
	},
	testMatch: [
		'<rootDir>/tests/js/**/*.test.js',
		'<rootDir>/tests/js/**/*.test.jsx',
	],
};
