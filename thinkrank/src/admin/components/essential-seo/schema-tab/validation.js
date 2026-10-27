/**
 * Schema Tab Validation
 *
 * Validation logic for schema settings and section data.
 * Extracted from SchemaTab.js for maintainability.
 *
 * @package
 * @since 1.11.0
 */

import { __ } from '@wordpress/i18n';
import { isValidUrl } from './utils';
import { rangesOf } from '../../../utils/businessHours';

/**
 * Validate settings before saving (basic validation only)
 * Note: Detailed validation is handled by runValidation()
 *
 * @param {Object} settings - Current settings object
 * @return {Array} Array of error messages
 */
export const validateSettings = (settings) => {
	const errors = [];

	// Only validate critical configuration issues, not content fields
	// Validate deployment method if set
	const validDeploymentMethods = ['json_ld', 'microdata', 'rdfa'];
	if (
		settings.deployment_method &&
		!validDeploymentMethods.includes(settings.deployment_method)
	) {
		errors.push(__('Invalid deployment method selected', 'thinkrank'));
	}

	// Only validate URL formats if URLs are provided (not required for save)
	if (settings.organization_url && !isValidUrl(settings.organization_url)) {
		errors.push(
			__('Organization URL must be a valid URL format', 'thinkrank')
		);
	}
	if (settings.website_url && !isValidUrl(settings.website_url)) {
		errors.push(__('Website URL must be a valid URL format', 'thinkrank'));
	}

	// Handle MediaPicker value for logo URL validation
	const logoUrl =
		typeof settings.organization_logo === 'object'
			? settings.organization_logo?.url
			: settings.organization_logo;
	if (logoUrl && !isValidUrl(logoUrl)) {
		errors.push(
			__('Organization Logo URL must be a valid URL format', 'thinkrank')
		);
	}

	return errors;
};

/**
 * Get validation data for specific section
 *
 * @param {string} section  - Active sub-section name
 * @param {Object} settings - Current settings object
 * @return {Object} Section-specific validation data
 */
export const getSectionValidationData = (section, settings) => {
	switch (section) {
		case 'schema-settings':
			return {
				auto_generate_schema: settings.auto_generate_schema,
				enabled_schema_types: settings.enabled_schema_types,
				validation_level: settings.validation_level,
			};
		case 'organization':
			return {
				organization_name: settings.organization_name,
				organization_type: settings.organization_type,
				organization_url: settings.organization_url,
				organization_description: settings.organization_description,
				organization_logo: settings.organization_logo,
			};
		case 'website':
			return {
				website_name: settings.website_name,
				website_url: settings.website_url,
				website_description: settings.website_description,
				website_author: settings.website_author,
			};
		case 'person':
			return {
				person_name: settings.person_name,
				person_image: settings.person_image,
				person_job_title: settings.person_job_title,
				person_nationality: settings.person_nationality,
				person_biography: settings.person_description, // Fixed: form uses person_description
				person_url: settings.person_url,
				person_email: settings.person_email,
				person_telephone: settings.person_telephone,
				person_birth_date: settings.person_birth_date,
				person_address: settings.person_address,
				person_works_for: settings.person_works_for,
			};
		case 'local-business':
			return {
				business_geo_latitude: settings.business_geo_latitude,
				business_geo_longitude: settings.business_geo_longitude,
				business_price_range: settings.business_price_range,
				contact_email: settings.contact_email,
				contact_phone: settings.contact_phone,
				contact_address: settings.contact_address,
				business_social_facebook: settings.business_social_facebook,
				business_social_twitter: settings.business_social_twitter,
				business_social_linkedin: settings.business_social_linkedin,
				business_social_instagram: settings.business_social_instagram,
				business_social_youtube: settings.business_social_youtube,
				// Site Identity data for logo
				logo_url: settings.logo_url,
				// Organization data for social media
				organization_social_facebook:
					settings.organization_social_facebook,
				organization_social_twitter:
					settings.organization_social_twitter,
				organization_social_linkedin:
					settings.organization_social_linkedin,
				organization_social_instagram:
					settings.organization_social_instagram,
				organization_social_youtube:
					settings.organization_social_youtube,
				// Organization contact hours for business hours display
				organization_contact_hours: settings.organization_contact_hours,
			};
		case 'schema-types':
			return {
				enabled_schema_types: settings.enabled_schema_types,
				// Product schema fields
				product_name: settings.product_name,
				product_image: settings.product_image,
				product_price: settings.product_price,
				product_currency: settings.product_currency,
				product_brand: settings.product_brand,
				product_sku: settings.product_sku,
				product_gtin: settings.product_gtin,
				product_availability: settings.product_availability,
				product_description: settings.product_description,
				product_url: settings.product_url,
				product_keywords: settings.product_keywords,
				product_review: settings.product_review,
				product_rating_value: settings.product_rating_value,
				product_rating_count: settings.product_rating_count,
				// Event schema fields
				event_name: settings.event_name,
				event_type: settings.event_type,
				event_start_date: settings.event_start_date,
				event_end_date: settings.event_end_date,
				event_status: settings.event_status,
				event_location: settings.event_location,
				event_attendance_mode: settings.event_attendance_mode,
				event_organizer: settings.event_organizer,
				event_performer: settings.event_performer,
				event_image: settings.event_image,
				event_price: settings.event_price,
				event_currency: settings.event_currency,
				event_description: settings.event_description,
				// SoftwareApplication schema fields
				software_name: settings.software_name,
				software_alternate_name: settings.software_alternate_name,
				software_version: settings.software_version,
				software_url: settings.software_url,
				software_creator: settings.software_creator,
				software_creator_type: settings.software_creator_type,
				software_description: settings.software_description,
				software_operating_systems: settings.software_operating_systems,
				software_category: settings.software_category,
				software_features: settings.software_features,
				software_file_size: settings.software_file_size,
				software_license: settings.software_license,
				software_price: settings.software_price,
				software_currency: settings.software_currency,
				software_price_valid_until: settings.software_price_valid_until,
				software_availability: settings.software_availability,
				software_pricing_description:
					settings.software_pricing_description,
				software_rating_value: settings.software_rating_value,
				software_rating_count: settings.software_rating_count,
				// HowTo schema fields
				howto_name: settings.howto_name,
				howto_description: settings.howto_description,
				howto_image: settings.howto_image,
				howto_steps: settings.howto_steps,
				howto_total_time: settings.howto_total_time,
				howto_prep_time: settings.howto_prep_time,
				howto_difficulty: settings.howto_difficulty,
				howto_estimated_cost: settings.howto_estimated_cost,
				howto_supply: settings.howto_supply,
				howto_tool: settings.howto_tool,
				howto_url: settings.howto_url,
				howto_yield: settings.howto_yield,
				// Person schema data
				person_name: settings.person_name,
				person_job_title: settings.person_job_title,
				person_description: settings.person_description,
				person_image: settings.person_image,
				person_url: settings.person_url,
				person_email: settings.person_email,
				person_telephone: settings.person_telephone,
				person_address: settings.person_address,
				person_birth_date: settings.person_birth_date,
				person_nationality: settings.person_nationality,
				person_works_for: settings.person_works_for,
				person_same_as: settings.person_same_as,
				// FAQPage schema data
				faq_questions: settings.faq_questions,
				faq_page_name: settings.faq_page_name,
				faq_page_description: settings.faq_page_description,
				faq_page_url: settings.faq_page_url,
			};
		case 'deployment':
			return {
				deployment_method: settings.deployment_method,
				schema_placement: settings.schema_placement,
				minify_schema: settings.minify_schema,
				validate_before_output: settings.validate_before_output,
				fallback_on_error: settings.fallback_on_error,
			};
		default:
			return settings;
	}
};

