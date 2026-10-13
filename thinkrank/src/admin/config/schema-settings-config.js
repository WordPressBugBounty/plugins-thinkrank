/**
 * Schema Settings Configuration
 *
 * Shared configuration for schema settings between frontend components.
 * This eliminates duplication and ensures consistency across the application.
 *
 * @package
 * @subpackage Config
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';

/**
 * Get default schema settings
 *
 * @param {string} contextType Context type ('site', 'post', 'page', 'product')
 * @return {Object} Default settings object
 */
export const getDefaultSettings = (contextType = 'site') => {
	const defaults = {
		// Core settings
		// Master switch. Must stay in sync with the PHP default in
		// includes/config/schema-settings-config.php — it is listed in
		// getBooleanSettings(), so a missing key here is saved back as `false`
		// and locks every schema form into a read-only state.
		enabled: true,
		auto_generate_schema: true,
		enabled_schema_types: [],
		validation_level: 'moderate',
		rich_snippets_optimization: true,
		cache_duration: 3600, // 1 hour
		auto_deploy: true,

		// Basic schema settings (moved from Site Identity)
		organization_schema: true,
		knowledge_graph: true,

		// Organization schema settings
		organization_name: '',
		organization_type: 'Organization',
		organization_logo: '',
		organization_url: '',
		organization_description: '',
		organization_social_facebook: '',
		organization_social_twitter: '',
		organization_social_linkedin: '',
		organization_social_instagram: '',
		organization_social_youtube: '',
		organization_contact_type: 'customer service',
		organization_contact_phone: '',
		organization_contact_email: '',
		organization_contact_hours: '',

		// Website schema settings
		website_name: '',
		website_url: '',
		website_description: '',
		website_author: '',

		// LocalBusiness schema settings (now managed in Business Info tab)
		// business_price_range: moved to Site Identity Business Info
		// business_geo_latitude: moved to Site Identity Business Info
		// business_geo_longitude: moved to Site Identity Business Info
		// business_opening_hours: moved to Site Identity Business Info

		// Content schema settings (site-wide only)
		enable_breadcrumbs_schema: true,
		enable_accordion_faq_schema: false,
		enable_local_business: false,

		// Removed post/page-specific schema settings (Product, Event, Article, Software Application, HowTo, FAQ)
		// These are now handled only at the post/page level via metabox

		// Person schema settings (site-wide)
		person_name: '',
		person_job_title: '',
		person_description: '',
		person_image: '',
		person_url: '',
		person_email: '',
		person_telephone: '',
		person_address: '',
		person_birth_date: '',
		person_nationality: '',
		person_works_for: '',
		person_same_as: [],

		// Removed FAQPage schema settings (now handled at post/page level)
	};

	// Context-specific defaults
	switch (contextType) {
		case 'site':
			defaults.enabled_schema_types = ['Organization', 'LocalBusiness'];
			defaults.auto_deploy = true;
			break;
		case 'post':
			defaults.enabled_schema_types = ['Article', 'Person'];
			defaults.validation_level = 'strict';
			break;
		case 'page':
			defaults.enabled_schema_types = ['Article', 'FAQ'];
			defaults.rich_snippets_optimization = true;
			break;
		case 'product':
			defaults.enabled_schema_types = ['Product'];
			defaults.validation_level = 'strict';
			defaults.rich_snippets_optimization = true;
			break;
	}

	return defaults;
};

/**
 * Get supported schema types for context
 *
 * @param {string} contextType Context type
 * @return {Array} Supported schema types
 */
export const getSupportedSchemaTypes = (contextType = 'site') => {
	// Site-level schema types only (post/page schemas handled by metabox)
	const siteTypes = ['Organization', 'LocalBusiness', 'Person', 'WebSite'];

	// All schema types for metabox context
	const allTypes = [
		'Article',
		'BlogPosting',
		'TechnicalArticle',
		'NewsArticle',
		'ScholarlyArticle',
		'Report',
		'Product',
		'Organization',
		'LocalBusiness',
		'Person',
		'WebSite',
		'FAQPage',
		'Event',
		'HowTo',
		'Review',
		'VideoObject',
	];

	// Return appropriate types based on context
	return contextType === 'metabox' ? allTypes : siteTypes;
};

