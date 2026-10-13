/**
 * Schema Tab Utilities
 *
 * Pure helper functions shared across schema-tab sub-modules.
 * Extracted from SchemaTab.js for maintainability.
 *
 * @package
 * @since 1.11.0
 */

import { __ } from '@wordpress/i18n';
import {
	getDefaultSettings as getSharedDefaultSettings,
	getValidationLevelOptions as getSharedValidationLevelOptions,
	getOrganizationTypeOptions as getSharedOrganizationTypeOptions,
	normalizeSettingsDataTypes as sharedNormalizeSettingsDataTypes,
	isValidUrl as sharedIsValidUrl,
} from '../../../config/schema-settings-config';

/**
 * Normalize settings data types (uses shared configuration)
 */
export const normalizeSettingsDataTypes = sharedNormalizeSettingsDataTypes;

/**
 * Get default settings structure (uses shared configuration)
 */
export const getDefaultSettings = () => getSharedDefaultSettings('site');

/**
 * Get validation level options (uses shared configuration)
 */
export const getValidationLevelOptions = getSharedValidationLevelOptions;

/**
 * Get organization type options
 */
export const getOrganizationTypeOptions = () => [
	{ label: __('Organization', 'thinkrank'), value: 'Organization' },
	{ label: __('Corporation', 'thinkrank'), value: 'Corporation' },
	{ label: __('Local Business', 'thinkrank'), value: 'LocalBusiness' },
	{ label: __('Non-Profit', 'thinkrank'), value: 'NGO' },
	{
		label: __('Educational Organization', 'thinkrank'),
		value: 'EducationalOrganization',
	},
	{
		label: __('Government Organization', 'thinkrank'),
		value: 'GovernmentOrganization',
	},
];

/**
 * Email validation helper
 * @param email
 */
export const isValidEmail = (email) => {
	const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
	return emailRegex.test(email);
};

/**
 * Re-export isValidUrl from shared config
 */
export const isValidUrl = sharedIsValidUrl;
