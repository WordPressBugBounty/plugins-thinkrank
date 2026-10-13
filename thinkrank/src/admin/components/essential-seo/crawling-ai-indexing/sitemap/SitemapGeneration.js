/**
 * Sitemap Generation Component — Shell
 *
 * Redesigned — uses plugin UI components and Tailwind classes.
 * All state management, validation, and API logic unchanged.
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	SkeletonCard,
	Card,
	ColorPicker,
	Input,
	Select,
	Toggle,
	Button,
	Container,
} from '../../../ui';
import MediaPicker from '../../../common/MediaPicker';
import SettingsCard from '../../../common/SettingsCard';
import SectionHeader from '../../../common/SectionHeader';
import FormField from '../../../common/FormField';
import OptimizationButton from '../../../common/OptimizationButton';

import RegenerationHealth from './RegenerationHealth';
import { validateInput, validateSitemapUrl } from './validators';
import { getDefaultSettings, applyPreset, applyUrlPattern } from './presets';
import {
	loadSettings,
	saveSettings,
	saveSettingsObject,
	generateSitemap,
	validateSingleSitemap,
	validateAllSitemaps,
	pingSearchEngines,
} from './api';
import useAutosaveSettings from '../../../../hooks/useAutosaveSettings';
import AdvancedOnly from '../../../common/AdvancedOnly';

/* ── Icons ──────────────────────────────────────────────────────────────── */

const IconLink = () => (
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
		<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
		<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
	</svg>
);

const IconSettings = () => (
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
		<circle cx="12" cy="12" r="3" />
		<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
	</svg>
);

const IconFilter = () => (
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
		<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
	</svg>
);

const IconShield = () => (
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
		<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
	</svg>
);

const IconSliders = () => (
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
		<line x1="4" y1="21" x2="4" y2="14" />
		<line x1="4" y1="10" x2="4" y2="3" />
		<line x1="12" y1="21" x2="12" y2="12" />
		<line x1="12" y1="8" x2="12" y2="3" />
		<line x1="20" y1="21" x2="20" y2="16" />
		<line x1="20" y1="12" x2="20" y2="3" />
		<line x1="1" y1="14" x2="7" y2="14" />
		<line x1="9" y1="8" x2="15" y2="8" />
		<line x1="17" y1="16" x2="23" y2="16" />
	</svg>
);

const IconPlus = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<line x1="12" y1="5" x2="12" y2="19" />
		<line x1="5" y1="12" x2="19" y2="12" />
	</svg>
);

const IconTrash = () => (
	<svg
		width="13"
		height="13"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<polyline points="3 6 5 6 21 6" />
		<path d="M19 6l-1 14H6L5 6" />
		<path d="M10 11v6" />
		<path d="M14 11v6" />
		<path d="M9 6V4h6v2" />
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

const IconShare = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<circle cx="18" cy="5" r="3" />
		<circle cx="6" cy="12" r="3" />
		<circle cx="18" cy="19" r="3" />
		<line x1="8.59" y1="13.51" x2="15.42" y2="17.49" />
		<line x1="15.41" y1="6.51" x2="8.59" y2="10.49" />
	</svg>
);

const IconRefresh = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<polyline points="1 4 1 10 7 10" />
		<path d="M3.51 15a9 9 0 1 0 .49-4.86" />
	</svg>
);

const IconInfo = () => (
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
		<line x1="12" y1="16" x2="12" y2="12" />
		<line x1="12" y1="8" x2="12.01" y2="8" />
	</svg>
);

/* ── Option arrays ───────────────────────────────────────────────────────── */

const PRESET_OPTIONS = [
	{ value: 'basic', label: __('Basic (Single sitemap)', 'thinkrank') },
	{ value: 'wordpress-core', label: __('WordPress Core', 'thinkrank') },
	{
		value: 'segmented',
		label: __('Segmented (Posts, Pages, Categories)', 'thinkrank'),
	},
	{
		value: 'ecommerce',
		label: __('E-commerce (Products, Categories)', 'thinkrank'),
	},
	{
		value: 'complete',
		label: __('Complete (All content + Custom post types)', 'thinkrank'),
	},
	{
		value: 'custom',
		label: __('Custom (User-modified configuration)', 'thinkrank'),
	},
];

const SITEMAP_TYPE_OPTIONS = [
	{ value: 'index', label: __('Index', 'thinkrank') },
	{ value: 'general', label: __('General', 'thinkrank') },
	{ value: 'posts', label: __('Posts', 'thinkrank') },
	{ value: 'pages', label: __('Pages', 'thinkrank') },
	{ value: 'categories', label: __('Categories', 'thinkrank') },
];

