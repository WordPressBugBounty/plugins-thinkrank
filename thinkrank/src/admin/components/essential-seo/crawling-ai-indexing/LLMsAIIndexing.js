/**
 * LLMs.txt AI Indexing Component
 *
 * Redesigned — uses plugin UI components and Tailwind classes.
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	SkeletonCard,
	Card,
	Input,
	Textarea,
	Select,
	Toggle,
	Button,
	Container,
	SettingsLoadError,
} from '../../ui';
import apiFetch from '@wordpress/api-fetch';
import SettingsCard from '../../common/SettingsCard';
import SectionHeader from '../../common/SectionHeader';
import FormField from '../../common/FormField';
import OptimizationButton from '../../common/OptimizationButton';
import LLMsTxtValidation from './LLMsTxtValidation';
import ModelBadge from '../../shared/ModelBadge';
import useAutosaveSettings from '../../../hooks/useAutosaveSettings';
import logger from '../../../../shared/utils/logger';

const TARGET_AUDIENCE_OPTIONS = [
	{ value: 'general', label: __('General Audience', 'thinkrank') },
	{ value: 'beginners', label: __('Beginners', 'thinkrank') },
	{ value: 'professionals', label: __('Professionals', 'thinkrank') },
	{ value: 'experts', label: __('Experts', 'thinkrank') },
	{ value: 'students', label: __('Students', 'thinkrank') },
	{ value: 'business_owners', label: __('Business Owners', 'thinkrank') },
	{ value: 'developers', label: __('Developers', 'thinkrank') },
	{ value: 'marketers', label: __('Marketers', 'thinkrank') },
	{ value: 'content_creators', label: __('Content Creators', 'thinkrank') },
	{
		value: 'small_business_owners',
		label: __('Small Business Owners', 'thinkrank'),
	},
];

const BUSINESS_TYPE_OPTIONS = [
	{ value: 'website', label: __('Website', 'thinkrank') },
	{ value: 'blog', label: __('Blog', 'thinkrank') },
	{ value: 'business', label: __('Business/Corporate', 'thinkrank') },
	{ value: 'ecommerce', label: __('E-commerce/Online Store', 'thinkrank') },
	{ value: 'portfolio', label: __('Portfolio/Showcase', 'thinkrank') },
	{ value: 'nonprofit', label: __('Non-profit Organization', 'thinkrank') },
	{ value: 'educational', label: __('Educational Institution', 'thinkrank') },
	{ value: 'news', label: __('News/Media', 'thinkrank') },
	{ value: 'community', label: __('Community/Forum', 'thinkrank') },
	{ value: 'saas', label: __('Software as a Service', 'thinkrank') },
	{ value: 'agency', label: __('Agency/Service Provider', 'thinkrank') },
	{ value: 'other', label: __('Other', 'thinkrank') },
];

const DELIVERY_MODE_OPTIONS = [
	{ value: 'auto', label: __('Automatic (recommended)', 'thinkrank') },
	{
		value: 'static',
		label: __('Static file in the site root', 'thinkrank'),
	},
	{ value: 'dynamic', label: __('Served by WordPress', 'thinkrank') },
];

const IconGlobe = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<circle cx="12" cy="12" r="10" />
		<line x1="2" y1="12" x2="22" y2="12" />
		<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
	</svg>
);

const IconBriefcase = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<rect x="2" y="7" width="20" height="14" rx="2" />
		<path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" />
	</svg>
);

const IconCode = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<polyline points="16 18 22 12 16 6" />
		<polyline points="8 6 2 12 8 18" />
	</svg>
);

const IconList = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<line x1="8" y1="6" x2="21" y2="6" />
		<line x1="8" y1="12" x2="21" y2="12" />
		<line x1="8" y1="18" x2="21" y2="18" />
		<line x1="3" y1="6" x2="3.01" y2="6" />
		<line x1="3" y1="12" x2="3.01" y2="12" />
		<line x1="3" y1="18" x2="3.01" y2="18" />
	</svg>
);

const IconCpu = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<rect x="4" y="4" width="16" height="16" rx="2" />
		<rect x="9" y="9" width="6" height="6" />
		<line x1="9" y1="1" x2="9" y2="4" />
		<line x1="15" y1="1" x2="15" y2="4" />
		<line x1="9" y1="20" x2="9" y2="23" />
		<line x1="15" y1="20" x2="15" y2="23" />
		<line x1="20" y1="9" x2="23" y2="9" />
		<line x1="20" y1="14" x2="23" y2="14" />
		<line x1="1" y1="9" x2="4" y2="9" />
		<line x1="1" y1="14" x2="4" y2="14" />
	</svg>
);

const IconFileText = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
		<polyline points="14 2 14 8 20 8" />
		<line x1="16" y1="13" x2="8" y2="13" />
		<line x1="16" y1="17" x2="8" y2="17" />
		<polyline points="10 9 9 9 8 9" />
	</svg>
);

const IconExternalLink = () => (
	<svg
		width="12"
		height="12"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
		<polyline points="15 3 21 3 21 9" />
		<line x1="10" y1="14" x2="21" y2="3" />
	</svg>
);

/**
 * Shorthand for error logging with LLMs.txt context
 * @param context
 * @param error
 */
