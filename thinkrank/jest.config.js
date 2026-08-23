/**
 * Jest configuration.
 *
 * Extends @wordpress/scripts' default so component tests can resolve the same
 * path aliases webpack.config.js defines — without this, importing anything
 * that reaches `@utils/…` fails to resolve under Jest while building fine.
 */
const defaultConfig = require('@wordpress/scripts/config/jest-unit.config');

module.exports = {
	...defaultConfig,
	moduleNameMapper: {
		...(defaultConfig.moduleNameMapper || {}),
		'^@admin/(.*)$': '<rootDir>/src/admin/$1',
		'^@components/(.*)$': '<rootDir>/src/admin/components/$1',
		'^@utils/(.*)$': '<rootDir>/src/admin/utils/$1',
		'^@/(.*)$': '<rootDir>/src/$1',
	},
};
