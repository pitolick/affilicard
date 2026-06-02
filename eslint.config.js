/**
 * ESLint flat config for affilicard.
 *
 * Extends @wordpress/scripts' default config and turns off
 * import/no-unresolved for @wordpress/* packages, because
 * @wordpress/scripts (webpack) externalizes all @wordpress/* imports at
 * build time — they are provided by WordPress at runtime and therefore do
 * NOT need to be installed as local npm packages.
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		rules: {
			// @wordpress/* packages are externalized by wp-scripts webpack and
			// provided by WordPress at runtime. They are not installed locally,
			// so the resolver can't find them. Suppress the false-positive error.
			'import/no-unresolved': [
				'error',
				{ ignore: [ '^@wordpress/' ] },
			],
		},
	},
];