const statusStyle = (s) => {
	if (s === 'active')
		return 'tr-bg-success-bg tr-text-success tr-border-success';
	if (s === 'not_found')
		return 'tr-bg-danger-bg tr-text-danger tr-border-danger';
	if (s === 'error')
		return 'tr-bg-warning-bg tr-text-warning tr-border-warning';
	return 'tr-bg-white tr-text-tertiary tr-border-secondary';
};

const statusLabel = (s) => {
	if (s === 'active') return __('Active', 'thinkrank');
	if (s === 'not_found') return __('Not Found', 'thinkrank');
	if (s === 'error') return __('Error', 'thinkrank');
	return __('Unknown', 'thinkrank');
};

/* ── Component ───────────────────────────────────────────────────────────── */

/**
 * Whether an inclusion flag is on.
 *
 * Settings round-trip through the database as '1' / '', never as real booleans,
 * so a strict comparison against `false` can never exclude anything. An absent
 * key is treated as enabled, preserving the previous default for a payload that
 * omits it entirely.
 *
 * @param {boolean|string|number|undefined} value Stored flag.
 * @return {boolean} True when the type should be included.
 */
const isEnabled = (value) =>
	value === undefined || value === null
		? true
		: value !== false && value !== '' && value !== '0' && value !== 0;

