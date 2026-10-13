/**
 * Schema Form Validation Utilities
 *
 * Provides validation functions for schema form data before schema generation.
 * This is separate from schema markup validation which happens after generation.
 *
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';

/**
 * Validate section data against schema standards
 * @param section
 * @param data
 */
export const validateSectionData = async (section, data) => {
	const validation = {
		section,
		isValid: true,
		hasErrors: false,
		hasWarnings: false,
		errors: [],
		warnings: [],
		suggestions: [],
		fieldValidation: {},
		validationSummary: {
			validatedFields: 0,
			totalFields: 0,
			completionPercentage: 0,
		},
	};

	switch (section) {
		case 'organization':
			validateOrganizationSchema(data, validation);
			break;
		case 'website':
			validateWebsiteSchema(data, validation);
			break;
		case 'person':
			validatePersonSchema(data, validation);
			break;
		case 'local-business':
			// LocalBusiness validation is now handled by source tabs (Business Info, Organization)
			// This tab is purely informational and doesn't need form validation
			validation.suggestions.push(
				__(
					'LocalBusiness schema data is validated in the source tabs: Business Info and Organization.',
					'thinkrank'
				)
			);
			validation.validationSummary.validatedFields = 0;
			validation.validationSummary.totalFields = 0;
			validation.validationSummary.completionPercentage = 0;
			break;
		case 'schema-types':
			validateSchemaTypesConfiguration(data, validation);
			break;
	}

	// Set overall validation status
	validation.isValid = !validation.hasErrors;

	// Calculate completion percentage
	if (validation.validationSummary.totalFields > 0) {
		validation.validationSummary.completionPercentage = Math.round(
			(validation.validationSummary.validatedFields /
				validation.validationSummary.totalFields) *
				100
		);
	}

	return validation;
};

/**
 * Validate organization schema section
 * @param data
 * @param validation
 */