/**
 * Get validation level options
 *
 * @return {Array} Validation level options
 */
export const getValidationLevelOptions = () => [
	{ value: 'strict', label: __('Strict', 'thinkrank') },
	{ value: 'moderate', label: __('Moderate', 'thinkrank') },
	{ value: 'lenient', label: __('Lenient', 'thinkrank') },
];

/**
 * Get boolean settings list
 *
 * @return {Array} List of boolean setting keys
 */
export const getBooleanSettings = () => [
	'enabled',
	'auto_generate_schema',
	'rich_snippets_optimization',
	'auto_deploy',
	'organization_schema',
	'knowledge_graph',
	'enable_breadcrumbs_schema',
	'enable_accordion_faq_schema',
	'enable_article_schema',
	'enable_product_schema',
	'enable_local_business',
	'enable_faq_schema',
	'enable_howto_schema',
];

/**
 * Get array settings list
 *
 * @return {Array} List of array setting keys
 */
export const getArraySettings = () => [
	'enabled_schema_types',
	'software_operating_systems',
	'software_features',
	'howto_supply',
];

/**
 * Get integer settings list
 *
 * @return {Array} List of integer setting keys
 */
export const getIntegerSettings = () => ['cache_duration'];

/**
 * Get organization type options
 *
 * @return {Array} Organization type options
 */
export const getOrganizationTypeOptions = () => [
	{ value: 'Organization', label: __('Organization', 'thinkrank') },
	{ value: 'Corporation', label: __('Corporation', 'thinkrank') },
	{
		value: 'EducationalOrganization',
		label: __('Educational Organization', 'thinkrank'),
	},
	{
		value: 'GovernmentOrganization',
		label: __('Government Organization', 'thinkrank'),
	},
	{ value: 'LocalBusiness', label: __('Local Business', 'thinkrank') },
	{ value: 'NGO', label: __('Non-Governmental Organization', 'thinkrank') },
	{ value: 'PerformingGroup', label: __('Performing Group', 'thinkrank') },
	{
		value: 'SportsOrganization',
		label: __('Sports Organization', 'thinkrank'),
	},
];

/**
 * Get contact type options
 *
 * @return {Array} Contact type options
 */
export const getContactTypeOptions = () => [
	{ value: 'customer service', label: __('Customer Service', 'thinkrank') },
	{ value: 'technical support', label: __('Technical Support', 'thinkrank') },
	{ value: 'billing support', label: __('Billing Support', 'thinkrank') },
	{ value: 'bill payment', label: __('Bill Payment', 'thinkrank') },
	{ value: 'sales', label: __('Sales', 'thinkrank') },
	{ value: 'reservations', label: __('Reservations', 'thinkrank') },
	{
		value: 'credit card support',
		label: __('Credit Card Support', 'thinkrank'),
	},
	{ value: 'emergency', label: __('Emergency', 'thinkrank') },
	{ value: 'baggage tracking', label: __('Baggage Tracking', 'thinkrank') },
	{
		value: 'roadside assistance',
		label: __('Roadside Assistance', 'thinkrank'),
	},
	{ value: 'package tracking', label: __('Package Tracking', 'thinkrank') },
];

/**
 * Normalize settings data types
 *
 * @param {Object} settings Settings object
 * @return {Object} Normalized settings
 */
export const normalizeSettingsDataTypes = (settings) => {
	const normalized = { ...settings };

	// Convert boolean settings
	getBooleanSettings().forEach((key) => {
		if (key in normalized) {
			normalized[key] = Boolean(normalized[key]);
		}
	});

	// Convert array settings
	getArraySettings().forEach((key) => {
		if (key in normalized && !Array.isArray(normalized[key])) {
			normalized[key] = [];
		}
	});

	// Convert integer settings
	getIntegerSettings().forEach((key) => {
		if (key in normalized) {
			normalized[key] = parseInt(normalized[key], 10) || 0;
		}
	});

	return normalized;
};

/**
 * Validate URL format
 *
 * @param {string} url URL to validate
 * @return {boolean} Whether URL is valid
 */
export const isValidUrl = (url) => {
	try {
		new URL(url);
		return true;
	} catch {
		return false;
	}
};