const SitemapGeneration = () => {
	const [isLoading, setIsLoading] = useState(true);
	const [isSaving, setIsSaving] = useState(false);
	const [isGenerating, setIsGenerating] = useState(false);
	const [settings, setSettings] = useState({});
	const [hasChanges, setHasChanges] = useState(false);
	const [notice, setNotice] = useState(null);
	const [selectedPreset, setSelectedPreset] = useState('basic');
	const [isValidatingAll, setIsValidatingAll] = useState(false);
	const [validatingIndex, setValidatingIndex] = useState(null);
	const [isPinging, setIsPinging] = useState(false);
	const [health, setHealth] = useState(null);
	// 'static' | 'dynamic' | null — the server's resolution of delivery_mode,
	// which may be stored as 'auto'.
	const [resolvedDeliveryMode, setResolvedDeliveryMode] = useState(null);

	// A sitemap URL is live either because a file was written (static) or
	// because WordPress answers the URL from PHP (dynamic). Dynamic delivery
	// deliberately never sets last_generated — it means "a file is on disk" —
	// so gating the links on it alone disabled them forever on those sites.
	const sitemapsAreLive =
		Boolean(settings.last_generated) || resolvedDeliveryMode === 'dynamic';

	// Sitemap generation and sitemap styling are feature switches, so flipping
	// either one is the save (see hooks/useAutosaveSettings.js). Everything else
	// on this screen still commits through the Save Settings button.
	const { update: persistSetting, isSaving: isTogglingFeature } =
		useAutosaveSettings({
			settings,
			setSettings,
			save: saveSettingsObject,
			logLabel: 'ThinkRank',
		});

	const stringToTokens = (str) => {
		if (!str) return [];
		return str
			.split(',')
			.map((item) => item.trim())
			.filter((item) => item);
	};

	const tokensToString = (tokens) => {
		if (!Array.isArray(tokens)) return '';
		return tokens.join(', ');
	};

	const handleSettingChange = (key, value) => {
		try {
			const validatedValue = validateInput(key, value);
			setSettings((prev) => {
				const newSettings = { ...prev, [key]: validatedValue };

				if (
					key === 'custom_url_pattern' &&
					value &&
					newSettings.sitemap_urls
				) {
					if (selectedPreset === 'basic') {
						setSelectedPreset('custom');
						newSettings.selected_preset = 'custom';
						const newUrl = value.replace('{type}', 'general');
						newSettings.sitemap_urls = [
							{
								url: newUrl.startsWith('/')
									? newUrl
									: '/' + newUrl,
								type: 'general',
								enabled: true,
								last_checked: null,
								status: 'unknown',
							},
						];
					} else {
						newSettings.sitemap_urls = newSettings.sitemap_urls.map(
							(sitemap) => {
								if (
									sitemap.type === 'index' ||
									sitemap.type === 'wordpress'
								)
									return sitemap;
								const newUrl = value.replace(
									'{type}',
									sitemap.type
								);
								return {
									...sitemap,
									url: newUrl.startsWith('/')
										? newUrl
										: '/' + newUrl,
								};
							}
						);
					}
				}

				if (key === 'use_sitemap_index') {
					if (value) {
						const pattern =
							newSettings.custom_url_pattern ||
							'sitemap-{type}.xml';
						const child = (type) => {
							let file = pattern.replace('{type}', type);
							if (!file.startsWith('/')) file = '/' + file;
							return {
								url: file,
								type,
								enabled: true,
								last_checked: null,
								status: 'unknown',
							};
						};
						// Populate the segmented set so the index has real child sitemaps.
						const urls = [
							{
								url: '/sitemap_index.xml',
								type: 'index',
								enabled: true,
								last_checked: null,
								status: 'unknown',
							},
						];
						// The API returns these as '1' / '' strings, so a strict
						// `!== false` was true even for a disabled type — only a
						// real boolean false would have excluded it, and the
						// settings layer never produces one. Coerce, then test
						// truthiness (which is what the tags line always did).
						if (isEnabled(newSettings.include_posts))
							urls.push(child('posts'));
						if (isEnabled(newSettings.include_pages))
							urls.push(child('pages'));
						if (isEnabled(newSettings.include_categories))
							urls.push(child('categories'));
						if (isEnabled(newSettings.include_tags))
							urls.push(child('tags'));
						newSettings.sitemap_urls = urls;
					} else {
						newSettings.sitemap_urls = [
							{
								url: '/sitemap.xml',
								type: 'general',
								enabled: true,
								last_checked: null,
								status: 'unknown',
							},
						];
					}
				}

				return newSettings;
			});
			setHasChanges(true);
		} catch (error) {
			setNotice({
				status: 'error',
				message:
					error.message ||
					__(
						'Invalid input provided. Please check your settings.',
						'thinkrank'
					),
			});
		}
	};

	const addNewSitemap = () => {
		setSettings((prev) => ({
			...prev,
			sitemap_urls: [
				...(prev.sitemap_urls || []),
				{
					url: applyUrlPattern('general', settings),
					type: 'general',
					enabled: true,
					last_checked: null,
					status: 'unknown',
				},
			],
			use_sitemap_index: true,
		}));
		setSelectedPreset('custom');
		setSettings((prev) => ({ ...prev, selected_preset: 'custom' }));
		setHasChanges(true);
	};

	/**
	 * Update a single sitemap row.
	 *
	 * @param {number}  index               Row index.
	 * @param {Object}  updatedSitemap      New row value.
	 * @param {Object}  options             Behaviour flags.
	 * @param {boolean} options.validateUrl Normalise/validate the URL (blur/commit only).
	 *                                      Off while typing so partial input like
	 *                                      "/sitemap.x" isn't rejected keystroke-by-keystroke.
	 * @param {boolean} options.markDirty   Mark settings dirty + switch preset to custom.
	 *                                      Off for status write-backs (validate/ping), which
	 *                                      must not make the form look unsaved.
	 */
	const updateSitemap = (index, updatedSitemap, options = {}) => {
		const { validateUrl = false, markDirty = true } = options;
		try {
			let nextSitemap = updatedSitemap;

			if (validateUrl && nextSitemap.url) {
				// Copy rather than mutate the caller's object.
				nextSitemap = {
					...nextSitemap,
					url: validateSitemapUrl(nextSitemap.url),
				};
			}

			// Single state update (previously two setSettings calls caused an extra render).
			setSettings((prev) => ({
				...prev,
				sitemap_urls: prev.sitemap_urls.map((sitemap, i) =>
					i === index ? nextSitemap : sitemap
				),
				...(markDirty ? { selected_preset: 'custom' } : {}),
			}));

			if (markDirty) {
				setSelectedPreset('custom');
				setHasChanges(true);
			}
		} catch (error) {
			setNotice({
				status: 'error',
				message:
					error.message ||
					__('Invalid sitemap URL provided', 'thinkrank'),
			});
		}
	};

	const removeSitemap = (index) => {
		setSettings((prev) => ({
			...prev,
			sitemap_urls: prev.sitemap_urls.filter((_, i) => i !== index),
		}));
		setSelectedPreset('custom');
		setSettings((prev) => ({ ...prev, selected_preset: 'custom' }));
		setHasChanges(true);
	};

	const getApiContext = () => ({
		settings,
		setSettings,
		setIsLoading,
		setIsSaving,
		setIsGenerating,
		setHasChanges,
		setNotice,
		setSelectedPreset,
		setIsValidatingAll,
		setValidatingIndex,
		setIsPinging,
		setHealth,
		setResolvedDeliveryMode,
		getDefaultSettings,
		updateSitemap,
	});

	useEffect(() => {
		loadSettings(getApiContext());
	}, []);

	const getOptimizationButtonConfig = () => [
		<OptimizationButton
			key="generate"
			type="generate"
			label={__('Generate Sitemap', 'thinkrank')}
			loadingLabel={__('Generating…', 'thinkrank')}
			onClick={() => generateSitemap(getApiContext())}
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

	const siteUrl = window.location.origin;
	const ctx = getApiContext();

	return (
		<div className="thinkrank-sitemap-tab">
			<RegenerationHealth health={health} />

			<SettingsCard
				title={__('XML Sitemap Management', 'thinkrank')}
				enabled={settings.enabled}
				onToggle={(value) =>
					persistSetting(
						{ enabled: value },
						value
							? __('XML sitemap is on.', 'thinkrank')
							: __('XML sitemap is off.', 'thinkrank')
					)
				}
				toggleLabel={__('Enable Sitemap', 'thinkrank')}
				toggleBusy={isTogglingFeature}
				optimizationButtons={getOptimizationButtonConfig()}
				notice={notice}
				onNoticeRemove={() => setNotice(null)}
				showDisabledWarning={true}
				disabledWarningMessage={__(
					'XML sitemap generation is disabled. Enable to configure multiple sitemaps, content inclusion, and search engine notifications.',
					'thinkrank'
				)}
			>
				<Container direction="column" gap={4}>
					{/* ── Sitemap URLs ──────────────────────────────────── */}
					<Card padding={false}>
						<SectionHeader
							title={__('Sitemap URLs', 'thinkrank')}
							description={__(
								'Configure which sitemaps are generated for your site',
								'thinkrank'
							)}
						/>
						<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
							{settings.use_sitemap_index && (
								<div className="tr-flex tr-items-start tr-gap-2 tr-bg-brand-bg tr-border tr-border-brand tr-rounded-lg tr-p-3">
									<span className="tr-text-brand tr-shrink-0 tr-mt-0.5">
										<IconInfo />
									</span>
									<p className="tr-text-xs tr-text-brand tr-m-0">
										{__('Sitemap index: ', 'thinkrank')}
										<a
											href={`${siteUrl}/sitemap_index.xml`}
											target="_blank"
											rel="noopener noreferrer"
											className="tr-text-brand tr-underline tr-inline-flex tr-items-center tr-gap-0.5"
										>
											{`${siteUrl}/sitemap_index.xml`}{' '}
											<IconExternalLink />
										</a>
									</p>
								</div>
							)}

							<Toggle
								id="use-sitemap-index"
								label={__('Use Sitemap Index', 'thinkrank')}
								description={__(
									'Generate a sitemap index referencing multiple individual sitemaps (recommended for large sites)',
									'thinkrank'
								)}
								checked={!!settings.use_sitemap_index}
								onChange={(value) =>
									handleSettingChange(
										'use_sitemap_index',
										value
									)
								}
								disabled={!settings.enabled}
							/>

							<Select
								label={__('Sitemap Delivery', 'thinkrank')}
								value={settings.delivery_mode || 'auto'}
								options={[
									{
										value: 'auto',
										label: __(
											'Automatic (recommended)',
											'thinkrank'
										),
									},
									{
										value: 'static',
										label: __(
											'Static files in the site root',
											'thinkrank'
										),
									},
									{
										value: 'dynamic',
										label: __(
											'Served by WordPress, no files written',
											'thinkrank'
										),
									},
								]}
								onChange={(e) =>
									handleSettingChange(
										'delivery_mode',
										e.target.value
									)
								}
								description={__(
									'Automatic writes sitemap files when your WordPress folder is writable, and serves the sitemap directly when it is not. Choose Served by WordPress if your host keeps the WordPress folder read only.',
									'thinkrank'
								)}
								disabled={!settings.enabled}
							/>

							<Select
								label={__('Quick Setup Templates', 'thinkrank')}
								value={selectedPreset}
								options={PRESET_OPTIONS}
								onChange={(e) =>
									applyPreset(e.target.value, ctx)
								}
								disabled={!settings.enabled}
							/>

							{/* Sitemap URL rows */}
							<div className="tr-flex tr-flex-col tr-gap-2">
								<p className="tr-text-xs tr-font-semibold tr-text-secondary tr-uppercase tr-tracking-wide tr-m-0">
									{__('Sitemap URLs', 'thinkrank')}
								</p>
								{settings.sitemap_urls?.map(
									(sitemap, index) => (
										<div
											key={index}
											className="tr-flex tr-items-end tr-gap-2 tr-border tr-border-secondary tr-rounded-lg tr-p-3"
										>
											<div className="tr-flex-1">
												{/*
												 * onChange stays free-form: validating every keystroke
												 * rejected partial input (e.g. "/sitemap.x") and froze
												 * the field. The URL is validated/normalised on blur.
												 */}
												<Input
													label={__(
														'URL',
														'thinkrank'
													)}
													value={sitemap.url}
													onChange={(e) =>
														updateSitemap(index, {
															...sitemap,
															url: e.target.value,
														})
													}
													onBlur={(e) =>
														updateSitemap(
															index,
															{
																...sitemap,
																url: e.target
																	.value,
															},
															{
																validateUrl: true,
															}
														)
													}
													placeholder="/sitemap.xml"
													disabled={!settings.enabled}
												/>
											</div>
											<div className="tr-w-36">
												<Select
													label={__(
														'Type',
														'thinkrank'
													)}
													value={sitemap.type}
													options={
														SITEMAP_TYPE_OPTIONS
													}
													onChange={(e) =>
														updateSitemap(index, {
															...sitemap,
															type: e.target
																.value,
														})
													}
													disabled={!settings.enabled}
												/>
											</div>
											<button
												onClick={() =>
													removeSitemap(index)
												}
												disabled={
													!settings.enabled ||
													(settings.sitemap_urls
														?.length ?? 0) <= 1
												}
												className="tr-flex tr-items-center tr-justify-center tr-w-10 tr-h-10 tr-rounded tr-border tr-border-danger tr-text-danger tr-bg-danger-bg hover:tr-bg-danger-bg tr-transition-colors tr-duration-150 tr-cursor-pointer disabled:tr-opacity-40 disabled:tr-cursor-not-allowed tr-shrink-0"
											>
												<IconTrash />
											</button>
										</div>
									)
								)}
							</div>

							<button
								onClick={addNewSitemap}
								disabled={!settings.enabled}
								className="tr-flex tr-items-center tr-gap-1.5 tr-text-xs tr-font-medium tr-text-brand tr-bg-brand-bg tr-border tr-border-brand tr-rounded tr-px-3 tr-py-2 hover:tr-bg-brand-bg tr-transition-colors tr-duration-150 tr-cursor-pointer disabled:tr-opacity-50 disabled:tr-cursor-not-allowed tr-self-start"
							>
								<IconPlus />{' '}
								{__('Add Sitemap URL', 'thinkrank')}
							</button>

							{/* View links — real once the URLs actually answer:
							     after generation on a static site, or immediately
							     on a dynamic one, where WordPress serves them and
							     no file is ever written. Otherwise they 404, so
							     render them disabled with a hint. */}
							{settings.sitemap_urls?.length > 0 && (
								<div>
									<p className="tr-text-xs tr-font-semibold tr-text-secondary tr-uppercase tr-tracking-wide tr-m-0 tr-mb-2">
										{__(
											'View Generated Sitemaps',
											'thinkrank'
										)}
									</p>
									<div className="tr-flex tr-flex-col tr-gap-1">
										{settings.sitemap_urls.map(
											(sitemap, index) =>
												sitemapsAreLive ? (
													<a
														key={index}
														href={`${siteUrl}${sitemap.url}`}
														target="_blank"
														rel="noopener noreferrer"
														className="tr-inline-flex tr-items-center tr-gap-1 tr-text-sm tr-text-brand hover:tr-underline"
													>
														{sitemap.url}{' '}
														<span className="tr-text-tertiary">
															({sitemap.type})
														</span>{' '}
														<IconExternalLink />
													</a>
												) : (
													<span
														key={index}
														title={__(
															'Generate the sitemap to enable this link',
															'thinkrank'
														)}
														className="tr-inline-flex tr-items-center tr-gap-1 tr-text-sm tr-text-tertiary tr-cursor-not-allowed"
													>
														{sitemap.url}{' '}
														<span className="tr-text-tertiary">
															({sitemap.type})
														</span>
													</span>
												)
										)}
									</div>
									{!sitemapsAreLive && (
										<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-1">
											{__(
												'Click “Generate Sitemap” to create these files before viewing them.',
												'thinkrank'
											)}
										</p>
									)}
								</div>
							)}
						</div>
					</Card>

					{/* ── General Settings ──────────────────────────────── */}
					<Card padding={false}>
						<SectionHeader
							title={__('General Settings', 'thinkrank')}
							description={__(
								'Core options that apply to all generated sitemaps.',
								'thinkrank'
							)}
						/>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<FormField
								id="tr-sm-links-per"
								label={__('Links Per Sitemap', 'thinkrank')}
								description={__(
									'Max links per sitemap page. Google recommends max 50,000 URLs',
									'thinkrank'
								)}
								disabled={!settings.enabled}
							>
								<Input
									id="tr-sm-links-per"
									type="number"
									value={settings.links_per_sitemap || 1000}
									onChange={(e) =>
										handleSettingChange(
											'links_per_sitemap',
											parseInt(e.target.value)
										)
									}
									min={100}
									max={50000}
									disabled={!settings.enabled}
								/>
							</FormField>
							<div className="tr-pt-6">
								<Toggle
									id="include-images"
									label={__(
										'Images in Sitemaps',
										'thinkrank'
									)}
									description={__(
										'Include references to images from post content. Helps search engines index important images',
										'thinkrank'
									)}
									checked={!!settings.include_images}
									onChange={(value) =>
										handleSettingChange(
											'include_images',
											value
										)
									}
									disabled={!settings.enabled}
								/>
							</div>
							<div className="tr-pt-6">
								<Toggle
									id="include-featured-images"
									label={__(
										'Include Featured Images',
										'thinkrank'
									)}
									description={__(
										'Include the featured image even if it does not appear directly in post content',
										'thinkrank'
									)}
									checked={!!settings.include_featured_images}
									onChange={(value) =>
										handleSettingChange(
											'include_featured_images',
											value
										)
									}
									disabled={
										!settings.enabled ||
										!settings.include_images
									}
								/>
							</div>
							<div className="tr-pt-6">
								<Toggle
									id="auto-generate"
									label={__(
										'Auto-Generate Sitemaps',
										'thinkrank'
									)}
									description={__(
										'Automatically regenerate sitemaps when content is published, updated, or deleted',
										'thinkrank'
									)}
									checked={!!settings.auto_generate}
									onChange={(value) =>
										handleSettingChange(
											'auto_generate',
											value
										)
									}
									disabled={!settings.enabled}
								/>
							</div>
							<div className="tr-pt-6">
								<Toggle
									id="ping-search-engines"
									label={__(
										'Ping Search Engines',
										'thinkrank'
									)}
									description={__(
										'Automatically notify Google and Bing when sitemaps are updated',
										'thinkrank'
									)}
									checked={!!settings.ping_search_engines}
									onChange={(value) =>
										handleSettingChange(
											'ping_search_engines',
											value
										)
									}
									disabled={!settings.enabled}
								/>
							</div>
						</Container>
					</Card>

					{/* ── Content Filtering ─────────────────────────────── */}
					<AdvancedOnly label={__('Content filtering', 'thinkrank')}>
						<Card padding={false}>
							<SectionHeader
								title={__('Content Filtering', 'thinkrank')}
								description={__(
									'Exclude specific content from your sitemaps',
									'thinkrank'
								)}
							/>
							<Container
								direction="column"
								gap={6}
								className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
							>
								<FormField
									id="tr-sm-exclude-posts"
									label={__('Exclude Posts', 'thinkrank')}
									description={__(
										'Comma-separated post IDs to exclude, e.g., 123, 456, 789',
										'thinkrank'
									)}
									disabled={!settings.enabled}
								>
									<Input
										id="tr-sm-exclude-posts"
										value={settings.exclude_posts || ''}
										onChange={(e) =>
											handleSettingChange(
												'exclude_posts',
												e.target.value
											)
										}
										placeholder="123, 456, 789"
										disabled={!settings.enabled}
									/>
								</FormField>
								<FormField
									id="tr-sm-exclude-terms"
									label={__('Exclude Terms', 'thinkrank')}
									description={__(
										'Comma-separated term IDs to exclude. Applies to all taxonomies',
										'thinkrank'
									)}
									disabled={!settings.enabled}
									className="tr-pt-6"
								>
									<Input
										id="tr-sm-exclude-terms"
										value={settings.exclude_terms || ''}
										onChange={(e) =>
											handleSettingChange(
												'exclude_terms',
												e.target.value
											)
										}
										placeholder="12, 34, 56"
										disabled={!settings.enabled}
									/>
								</FormField>
								<div className="tr-pt-6">
									<Toggle
										id="exclude-password-protected"
										label={__(
											'Exclude Password Protected Posts',
											'thinkrank'
										)}
										description={__(
											'When enabled, password-protected posts will be excluded from sitemaps',
											'thinkrank'
										)}
										checked={
											!!settings.exclude_password_protected
										}
										onChange={(value) =>
											handleSettingChange(
												'exclude_password_protected',
												value
											)
										}
										disabled={!settings.enabled}
									/>
								</div>
								<div className="tr-pt-6">
									<Toggle
										id="exclude-private-posts"
										label={__(
											'Exclude Private Posts',
											'thinkrank'
										)}
										description={__(
											'When enabled, private posts will be excluded from sitemaps',
											'thinkrank'
										)}
										checked={
											!!settings.exclude_private_posts
										}
										onChange={(value) =>
											handleSettingChange(
												'exclude_private_posts',
												value
											)
										}
										disabled={!settings.enabled}
									/>
								</div>

								{/* Auto quality filtering info */}
								<div className="tr-pt-6">
									<div className="tr-bg-brand-bg tr-border tr-border-brand tr-rounded-lg tr-p-4">
										<p className="tr-text-sm tr-font-semibold tr-text-brand tr-m-0 tr-mb-2">
											{__(
												'What Gets Included',
												'thinkrank'
											)}
										</p>
										<ol className="tr-m-0 tr-pl-5 tr-flex tr-flex-col tr-gap-1">
											{[
												__(
													'Published, publicly viewable content of the enabled post types and taxonomies',
													'thinkrank'
												),
												__(
													'Excludes items set to noindex or excluded from search',
													'thinkrank'
												),
												__(
													'Password-protected and private content only when those toggles are enabled above',
													'thinkrank'
												),
											].map((item, i) => (
												<li
													key={i}
													className="tr-text-xs tr-text-brand"
												>
													{item}
												</li>
											))}
										</ol>
									</div>
								</div>
							</Container>
						</Card>
					</AdvancedOnly>

					{/* ── Health & Validation ───────────────────────────── */}
					<Card padding={false}>
						<SectionHeader
							title={__('Health & Validation', 'thinkrank')}
							description={__(
								'Check the status of your generated sitemaps',
								'thinkrank'
							)}
						/>
						<div className="tr-p-5 tr-flex tr-flex-col tr-gap-3">
							{settings.sitemap_urls?.map((sitemap, index) => (
								<div
									key={index}
									className="tr-border tr-border-secondary tr-rounded-lg tr-p-4 tr-flex tr-items-start tr-justify-between tr-gap-3"
								>
									<div className="tr-flex-1 tr-min-w-0">
										<code className="tr-text-xs tr-font-medium tr-text-secondary tr-bg-white tr-px-2 tr-py-0.5 tr-rounded">
											{sitemap.url}
										</code>
										<div className="tr-flex tr-items-center tr-gap-2 tr-mt-2 tr-flex-wrap">
											<span
												className={`tr-inline-flex tr-items-center tr-text-xs tr-font-medium tr-px-2 tr-py-0.5 tr-rounded-full tr-border ${statusStyle(sitemap.status)}`}
											>
												{statusLabel(sitemap.status)}
											</span>
											{sitemap.last_checked && (
												<span className="tr-text-xs tr-text-tertiary">
													{__(
														'Checked:',
														'thinkrank'
													)}{' '}
													{new Date(
														sitemap.last_checked
													).toLocaleString()}
													{sitemap.response_code && (
														<span className="tr-ml-1 tr-font-medium">
															(
															{
																sitemap.response_code
															}
															)
														</span>
													)}
												</span>
											)}
										</div>
									</div>
									<Button
										variant="secondary"
										size="sm"
										onClick={() =>
											validateSingleSitemap(ctx, index)
										}
										loading={validatingIndex === index}
										disabled={!settings.enabled}
									>
										{validatingIndex === index
											? __('Checking…', 'thinkrank')
											: __('Check', 'thinkrank')}
									</Button>
								</div>
							))}

							{/* Bulk actions */}
							<div className="tr-border tr-border-secondary tr-rounded-lg tr-p-4">
								<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-m-0 tr-mb-3">
									{__('Bulk Actions', 'thinkrank')}
								</p>
								<div className="tr-flex tr-gap-2 tr-flex-wrap">
									<Button
										variant="primary"
										size="sm"
										onClick={() => validateAllSitemaps(ctx)}
										loading={isValidatingAll}
										disabled={!settings.enabled}
									>
										<IconRefresh />{' '}
										{isValidatingAll
											? __('Validating…', 'thinkrank')
											: __(
													'Validate All Sitemaps',
													'thinkrank'
												)}
									</Button>
									<Button
										variant="secondary"
										size="sm"
										onClick={() => pingSearchEngines(ctx)}
										loading={isPinging}
										disabled={!settings.enabled}
									>
										<IconShare />{' '}
										{isPinging
											? __('Pinging…', 'thinkrank')
											: __(
													'Ping Search Engines',
													'thinkrank'
												)}
									</Button>
								</div>
							</div>
						</div>
					</Card>

					{/* ── Advanced Options ──────────────────────────────── */}
					<AdvancedOnly
						label={__('Sitemap styling and filenames', 'thinkrank')}
					>
						<Card padding={false}>
							<SectionHeader
								title={__('Advanced Options', 'thinkrank')}
								description={__(
									'Customize sitemap styling and filename patterns.',
									'thinkrank'
								)}
							/>
							<Container
								direction="column"
								gap={6}
								className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
							>
								<Toggle
									id="enable-styling"
									label={__(
										'Enable Sitemap Styling',
										'thinkrank'
									)}
									description={__(
										'Apply custom CSS styling to make XML sitemaps more readable in browsers',
										'thinkrank'
									)}
									checked={!!settings.enable_styling}
									onChange={(value) =>
										persistSetting(
											{ enable_styling: value },
											value
												? __(
														'Sitemap styling is on.',
														'thinkrank'
													)
												: __(
														'Sitemap styling is off.',
														'thinkrank'
													)
										)
									}
									disabled={
										!settings.enabled || isTogglingFeature
									}
								/>
								{/*
								 * Branding. Hidden while styling is off, because
								 * without a stylesheet there is nothing for any of
								 * it to paint.
								 */}
								{!!settings.enable_styling && (
									<Container
										direction="column"
										gap={5}
										className="tr-pt-6"
									>
										<Toggle
											id="styling-logo"
											label={__(
												'Show Logo On Sitemap',
												'thinkrank'
											)}
											description={__(
												'Place a logo above the sitemap heading. Leave the image empty to use your site icon.',
												'thinkrank'
											)}
											checked={!!settings.styling_logo}
											onChange={(value) =>
												persistSetting(
													{ styling_logo: value },
													value
														? __(
																'Sitemap logo is on.',
																'thinkrank'
															)
														: __(
																'Sitemap logo is off.',
																'thinkrank'
															)
												)
											}
											disabled={
												!settings.enabled ||
												isTogglingFeature
											}
										/>

										{!!settings.styling_logo && (
											<MediaPicker
												label={__(
													'Sitemap Logo',
													'thinkrank'
												)}
												value={
													settings.styling_logo_url ||
													''
												}
												onChange={(value) =>
													handleSettingChange(
														'styling_logo_url',
														value || ''
													)
												}
												help={__(
													'Shown at up to 64px tall. Empty falls back to your site icon.',
													'thinkrank'
												)}
												allowedTypes={['image']}
											/>
										)}

										<Container
											direction="row"
											gap={6}
											className="tr-flex-wrap"
										>
											<ColorPicker
												id="tr-sm-color-main"
												label={__(
													'Main Color',
													'thinkrank'
												)}
												description={__(
													'Header, table heading and links.',
													'thinkrank'
												)}
												value={
													settings.styling_color_main ||
													''
												}
												fallback="#667eea"
												onChange={(value) =>
													handleSettingChange(
														'styling_color_main',
														value
													)
												}
												disabled={!settings.enabled}
											/>

											<ColorPicker
												id="tr-sm-color-accent"
												label={__(
													'Accent Color',
													'thinkrank'
												)}
												description={__(
													'Second half of the header gradient.',
													'thinkrank'
												)}
												value={
													settings.styling_color_accent ||
													''
												}
												fallback="#764ba2"
												onChange={(value) =>
													handleSettingChange(
														'styling_color_accent',
														value
													)
												}
												disabled={!settings.enabled}
											/>
										</Container>

										<p className="tr-text-xs tr-text-tertiary tr-m-0">
											{__(
												'Text colours are worked out from what you pick, so the sitemap stays readable whichever colours you choose. Leave a colour empty to keep the default.',
												'thinkrank'
											)}
										</p>
									</Container>
								)}

								<FormField
									id="tr-sm-url-pattern"
									label={__(
										'Custom Sitemap Filename Pattern',
										'thinkrank'
									)}
									description={__(
										'Use {type} as placeholder for content type, e.g., seo-{type}.xml. Applies to Segmented, E-commerce, and Complete templates.',
										'thinkrank'
									)}
									disabled={!settings.enabled}
									className="tr-pt-6"
								>
									<Input
										id="tr-sm-url-pattern"
										value={(
											settings.custom_url_pattern ||
											'sitemap-{type}.xml'
										).replace(/^\/+/, '')}
										onChange={(e) =>
											handleSettingChange(
												'custom_url_pattern',
												e.target.value.replace(
													/^\/+/,
													''
												)
											)
										}
										placeholder="sitemap-{type}.xml"
										disabled={!settings.enabled}
									/>
								</FormField>
							</Container>
						</Card>
					</AdvancedOnly>

					{/* Save button */}
					<div className="tr-flex tr-justify-end">
						<Button
							variant="primary"
							onClick={() => saveSettings(ctx)}
							loading={isSaving}
							disabled={!hasChanges || isSaving}
						>
							{isSaving
								? __('Saving…', 'thinkrank')
								: __('Save Settings', 'thinkrank')}
						</Button>
					</div>
				</Container>
			</SettingsCard>
		</div>
	);
};

export default SitemapGeneration;
