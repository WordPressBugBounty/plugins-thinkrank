/**
 * Site Identity Tab Component - Complete Implementation
 *
 * Full feature parity with PHP implementation including:
 * - Title format management with templates
 * - Advanced breadcrumb settings
 * - Robots.txt management
 * - Schema & SEO settings
 * - AI optimization features
 *
 * Now works with sidebar navigation instead of internal tabs
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { SkeletonCard, Card, Button, SettingsLoadError } from '../ui';
import apiFetch from '@wordpress/api-fetch';
// Import shared components for refactoring
import OptimizationButton from '../common/OptimizationButton';
import SettingsCard from '../common/SettingsCard';

// Import extracted site identity components
import SiteInformation from './site-identity/SiteInformation';
import TitleFormats from './site-identity/TitleFormats';
import BreadcrumbSettings from './site-identity/BreadcrumbSettings';
import HeroSection from './site-identity/HeroSection';
import LocalSEO from './site-identity/LocalSEO';
import SiteIdentityValidation from './site-identity/SiteIdentityValidation';
import SiteIdentityOptimization from './site-identity/SiteIdentityOptimization';
import useAutosaveSettings from '../../hooks/useAutosaveSettings';
import logger from '../../../shared/utils/logger';
import notify from '../../utils/notify';

/**
 * Site Identity Tab Component
 * @param root0
 * @param root0.activeSubSection
 */