const validateOrganizationSchema = (data, validation) => {
	let validatedFields = 0;
	const totalFields = 12; // organization_name, organization_url, organization_description, organization_logo, organization_type, 5 social fields, 3 contact fields (removed organization_email and organization_address)

	// Required fields for organization schema
	if (!data.organization_name?.trim()) {
		validation.errors.push(
			__(
				'Organization name is required for organization schema.',
				'thinkrank'
			)
		);
		validation.hasErrors = true;
		validation.fieldValidation.organization_name = 'error';
	} else {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Organization name is properly configured.', 'thinkrank')
		);
	}

	if (!data.organization_url?.trim()) {
		validation.errors.push(
			__(
				'Organization URL is required for organization schema.',
				'thinkrank'
			)
		);
		validation.hasErrors = true;
		validation.fieldValidation.organization_url = 'error';
	} else if (!isValidUrl(data.organization_url)) {
		validation.errors.push(
			__('Organization URL must be a valid URL format.', 'thinkrank')
		);
		validation.hasErrors = true;
		validation.fieldValidation.organization_url = 'error';
	} else {
		validatedFields++;
		validation.suggestions.push(
			__(
				'✓ Organization URL is valid and properly formatted.',
				'thinkrank'
			)
		);
	}

	if (!data.organization_description?.trim()) {
		validation.warnings.push(
			__(
				'Organization description is recommended for better SEO.',
				'thinkrank'
			)
		);
		validation.hasWarnings = true;
		validation.fieldValidation.organization_description = 'warning';
	} else {
		validatedFields++;
		validation.suggestions.push(
			__(
				'✓ Organization description is provided for better SEO.',
				'thinkrank'
			)
		);
	}

	if (!data.organization_logo?.trim()) {
		validation.warnings.push(
			__(
				'Organization logo is recommended for rich snippets.',
				'thinkrank'
			)
		);
		validation.hasWarnings = true;
		validation.fieldValidation.organization_logo = 'warning';
	} else if (!isValidUrl(data.organization_logo)) {
		validation.errors.push(
			__('Organization logo must be a valid URL format.', 'thinkrank')
		);
		validation.hasErrors = true;
		validation.fieldValidation.organization_logo = 'error';
	} else {
		validatedFields++;
		validation.suggestions.push(
			__(
				'✓ Organization logo is configured for rich snippets.',
				'thinkrank'
			)
		);
	}

	// Organization email is now managed in Business Info tab
	validation.suggestions.push(
		__(
			'ℹ Business contact information (email, phone, address) is now managed in Site Identity > Business Info tab for consistency across all local SEO features.',
			'thinkrank'
		)
	);

	if (!data.organization_type?.trim()) {
		validation.warnings.push(
			__(
				'Organization type helps search engines understand your business.',
				'thinkrank'
			)
		);
		validation.hasWarnings = true;
		validation.fieldValidation.organization_type = 'warning';
	} else {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Organization type is properly configured.', 'thinkrank')
		);
	}

	// Check for sameAs (social media profiles) - this is what's missing in deployment
	const socialFields = [
		{ key: 'organization_social_facebook', name: 'Facebook' },
		{ key: 'organization_social_twitter', name: 'Twitter' },
		{ key: 'organization_social_linkedin', name: 'LinkedIn' },
		{ key: 'organization_social_instagram', name: 'Instagram' },
		{ key: 'organization_social_youtube', name: 'YouTube' },
	];

	let hasSocialProfiles = false;
	socialFields.forEach((field) => {
		if (data[field.key]?.trim()) {
			if (!isValidUrl(data[field.key])) {
				validation.errors.push(
					__(
						`${field.name} URL must be a valid URL format.`,
						'thinkrank'
					)
				);
				validation.hasErrors = true;
				validation.fieldValidation[field.key] = 'error';
			} else {
				validatedFields++;
				hasSocialProfiles = true;
				validation.suggestions.push(
					__(
						`✓ ${field.name} social profile is configured.`,
						'thinkrank'
					)
				);
			}
		}
	});

	if (!hasSocialProfiles) {
		validation.suggestions.push(
			__(
				'ℹ Social media profiles are managed in Site Identity > Social Media for consistency across all schema types.',
				'thinkrank'
			)
		);
	}

	// Contact information is now managed in Business Info tab
	// Note: Contact data comes from Site Identity > Business Info, not organization form fields
	validation.suggestions.push(
		__(
			'ℹ Contact information (phone, email, type) is managed in Site Identity > Business Info for consistency across all local SEO features.',
			'thinkrank'
		)
	);

	validation.validationSummary.validatedFields = validatedFields;
	validation.validationSummary.totalFields = totalFields;
};

/**
 * Validate website schema section
 * @param data
 * @param validation
 */
const validateWebsiteSchema = (data, validation) => {
	let validatedFields = 0;
	const totalFields = 4; // website_name, website_url, website_description, search config (logo managed elsewhere)

	// Website name validation (optional with fallback)
	if (!data.website_name?.trim()) {
		validation.suggestions.push(
			__('✓ Website name will use site title as fallback.', 'thinkrank')
		);
		validatedFields++; // This is acceptable as we have a fallback
	} else {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Website name is properly configured.', 'thinkrank')
		);
	}

	// Website URL validation (optional with fallback)
	if (!data.website_url?.trim()) {
		validation.suggestions.push(
			__('✓ Website URL will use home URL as fallback.', 'thinkrank')
		);
		validatedFields++; // This is acceptable as we have a fallback
	} else if (!isValidUrl(data.website_url)) {
		validation.errors.push(
			__(
				'Website URL format is invalid. Please enter a valid URL.',
				'thinkrank'
			)
		);
		validation.hasErrors = true;
		validation.fieldValidation.website_url = 'error';
	} else {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Website URL is valid and properly formatted.', 'thinkrank')
		);
	}

	// Website description validation (optional with fallback)
	if (!data.website_description?.trim()) {
		validation.suggestions.push(
			__(
				'✓ Website description will use site tagline as fallback.',
				'thinkrank'
			)
		);
		validatedFields++; // This is acceptable as we have a fallback
	} else {
		if (data.website_description.length > 160) {
			validation.warnings.push(
				__(
					'Website description is longer than 160 characters and may be truncated.',
					'thinkrank'
				)
			);
			validation.hasWarnings = true;
			validation.fieldValidation.website_description = 'warning';
		}
		validatedFields++;
		validation.suggestions.push(
			__('✓ Website description is provided for better SEO.', 'thinkrank')
		);
	}

	// Search configuration validation
	if (data.website_enable_search !== false) {
		if (data.website_search_url && data.website_search_url.trim()) {
			if (!data.website_search_url.includes('{search_term_string}')) {
				validation.warnings.push(
					__(
						'Custom search URL should include {search_term_string} placeholder.',
						'thinkrank'
					)
				);
				validation.hasWarnings = true;
				validation.fieldValidation.website_search_url = 'warning';
			} else {
				validation.suggestions.push(
					__(
						'✓ Custom search URL is properly configured.',
						'thinkrank'
					)
				);
			}
		} else {
			validation.suggestions.push(
				__(
					'✓ Search functionality will use default WordPress search.',
					'thinkrank'
				)
			);
		}
		validatedFields++;
	} else {
		validation.warnings.push(
			__(
				'Sitelinks search box is disabled. Consider enabling for better user experience.',
				'thinkrank'
			)
		);
		validation.hasWarnings = true;
		validation.fieldValidation.website_enable_search = 'warning';
	}

	// Logo is managed in Site Identity tab - not validated here

	validation.validationSummary.validatedFields = validatedFields;
	validation.validationSummary.totalFields = totalFields;
};