const logError = (context, error) =>
	logger.error(`[LLMs.txt] ${context}:`, error);

/**
 * Helper function to extract user-friendly error messages
 * @param error
 */
const getErrorMessage = (error) => {
	if (error && error.code && error.message) {
		switch (error.code) {
			case 'rate_limit_exceeded':
				return {
					message: __(
						'Too many requests. Please wait a few minutes before trying again.',
						'thinkrank'
					),
					status: 'warning',
				};
			case 'rest_forbidden':
				return {
					message: __(
						'You do not have permission to perform this action.',
						'thinkrank'
					),
					status: 'error',
				};
			case 'missing_field':
				return {
					// translators: %s: name of the missing required field
					message: sprintf(
						__('Required field is missing: %s', 'thinkrank'),
						error.message.replace(
							/Required field '(.+)' is missing or empty/,
							'$1'
						)
					),
					status: 'error',
				};
			case 'invalid_input':
				return {
					message: __(
						'Invalid input provided. Please check your form data and try again.',
						'thinkrank'
					),
					status: 'error',
				};
			case 'validation_failed':
				return {
					message: __(
						'Form validation failed. Please check the required fields and try again.',
						'thinkrank'
					),
					status: 'error',
				};
			case 'generation_failed':
				return {
					message: __(
						'Content generation failed. Please check your input and try again.',
						'thinkrank'
					),
					status: 'error',
				};
			case 'ai_optimization_failed':
				return {
					message:
						error.message ||
						__(
							'AI optimization failed. Please check your API configuration and try again.',
							'thinkrank'
						),
					status: 'error',
				};
			case 'qm_fatal':
				return {
					message: __(
						'A system error occurred. Please check the plugin configuration and try again.',
						'thinkrank'
					),
					status: 'error',
				};
			default:
				return {
					message:
						error.message ||
						__(
							'An unexpected error occurred. Please try again.',
							'thinkrank'
						),
					status: 'error',
				};
		}
	}
	if (error instanceof Error) {
		return {
			message:
				error.message ||
				__(
					'An unexpected error occurred. Please try again.',
					'thinkrank'
				),
			status: 'error',
		};
	}
	if (error && error.error) {
		return { message: error.error, status: 'error' };
	}
	return {
		message: __(
			'An unexpected error occurred. Please try again.',
			'thinkrank'
		),
		status: 'error',
	};
};

/**
 * LLMs.txt AI Indexing Component
 */
