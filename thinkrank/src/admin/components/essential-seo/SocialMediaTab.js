/**
 * Social Media Tab Component
 *
 * Social media meta configuration for Open Graph, Twitter Cards,
 * and platform-specific optimization
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	SkeletonCard,
	Card,
	Input,
	Textarea,
	Select,
	Toggle,
	Button,
	Badge,
	Container,
	SettingsLoadError,
} from '../ui';
import apiFetch from '@wordpress/api-fetch';
import MediaPicker from '../common/MediaPicker';
import SettingsCard from '../common/SettingsCard';
import SectionHeader from '../common/SectionHeader';
import FormField from '../common/FormField';
import OptimizationButton from '../common/OptimizationButton';
import SocialMediaValidation from './social-media/SocialMediaValidation';
import useAutosaveSettings from '../../hooks/useAutosaveSettings';
import logger from '../../../shared/utils/logger';
import AdvancedOnly from '../common/AdvancedOnly';

// Display domain (no protocol / www) for the preview card footer.
const getDomain = (url) => {
	if (!url) return '';
	try {
		return new URL(url).hostname.replace(/^www\./, '');
	} catch {
		return url;
	}
};

// Platform → image aspect ratio for the preview card.
const PLATFORM_ASPECT = {
	facebook: 'tr-aspect-[1.91/1]',
	twitter: 'tr-aspect-[2/1]',
	linkedin: 'tr-aspect-[1.91/1]',
	pinterest: 'tr-aspect-[2/3]',
};

/**
 * Social Media Tab Component
 * @param root0
 * @param root0.activeSubSection
 */