/**
 * Validate person schema section
 * @param data
 * @param validation
 */
const validatePersonSchema = (data, validation) => {
	let validatedFields = 0;
	const totalFields = 11; // person_name, person_image, person_job_title, person_nationality, person_biography, person_url, person_email, person_telephone, person_birth_date, person_address, person_works_for

	// Required fields for person schema
	if (!data.person_name?.trim()) {
		validation.errors.push(
			__('Person name is required for person schema.', 'thinkrank')
		);
		validation.hasErrors = true;
		validation.fieldValidation.person_name = 'error';
	} else {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Person name is properly configured.', 'thinkrank')
		);
	}

	// Profile image validation (recommended)
	if (!data.person_image?.trim()) {
		validation.warnings.push(
			__(
				'Profile image is recommended for better person schema visibility.',
				'thinkrank'
			)
		);
		validation.hasWarnings = true;
		validation.fieldValidation.person_image = 'warning';
	} else {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Profile image is configured.', 'thinkrank')
		);
	}

	// Job title validation (recommended)
	if (!data.person_job_title?.trim()) {
		validation.warnings.push(
			__(
				'Job title is recommended for professional person schema.',
				'thinkrank'
			)
		);
		validation.hasWarnings = true;
		validation.fieldValidation.person_job_title = 'warning';
	} else {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Job title is properly configured.', 'thinkrank')
		);
	}

	// Nationality validation (optional)
	if (data.person_nationality?.trim()) {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Nationality information is included.', 'thinkrank')
		);
	}

	// Biography validation (recommended)
	if (!data.person_biography?.trim()) {
		validation.warnings.push(
			__(
				'Biography is recommended for comprehensive person schema.',
				'thinkrank'
			)
		);
		validation.hasWarnings = true;
		validation.fieldValidation.person_biography = 'warning';
	} else if (data.person_biography.trim().length < 50) {
		validation.warnings.push(
			__(
				'Biography is quite short. Consider adding more details for better SEO.',
				'thinkrank'
			)
		);
		validation.hasWarnings = true;
		validation.fieldValidation.person_biography = 'warning';
		validatedFields++;
	} else {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Biography is comprehensive and well-detailed.', 'thinkrank')
		);
	}

	// Website URL validation (optional)
	if (data.person_url?.trim()) {
		if (!isValidUrl(data.person_url)) {
			validation.errors.push(
				__(
					'Person website URL must be a valid URL format.',
					'thinkrank'
				)
			);
			validation.hasErrors = true;
			validation.fieldValidation.person_url = 'error';
		} else {
			validatedFields++;
			validation.suggestions.push(
				__(
					'✓ Person website URL is valid and properly formatted.',
					'thinkrank'
				)
			);
		}
	}

	// Email validation (optional)
	if (data.person_email?.trim()) {
		if (!isValidEmail(data.person_email)) {
			validation.errors.push(
				__('Person email must be a valid email format.', 'thinkrank')
			);
			validation.hasErrors = true;
			validation.fieldValidation.person_email = 'error';
		} else {
			validatedFields++;
			validation.suggestions.push(
				__(
					'✓ Person email is valid and properly formatted.',
					'thinkrank'
				)
			);
		}
	}

	// Phone validation (optional)
	if (data.person_telephone?.trim()) {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Phone number is included.', 'thinkrank')
		);
	}

	// Birth date validation (optional)
	if (data.person_birth_date?.trim()) {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Birth date information is included.', 'thinkrank')
		);
	}

	// Address validation (optional)
	if (data.person_address?.trim()) {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Address information is included.', 'thinkrank')
		);
	}

	// Works for validation (optional)
	if (data.person_works_for?.trim()) {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Organization affiliation is included.', 'thinkrank')
		);
	}

	validation.validationSummary.validatedFields = validatedFields;
	validation.validationSummary.totalFields = totalFields;
};