const LLMsAIIndexing = () => {
	const [isLoading, setIsLoading] = useState(true);
	// True once a settings GET has failed. The form is not rendered while it is
	// set, so the defaults object can never be saved over stored values (#390).
	const [loadFailed, setLoadFailed] = useState(false);
	const [isSaving, setIsSaving] = useState(false);
	const [isGenerating, setIsGenerating] = useState(false);
	const [isAiOptimizing, setIsAiOptimizing] = useState(false);
	const [isValidating, setIsValidating] = useState(false);
	const [settings, setSettings] = useState({});
	const [hasChanges, setHasChanges] = useState(false);
	const [notice, setNotice] = useState(null);
	const [fileStatus, setFileStatus] = useState({});
	const [validationResults, setValidationResults] = useState(null);
	const [optimizationResults, setOptimizationResults] = useState(null);
	const [optimizationHistory, setOptimizationHistory] = useState([]);

	useEffect(() => {
		loadOverviewData();
	}, []);

	const loadOverviewData = async () => {
		try {
			setIsLoading(true);
			const response = await apiFetch({
				path: '/thinkrank/v1/llms-txt/overview',
				method: 'GET',
			});
			if (response.success && response.data) {
				setSettings(response.data.settings || getDefaultSettings());
				setFileStatus(response.data.file_status || {});
				setOptimizationHistory(
					response.data.optimization_history || []
				);
			} else {
				await loadSettings();
				await loadFileStatus();
				await loadOptimizationHistory();
			}
		} catch (error) {
			logError('Overview data load error', error);
			try {
				await loadSettings();
				await loadFileStatus();
				await loadOptimizationHistory();
			} catch (fallbackError) {
				// Deliberately no setSettings(getDefaultSettings()) here: the
				// form would look like the user's own configuration, and the
				// next Save would post the defaults over it (#390).
				logError('Fallback load error', fallbackError);
				setLoadFailed(true);
				setNotice({
					status: 'error',
					message: __(
						'Could not load your LLMs.txt settings. Nothing has been changed. Retry before editing.',
						'thinkrank'
					),
				});
			}
		} finally {
			setIsLoading(false);
		}
	};

	const loadSettings = async () => {
		try {
			setIsLoading(true);
			const response = await apiFetch({
				path: '/thinkrank/v1/llms-txt/settings?context_type=site',
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
					response.error || 'Failed to load LLMs.txt settings'
				);
			}
			await loadOptimizationHistory();
		} catch (error) {
			// Deliberately no setSettings(getDefaultSettings()) here (#390).
			logError('Settings load error', error);
			setLoadFailed(true);
			setNotice({
				status: 'error',
				message: __(
					'Could not load your LLMs.txt settings. Nothing has been changed. Retry before editing.',
					'thinkrank'
				),
			});
		} finally {
			setIsLoading(false);
		}
	};

	const loadOptimizationHistory = async (limit = 5) => {
		try {
			const response = await apiFetch({
				path: `/thinkrank/v1/llms-txt/optimization-results?limit=${limit}`,
				method: 'GET',
			});
			if (response.success && response.data) {
				setOptimizationHistory(response.data);
			}
		} catch (error) {
			logError('Failed to load optimization history', error);
		}
	};

	const loadFileStatus = async () => {
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/llms-txt/status',
				method: 'GET',
			});
			if (response.success) {
				setFileStatus(response.data);
			}
		} catch (error) {
			logError('Status load error', error);
		}
	};

	const getDefaultSettings = () => ({
		enabled: true,
		site_name: '',
		website_description: '',
		key_features: '',
		target_audience: 'general',
		business_type: 'website',
		technical_stack: 'WordPress',
		development_approach: '',
		setup_instructions: '',
		ai_context_custom: '',
		auto_generate: false,
		delivery_mode: 'auto',
		documentation_links: '',
		technical_links: '',
		optional_links: '',
		custom_sections: '',
	});

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
				path: '/thinkrank/v1/llms-txt/settings',
				method: 'POST',
				data: { settings, context_type: 'site' },
			});
			if (response.success) {
				setHasChanges(false);
				// A delivery-mode switch that could not move the published
				// document — or one that landed on a server serving the file
				// without a character set — still saves, but comes back with an
				// explanation. Show it rather than an unqualified success.
				const partial =
					response.delivery_switch_failed === true ||
					response.delivery_warning === true ||
					response.file_unpublished === false;
				setNotice({
					status: partial ? 'warning' : 'success',
					message: partial
						? response.message
						: __(
								'LLMs.txt settings saved successfully!',
								'thinkrank'
							),
				});
				await loadFileStatus();
			} else {
				const errorInfo = getErrorMessage(response);
				setNotice({
					status: errorInfo.status,
					message: errorInfo.message,
				});
			}
		} catch (error) {
			logError('Settings save error', error);
			const errorInfo = getErrorMessage(error);
			setNotice({ status: errorInfo.status, message: errorInfo.message });
		} finally {
			setIsSaving(false);
		}
	};

	// The feature switch IS the save: flipping it persists immediately and
	// rolls back if the request fails. The other fields — auto-generate
	// included — stay on the Save Settings button.
	const { update: updateFeature, isSaving: isTogglingFeature } =
		useAutosaveSettings({
			settings,
			setSettings,
			save: async (next) => {
				const response = await apiFetch({
					path: '/thinkrank/v1/llms-txt/settings',
					method: 'POST',
					data: { settings: next, context_type: 'site' },
				});

				if (!response.success) {
					throw new Error(getErrorMessage(response).message);
				}
			},
			logLabel: 'LLMs.txt',
		});

	const generateLLMsTxt = async () => {
		try {
			setIsGenerating(true);
			setNotice(null);

			const requiredFields = [
				{
					key: 'website_description',
					label: __('Website Description', 'thinkrank'),
				},
				{ key: 'key_features', label: __('Key Features', 'thinkrank') },
				{
					key: 'target_audience',
					label: __('Target Audience', 'thinkrank'),
				},
			];
			const missingFields = requiredFields.filter(
				(f) => !settings[f.key] || settings[f.key].trim() === ''
			);
			if (missingFields.length > 0) {
				setNotice({
					status: 'error',
					// translators: %s: comma-separated list of required field labels
					message: sprintf(
						__(
							'Please fill in the following required fields: %s',
							'thinkrank'
						),
						missingFields.map((f) => f.label).join(', ')
					),
				});
				return;
			}

			const response = await apiFetch({
				path: '/thinkrank/v1/llms-txt/generate',
				method: 'POST',
				data: {
					user_input: {
						site_name: settings.site_name,
						website_description: settings.website_description,
						key_features: settings.key_features,
						target_audience: settings.target_audience,
						business_type: settings.business_type,
						technical_stack: settings.technical_stack,
						development_approach: settings.development_approach,
						setup_instructions: settings.setup_instructions,
						ai_context_custom: settings.ai_context_custom,
						documentation_links: settings.documentation_links,
						technical_links: settings.technical_links,
						optional_links: settings.optional_links,
						custom_sections: settings.custom_sections,
					},
					save_to_file: true,
				},
			});

			if (response.success) {
				setNotice({
					status: 'success',
					message: __(
						'LLMs.txt generated successfully! File saved to website root.',
						'thinkrank'
					),
				});
				loadFileStatus();
			} else {
				const errorInfo = getErrorMessage(response);
				setNotice({
					status: errorInfo.status,
					message: errorInfo.message,
				});
			}
		} catch (error) {
			logError('Generation error', error);
			const errorInfo = getErrorMessage(error);
			setNotice({ status: errorInfo.status, message: errorInfo.message });
		} finally {
			setIsGenerating(false);
		}
	};

	const aiOptimizeLLMsTxt = async () => {
		try {
			setIsAiOptimizing(true);
			setNotice(null);

			const requiredFields = [
				{ key: 'site_name', label: __('Website Title', 'thinkrank') },
				{
					key: 'website_description',
					label: __('Website Description', 'thinkrank'),
				},
				{ key: 'key_features', label: __('Key Features', 'thinkrank') },
			];
			const missingFields = requiredFields.filter(
				(f) => !settings[f.key] || settings[f.key].trim() === ''
			);
			if (missingFields.length > 0) {
				setNotice({
					status: 'error',
					// translators: %s: comma-separated list of required field labels
					message: sprintf(
						__(
							'Please fill in the following required fields before AI optimization: %s',
							'thinkrank'
						),
						missingFields.map((f) => f.label).join(', ')
					),
				});
				return;
			}

			const response = await apiFetch({
				path: '/thinkrank/v1/llms-txt/ai-optimize',
				method: 'POST',
				data: {
					website_data: {
						site_name: settings.site_name,
						website_description: settings.website_description,
						key_features: settings.key_features,
						target_audience: settings.target_audience,
						business_type: settings.business_type,
						technical_stack: settings.technical_stack,
						development_approach: settings.development_approach,
					},
					tone: 'professional',
				},
			});

			if (response.success) {
				const optimizedData = response.data.optimized_data;
				setSettings((prev) => ({
					...prev,
					site_name: optimizedData.site_name || prev.site_name,
					website_description:
						optimizedData.project_overview ||
						prev.website_description,
					key_features:
						optimizedData.key_features || prev.key_features,
					development_approach:
						optimizedData.development_guidelines ||
						prev.development_approach,
					ai_context_custom:
						optimizedData.ai_context || prev.ai_context_custom,
				}));
				setHasChanges(true);
				setOptimizationResults({
					analysis: response.data.analysis,
					suggestions: response.data.suggestions,
					improvements_made: response.data.improvements_made,
					score: response.data.score,
					provider: response.data.provider,
					model: response.data.model,
				});
				setNotice({
					status: 'success',
					message: __(
						'AI optimization completed! Review the optimized content and insights below.',
						'thinkrank'
					),
				});
				await loadOptimizationHistory();
			} else {
				const errorInfo = getErrorMessage(response);
				setNotice({
					status: errorInfo.status,
					message: errorInfo.message,
				});
			}
		} catch (error) {
			logError('AI optimization error', error);
			const errorInfo = getErrorMessage(error);
			setNotice({ status: errorInfo.status, message: errorInfo.message });
		} finally {
			setIsAiOptimizing(false);
		}
	};

	const validateLLMsTxt = async () => {
		try {
			setIsValidating(true);
			setNotice(null);
			const response = await apiFetch({
				path: '/thinkrank/v1/llms-txt/validate',
				method: 'POST',
				data: {
					settings: {
						enabled: settings.enabled,
						site_name: settings.site_name,
						website_description: settings.website_description,
						key_features: settings.key_features,
						target_audience: settings.target_audience,
						business_type: settings.business_type,
						technical_stack: settings.technical_stack,
						development_approach: settings.development_approach,
						setup_instructions: settings.setup_instructions,
						documentation_links: settings.documentation_links,
						technical_links: settings.technical_links,
						optional_links: settings.optional_links,
						custom_sections: settings.custom_sections,
					},
				},
			});

			if (response.success) {
				setValidationResults(response.data);
				setNotice({
					status: 'success',
					message: __(
						'Validation completed successfully!',
						'thinkrank'
					),
				});
			} else {
				const errorInfo = getErrorMessage(response);
				setNotice({
					status: errorInfo.status,
					message: errorInfo.message,
				});
			}
		} catch (error) {
			logError('Validation error', error);
			const errorInfo = getErrorMessage(error);
			setNotice({ status: errorInfo.status, message: errorInfo.message });
		} finally {
			setIsValidating(false);
		}
	};

	const getOptimizationButtonConfig = () => [
		<OptimizationButton
			key="validate"
			type="validate"
			label={__('Validate', 'thinkrank')}
			loadingLabel={__('Validating…', 'thinkrank')}
			onClick={validateLLMsTxt}
			isBusy={isValidating}
			disabled={!settings.enabled || isValidating}
		/>,
		<OptimizationButton
			key="ai-optimize"
			type="ai"
			label={__('AI Optimize', 'thinkrank')}
			loadingLabel={__('AI Optimizing…', 'thinkrank')}
			onClick={aiOptimizeLLMsTxt}
			isBusy={isAiOptimizing}
			disabled={!settings.enabled || isAiOptimizing}
		/>,
		<OptimizationButton
			key="generate"
			type="generate"
			label={__('Generate File', 'thinkrank')}
			loadingLabel={__('Generating…', 'thinkrank')}
			onClick={generateLLMsTxt}
			isBusy={isGenerating}
			disabled={!settings.enabled || isGenerating}
		/>,
	];

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
				onRetry={loadOverviewData}
				isRetrying={isLoading}
				message={__(
					'Your LLMs.txt settings could not be read, so this screen has nothing to edit yet. Nothing has changed. Retry to load them.',
					'thinkrank'
				)}
			/>
		);
	}

	const disabled = !settings.enabled;

	return (
		<div className="thinkrank-llms-txt-tab">
			<SettingsCard
				title={__('LLMs.txt Management', 'thinkrank')}
				enabled={settings.enabled}
				onToggle={(value) =>
					updateFeature(
						{ enabled: value },
						value
							? __('LLMs.txt is on.', 'thinkrank')
							: __('LLMs.txt is off.', 'thinkrank')
					)
				}
				toggleLabel={__('Enable LLMs.txt', 'thinkrank')}
				toggleBusy={isTogglingFeature}
				optimizationButtons={getOptimizationButtonConfig()}
				notice={notice}
				onNoticeRemove={() => setNotice(null)}
				showDisabledWarning={true}
				disabledWarningMessage={__(
					'LLMs.txt generation is disabled. Enable to configure website information and generate AI-friendly documentation.',
					'thinkrank'
				)}
			>
				<Container direction="column" gap={4}>
					{/* ── Website Information ───────────────────────────────── */}
					<Card padding={false}>
						<SectionHeader
							title={__('Website Information', 'thinkrank')}
							description={__(
								'Core details used to generate your LLMs.txt file',
								'thinkrank'
							)}
						/>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<FormField
								id="tr-llms-site-name"
								label={__('Website Title', 'thinkrank')}
								description={__(
									'The name of your website as it will appear in the LLMs.txt file',
									'thinkrank'
								)}
								disabled={disabled}
							>
								<Input
									id="tr-llms-site-name"
									value={settings.site_name || ''}
									onChange={(e) =>
										handleSettingChange(
											'site_name',
											e.target.value
										)
									}
									placeholder={__(
										'Your Website',
										'thinkrank'
									)}
									disabled={disabled}
								/>
							</FormField>
							<FormField
								id="tr-llms-desc"
								label={__('Website Description *', 'thinkrank')}
								description={__(
									'Comprehensive description of your website and its purpose (required)',
									'thinkrank'
								)}
								disabled={disabled}
								className="tr-pt-6"
							>
								<Textarea
									id="tr-llms-desc"
									value={settings.website_description || ''}
									onChange={(e) =>
										handleSettingChange(
											'website_description',
											e.target.value
										)
									}
									placeholder={__(
										'Describe what your website does, its main purpose, and key objectives…',
										'thinkrank'
									)}
									rows={4}
									disabled={disabled}
								/>
							</FormField>
							<FormField
								id="tr-llms-features"
								label={__('Key Features *', 'thinkrank')}
								description={__(
									'Main features and functionality of your website (required). One feature per line: a comma stays part of a feature, it does not start a new one.',
									'thinkrank'
								)}
								disabled={disabled}
								className="tr-pt-6"
							>
								<Textarea
									id="tr-llms-features"
									value={settings.key_features || ''}
									onChange={(e) =>
										handleSettingChange(
											'key_features',
											e.target.value
										)
									}
									placeholder={__(
										'One feature per line, e.g. Collect reviews from Trustpilot, Google and Etsy',
										'thinkrank'
									)}
									rows={3}
									disabled={disabled}
								/>
							</FormField>
						</Container>
					</Card>

					{/* ── Business Details ──────────────────────────────────── */}
					<Card padding={false}>
						<SectionHeader
							title={__('Business Details', 'thinkrank')}
							description={__(
								'Audience and business type details.',
								'thinkrank'
							)}
						/>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<FormField
								id="tr-llms-audience"
								label={__('Target Audience *', 'thinkrank')}
								description={__(
									'Primary audience for your website (required)',
									'thinkrank'
								)}
								disabled={disabled}
							>
								<Select
									id="tr-llms-audience"
									value={
										settings.target_audience || 'general'
									}
									options={TARGET_AUDIENCE_OPTIONS}
									onChange={(e) =>
										handleSettingChange(
											'target_audience',
											e.target.value
										)
									}
									disabled={disabled}
								/>
							</FormField>
							<FormField
								id="tr-llms-biz-type"
								label={__('Business Type', 'thinkrank')}
								description={__(
									'Type of website or business',
									'thinkrank'
								)}
								disabled={disabled}
								className="tr-pt-6"
							>
								<Select
									id="tr-llms-biz-type"
									value={settings.business_type || 'website'}
									options={BUSINESS_TYPE_OPTIONS}
									onChange={(e) =>
										handleSettingChange(
											'business_type',
											e.target.value
										)
									}
									disabled={disabled}
								/>
							</FormField>
						</Container>
					</Card>

					{/* ── Technical Information ─────────────────────────────── */}
					<Card padding={false}>
						<SectionHeader
							title={__('Technical Information', 'thinkrank')}
							description={__(
								'Stack, methodology, and setup details.',
								'thinkrank'
							)}
						/>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<FormField
								id="tr-llms-stack"
								label={__('Technical Stack', 'thinkrank')}
								description={__(
									'Technologies and frameworks used',
									'thinkrank'
								)}
								disabled={disabled}
							>
								<Textarea
									id="tr-llms-stack"
									value={settings.technical_stack || ''}
									onChange={(e) =>
										handleSettingChange(
											'technical_stack',
											e.target.value
										)
									}
									placeholder={__(
										'e.g., WordPress, PHP, JavaScript, MySQL, React…',
										'thinkrank'
									)}
									rows={2}
									disabled={disabled}
								/>
							</FormField>
							<FormField
								id="tr-llms-dev-approach"
								label={__('Development Approach', 'thinkrank')}
								description={__(
									'Development methodology and practices',
									'thinkrank'
								)}
								disabled={disabled}
								className="tr-pt-6"
							>
								<Textarea
									id="tr-llms-dev-approach"
									value={settings.development_approach || ''}
									onChange={(e) =>
										handleSettingChange(
											'development_approach',
											e.target.value
										)
									}
									placeholder={__(
										'e.g., Agile development, WordPress coding standards, responsive design…',
										'thinkrank'
									)}
									rows={3}
									disabled={disabled}
								/>
							</FormField>
							<FormField
								id="tr-llms-setup"
								label={__('Setup Instructions', 'thinkrank')}
								description={__(
									'Instructions for setting up or working with the project',
									'thinkrank'
								)}
								disabled={disabled}
								className="tr-pt-6"
							>
								<Textarea
									id="tr-llms-setup"
									value={settings.setup_instructions || ''}
									onChange={(e) =>
										handleSettingChange(
											'setup_instructions',
											e.target.value
										)
									}
									placeholder={__(
										'Provide setup instructions for developers or users…',
										'thinkrank'
									)}
									rows={3}
									disabled={disabled}
								/>
							</FormField>
						</Container>
					</Card>

					{/* ── LLMs.txt Structured Content ───────────────────────── */}
					<Card padding={false}>
						<SectionHeader
							title={__(
								'LLMs.txt Structured Content',
								'thinkrank'
							)}
							description={__(
								'Control structured sections of your LLMs.txt file. Format: [Link Title](URL): Description',
								'thinkrank'
							)}
						/>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<FormField
								id="tr-llms-doc-links"
								label={__('Documentation Links', 'thinkrank')}
								description={__(
									'Links to documentation, guides, and important pages. Format: [Title](URL): Description',
									'thinkrank'
								)}
								disabled={disabled}
							>
								<Textarea
									id="tr-llms-doc-links"
									value={settings.documentation_links || ''}
									onChange={(e) =>
										handleSettingChange(
											'documentation_links',
											e.target.value
										)
									}
									placeholder={
										'- [Getting Started Guide](https://yoursite.com/guide): Complete setup guide\n- [API Documentation](https://yoursite.com/api): REST API reference'
									}
									rows={4}
									disabled={disabled}
								/>
							</FormField>
							<FormField
								id="tr-llms-tech-links"
								label={__('Technical Links', 'thinkrank')}
								description={__(
									'Links to technical resources, code repositories, and development info',
									'thinkrank'
								)}
								disabled={disabled}
								className="tr-pt-6"
							>
								<Textarea
									id="tr-llms-tech-links"
									value={settings.technical_links || ''}
									onChange={(e) =>
										handleSettingChange(
											'technical_links',
											e.target.value
										)
									}
									placeholder={
										'- [GitHub Repository](https://github.com/user/repo): Source code and issues\n- [Technical Specs](https://yoursite.com/specs): Architecture details'
									}
									rows={3}
									disabled={disabled}
								/>
							</FormField>
							<FormField
								id="tr-llms-opt-links"
								label={__('Optional Links', 'thinkrank')}
								description={__(
									'Secondary resources that can be skipped for shorter context',
									'thinkrank'
								)}
								disabled={disabled}
								className="tr-pt-6"
							>
								<Textarea
									id="tr-llms-opt-links"
									value={settings.optional_links || ''}
									onChange={(e) =>
										handleSettingChange(
											'optional_links',
											e.target.value
										)
									}
									placeholder={
										'- [Blog Posts](https://yoursite.com/blog): Latest updates and insights\n- [Community Forum](https://yoursite.com/forum): User discussions'
									}
									rows={3}
									disabled={disabled}
								/>
							</FormField>
							<FormField
								id="tr-llms-custom"
								label={__('Custom Sections', 'thinkrank')}
								description={__(
									'Additional custom sections in markdown format',
									'thinkrank'
								)}
								disabled={disabled}
								className="tr-pt-6"
							>
								<Textarea
									id="tr-llms-custom"
									value={settings.custom_sections || ''}
									onChange={(e) =>
										handleSettingChange(
											'custom_sections',
											e.target.value
										)
									}
									placeholder={
										'## Examples\n\n- [Sample Project](https://yoursite.com/example): Working example\n\n## Resources\n\n- [External Tool](https://example.com): Useful external resource'
									}
									rows={5}
									disabled={disabled}
								/>
							</FormField>
						</Container>
					</Card>

					{/* ── AI Context ────────────────────────────────────────── */}
					<Card padding={false}>
						<SectionHeader
							title={__('AI Context (Internal Use)', 'thinkrank')}
							description={__(
								'This context helps AI optimization but is not included in the public LLMs.txt file',
								'thinkrank'
							)}
						/>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<FormField
								id="tr-llms-ai-context"
								label={__('Additional AI Context', 'thinkrank')}
								description={__(
									'Custom context information for AI assistants',
									'thinkrank'
								)}
								disabled={disabled}
							>
								<Textarea
									id="tr-llms-ai-context"
									value={settings.ai_context_custom || ''}
									onChange={(e) =>
										handleSettingChange(
											'ai_context_custom',
											e.target.value
										)
									}
									placeholder={__(
										'Provide specific context that would help AI assistants better understand your website…',
										'thinkrank'
									)}
									rows={3}
									disabled={disabled}
								/>
							</FormField>
							<FormField
								id="tr-llms-delivery-mode"
								label={__('Delivery Method', 'thinkrank')}
								description={__(
									'How /llms.txt reaches visitors. A static file is answered by the web server, which on Nginx sends no character-set and garbles accented characters and curly quotes. "Served by WordPress" keeps the document in WordPress and always responds as UTF-8. Automatic prefers a static file on Apache/LiteSpeed, checks what the published file is really served with, and moves to WordPress delivery if the character-set is missing.',
									'thinkrank'
								)}
								disabled={disabled}
							>
								<Select
									id="tr-llms-delivery-mode"
									value={settings.delivery_mode || 'auto'}
									options={DELIVERY_MODE_OPTIONS}
									onChange={(e) =>
										handleSettingChange(
											'delivery_mode',
											e.target.value
										)
									}
									disabled={disabled}
								/>
							</FormField>
							<div className="tr-pt-6">
								<Toggle
									id="llms-auto-generate"
									label={__('Auto-generate', 'thinkrank')}
									description={__(
										'Automatically regenerate llms.txt when settings change',
										'thinkrank'
									)}
									checked={settings.auto_generate ?? false}
									onChange={(value) =>
										handleSettingChange(
											'auto_generate',
											value
										)
									}
									disabled={disabled}
								/>
							</div>
						</Container>
					</Card>

					{/* ── File Status ───────────────────────────────────────── */}
					<Card padding={false}>
						<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
							<div className="tr-flex tr-items-center tr-gap-2">
								<span className="tr-text-tertiary">
									<IconFileText />
								</span>
								<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
									{__('Publish Status', 'thinkrank')}
								</h3>
							</div>
						</div>
						<div className="tr-p-5 tr-flex tr-flex-col tr-gap-3">
							<div className="tr-flex tr-items-center tr-gap-2">
								<span className="tr-text-sm tr-font-medium tr-text-secondary">
									{__('Status:', 'thinkrank')}
								</span>
								{fileStatus.published ? (
									<span className="tr-text-xs tr-font-semibold tr-px-2 tr-py-0.5 tr-rounded-full tr-bg-green-100 tr-text-green-700">
										{__('Generated', 'thinkrank')}
									</span>
								) : (
									<span className="tr-text-xs tr-font-semibold tr-px-2 tr-py-0.5 tr-rounded-full tr-bg-yellow-100 tr-text-yellow-700">
										{__('Not Generated', 'thinkrank')}
									</span>
								)}
							</div>
							<div className="tr-flex tr-items-center tr-gap-2">
								<span className="tr-text-sm tr-font-medium tr-text-secondary">
									{__('Delivery:', 'thinkrank')}
								</span>
								<span className="tr-text-sm tr-text-tertiary">
									{fileStatus.delivery_mode === 'dynamic'
										? __(
												'Served by WordPress as UTF-8',
												'thinkrank'
											)
										: __(
												'Static file in the site root',
												'thinkrank'
											)}
								</span>
							</div>
							{fileStatus.published && (
								<>
									<div className="tr-flex tr-items-center tr-gap-2">
										<span className="tr-text-sm tr-font-medium tr-text-secondary">
											{__('Last Modified:', 'thinkrank')}
										</span>
										<span className="tr-text-sm tr-text-tertiary">
											{fileStatus.last_modified
												? new Date(
														fileStatus.last_modified *
															1000
													).toLocaleString()
												: __('Unknown', 'thinkrank')}
										</span>
									</div>
									<div className="tr-flex tr-items-center tr-gap-2">
										<span className="tr-text-sm tr-font-medium tr-text-secondary">
											{__('Size:', 'thinkrank')}
										</span>
										<span className="tr-text-sm tr-text-tertiary">
											{fileStatus.file_size
												? `${Math.round((fileStatus.file_size / 1024) * 100) / 100} KB`
												: __('Unknown', 'thinkrank')}
										</span>
									</div>
									<a
										href={fileStatus.file_url}
										target="_blank"
										rel="noopener noreferrer"
										className="tr-inline-flex tr-items-center tr-gap-1.5 tr-text-sm tr-text-blue-600 hover:tr-text-blue-800 tr-no-underline"
									>
										<IconExternalLink />
										{__('View LLMs.txt', 'thinkrank')}
									</a>
								</>
							)}
							{fileStatus.delivery_warning && (
								<div className="tr-flex tr-items-start tr-gap-2 tr-p-3 tr-bg-yellow-50 tr-border tr-border-yellow-200 tr-rounded-lg tr-text-sm tr-text-yellow-800">
									<span className="tr-shrink-0">⚠</span>
									<span>{fileStatus.delivery_warning}</span>
								</div>
							)}
							{!fileStatus.writable &&
								fileStatus.delivery_mode !== 'dynamic' && (
									<div className="tr-flex tr-items-start tr-gap-2 tr-p-3 tr-bg-yellow-50 tr-border tr-border-yellow-200 tr-rounded-lg tr-text-sm tr-text-yellow-800">
										<span className="tr-shrink-0">⚠</span>
										<span>
											{__(
												'WordPress root directory is not writable, so no static llms.txt can be written. Set Delivery Method to "Served by WordPress" to publish without a file.',
												'thinkrank'
											)}
										</span>
									</div>
								)}
						</div>
					</Card>

					{/* LLMs.txt Validation Component */}
					<LLMsTxtValidation
						validationResults={validationResults}
						isValidating={isValidating}
						onValidate={validateLLMsTxt}
						settings={settings}
						fileStatus={fileStatus}
					/>

					{/* Save Settings Button */}
					<div className="tr-flex tr-items-center tr-justify-end tr-gap-3">
						{hasChanges && (
							<p className="tr-text-xs tr-text-tertiary tr-m-0">
								{__('You have unsaved changes.', 'thinkrank')}
							</p>
						)}
						<Button
							variant="primary"
							onClick={saveSettings}
							loading={isSaving}
							disabled={!hasChanges || isSaving}
						>
							{isSaving
								? __('Saving…', 'thinkrank')
								: __('Save Settings', 'thinkrank')}
						</Button>
					</div>
				</Container>

				{/* AI Optimization Results */}
				{optimizationResults &&
					optimizationResults.suggestions &&
					optimizationResults.suggestions.length > 0 && (
						<Card padding={false} className="tr-mt-4">
							<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
								<div className="tr-flex tr-items-center tr-justify-between">
									<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
										{__('AI Recommendations', 'thinkrank')}
									</h3>
									<ModelBadge
										provider={optimizationResults.provider}
										model={
											optimizationResults.model ||
											optimizationResults.ai_model
										}
									/>
								</div>
								<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
									{__(
										'Additional suggestions to enhance your website',
										'thinkrank'
									)}
								</p>
							</div>
							<div className="tr-p-5">
								<ul className="tr-flex tr-flex-col tr-gap-2 tr-m-0 tr-p-0 tr-list-none">
									{optimizationResults.suggestions.map(
										(suggestion, index) => (
											<li
												key={index}
												className="tr-text-sm tr-text-secondary tr-p-3 tr-bg-blue-50 tr-rounded-lg tr-border tr-border-blue-100"
											>
												{suggestion}
											</li>
										)
									)}
								</ul>
							</div>
						</Card>
					)}
			</SettingsCard>

			{/* Optimization History Section */}
			{optimizationHistory.length > 0 && (
				<div className="tr-mt-4">
					<Card padding={false}>
						<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
							<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
								{__('Recent AI Optimizations', 'thinkrank')}
							</h3>
							<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
								{__(
									'History of your LLMs.txt AI optimization results',
									'thinkrank'
								)}
							</p>
						</div>
						<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
							{optimizationHistory.map((result, index) => (
								<div
									key={index}
									className={`tr-pb-4 ${index < optimizationHistory.length - 1 ? 'tr-border-b tr-border-secondary' : ''}`}
								>
									<div className="tr-flex tr-items-center tr-justify-between tr-mb-3">
										<div className="tr-flex tr-items-center tr-gap-3">
											<span className="tr-text-sm tr-font-medium tr-text-blue-700">
												{result.formatted_date}
											</span>
											<ModelBadge
												provider={result.provider}
												model={result.model}
											/>
										</div>
										{result.score > 0 && (
											<span
												className={`tr-text-xs tr-font-semibold tr-px-2 tr-py-0.5 tr-rounded-full ${result.score >= 80 ? 'tr-bg-green-100 tr-text-green-700' : result.score >= 60 ? 'tr-bg-yellow-100 tr-text-yellow-700' : 'tr-bg-red-100 tr-text-red-700'}`}
											>
												{result.score}/100
											</span>
										)}
									</div>
									{result.suggestions &&
										result.suggestions.length > 0 && (
											<ul className="tr-flex tr-flex-col tr-gap-1.5 tr-m-0 tr-p-0 tr-list-none">
												{result.suggestions.map(
													(suggestion, si) => (
														<li
															key={si}
															className="tr-text-sm tr-text-secondary tr-p-3 tr-bg-white tr-border tr-border-secondary tr-rounded"
														>
															{suggestion}
														</li>
													)
												)}
											</ul>
										)}
								</div>
							))}
						</div>
					</Card>
				</div>
			)}
		</div>
	);
};

export default LLMsAIIndexing;