const SocialMediaTab = ({ activeSubSection = 'open-graph' }) => {
	const [isLoading, setIsLoading] = useState(true);
	// True once a settings GET has failed. The form is not rendered while it is
	// set, so the defaults object can never be saved over stored values (#390).
	const [loadFailed, setLoadFailed] = useState(false);
	const [isSaving, setIsSaving] = useState(false);
	const [isGeneratingPreview, setIsGeneratingPreview] = useState(false);
	const [isValidating, setIsValidating] = useState(false);
	const [isSyncing, setIsSyncing] = useState(false);
	const [isVerifying, setIsVerifying] = useState(false);
	const [settings, setSettings] = useState({});
	const [hasChanges, setHasChanges] = useState(false);
	const [notice, setNotice] = useState(null);
	const [socialPreview, setSocialPreview] = useState(null);
	const [selectedPlatform, setSelectedPlatform] = useState('facebook');
	const [validationResults, setValidationResults] = useState(null);

	useEffect(() => {
		loadSettings();
	}, []);

	// Clear notice and validation results when switching tabs
	useEffect(() => {
		setValidationResults(null);
		setNotice(null);
	}, [activeSubSection]);

	const loadSettings = async () => {
		try {
			setIsLoading(true);
			// Use dedicated social media settings endpoint
			const response = await apiFetch({
				path: '/thinkrank/v1/social-media/settings',
				method: 'GET',
			});

			if (response.success) {
				setSettings({
					...getDefaultSettings(),
					...response.data.settings,
				});
				setLoadFailed(false);
			} else {
				throw new Error(
					response.error || 'Failed to load social media settings'
				);
			}
		} catch (error) {
			// Deliberately no setSettings(getDefaultSettings()) here: the form
			// would look like the user's own configuration, and the next Save
			// would post the defaults over it (#390).
			logger.error('Social Media settings load error:', error);
			setLoadFailed(true);
			setNotice({
				status: 'error',
				message: __(
					'Could not load your social media settings. Nothing has been changed. Retry before editing.',
					'thinkrank'
				),
			});
		} finally {
			setIsLoading(false);
		}
	};

	const getDefaultSettings = () => {
		// Get WordPress site defaults from localized data
		const siteName = window.thinkrankAdmin?.siteName || '';
		const siteDescription = window.thinkrankAdmin?.siteDescription || '';
		const siteUrl =
			window.thinkrankAdmin?.siteUrl || window.location.origin;

		return {
			// Open Graph settings
			enable_open_graph: true,
			oembed_use_seo_title: false,
			oembed_use_social_image: false,
			oembed_remove_author: false,
			og_site_name: siteName,
			og_description: siteDescription,
			og_type: 'website',
			// No default: og:locale follows the site language unless explicitly
			// overridden. Sending a value here persisted it as a real DB row on
			// the first save of this tab, which is what made the old hardcoded
			// default sticky.
			og_locale: '',
			default_og_image: '',
			og_multiple_images: false,
			og_image_width: 1200,
			og_image_height: 630,

			// Twitter Cards settings
			enable_twitter_cards: true,
			twitter_username: '',
			twitter_creator: '',
			twitter_card_type: 'summary_large_image',
			default_twitter_image: '',

			// Facebook settings
			facebook_app_id: '',
			facebook_admins: '',

			// LinkedIn settings
			enable_linkedin: false,

			// Pinterest settings
			enable_pinterest: false,
			pinterest_site_verification: '',

			// Instagram settings
			enable_instagram: false,
			instagram_verification: '',

			// TikTok settings
			enable_tiktok: false,
			tiktok_verification: '',

			// YouTube settings
			enable_youtube: false,
			youtube_channel_id: '',

			// WhatsApp Business settings
			enable_whatsapp: false,
			whatsapp_business_id: '',

			// Advanced settings
			auto_generate_descriptions: true,
			fallback_to_excerpt: true,
			strip_html_tags: true,
			max_description_length: 160,
		};
	};

	const handleSettingChange = (key, value) => {
		setSettings((prev) => ({ ...prev, [key]: value }));
		setHasChanges(true);
	};

	const saveSettings = async () => {
		// Belt and braces: the form is not rendered while loadFailed is set.
		if (loadFailed) {
			return;
		}

		try {
			setIsSaving(true);
			setNotice(null);

			const response = await apiFetch({
				path: '/thinkrank/v1/social-media/settings',
				method: 'POST',
				data: {
					settings,
					context_type: 'site',
					validation_context: activeSubSection, // Pass current tab for focused validation
				},
			});

			if (response.success) {
				setHasChanges(false);
				setNotice({
					status: 'success',
					message: __(
						'Social media settings saved successfully!',
						'thinkrank'
					),
				});
			} else {
				throw new Error(response.error || 'Failed to save settings');
			}
		} catch (error) {
			logger.error('Social Media settings save error:', error);

			let errorMessage = __(
				'Failed to save social media settings. Please try again.',
				'thinkrank'
			);

			// Handle validation errors (WP_Error format)
			if (
				error.data &&
				error.data.validation_errors &&
				error.data.validation_errors.length > 0
			) {
				logger.error(
					'Validation errors:',
					error.data.validation_errors
				);
				errorMessage =
					__('Validation failed: ', 'thinkrank') +
					error.data.validation_errors.join(', ');
			}
			// Handle other error formats
			else if (
				error.validation &&
				error.validation.errors &&
				error.validation.errors.length > 0
			) {
				logger.error('Validation errors:', error.validation.errors);
				errorMessage =
					__('Validation failed: ', 'thinkrank') +
					error.validation.errors.join(', ');
			}
			// Handle simple error messages
			else if (error.message) {
				errorMessage = error.message;
			}

			setNotice({
				status: 'error',
				message: errorMessage,
			});
		} finally {
			setIsSaving(false);
		}
	};

	// The Open Graph and Twitter Cards switches ARE the save: flipping either
	// persists immediately and rolls back if the request fails. Every other
	// field keeps using the Save Settings button.
	const { update: updateFeature, isSaving: isTogglingFeature } =
		useAutosaveSettings({
			settings,
			setSettings,
			save: async (next) => {
				const response = await apiFetch({
					path: '/thinkrank/v1/social-media/settings',
					method: 'POST',
					data: {
						settings: next,
						context_type: 'site',
						validation_context: activeSubSection,
					},
				});

				if (!response.success) {
					throw new Error(
						response.error ||
							__('Failed to save settings.', 'thinkrank')
					);
				}
			},
			logLabel: 'Social Media',
		});

	/**
	 * Generate social media preview
	 */
	const generatePreview = async () => {
		try {
			setIsGeneratingPreview(true);

			// Prepare preview data with fallbacks to WordPress defaults
			const siteName = window.thinkrankAdmin?.siteName || '';
			const siteDescription =
				window.thinkrankAdmin?.siteDescription || '';
			const siteUrl =
				window.thinkrankAdmin?.siteUrl || window.location.origin;

			const previewData = {
				title: settings.og_site_name || siteName || 'Your Site Name',
				description:
					settings.og_description ||
					siteDescription ||
					'Your site description',
				url: siteUrl,
				image: settings.default_og_image || '',
				type: settings.og_type || 'website',
			};

			const response = await apiFetch({
				path: '/thinkrank/v1/social-media/preview',
				method: 'POST',
				data: {
					data: previewData,
					platform: selectedPlatform,
				},
			});

			if (response.success) {
				// Add client-side enhancements to the preview data
				const enhancedPreview = {
					...response.data,
					platform: selectedPlatform,
					character_counts: {
						title: response.data.title?.length || 0,
						description: response.data.description?.length || 0,
					},
				};

				setSocialPreview(enhancedPreview);
				setNotice({
					status: 'success',
					message: __(
						'Social media preview generated successfully!',
						'thinkrank'
					),
				});
			}
		} catch (error) {
			logger.error('Social media preview error:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to generate social media preview. Please try again.',
					'thinkrank'
				),
			});
		} finally {
			setIsGeneratingPreview(false);
		}
	};

	/**
	 * Sync data from Site Identity tab
	 * @param fieldsToSync
	 */
	const syncFromSiteIdentity = async (fieldsToSync = []) => {
		try {
			setIsSyncing(true);

			// Get Site Identity settings using the same endpoint as Site Identity tab
			const response = await apiFetch({
				path: '/thinkrank/v1/site-identity/settings',
				method: 'GET',
			});

			if (response.success) {
				const siteIdentitySettings = response.data.settings;
				const updatedSettings = { ...settings };

				// Map Site Identity fields to Social Media fields
				const fieldMapping = {
					og_site_name: siteIdentitySettings.site_name || '',
					og_description:
						siteIdentitySettings.site_description ||
						siteIdentitySettings.tagline ||
						'',
					default_og_image: siteIdentitySettings.logo_url || '',
					twitter_username: '', // No direct mapping
					default_twitter_image: siteIdentitySettings.logo_url || '',
				};

				// Update only requested fields
				const syncedFields = [];
				let hasActualChanges = false;

				fieldsToSync.forEach((field) => {
					if (fieldMapping.hasOwnProperty(field)) {
						const oldValue = updatedSettings[field] || '';
						const newValue = fieldMapping[field] || '';

						if (oldValue !== newValue) {
							updatedSettings[field] = newValue;
							syncedFields.push(field);
							hasActualChanges = true;
						}
					}
				});

				if (hasActualChanges) {
					setSettings(updatedSettings);
					setHasChanges(true);
					setNotice({
						status: 'success',
						message:
							__(
								'Successfully synced data from Site Identity settings!',
								'thinkrank'
							) + ` (${syncedFields.length} fields updated)`,
					});
				} else {
					setNotice({
						status: 'info',
						message: __(
							'No changes needed - Social Media fields are already up to date with Site Identity settings.',
							'thinkrank'
						),
					});
				}
			} else {
				throw new Error(
					response.message || 'API response indicated failure'
				);
			}
		} catch (error) {
			logger.error('Sync error:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to sync from Site Identity. Please try again.',
					'thinkrank'
				),
			});
		} finally {
			setIsSyncing(false);
		}
	};

	/**
	 * Validate social media settings (server-side validation)
	 */
	const validateSettings = async () => {
		try {
			setIsValidating(true);
			const response = await apiFetch({
				path: '/thinkrank/v1/social-media/validate',
				method: 'POST',
				data: {
					settings,
					context_type: 'site',
					validation_context: activeSubSection, // Pass current tab for focused validation
				},
			});

			if (response.success) {
				setValidationResults(response.data);
				setNotice({
					status: 'success',
					message: __(
						'Social media settings validated successfully!',
						'thinkrank'
					),
				});
			} else {
				setNotice({
					status: 'error',
					message:
						response.data?.message ||
						__(
							'Validation failed. Please check your settings.',
							'thinkrank'
						),
				});
			}
		} catch (error) {
			logger.error('Social media validation error:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to validate social media settings. Please try again.',
					'thinkrank'
				),
			});
		} finally {
			setIsValidating(false);
		}
	};

	/**
	 * Validate Open Graph settings (client-side validation)
	 */
	const validateOpenGraph = async () => {
		try {
			setIsValidating(true);

			// Perform client-side validation
			const validationResults = [];
			const warnings = [];

			// Check required fields
			if (!settings.og_site_name || settings.og_site_name.trim() === '') {
				validationResults.push(
					__('Site name is required for Open Graph', 'thinkrank')
				);
			}

			if (
				!settings.og_description ||
				settings.og_description.trim() === ''
			) {
				warnings.push(
					__(
						'Description is recommended for better social sharing',
						'thinkrank'
					)
				);
			} else if (settings.og_description.length > 160) {
				warnings.push(
					__(
						'Description should be under 160 characters for optimal display',
						'thinkrank'
					)
				);
			}

			if (
				!settings.default_og_image ||
				settings.default_og_image.trim() === ''
			) {
				warnings.push(
					__(
						'Default image is recommended for social sharing',
						'thinkrank'
					)
				);
			}

			// Check title length
			if (settings.og_site_name && settings.og_site_name.length > 60) {
				warnings.push(
					__(
						'Site name should be under 60 characters for optimal display',
						'thinkrank'
					)
				);
			}

			// Determine validation status
			const hasErrors = validationResults.length > 0;
			const hasWarnings = warnings.length > 0;

			let message = '';
			let status = 'success';

			if (hasErrors) {
				status = 'error';
				message =
					__('Open Graph validation failed:', 'thinkrank') +
					' ' +
					validationResults.join(', ');
			} else if (hasWarnings) {
				status = 'warning';
				message =
					__(
						'Open Graph validation passed with recommendations:',
						'thinkrank'
					) +
					' ' +
					warnings.join(', ');
			} else {
				message = __('Open Graph settings are valid!', 'thinkrank');
			}

			setNotice({
				status,
				message,
			});
		} catch (error) {
			logger.error('Validation error:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to validate Open Graph settings. Please try again.',
					'thinkrank'
				),
			});
		} finally {
			setIsValidating(false);
		}
	};

	/**
	 * Validate Twitter Cards settings (client-side validation)
	 */
	const validateTwitterCards = async () => {
		try {
			setIsValidating(true);

			// Perform client-side validation
			const validationResults = [];
			const warnings = [];

			// Check Twitter username format
			if (
				settings.twitter_username &&
				settings.twitter_username.trim() !== ''
			) {
				const username = settings.twitter_username.trim();

				// Remove @ if user included it (we'll add it back in output)
				const cleanUsername = username.startsWith('@')
					? username.slice(1)
					: username;

				if (cleanUsername.length === 0) {
					validationResults.push(
						__('Twitter username cannot be empty', 'thinkrank')
					);
				} else if (cleanUsername.length > 15) {
					validationResults.push(
						__(
							'Twitter username should be 15 characters or less',
							'thinkrank'
						)
					);
				} else if (!/^[A-Za-z0-9_]+$/.test(cleanUsername)) {
					validationResults.push(
						__(
							'Twitter username can only contain letters, numbers, and underscores',
							'thinkrank'
						)
					);
				}

				// Update the setting to store without @ for consistency
				if (username.startsWith('@')) {
					handleSettingChange('twitter_username', cleanUsername);
				}
			} else {
				warnings.push(
					__(
						'Twitter username is recommended for proper attribution',
						'thinkrank'
					)
				);
			}

			// Check Twitter creator format
			if (
				settings.twitter_creator &&
				settings.twitter_creator.trim() !== ''
			) {
				const creator = settings.twitter_creator.trim();

				// Remove @ if user included it (we'll add it back in output)
				const cleanCreator = creator.startsWith('@')
					? creator.slice(1)
					: creator;

				if (cleanCreator.length === 0) {
					validationResults.push(
						__('Twitter creator cannot be empty', 'thinkrank')
					);
				} else if (cleanCreator.length > 15) {
					validationResults.push(
						__(
							'Twitter creator should be 15 characters or less',
							'thinkrank'
						)
					);
				} else if (!/^[A-Za-z0-9_]+$/.test(cleanCreator)) {
					validationResults.push(
						__(
							'Twitter creator can only contain letters, numbers, and underscores',
							'thinkrank'
						)
					);
				}

				// Update the setting to store without @ for consistency
				if (creator.startsWith('@')) {
					handleSettingChange('twitter_creator', cleanCreator);
				}
			}

			// Check card type
			const validCardTypes = [
				'summary',
				'summary_large_image',
				'app',
				'player',
			];
			if (!validCardTypes.includes(settings.twitter_card_type)) {
				validationResults.push(
					__('Invalid Twitter Card type selected', 'thinkrank')
				);
			}

			// Check image for summary_large_image
			if (settings.twitter_card_type === 'summary_large_image') {
				if (
					!settings.default_twitter_image ||
					settings.default_twitter_image.trim() === ''
				) {
					warnings.push(
						__(
							'Image is recommended for summary_large_image card type',
							'thinkrank'
						)
					);
				}
			}

			// Determine validation status
			const hasErrors = validationResults.length > 0;
			const hasWarnings = warnings.length > 0;

			let message = '';
			let status = 'success';

			if (hasErrors) {
				status = 'error';
				message =
					__('Twitter Cards validation failed:', 'thinkrank') +
					' ' +
					validationResults.join(', ');
			} else if (hasWarnings) {
				status = 'warning';
				message =
					__(
						'Twitter Cards validation passed with recommendations:',
						'thinkrank'
					) +
					' ' +
					warnings.join(', ');
			} else {
				message = __('Twitter Cards settings are valid!', 'thinkrank');
			}

			setNotice({
				status,
				message,
			});
		} catch (error) {
			logger.error('Validation error:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to validate Twitter Cards settings. Please try again.',
					'thinkrank'
				),
			});
		} finally {
			setIsValidating(false);
		}
	};

	/**
	 * Verify platform settings (client-side validation)
	 */
	const verifyPlatformSettings = async () => {
		try {
			setIsVerifying(true);

			// Perform client-side validation
			const validationResults = [];
			const warnings = [];
			const verified = [];

			// Check Facebook App ID
			if (
				settings.facebook_app_id &&
				settings.facebook_app_id.trim() !== ''
			) {
				const appId = settings.facebook_app_id.trim();
				if (!/^\d+$/.test(appId)) {
					validationResults.push(
						__(
							'Facebook App ID should contain only numbers',
							'thinkrank'
						)
					);
				} else if (appId.length < 10) {
					warnings.push(
						__(
							'Facebook App ID seems too short (usually 15+ digits)',
							'thinkrank'
						)
					);
				} else {
					verified.push(
						__('Facebook App ID format is valid', 'thinkrank')
					);
				}
			}

			// Check Facebook Admins
			if (
				settings.facebook_admins &&
				settings.facebook_admins.trim() !== ''
			) {
				const admins = settings.facebook_admins.trim();
				if (!/^[\d,\s]+$/.test(admins)) {
					validationResults.push(
						__(
							'Facebook Admins should be comma-separated user IDs (numbers only)',
							'thinkrank'
						)
					);
				} else {
					verified.push(
						__('Facebook Admins format is valid', 'thinkrank')
					);
				}
			}

			// Check Pinterest Site Verification
			if (
				settings.pinterest_site_verification &&
				settings.pinterest_site_verification.trim() !== ''
			) {
				const verification =
					settings.pinterest_site_verification.trim();
				if (!/^[a-f0-9]{32}$/.test(verification)) {
					validationResults.push(
						__(
							'Pinterest site verification should be a 32-character hex string',
							'thinkrank'
						)
					);
				} else {
					verified.push(
						__(
							'Pinterest site verification format is valid',
							'thinkrank'
						)
					);
				}
			}

			// Check Instagram verification format
			if (
				settings.instagram_verification &&
				settings.instagram_verification.trim() !== ''
			) {
				const verification = settings.instagram_verification.trim();
				if (!/^[a-zA-Z0-9_-]{20,}$/.test(verification)) {
					validationResults.push(
						__(
							'Instagram verification should be at least 20 characters (letters, numbers, underscore, dash)',
							'thinkrank'
						)
					);
				} else {
					verified.push(
						__(
							'Instagram verification format is valid',
							'thinkrank'
						)
					);
				}
			}

			// Check TikTok verification format
			if (
				settings.tiktok_verification &&
				settings.tiktok_verification.trim() !== ''
			) {
				const verification = settings.tiktok_verification.trim();
				if (!/^[a-zA-Z0-9_-]{20,}$/.test(verification)) {
					validationResults.push(
						__(
							'TikTok verification should be at least 20 characters (letters, numbers, underscore, dash)',
							'thinkrank'
						)
					);
				} else {
					verified.push(
						__('TikTok verification format is valid', 'thinkrank')
					);
				}
			}

			// Check YouTube Channel ID format
			if (
				settings.youtube_channel_id &&
				settings.youtube_channel_id.trim() !== ''
			) {
				const channelId = settings.youtube_channel_id.trim();
				if (!/^UC[a-zA-Z0-9_-]{22}$/.test(channelId)) {
					validationResults.push(
						__(
							'YouTube Channel ID should start with "UC" followed by 22 characters',
							'thinkrank'
						)
					);
				} else {
					verified.push(
						__('YouTube Channel ID format is valid', 'thinkrank')
					);
				}
			}

			// Check WhatsApp Business ID format
			if (
				settings.whatsapp_business_id &&
				settings.whatsapp_business_id.trim() !== ''
			) {
				const businessId = settings.whatsapp_business_id.trim();
				if (!/^[0-9]{10,15}$/.test(businessId)) {
					validationResults.push(
						__(
							'WhatsApp Business ID should be 10-15 digits',
							'thinkrank'
						)
					);
				} else {
					verified.push(
						__('WhatsApp Business ID format is valid', 'thinkrank')
					);
				}
			}

			// Check if any platform settings are configured
			const hasAnySettings = [
				settings.facebook_app_id,
				settings.facebook_admins,
				settings.pinterest_site_verification,
				settings.instagram_verification,
				settings.tiktok_verification,
				settings.youtube_channel_id,
				settings.whatsapp_business_id,
			].some((setting) => setting && setting.trim() !== '');

			if (!hasAnySettings) {
				warnings.push(
					__(
						'No platform settings configured - consider adding verification codes for better platform integration',
						'thinkrank'
					)
				);
			} else {
				verified.push(
					__('Platform integrations configured', 'thinkrank')
				);
			}

			// Determine validation status
			const hasErrors = validationResults.length > 0;
			const hasWarnings = warnings.length > 0;
			const hasVerified = verified.length > 0;

			let message = '';
			let status = 'success';

			if (hasErrors) {
				status = 'error';
				message =
					__('Platform verification failed:', 'thinkrank') +
					' ' +
					validationResults.join(', ');
			} else if (hasWarnings && !hasVerified) {
				status = 'warning';
				message = warnings.join(', ');
			} else if (hasVerified) {
				status = 'success';
				message = verified.join(', ');
				if (hasWarnings) {
					message +=
						'. ' +
						__('Note:', 'thinkrank') +
						' ' +
						warnings.join(', ');
				}
			} else {
				message = __(
					'Platform settings verified successfully!',
					'thinkrank'
				);
			}

			setNotice({
				status,
				message,
			});
		} catch (error) {
			logger.error('Verification error:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to verify platform settings. Please try again.',
					'thinkrank'
				),
			});
		} finally {
			setIsVerifying(false);
		}
	};

	/**
	 * Get context-specific button configuration for each sub-section
	 * Following Site Identity getOptimizationButtonConfig pattern
	 */
	const getOptimizationButtonConfig = () => {
		const baseConfig = {
			disabled: false,
			size: 'default',
		};

		switch (activeSubSection) {
			case 'open-graph':
				return [
					<OptimizationButton
						key="validate-social"
						type="validate"
						label={__('Validate Social Media', 'thinkrank')}
						loadingLabel={__('Validating…', 'thinkrank')}
						onClick={validateSettings}
						isBusy={isValidating}
						{...baseConfig}
					/>,
					<OptimizationButton
						key="sync-og"
						type="generate"
						label={__('Sync from Site Identity', 'thinkrank')}
						loadingLabel={__('Syncing…', 'thinkrank')}
						onClick={() =>
							syncFromSiteIdentity([
								'og_site_name',
								'og_description',
								'default_og_image',
							])
						}
						isBusy={isSyncing}
						{...baseConfig}
					/>,
				];

			case 'twitter-cards':
				return [
					<OptimizationButton
						key="validate-social"
						type="validate"
						label={__('Validate Social Media', 'thinkrank')}
						loadingLabel={__('Validating…', 'thinkrank')}
						onClick={validateSettings}
						isBusy={isValidating}
						{...baseConfig}
					/>,
				];

			case 'preview':
				return [
					<OptimizationButton
						key="generate-preview"
						type="generate"
						label={__('Generate Preview', 'thinkrank')}
						loadingLabel={__('Generating…', 'thinkrank')}
						onClick={generatePreview}
						isBusy={isGeneratingPreview}
						{...baseConfig}
					/>,
				];

			default:
				return [];
		}
	};

	/**
	 * Render Open Graph tab
	 */
	const renderOpenGraphTab = () => {
		const descLen = settings.og_description?.length || 0;
		const descGood = descLen >= 120 && descLen <= 160;
		const lengthLabel = descGood
			? __('Good length', 'thinkrank')
			: descLen < 120
				? __('Too short', 'thinkrank')
				: __('Too long', 'thinkrank');
		return (
			<Container direction="column" gap={4}>
				<Card padding={false}>
					<SectionHeader
						title={__('Open Graph Settings', 'thinkrank')}
						description={__(
							'Add Open Graph meta tags for social media sharing.',
							'thinkrank'
						)}
						trailing={
							<Toggle
								checked={settings.enable_open_graph}
								onChange={(val) =>
									updateFeature(
										{ enable_open_graph: val },
										val
											? __(
													'Open Graph is on.',
													'thinkrank'
												)
											: __(
													'Open Graph is off.',
													'thinkrank'
												)
									)
								}
								label={__('Enable Open Graph', 'thinkrank')}
								disabled={isTogglingFeature}
							/>
						}
					/>
					{settings.enable_open_graph && (
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<FormField
								id="tr-og-site-name"
								label={__('Site Name', 'thinkrank')}
								description={__(
									'The name of your website',
									'thinkrank'
								)}
							>
								<Input
									id="tr-og-site-name"
									value={settings.og_site_name}
									onChange={(e) =>
										handleSettingChange(
											'og_site_name',
											e.target.value
										)
									}
									placeholder={
										window.thinkrankAdmin?.siteName ||
										__('Your Site Name', 'thinkrank')
									}
								/>
							</FormField>

							<FormField
								id="tr-og-desc"
								label={__('Default Description', 'thinkrank')}
								description={__(
									'Default description for social media sharing (120-160 characters recommended)',
									'thinkrank'
								)}
								counter={
									<span
										className={`tr-text-xs tr-font-medium ${descGood ? 'tr-text-success' : 'tr-text-warning'}`}
									>
										{lengthLabel} ({descLen}/160)
									</span>
								}
								className="tr-pt-6"
							>
								<Textarea
									id="tr-og-desc"
									value={settings.og_description}
									onChange={(e) =>
										handleSettingChange(
											'og_description',
											e.target.value
										)
									}
									placeholder={
										window.thinkrankAdmin
											?.siteDescription ||
										__(
											'Your site description for social media sharing',
											'thinkrank'
										)
									}
									rows={3}
								/>
							</FormField>

							<FormField
								id="tr-og-type"
								label={__('Content Type', 'thinkrank')}
								description={__(
									'The type of content your site represents',
									'thinkrank'
								)}
								className="tr-pt-6"
							>
								<Select
									id="tr-og-type"
									value={settings.og_type}
									options={[
										{
											label: __('Website', 'thinkrank'),
											value: 'website',
										},
										{
											label: __('Article', 'thinkrank'),
											value: 'article',
										},
										{
											label: __('Blog', 'thinkrank'),
											value: 'blog',
										},
										{
											label: __('Business', 'thinkrank'),
											value: 'business',
										},
									]}
									onChange={(e) =>
										handleSettingChange(
											'og_type',
											e.target.value
										)
									}
								/>
							</FormField>

							<div className="tr-pt-6">
								<MediaPicker
									label={__(
										'Default Open Graph Image',
										'thinkrank'
									)}
									value={settings.default_og_image}
									onChange={(value) =>
										handleSettingChange(
											'default_og_image',
											value
										)
									}
									help={__(
										'Default image for social media sharing (1200x630px recommended)',
										'thinkrank'
									)}
									allowedTypes={['image']}
								/>
							</div>

							<div className="tr-pt-6">
								<Toggle
									id="og-multiple-images"
									label={__(
										'Offer Alternative Images',
										'thinkrank'
									)}
									description={__(
										'Publish the featured image and images from the post body as alternatives after the main one, so a sharer can choose and a platform has a fallback if the main image fails validation. Twitter Cards and schema keep using the main image only.',
										'thinkrank'
									)}
									checked={!!settings.og_multiple_images}
									onChange={(val) =>
										updateFeature(
											{ og_multiple_images: val },
											val
												? __(
														'Alternative social images are on.',
														'thinkrank'
													)
												: __(
														'Alternative social images are off.',
														'thinkrank'
													)
										)
									}
									disabled={isTogglingFeature}
								/>
							</div>
						</Container>
					)}
				</Card>

				{/*
				 * Embed cards are not Open Graph output, so this card sits outside
				 * the enable_open_graph gate: a site can leave OG off and still
				 * want its embeds to carry the SEO title.
				 */}
				<AdvancedOnly label={__('Embed cards', 'thinkrank')}>
					<Card padding={false}>
						<SectionHeader
							title={__('Embed Cards', 'thinkrank')}
							description={__(
								'What other sites show when someone pastes a link to your content. Each switch saves as you flip it.',
								'thinkrank'
							)}
						/>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<Toggle
								id="oembed-use-seo-title"
								label={__(
									'Use SEO Title In Embeds',
									'thinkrank'
								)}
								description={__(
									'Show the SEO title you wrote instead of the raw post title. Posts without an SEO title keep their own.',
									'thinkrank'
								)}
								checked={!!settings.oembed_use_seo_title}
								onChange={(val) =>
									updateFeature(
										{ oembed_use_seo_title: val },
										val
											? __(
													'Embeds now use the SEO title.',
													'thinkrank'
												)
											: __(
													'Embeds now use the post title.',
													'thinkrank'
												)
									)
								}
								disabled={isTogglingFeature}
							/>

							<div className="tr-pt-6">
								<Toggle
									id="oembed-use-social-image"
									label={__(
										'Use Social Image In Embeds',
										'thinkrank'
									)}
									description={__(
										'Put the social image on the embed card. A featured image still wins over the site-wide default.',
										'thinkrank'
									)}
									checked={!!settings.oembed_use_social_image}
									onChange={(val) =>
										updateFeature(
											{ oembed_use_social_image: val },
											val
												? __(
														'Embeds now use the social image.',
														'thinkrank'
													)
												: __(
														'Embeds no longer use the social image.',
														'thinkrank'
													)
										)
									}
									disabled={isTogglingFeature}
								/>
							</div>

							<div className="tr-pt-6">
								<Toggle
									id="oembed-remove-author"
									label={__(
										'Remove Author From Embeds',
										'thinkrank'
									)}
									description={__(
										'Drop the author name and link from the embed data. Useful for brands and multi-author sites that do not want personal names travelling with every embed.',
										'thinkrank'
									)}
									checked={!!settings.oembed_remove_author}
									onChange={(val) =>
										updateFeature(
											{ oembed_remove_author: val },
											val
												? __(
														'Embeds no longer name the author.',
														'thinkrank'
													)
												: __(
														'Embeds name the author again.',
														'thinkrank'
													)
										)
									}
									disabled={isTogglingFeature}
								/>
							</div>
						</Container>
					</Card>
				</AdvancedOnly>
			</Container>
		);
	};

	/**
	 * Render Twitter tab
	 */
	const renderTwitterTab = () => (
		<Container direction="column" gap={4}>
			<Card padding={false}>
				<SectionHeader
					title={__('Twitter Cards Settings', 'thinkrank')}
					description={__(
						'Add Twitter Card meta tags for Twitter sharing.',
						'thinkrank'
					)}
					trailing={
						<Toggle
							checked={settings.enable_twitter_cards}
							onChange={(val) =>
								updateFeature(
									{ enable_twitter_cards: val },
									val
										? __(
												'Twitter Cards are on.',
												'thinkrank'
											)
										: __(
												'Twitter Cards are off.',
												'thinkrank'
											)
								)
							}
							label={__('Enable Twitter Cards', 'thinkrank')}
							disabled={isTogglingFeature}
						/>
					}
				/>
				{settings.enable_twitter_cards && (
					<Container
						direction="column"
						gap={6}
						className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
					>
						<FormField
							id="tr-tw-user"
							label={__('Twitter Username', 'thinkrank')}
							description={__(
								'Your Twitter username (without @)',
								'thinkrank'
							)}
						>
							<Input
								id="tr-tw-user"
								value={settings.twitter_username}
								onChange={(e) =>
									handleSettingChange(
										'twitter_username',
										e.target.value
									)
								}
								placeholder="username"
							/>
						</FormField>

						<FormField
							id="tr-tw-creator"
							label={__('Twitter Creator', 'thinkrank')}
							description={__(
								'Content creator Twitter username (without @)',
								'thinkrank'
							)}
							className="tr-pt-6"
						>
							<Input
								id="tr-tw-creator"
								value={settings.twitter_creator}
								onChange={(e) =>
									handleSettingChange(
										'twitter_creator',
										e.target.value
									)
								}
								placeholder="creator_username"
							/>
						</FormField>

						<FormField
							id="tr-tw-card-type"
							label={__('Card Type', 'thinkrank')}
							description={__(
								'The type of Twitter Card to use',
								'thinkrank'
							)}
							className="tr-pt-6"
						>
							<Select
								id="tr-tw-card-type"
								value={settings.twitter_card_type}
								options={[
									{
										label: __(
											'Summary Large Image',
											'thinkrank'
										),
										value: 'summary_large_image',
									},
									{
										label: __('Summary', 'thinkrank'),
										value: 'summary',
									},
									{
										label: __('App', 'thinkrank'),
										value: 'app',
									},
									{
										label: __('Player', 'thinkrank'),
										value: 'player',
									},
								]}
								onChange={(e) =>
									handleSettingChange(
										'twitter_card_type',
										e.target.value
									)
								}
							/>
						</FormField>

						<div className="tr-pt-6">
							<MediaPicker
								label={__(
									'Default Twitter Card Image',
									'thinkrank'
								)}
								value={settings.default_twitter_image}
								onChange={(value) =>
									handleSettingChange(
										'default_twitter_image',
										value
									)
								}
								help={__(
									'Default image for Twitter sharing (1200x600px recommended)',
									'thinkrank'
								)}
								allowedTypes={['image']}
							/>
						</div>
					</Container>
				)}
			</Card>
		</Container>
	);

	/**
	 * Render Preview tab
	 */
	const renderPreviewTab = () => {
		const platformName =
			selectedPlatform.charAt(0).toUpperCase() +
			selectedPlatform.slice(1);
		const aspectClass =
			PLATFORM_ASPECT[selectedPlatform] || 'tr-aspect-video';

		return (
			<Card padding={false} className="tr-mb-4">
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary tr-flex tr-items-center tr-justify-between tr-gap-3 tr-flex-wrap">
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Social Media Preview', 'thinkrank')}
					</h3>
					<div className="tr-w-40">
						<Select
							value={selectedPlatform}
							options={[
								{
									label: __('Facebook', 'thinkrank'),
									value: 'facebook',
								},
								{
									label: __('Twitter', 'thinkrank'),
									value: 'twitter',
								},
								{
									label: __('LinkedIn', 'thinkrank'),
									value: 'linkedin',
								},
								{
									label: __('Pinterest', 'thinkrank'),
									value: 'pinterest',
								},
							]}
							onChange={(e) =>
								setSelectedPlatform(e.target.value)
							}
						/>
					</div>
				</div>
				<div className="tr-p-6">
					{socialPreview ? (
						<div className="tr-max-w-[480px] tr-mx-auto">
							<div className="tr-rounded-xl tr-border tr-border-secondary tr-overflow-hidden tr-bg-white tr-shadow-sm">
								{/* Header: platform + validity */}
								<div className="tr-flex tr-items-center tr-justify-between tr-gap-2 tr-px-3 tr-py-2 tr-border-b tr-border-secondary">
									<span className="tr-text-xs tr-font-semibold tr-text-secondary">
										{platformName}{' '}
										{__('Preview', 'thinkrank')}
									</span>
									{socialPreview.valid ? (
										<Badge variant="good">
											✓ {__('Valid', 'thinkrank')}
										</Badge>
									) : (
										<Badge variant="warning">
											⚠ {__('Issues Found', 'thinkrank')}
										</Badge>
									)}
								</div>

								{/* Image (or placeholder) */}
								{socialPreview.image ? (
									<div
										className={`${aspectClass} tr-relative tr-bg-[#F6F7FB] tr-overflow-hidden`}
									>
										<img
											src={socialPreview.image}
											alt={socialPreview.title}
											className="tr-w-full tr-h-full tr-object-cover"
											onError={(e) => {
												e.target.style.display = 'none';
											}}
										/>
										{selectedPlatform === 'pinterest' && (
											<div className="tr-absolute tr-top-2 tr-right-2 tr-flex tr-items-center tr-justify-center tr-w-8 tr-h-8 tr-rounded-full tr-bg-black/70 tr-text-white tr-text-sm">
												📌
											</div>
										)}
									</div>
								) : (
									<div
										className={`${aspectClass} tr-flex tr-flex-col tr-items-center tr-justify-center tr-gap-1 tr-bg-[#F6F7FB] tr-text-tertiary`}
									>
										<span className="tr-text-2xl">🖼️</span>
										<span className="tr-text-[11px]">
											{__(
												'No share image set',
												'thinkrank'
											)}
										</span>
									</div>
								)}

								{/* Content (link-card footer) */}
								<div className="tr-bg-[#F2F3F5] tr-px-3 tr-py-2.5">
									<p className="tr-text-[11px] tr-uppercase tr-tracking-wide tr-text-tertiary tr-m-0 tr-truncate">
										{getDomain(socialPreview.preview_url)}
									</p>
									<p className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0 tr-mt-0.5 tr-leading-snug">
										{socialPreview.title}
									</p>
									<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5 tr-leading-relaxed">
										{socialPreview.description}
									</p>
									{(selectedPlatform === 'twitter' ||
										selectedPlatform === 'pinterest') && (
										<p className="tr-text-[11px] tr-text-tertiary tr-m-0 tr-mt-1.5">
											{selectedPlatform === 'twitter'
												? `${socialPreview.title?.length || 0}/70 ${__('chars', 'thinkrank')}`
												: __(
														'Pin it • Save',
														'thinkrank'
													)}
										</p>
									)}
								</div>
							</div>

							{socialPreview.warnings &&
								socialPreview.warnings.length > 0 && (
									<div className="tr-mt-4 tr-rounded-lg tr-bg-warning-bg tr-border tr-border-warning/20 tr-p-3">
										<h5 className="tr-text-xs tr-font-semibold tr-text-warning tr-m-0 tr-mb-2">
											{__('Warnings:', 'thinkrank')}
										</h5>
										<ul className="tr-m-0 tr-pl-5 tr-list-disc">
											{socialPreview.warnings.map(
												(warning, index) => (
													<li
														key={index}
														className="tr-text-xs tr-text-warning tr-leading-relaxed"
													>
														{warning}
													</li>
												)
											)}
										</ul>
									</div>
								)}

							{socialPreview.suggestions &&
								socialPreview.suggestions.length > 0 && (
									<div className="tr-mt-4 tr-rounded-lg tr-bg-success-bg tr-border tr-border-success/20 tr-p-3">
										<h5 className="tr-text-xs tr-font-semibold tr-text-success tr-m-0 tr-mb-2">
											{__('Suggestions:', 'thinkrank')}
										</h5>
										<ul className="tr-m-0 tr-pl-5 tr-list-disc">
											{socialPreview.suggestions.map(
												(suggestion, index) => (
													<li
														key={index}
														className="tr-text-xs tr-text-secondary tr-leading-relaxed"
													>
														{suggestion}
													</li>
												)
											)}
										</ul>
									</div>
								)}
						</div>
					) : (
						<div className="tr-flex tr-flex-col tr-items-center tr-justify-center tr-text-center tr-py-10 tr-px-4 tr-rounded-xl tr-border tr-border-dashed tr-border-secondary tr-bg-[#F6F7FB]">
							<span className="tr-text-2xl tr-mb-2">🔗</span>
							<p className="tr-text-sm tr-font-medium tr-text-secondary tr-m-0">
								{__('Social preview', 'thinkrank')}
							</p>
							<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-1 tr-max-w-[280px]">
								{__(
									'Click "Generate Preview" to see how your content will appear on social media.',
									'thinkrank'
								)}
							</p>
						</div>
					)}
				</div>
			</Card>
		);
	};

	if (isLoading) {
		return (
			<div className="thinkrank-loading">
				<SkeletonCard lines={3} />
			</div>
		);
	}

	if (loadFailed) {
		return (
			<SettingsLoadError
				onRetry={loadSettings}
				isRetrying={isLoading}
				message={__(
					'Your social media settings could not be read, so this screen has nothing to edit yet. Nothing has changed. Retry to load them.',
					'thinkrank'
				)}
			/>
		);
	}

	return (
		<div className="thinkrank-social-media-tab">
			<SettingsCard
				title={__('Social Media & Open Graph', 'thinkrank')}
				optimizationButtons={getOptimizationButtonConfig()}
				notice={notice}
				onNoticeRemove={() => setNotice(null)}
			>
				{/* Content based on active subsection */}
				{(() => {
					// Map activeSubSection to tab names for compatibility
					const sectionMap = {
						'open-graph': 'open-graph',
						'twitter-cards': 'twitter',
						preview: 'preview',
					};

					const tabName =
						sectionMap[activeSubSection] || 'open-graph';

					switch (tabName) {
						case 'open-graph':
							return renderOpenGraphTab();
						case 'twitter':
							return renderTwitterTab();

						case 'preview':
							return renderPreviewTab();
						default:
							return null;
					}
				})()}

				{/* Validation Results Display - Hide on preview tab */}
				{activeSubSection !== 'preview' && (
					<SocialMediaValidation
						validationResults={validationResults}
					/>
				)}

				<div className="tr-flex tr-justify-end tr-mt-6">
					<Button
						variant="primary"
						size="md"
						onClick={saveSettings}
						disabled={!hasChanges || isSaving}
					>
						{isSaving
							? __('Saving…', 'thinkrank')
							: __('Save Settings', 'thinkrank')}
					</Button>
				</div>
			</SettingsCard>
		</div>
	);
};

export default SocialMediaTab;