/**
 * Validate schema configuration settings
 * @param data
 * @param validation
 */
const validateSchemaConfigurationSettings = (data, validation) => {
	let validatedFields = 0;
	const totalFields = 6; // Schema configuration settings

	// Check if schema types are enabled
	const enabledSchemaTypes = data.enabled_schema_types || [];

	if (enabledSchemaTypes.length === 0) {
		validation.warnings.push(
			__(
				'No schema types are enabled. Enable at least one schema type for better SEO.',
				'thinkrank'
			)
		);
		validation.hasWarnings = true;
		validation.fieldValidation.enabled_schema_types = 'warning';
	} else {
		validatedFields++;
		validation.suggestions.push(
			__(
				`✓ ${enabledSchemaTypes.length} schema type(s) enabled: ${enabledSchemaTypes.join(', ')}.`,
				'thinkrank'
			)
		);
	}

	// Check validation level
	if (!data.validation_level?.trim()) {
		validation.warnings.push(
			__(
				'Validation level should be set for proper schema validation.',
				'thinkrank'
			)
		);
		validation.hasWarnings = true;
		validation.fieldValidation.validation_level = 'warning';
	} else {
		validatedFields++;
		validation.suggestions.push(
			__(
				`✓ Validation level set to ${data.validation_level}.`,
				'thinkrank'
			)
		);
	}

	// Check if auto-generation is enabled
	if (data.auto_generate_schema === false) {
		validation.warnings.push(
			__(
				'Auto-generate schema is disabled. Enable for automatic schema generation.',
				'thinkrank'
			)
		);
		validation.hasWarnings = true;
		validation.fieldValidation.auto_generate_schema = 'warning';
	} else {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Auto-generate schema is enabled.', 'thinkrank')
		);
	}

	// Check if rich snippets optimization is enabled
	if (data.rich_snippets_optimization === false) {
		validation.warnings.push(
			__(
				'Rich snippets optimization is disabled. Enable for better search results.',
				'thinkrank'
			)
		);
		validation.hasWarnings = true;
		validation.fieldValidation.rich_snippets_optimization = 'warning';
	} else {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Rich snippets optimization is enabled.', 'thinkrank')
		);
	}

	// Check cache duration
	if (!data.cache_duration || data.cache_duration < 300) {
		validation.warnings.push(
			__(
				'Cache duration should be at least 300 seconds for optimal performance.',
				'thinkrank'
			)
		);
		validation.hasWarnings = true;
		validation.fieldValidation.cache_duration = 'warning';
	} else {
		validatedFields++;
		validation.suggestions.push(
			__(
				`✓ Cache duration set to ${data.cache_duration} seconds.`,
				'thinkrank'
			)
		);
	}

	// Check if knowledge graph is enabled
	if (data.knowledge_graph === false) {
		validation.warnings.push(
			__(
				'Knowledge graph is disabled. Enable for better search engine understanding.',
				'thinkrank'
			)
		);
		validation.hasWarnings = true;
		validation.fieldValidation.knowledge_graph = 'warning';
	} else {
		validatedFields++;
		validation.suggestions.push(
			__('✓ Knowledge graph is enabled.', 'thinkrank')
		);
	}

	validation.validationSummary.validatedFields = validatedFields;
	validation.validationSummary.totalFields = totalFields;
};