/**
 * Format business hours from Local SEO for contact hours display
 *
 * @param {Object} businessHours - Business hours data
 * @return {string} Formatted hours string
 */
export const formatBusinessHoursForContact = (businessHours) => {
	if (!businessHours || typeof businessHours !== 'object') {
		return __('No business hours configured in Local SEO', 'thinkrank');
	}

	const dayNames = {
		monday: __('Mo', 'thinkrank'),
		tuesday: __('Tu', 'thinkrank'),
		wednesday: __('We', 'thinkrank'),
		thursday: __('Th', 'thinkrank'),
		friday: __('Fr', 'thinkrank'),
		saturday: __('Sa', 'thinkrank'),
		sunday: __('Su', 'thinkrank'),
	};

	const formattedDays = [];
	Object.keys(dayNames).forEach((day) => {
		const dayData = businessHours[day];
		// Every range of a split day, as the schema publishes it (#1119).
		const ranges = dayData?.closed
			? []
			: rangesOf(dayData).filter((range) => range.open && range.close);
		if (ranges.length > 0) {
			formattedDays.push(
				`${dayNames[day]} ${ranges
					.map((range) => `${range.open}-${range.close}`)
					.join(', ')}`
			);
		}
	});

	return formattedDays.length > 0
		? formattedDays.join(', ')
		: __('No business hours configured in Local SEO', 'thinkrank');
};

/**
 * Map Site Identity data to Schema Manager fields
 *
 * @param {Object} siteIdentityData - Site Identity settings
 * @param {string} syncType         - Type of sync (website, organization)
 * @return {Object} Mapped data
 */
export const mapSiteIdentityData = (siteIdentityData, syncType) => {
	const mappings = {
		website: {
			website_name: siteIdentityData.site_name || '',
			website_url: siteIdentityData.site_url || window.location.origin,
			website_description:
				siteIdentityData.site_description ||
				siteIdentityData.default_meta_description ||
				'',
			website_author: siteIdentityData.site_author || '',
		},
		organization: {
			organization_name: siteIdentityData.site_name || '',
			organization_url:
				siteIdentityData.site_url || window.location.origin,
			organization_description:
				siteIdentityData.site_description ||
				siteIdentityData.default_meta_description ||
				'',
			// MediaPicker expects URL string, Site Identity provides URL string
			organization_logo: siteIdentityData.logo_url || '',
		},
	};

	return mappings[syncType] || {};
};

/**
 * Get display name for sync type
 *
 * @param {string} syncType - Sync type key
 * @return {string} Display name
 */
export const getSyncTypeDisplayName = (syncType) => {
	const displayNames = {
		website: __('Website', 'thinkrank'),
		organization: __('Organization', 'thinkrank'),
	};
	return displayNames[syncType] || syncType;
};