const SiteIdentityTab = ({ activeSubSection = 'basic-info' }) => {
	// State management
	const [isLoading, setIsLoading] = useState(true);
	// True once a settings GET has failed. The form is not rendered while it is
	// set, so the defaults object can never be saved over stored values (#390).
	const [loadFailed, setLoadFailed] = useState(false);
	const [isSaving, setIsSaving] = useState(false);
	const [isValidating, setIsValidating] = useState(false);
	const [isOptimizing, setIsOptimizing] = useState(false);

	const [settings, setSettings] = useState({});
	const [hasChanges, setHasChanges] = useState(false);
	const [titleTemplates, setTitleTemplates] = useState([]);
	const [breadcrumbTypes, setBreadcrumbTypes] = useState([]);
	const [validationResults, setValidationResults] = useState(null);
	const [optimizationResults, setOptimizationResults] = useState({});
	const [aiOptimizationResults, setAiOptimizationResults] = useState(null);
	const [isAiOptimizing, setIsAiOptimizing] = useState(false);

	useEffect(() => {
		loadSettings();
		loadTitleTemplates();
		loadBreadcrumbTypes();
	}, []);

	// Clear validation results when switching between sections.
	// (Notices are transient toasts now — nothing to clear.)
	useEffect(() => {
		setValidationResults(null);
	}, [activeSubSection]);

	/**
	 * Get dynamic tab display name for notices
	 */
	const getTabDisplayName = () => {
		return __('Site Identity', 'thinkrank');
	};

	/**
	 * Get sub-section specific display name for notices
	 */
	const getSubSectionDisplayName = () => {
		const subSectionNames = {
			'basic-info': __('Basic Info', 'thinkrank'),
			'title-formats': __('Title Formats', 'thinkrank'),
			breadcrumbs: __('Breadcrumbs', 'thinkrank'),
			'hero-section': __('Hero & Branding', 'thinkrank'),
			'local-seo': __('Business Info', 'thinkrank'),
			'robots-txt': __('Robots.txt', 'thinkrank'),
		};

		return subSectionNames[activeSubSection] || getTabDisplayName();
	};

	/**
	 * Get default settings structure (matches PHP implementation)
	 */
	const getDefaultSettings = () => ({
		enabled: true,
		// Title format settings
		homepage_title: '%site_title% %sep% %site_description%',
		post_title: '%post_title% %sep% %site_title%',
		page_title: '%page_title% %sep% %site_title%',
		category_title: '%category_title% %sep% %site_title%',
		tag_title: '%tag_title% %sep% %site_title%',
		author_title: '%author_name% %sep% %site_title%',
		search_title: 'Search Results for "%search_term%" %sep% %site_title%',
		archive_title: '%archive_title% %sep% %site_title%',
		title_separator: 'pipe',
		// Site information
		site_name: '',
		site_description: '',
		tagline: '',
		// Breadcrumb settings
		breadcrumbs_enabled: true,
		breadcrumb_type: 'hierarchical',
		breadcrumb_home_text: 'Home',
		breadcrumb_separator: '›',
		breadcrumb_prefix: '',
		show_current_page: true,
		breadcrumb_use_seo_title: false,

		// SEO defaults
		default_meta_description: '',
		homepage_description: '',
		social_media_accounts: [],
		// Schema settings
		organization_schema: true,
		knowledge_graph: true,
		// Site assets
		logo_url: '',
		favicon_url: '',
		apple_touch_icon_url: '',
		// Hero section
		hero_title: '',
		hero_subtitle: '',
		hero_cta_text: '',
		hero_cta_url: '',
		hero_background_image: '',
		// Local SEO
		local_seo_enabled: false,
		business_type: 'LocalBusiness',
		business_name: '',
		business_address: '',
		business_city: '',
		business_state: '',
		business_postal_code: '',
		business_country: '',
		business_phone: '',
		business_email: '',
		business_latitude: '',
		business_longitude: '',
		business_price_range: '',
		business_hours: {
			monday: { open: '09:00', close: '17:00', closed: true },
			tuesday: { open: '09:00', close: '17:00', closed: true },
			wednesday: { open: '09:00', close: '17:00', closed: true },
			thursday: { open: '09:00', close: '17:00', closed: true },
			friday: { open: '09:00', close: '17:00', closed: true },
			saturday: { open: '10:00', close: '16:00', closed: true },
			sunday: { open: '09:00', close: '17:00', closed: true },
		},
	});

	/**
	 * Load settings from API
	 */
	const loadSettings = async () => {
		try {
			setIsLoading(true);
			const response = await apiFetch({
				path: '/thinkrank/v1/site-identity/settings',
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
					response.error || 'Failed to load site identity settings'
				);
			}
		} catch (error) {
			// Deliberately no setSettings(getDefaultSettings()) here. Falling
			// back to defaults renders a form that looks like the user's own
			// configuration, and the next Save posts ~40 default keys over it.
			logger.error('Site Identity settings load error:', error);
			setLoadFailed(true);
			notify.error(
				__(
					'Could not load your Site Identity settings. Nothing has been changed. Retry before editing.',
					'thinkrank'
				)
			);
		} finally {
			setIsLoading(false);
		}
	};

	/**
	 * Load title templates from API
	 */
	const loadTitleTemplates = async () => {
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/site-identity/title/templates',
				method: 'GET',
			});

			if (response.success) {
				setTitleTemplates(response.data.templates || []);
			}
		} catch (error) {
			logger.error('Failed to load title templates:', error);
		}
	};

	/**
	 * Load breadcrumb types from API
	 */
	const loadBreadcrumbTypes = async () => {
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/site-identity/breadcrumbs/types',
				method: 'GET',
			});

			if (response.success) {
				setBreadcrumbTypes(response.data.types || []);
			}
		} catch (error) {
			logger.error('Failed to load breadcrumb types:', error);
		}
	};

	/**
	 * Handle setting changes
	 * @param key
	 * @param value
	 */
	const handleSettingChange = (key, value) => {
		setSettings((prev) => ({ ...prev, [key]: value }));
		setHasChanges(true);
	};

	/**
	 * Save settings to API
	 */
	const saveSettings = async () => {
		// Belt and braces: the form is not rendered while loadFailed is set, so
		// this is unreachable through the UI — but any future caller inherits
		// the guarantee rather than posting defaults over stored values.
		if (loadFailed) {
			return;
		}

		try {
			setIsSaving(true);

			const response = await apiFetch({
				path: '/thinkrank/v1/site-identity/settings',
				method: 'POST',
				data: {
					settings,
					context_type: 'site',
				},
			});

			if (response.success) {
				// Re-hydrate from the server response so the form reflects
				// the sanitized values actually persisted.
				if (response.data?.settings) {
					setSettings({
						...getDefaultSettings(),
						...response.data.settings,
					});
				}
				setHasChanges(false);
				notify.success(
					sprintf(
						/* translators: %s: settings section name. */
						__('%s settings saved successfully!', 'thinkrank'),
						getTabDisplayName()
					)
				);
			} else {
				throw new Error(response.error || 'Failed to save settings');
			}
		} catch (error) {
			logger.error('Site Identity settings save error:', error);

			// `+` binds tighter than `??`, so the old expression parsed as
			// `(undefined + '. ') ?? ''` — the string "undefined. ", which is
			// never nullish, so the `?? ''` fallback was dead. Every failed
			// save that was not a field-validation rejection showed the literal
			// word "undefined", which reads as a crash and hid the real cause;
			// error.message was never surfaced at all (#404).
			const detail =
				error?.data?.validation_errors?.[0] || error?.message || '';

			notify.error(
				detail
					? sprintf(
							/* translators: 1: settings section name, 2: reason the save failed. */
							__(
								'Failed to save %1$s settings. %2$s',
								'thinkrank'
							),
							getTabDisplayName(),
							detail
						)
					: sprintf(
							/* translators: %s: settings section name. */
							__(
								'Failed to save %s settings. Please try again.',
								'thinkrank'
							),
							getTabDisplayName()
						)
			);
		} finally {
			setIsSaving(false);
		}
	};

	// The feature switch IS the save: flipping it persists immediately and
	// rolls back if the request fails. Every other field on this tab keeps
	// using the Save Settings button below.
	const { update: updateFeature, isSaving: isTogglingFeature } =
		useAutosaveSettings({
			settings,
			setSettings,
			save: async (next) => {
				const response = await apiFetch({
					path: '/thinkrank/v1/site-identity/settings',
					method: 'POST',
					data: {
						settings: next,
						context_type: 'site',
					},
				});

				if (!response.success) {
					throw new Error(
						response.error ||
							__('Failed to save settings.', 'thinkrank')
					);
				}

				return response.data?.settings
					? { ...getDefaultSettings(), ...response.data.settings }
					: next;
			},
			logLabel: 'Site Identity',
		});

	/**
	 * Persist a feature switch owned by one of the presentational children
	 * (Breadcrumbs, Local SEO). They hold no state of their own, so they hand
	 * the key back up here and reuse the same autosave as the tab's own switch.
	 * @param {string} key     Settings key to persist.
	 * @param {*}      value   New value for that key.
	 * @param {string} message Success toast text.
	 */
	const persistSetting = (key, value, message) =>
		updateFeature({ [key]: value }, message);

	/**
	 * Validate settings using API
	 */
	const validateSettings = async () => {
		try {
			setIsValidating(true);
			const response = await apiFetch({
				path: '/thinkrank/v1/site-identity/validate',
				method: 'POST',
				data: {
					settings,
					context_type: 'site',
					tab_context: activeSubSection,
				},
			});

			if (response.success) {
				setValidationResults(response.data);

				const hasErrors =
					response.data.issues && response.data.issues.length > 0;
				const hasWarnings =
					response.data.warnings && response.data.warnings.length > 0;

				let status = 'success';
				let message = sprintf(
					/* translators: %s: settings section name. */
					__('%s validation completed successfully!', 'thinkrank'),
					getSubSectionDisplayName()
				);

				if (hasErrors) {
					status = 'error';
					message = sprintf(
						/* translators: %s: settings section name. */
						__(
							'%s validation found errors that need to be fixed.',
							'thinkrank'
						),
						getSubSectionDisplayName()
					);
				} else if (hasWarnings) {
					status = 'warning';
					message = sprintf(
						/* translators: %s: settings section name. */
						__(
							'%s validation completed with warnings.',
							'thinkrank'
						),
						getSubSectionDisplayName()
					);
				}

				notify[status](message);
			}
		} catch (error) {
			logger.error('Validation error:', error);
			notify.error(
				sprintf(
					/* translators: %s: settings section name. */
					__('Failed to validate %s settings.', 'thinkrank'),
					getSubSectionDisplayName()
				)
			);
		} finally {
			setIsValidating(false);
		}
	};

	/**
	 * Optimize site identity using rule-based optimization
	 * @param focus
	 */
	const optimizeIdentity = async (focus = 'all') => {
		try {
			setIsOptimizing(true);
			const response = await apiFetch({
				path: '/thinkrank/v1/site-identity/optimize',
				method: 'POST',
				data: {
					identity_data: settings,
					options: { focus },
				},
			});

			if (response.success) {
				// Store results per tab/section
				setOptimizationResults((prev) => ({
					...prev,
					[focus]: response.data,
				}));
				notify.success(
					sprintf(
						/* translators: %s: settings section name. */
						__(
							'%s optimization completed successfully!',
							'thinkrank'
						),
						getSubSectionDisplayName()
					)
				);

				// Scroll to results card
				scrollToResults();
			}
		} catch (error) {
			logger.error('Optimization error:', error);
			notify.error(
				sprintf(
					/* translators: %s: settings section name. */
					__('Failed to optimize %s. Please try again.', 'thinkrank'),
					getSubSectionDisplayName()
				)
			);
		} finally {
			setIsOptimizing(false);
		}
	};

	/**
	 * Optimize site information using AI
	 */
	const optimizeBasicInfo = async () => {
		try {
			setIsAiOptimizing(true);
			const response = await apiFetch({
				path: '/thinkrank/v1/site-identity/ai-optimize-info',
				method: 'POST',
				data: {
					site_data: {
						site_name: settings.site_name || '',
						site_description: settings.site_description || '',
						tagline: settings.tagline || '',
						default_meta_description:
							settings.default_meta_description || '',
					},
					business_type: 'website',
					target_audience: 'general',
					tone: 'professional',
				},
			});

			if (response.success) {
				setAiOptimizationResults(response.data);

				// Show success message with AI insights
				let message = sprintf(
					/* translators: %s: settings section name. */
					__('%s AI optimization completed!', 'thinkrank'),
					getSubSectionDisplayName()
				);
				if (response.data.ai_model) {
					message += ` (${response.data.ai_model})`;
				}
				if (
					response.data.suggestions &&
					response.data.suggestions.length > 0
				) {
					message += ` ${response.data.suggestions.length} ${__('suggestions generated.', 'thinkrank')}`;
				}

				notify.success(message);

				// Scroll to results card
				scrollToResults();
			}
		} catch (error) {
			logger.error('AI optimization error:', error);

			// Handle specific AI-related errors
			let errorMessage = sprintf(
				/* translators: %s: settings section name. */
				__('Failed to optimize %s information.', 'thinkrank'),
				getSubSectionDisplayName()
			);
			if (error.message && error.message.includes('API key')) {
				errorMessage = __(
					'AI optimization requires an API key. Please configure your OpenAI or Claude API key in ThinkRank settings.',
					'thinkrank'
				);
			} else if (error.message && error.message.includes('rate limit')) {
				errorMessage = __(
					'AI service rate limit reached. Please try again in a few minutes.',
					'thinkrank'
				);
			} else if (error.code === 'missing_field' && error.message) {
				// Surface the specific field the backend rejected.
				errorMessage = error.message;
			}

			notify.error(errorMessage);
		} finally {
			setIsAiOptimizing(false);
		}
	};

	/**
	 * Scroll to optimization results card
	 */
	const scrollToResults = () => {
		// Small delay to ensure the results card is rendered
		setTimeout(() => {
			const resultsCard = document.querySelector(
				'.site-identity-optimization-results'
			);
			if (resultsCard) {
				resultsCard.scrollIntoView({
					behavior: 'smooth',
					block: 'start',
					inline: 'nearest',
				});
			}
		}, 100);
	};

	/**
	 * Optimize hero content using AI
	 */
	const optimizeHeroContent = async () => {
		try {
			setIsOptimizing(true);
			const response = await apiFetch({
				path: '/thinkrank/v1/site-identity/ai-optimize-hero',
				method: 'POST',
				data: {
					hero_data: {
						hero_title: settings.hero_title || '',
						hero_subtitle: settings.hero_subtitle || '',
						hero_cta_text: settings.hero_cta_text || '',
						hero_cta_url: settings.hero_cta_url || '',
					},
					context: {
						site_name:
							settings.site_name ||
							document.title
								.replace(' ‹ ', ' - ')
								.split(' - ')[0] ||
							'Your Website',
						site_url: window.location.origin,
						business_type: settings.business_type || 'website',
						site_description: settings.site_description || '',
					},
					business_type: settings.business_type || 'website',
					target_audience: 'general',
					tone: 'professional',
				},
			});

			if (response.success) {
				// Store results for optimization display
				setOptimizationResults((prev) => ({
					...prev,
					hero_section: response.data,
				}));

				// Apply optimized data to settings
				const optimizedData = response.data.optimized_data;
				if (optimizedData) {
					Object.keys(optimizedData).forEach((key) => {
						if (optimizedData[key]) {
							handleSettingChange(key, optimizedData[key]);
						}
					});
				}

				// Show success message with AI insights
				let message = sprintf(
					/* translators: %s: settings section name. */
					__('%s AI optimization completed!', 'thinkrank'),
					getSubSectionDisplayName()
				);
				if (response.data.ai_model) {
					message += ` (${response.data.ai_model})`;
				}
				if (
					response.data.suggestions &&
					response.data.suggestions.length > 0
				) {
					message += ` ${response.data.suggestions.length} ${__('suggestions generated.', 'thinkrank')}`;
				}

				notify.success(message);

				// Scroll to results card
				scrollToResults();
			}
		} catch (error) {
			logger.error('Hero AI optimization error:', error);

			// Handle specific AI-related errors
			let errorMessage = sprintf(
				/* translators: %s: settings section name. */
				__('Failed to optimize %s content.', 'thinkrank'),
				getSubSectionDisplayName()
			);
			if (error.message && error.message.includes('API key')) {
				errorMessage = __(
					'AI optimization requires an API key. Please configure your OpenAI or Claude API key in ThinkRank settings.',
					'thinkrank'
				);
			} else if (error.message && error.message.includes('rate limit')) {
				errorMessage = __(
					'AI service rate limit reached. Please try again in a few minutes.',
					'thinkrank'
				);
			}

			notify.error(errorMessage);
		} finally {
			setIsOptimizing(false);
		}
	};

	/**
	 * Determine optimization button type based on active section
	 */
	const getOptimizationButtonConfig = () => {
		switch (activeSubSection) {
			case 'basic-info':
				return {
					type: 'ai',
					label: __('AI Optimize', 'thinkrank'),
					loadingLabel: __('AI Optimizing…', 'thinkrank'),
					onClick: optimizeBasicInfo,
					isBusy: isAiOptimizing,
					// Site name is the only required input; empty description/
					// tagline are fine — the AI generates them.
					disabled:
						!settings.enabled ||
						isAiOptimizing ||
						!settings.site_name,
				};
			case 'title-formats':
				return {
					type: 'rule',
					label: __('Validate & Optimize Titles', 'thinkrank'),
					loadingLabel: __('Optimizing…', 'thinkrank'),
					onClick: () => optimizeIdentity('title_formats'),
					isBusy: isOptimizing,
					disabled: !settings.enabled || isOptimizing,
				};
			case 'breadcrumbs':
				return {
					type: 'rule',
					label: __('Validate & Optimize Breadcrumbs', 'thinkrank'),
					loadingLabel: __('Optimizing…', 'thinkrank'),
					onClick: () => optimizeIdentity('breadcrumbs'),
					isBusy: isOptimizing,
					disabled: !settings.enabled || isOptimizing,
				};
			case 'hero-section':
				return {
					type: 'ai',
					label: __('AI Optimize Hero', 'thinkrank'),
					loadingLabel: __('AI Optimizing…', 'thinkrank'),
					onClick: optimizeHeroContent,
					isBusy: isOptimizing,
					disabled: !settings.enabled || isOptimizing,
				};
			case 'local-seo':
				return {
					type: 'validate',
					label: __('Validate Business Info', 'thinkrank'),
					loadingLabel: __('Validating…', 'thinkrank'),
					onClick: validateSettings,
					isBusy: isValidating,
					disabled: !settings.enabled || isValidating,
				};
			default:
				return {
					type: 'rule',
					label: __('Validate & Optimize', 'thinkrank'),
					loadingLabel: __('Optimizing…', 'thinkrank'),
					onClick: () => optimizeIdentity('all'),
					isBusy: isOptimizing,
					disabled: !settings.enabled || isOptimizing,
				};
		}
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
					'Your Site Identity settings could not be read, so this screen has nothing to edit yet. Nothing has changed. Retry to load them.',
					'thinkrank'
				)}
			/>
		);
	}

	return (
		<div className="thinkrank-site-identity-tab">
			<SettingsCard
				title={__('Site Identity Settings', 'thinkrank')}
				enabled={settings.enabled}
				onToggle={(value) =>
					updateFeature(
						{ enabled: value },
						value
							? __('Site Identity is on.', 'thinkrank')
							: __('Site Identity is off.', 'thinkrank')
					)
				}
				toggleLabel={__('Enable Site Identity', 'thinkrank')}
				toggleBusy={isTogglingFeature}
				optimizationButtons={[
					// Show Validate button for tabs with form inputs
					[
						'basic-info',
						'title-formats',
						'breadcrumbs',
						'hero-section',
						'local-seo',
					].includes(activeSubSection) && (
						<OptimizationButton
							key="validate"
							type="validate"
							onClick={validateSettings}
							isBusy={isValidating}
							disabled={!settings.enabled || isValidating}
						/>
					),
					// Main optimization button
					(() => {
						const buttonConfig = getOptimizationButtonConfig();
						return (
							<OptimizationButton
								key="optimize"
								type={buttonConfig.type}
								label={buttonConfig.label}
								loadingLabel={buttonConfig.loadingLabel}
								onClick={buttonConfig.onClick}
								isBusy={buttonConfig.isBusy}
								disabled={buttonConfig.disabled}
							/>
						);
					})(),
				].filter(Boolean)}
				showDisabledWarning={true}
				disabledWarningMessage={__(
					'Site identity features are disabled. Enable to configure title formats, breadcrumbs, and robots.txt.',
					'thinkrank'
				)}
			>
				{/* Content based on active subsection */}
				{(() => {
					// Map activeSubSection to tab names for compatibility
					const sectionMap = {
						'basic-info': 'basic',
						'title-formats': 'titles',
						breadcrumbs: 'breadcrumbs',
						'hero-section': 'hero',
						'local-seo': 'local',
						'robots-txt': 'robots',
					};

					const tabName = sectionMap[activeSubSection] || 'basic';

					switch (tabName) {
						case 'basic':
							return (
								<SiteInformation
									settings={settings}
									handleSettingChange={handleSettingChange}
								/>
							);

						case 'titles':
							return (
								<TitleFormats
									settings={settings}
									handleSettingChange={handleSettingChange}
								/>
							);

						case 'breadcrumbs':
							return (
								<BreadcrumbSettings
									settings={settings}
									handleSettingChange={handleSettingChange}
									onPersistSetting={persistSetting}
									isPersistingSetting={isTogglingFeature}
								/>
							);

						case 'hero':
							return (
								<HeroSection
									settings={settings}
									handleSettingChange={handleSettingChange}
								/>
							);

						case 'local':
							return (
								<LocalSEO
									settings={settings}
									handleSettingChange={handleSettingChange}
									onPersistSetting={persistSetting}
									isPersistingSetting={isTogglingFeature}
								/>
							);

						default:
							return null;
					}
				})()}

				{/* Validation Results */}
				<SiteIdentityValidation
					validationResults={validationResults}
					activeSubSection={activeSubSection}
				/>

				{/* Optimization Results */}
				<SiteIdentityOptimization
					optimizationResults={optimizationResults}
					activeSubSection={activeSubSection}
					settings={settings}
					handleSettingChange={handleSettingChange}
					setOptimizationResults={setOptimizationResults}
				/>

				{/* AI Optimization Results */}
				{aiOptimizationResults && (
					<Card padding={false} className="tr-mt-4">
						<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary tr-flex tr-items-center tr-justify-between">
							<div>
								<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
									{__(
										'AI Site Information Optimization',
										'thinkrank'
									)}
								</h3>
								{aiOptimizationResults.ai_model && (
									<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
										{__('Generated by:', 'thinkrank')}{' '}
										{aiOptimizationResults.ai_model}
									</p>
								)}
							</div>
							{aiOptimizationResults.score !== undefined && (
								<span
									className={`tr-text-xs tr-font-bold tr-px-2 tr-py-1 tr-rounded tr-text-white ${
										aiOptimizationResults.score >= 80
											? 'tr-bg-success'
											: aiOptimizationResults.score >= 60
												? 'tr-bg-warning'
												: 'tr-bg-danger'
									}`}
								>
									{__('Score:', 'thinkrank')}{' '}
									{aiOptimizationResults.score}/100
								</span>
							)}
						</div>
						<div className="tr-p-6 tr-flex tr-flex-col tr-gap-4">
							{aiOptimizationResults.analysis && (
								<div>
									<h4 className="tr-text-xs tr-font-semibold tr-text-secondary tr-m-0 tr-mb-2">
										{__('AI Analysis', 'thinkrank')}
									</h4>
									<p className="tr-text-sm tr-text-secondary tr-italic tr-bg-brand-bg tr-border tr-border-brand tr-rounded tr-p-3 tr-m-0">
										{aiOptimizationResults.analysis}
									</p>
								</div>
							)}

							{aiOptimizationResults.suggestions &&
								aiOptimizationResults.suggestions.length >
									0 && (
									<div>
										<h4 className="tr-text-xs tr-font-semibold tr-text-secondary tr-m-0 tr-mb-2">
											{__('AI Suggestions', 'thinkrank')}
										</h4>
										<ul className="tr-m-0 tr-pl-5 tr-flex tr-flex-col tr-gap-1">
											{aiOptimizationResults.suggestions.map(
												(suggestion, index) => (
													<li
														key={index}
														className="tr-text-sm tr-text-secondary"
													>
														{suggestion}
													</li>
												)
											)}
										</ul>
									</div>
								)}

							{aiOptimizationResults.optimized_data &&
								Object.keys(
									aiOptimizationResults.optimized_data
								).length > 0 && (
									<div>
										<h4 className="tr-text-xs tr-font-semibold tr-text-secondary tr-m-0 tr-mb-2">
											{__(
												'AI-Generated Content',
												'thinkrank'
											)}
										</h4>
										<div className="tr-bg-white tr-border tr-border-secondary tr-rounded tr-p-3 tr-flex tr-flex-col tr-gap-2">
											{Object.keys(
												aiOptimizationResults.optimized_data
											).map((key) => (
												<div key={key}>
													<span className="tr-text-xs tr-font-semibold tr-text-secondary">
														{key
															.replace(/_/g, ' ')
															.replace(
																/\b\w/g,
																(l) =>
																	l.toUpperCase()
															)}
														:
													</span>
													<span className="tr-text-sm tr-text-secondary tr-ml-1">
														{
															aiOptimizationResults
																.optimized_data[
																key
															]
														}
													</span>
												</div>
											))}
										</div>
									</div>
								)}

							{aiOptimizationResults.optimized_data && (
								<div className="tr-flex tr-gap-2">
									<Button
										variant="primary"
										size="md"
										onClick={() => {
											Object.keys(
												aiOptimizationResults.optimized_data
											).forEach((key) => {
												if (
													settings.hasOwnProperty(key)
												) {
													handleSettingChange(
														key,
														aiOptimizationResults
															.optimized_data[key]
													);
												}
											});
											notify.success(
												sprintf(
													/* translators: %s: settings section name. */
													__(
														'%s AI optimization suggestions applied successfully!',
														'thinkrank'
													),
													getSubSectionDisplayName()
												)
											);
											setAiOptimizationResults(null);
										}}
									>
										{__(
											'Apply AI Suggestions',
											'thinkrank'
										)}
									</Button>
									<Button
										variant="secondary"
										size="md"
										onClick={() =>
											setAiOptimizationResults(null)
										}
									>
										{__('Dismiss', 'thinkrank')}
									</Button>
								</div>
							)}
						</div>
					</Card>
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

export default SiteIdentityTab;