/**
 * Validation helper functions
 * @param url
 */
const isValidUrl = (url) => {
	try {
		new URL(url);
		return true;
	} catch {
		return false;
	}
};

const isValidEmail = (email) => {
	const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
	return emailRegex.test(email);
};

const isValidLatitude = (lat) => {
	const num = parseFloat(lat);
	return !isNaN(num) && num >= -90 && num <= 90;
};

const isValidLongitude = (lng) => {
	const num = parseFloat(lng);
	return !isNaN(num) && num >= -180 && num <= 180;
};

/**
 * Validate schema types configuration section
 * @param data
 * @param validation
 */
const validateSchemaTypesConfiguration = (data, validation) => {
	let validatedFields = 0;
	let totalFields = 0;

	// Get enabled schema types
	const enabledTypes = data.enabled_schema_types || [];

	// Validate Product schema configuration
	if (enabledTypes.includes('Product')) {
		totalFields += 7; // product_name, product_price, product_currency, product_brand, product_sku, product_availability, product_description

		if (!data.product_name?.trim()) {
			validation.warnings.push(
				__(
					'Product name is recommended for Product schema.',
					'thinkrank'
				)
			);
			validation.hasWarnings = true;
			validation.fieldValidation.product_name = 'warning';
		} else {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Product name is configured.', 'thinkrank')
			);
		}

		if (!data.product_description?.trim()) {
			validation.warnings.push(
				__(
					'Product description is recommended for Product schema.',
					'thinkrank'
				)
			);
			validation.hasWarnings = true;
			validation.fieldValidation.product_description = 'warning';
		} else {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Product description is configured.', 'thinkrank')
			);
		}

		if (data.product_price?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Product price is configured.', 'thinkrank')
			);
		}

		if (data.product_brand?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Product brand is configured.', 'thinkrank')
			);
		}

		if (data.product_sku?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Product SKU is configured.', 'thinkrank')
			);
		}

		if (data.product_currency?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Product currency is configured.', 'thinkrank')
			);
		}

		if (data.product_availability?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Product availability is configured.', 'thinkrank')
			);
		}
	}

	// Validate Event schema configuration
	if (enabledTypes.includes('Event')) {
		totalFields += 12; // event_name, event_type, event_start_date, event_end_date, event_status, event_location, event_attendance_mode, event_organizer, event_performer, event_image, event_price, event_description

		if (!data.event_name?.trim()) {
			validation.warnings.push(
				__('Event name is recommended for Event schema.', 'thinkrank')
			);
			validation.hasWarnings = true;
			validation.fieldValidation.event_name = 'warning';
		} else {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Event name is configured.', 'thinkrank')
			);
		}

		if (!data.event_start_date?.trim()) {
			validation.warnings.push(
				__(
					'Event start date is recommended for Event schema.',
					'thinkrank'
				)
			);
			validation.hasWarnings = true;
			validation.fieldValidation.event_start_date = 'warning';
		} else {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Event start date is configured.', 'thinkrank')
			);
		}

		if (data.event_location?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Event location is configured.', 'thinkrank')
			);
		}

		if (data.event_organizer?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Event organizer is configured.', 'thinkrank')
			);
		}

		if (data.event_performer?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Event performer is configured.', 'thinkrank')
			);
		}

		if (data.event_image?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Event image is configured.', 'thinkrank')
			);
		}

		if (data.event_price?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Event pricing is configured.', 'thinkrank')
			);
		}

		if (data.event_description?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Event description is configured.', 'thinkrank')
			);
		}

		if (data.event_type?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Event type is configured.', 'thinkrank')
			);
		}

		if (data.event_end_date?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Event end date is configured.', 'thinkrank')
			);
		}

		if (data.event_status?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Event status is configured.', 'thinkrank')
			);
		}

		if (data.event_attendance_mode?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Event attendance mode is configured.', 'thinkrank')
			);
		}
	}

	// Validate Article schema configuration
	if (enabledTypes.includes('Article')) {
		totalFields += 9; // article_headline, article_type, article_author, article_publisher, article_date_published, article_date_modified, article_section, article_keywords, article_summary

		if (!data.article_headline?.trim()) {
			validation.warnings.push(
				__(
					'Article headline is recommended for Article schema.',
					'thinkrank'
				)
			);
			validation.hasWarnings = true;
			validation.fieldValidation.article_headline = 'warning';
		} else {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Article headline is configured.', 'thinkrank')
			);
		}

		if (!data.article_author?.trim()) {
			validation.warnings.push(
				__(
					'Article author is recommended for Article schema.',
					'thinkrank'
				)
			);
			validation.hasWarnings = true;
			validation.fieldValidation.article_author = 'warning';
		} else {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Article author is configured.', 'thinkrank')
			);
		}

		if (data.article_summary?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Article summary is configured.', 'thinkrank')
			);
		}

		if (data.article_publisher?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Article publisher is configured.', 'thinkrank')
			);
		}

		if (data.article_date_published?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Article publication date is configured.', 'thinkrank')
			);
		}

		if (data.article_date_modified?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Article modification date is configured.', 'thinkrank')
			);
		}

		if (data.article_section?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Article section is configured.', 'thinkrank')
			);
		}

		if (data.article_keywords?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Article keywords are configured.', 'thinkrank')
			);
		}

		if (data.article_type?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Article type is configured.', 'thinkrank')
			);
		}
	}

	// Validate SoftwareApplication schema configuration
	if (enabledTypes.includes('SoftwareApplication')) {
		totalFields += 12; // software_name, software_alternate_name, software_version, software_url, software_creator, software_description, software_operating_systems, software_category, software_features, software_price, software_currency, software_availability

		if (!data.software_name?.trim()) {
			validation.warnings.push(
				__(
					'Software name is recommended for SoftwareApplication schema.',
					'thinkrank'
				)
			);
			validation.hasWarnings = true;
			validation.fieldValidation.software_name = 'warning';
		} else {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Software name is configured.', 'thinkrank')
			);
		}

		if (!data.software_description?.trim()) {
			validation.warnings.push(
				__(
					'Software description is recommended for SoftwareApplication schema.',
					'thinkrank'
				)
			);
			validation.hasWarnings = true;
			validation.fieldValidation.software_description = 'warning';
		} else {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Software description is configured.', 'thinkrank')
			);
		}

		if (data.software_alternate_name?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__(
					'✓ Software tagline/alternate name is configured.',
					'thinkrank'
				)
			);
		}

		if (data.software_version?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Software version is configured.', 'thinkrank')
			);
		}

		if (data.software_url?.trim()) {
			if (!isValidUrl(data.software_url)) {
				validation.errors.push(
					__('Software URL must be a valid URL format.', 'thinkrank')
				);
				validation.hasErrors = true;
				validation.fieldValidation.software_url = 'error';
			} else {
				validatedFields++;
				validation.suggestions.push(
					__('✓ Software URL is configured.', 'thinkrank')
				);
			}
		}

		if (data.software_creator?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Software creator is configured.', 'thinkrank')
			);
		}

		if (
			data.software_operating_systems &&
			data.software_operating_systems.length > 0
		) {
			validatedFields++;
			validation.suggestions.push(
				__(
					`✓ Operating systems configured (${data.software_operating_systems.length} systems).`,
					'thinkrank'
				)
			);
		}

		if (data.software_category?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Software category is configured.', 'thinkrank')
			);
		}

		if (data.software_features && data.software_features.length > 0) {
			validatedFields++;
			validation.suggestions.push(
				__(
					`✓ Software features configured (${data.software_features.length} features).`,
					'thinkrank'
				)
			);
		}

		if (data.software_price?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Software price is configured.', 'thinkrank')
			);
		}

		if (data.software_currency?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Software currency is configured.', 'thinkrank')
			);
		}

		if (data.software_availability?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ Software availability is configured.', 'thinkrank')
			);
		}
	}

	// Validate HowTo schema configuration
	if (enabledTypes.includes('HowTo')) {
		totalFields += 9; // howto_name, howto_description, howto_total_time, howto_prep_time, howto_difficulty, howto_estimated_cost, howto_supply, howto_url, howto_yield

		if (!data.howto_name?.trim()) {
			validation.warnings.push(
				__('HowTo title is recommended for HowTo schema.', 'thinkrank')
			);
			validation.hasWarnings = true;
			validation.fieldValidation.howto_name = 'warning';
		} else {
			validatedFields++;
			validation.suggestions.push(
				__('✓ HowTo title is configured.', 'thinkrank')
			);
		}

		if (!data.howto_description?.trim()) {
			validation.warnings.push(
				__(
					'HowTo description is recommended for HowTo schema.',
					'thinkrank'
				)
			);
			validation.hasWarnings = true;
			validation.fieldValidation.howto_description = 'warning';
		} else {
			validatedFields++;
			validation.suggestions.push(
				__('✓ HowTo description is configured.', 'thinkrank')
			);
		}

		if (data.howto_total_time?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ HowTo total time is configured.', 'thinkrank')
			);
		}

		if (data.howto_prep_time?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ HowTo prep time is configured.', 'thinkrank')
			);
		}

		if (data.howto_difficulty?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ HowTo difficulty is configured.', 'thinkrank')
			);
		}

		if (data.howto_estimated_cost?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ HowTo estimated cost is configured.', 'thinkrank')
			);
		}

		if (data.howto_supply && data.howto_supply.length > 0) {
			validatedFields++;
			validation.suggestions.push(
				__(
					`✓ HowTo tools/materials configured (${data.howto_supply.length} items).`,
					'thinkrank'
				)
			);
		}

		if (data.howto_url?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ HowTo URL is configured.', 'thinkrank')
			);
		}

		if (data.howto_yield?.trim()) {
			validatedFields++;
			validation.suggestions.push(
				__('✓ HowTo yield/result is configured.', 'thinkrank')
			);
		}
	}

	// Set validation summary
	validation.validationSummary.validatedFields = validatedFields;
	validation.validationSummary.totalFields = totalFields;
	validation.validationSummary.completionPercentage =
		totalFields > 0
			? Math.round((validatedFields / totalFields) * 100)
			: 100;

	// Overall validation status
	validation.isValid = !validation.hasErrors;
};

/**
 * Main validation function
 * @param data
 */
export const validateSchemaData = (data) => {
	const validation = {
		hasErrors: false,
		hasWarnings: false,
		errors: [],
		warnings: [],
		suggestions: [],
		fieldValidation: {},
		validationSummary: {
			validatedFields: 0,
			totalFields: 0,
		},
	};

	// Validate each schema section that has actual form fields
	validateOrganizationSchema(data, validation);
	validateWebsiteSchema(data, validation);
	validatePersonSchema(data, validation);
	// validateLocalBusinessSchema(data, validation); // Removed - LocalBusiness is now informational only
	validateSchemaConfigurationSettings(data, validation);

	// Add note about deployment requirements
	if (data.enabled_schema_types && data.enabled_schema_types.length > 0) {
		const enabledTypes = data.enabled_schema_types;
		const missingFormTypes = [];

		if (enabledTypes.includes('SoftwareApplication')) {
			missingFormTypes.push('SoftwareApplication');
		}
		if (enabledTypes.includes('BreadcrumbList')) {
			missingFormTypes.push('BreadcrumbList');
		}

		if (missingFormTypes.length > 0) {
			validation.warnings.push(
				__(
					`Note: ${missingFormTypes.join(', ')} schema types are enabled but don't have dedicated form fields. These will be auto-generated from site content during deployment.`,
					'thinkrank'
				)
			);
			validation.hasWarnings = true;
		}
	}

	return validation;
};
