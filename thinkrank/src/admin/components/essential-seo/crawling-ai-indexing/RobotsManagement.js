/**
 * Robots.txt Management Component
 *
 * Extracted from SiteIdentityTab.js - maintains exact functionality
 * for robots.txt generation and management.
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	ToggleControl,
	Button,
	Flex,
	TextareaControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import {
	Card,
	Container,
	Select,
	Textarea,
	Toggle,
	SkeletonCard,
	SettingsLoadError,
} from '../../ui';
import SettingsCard from '../../common/SettingsCard';
import SectionHeader from '../../common/SectionHeader';
import FormField from '../../common/FormField';
import OptimizationButton from '../../common/OptimizationButton';
import useAutosaveSettings from '../../../hooks/useAutosaveSettings';
import logger from '../../../../shared/utils/logger';

/**
 * Robots.txt Management Component
 * @param root0
 * @param root0.onNavigate
 */
const RobotsManagement = ({ onNavigate }) => {
	// The textarea is seeded with the body that is actually SERVED, which on a
	// site that never customised robots.txt is the auto-generated output (see
	// loadSettings). That is a display convenience, not an edit — but every save
	// path sends the whole settings object, so persisting the seed wrote the
	// generated body into `robots_txt_content` and switched the site to a manual
	// override for good: render_robots_txt() takes the custom branch whenever
	// that value is non-empty, and later changes to the generated output (a new
	// sitemap, WooCommerce activated or removed, a new post type) stopped
	// reaching /robots.txt. Flipping one AI-crawler switch was enough, because
	// that flip IS a save.
	//
	// So remember what we seeded and drop the key from the payload while the
	// textarea still holds exactly that. A real edit changes the bytes and is
	// saved normally; typing the generated body back out by hand is the same
	// thing as never having overridden it.
	const seededFromGeneratedRef = useRef(false);
	const seededContentRef = useRef('');
	const [isLoading, setIsLoading] = useState(true);
	// True once a settings GET has failed. The form is not rendered while it is
	// set, so the defaults object can never be saved over stored values (#390).
	const [loadFailed, setLoadFailed] = useState(false);
	const [isSaving, setIsSaving] = useState(false);
	const [isGeneratingRobots, setIsGeneratingRobots] = useState(false);
	const [settings, setSettings] = useState({});
	const [sitemapUrls, setSitemapUrls] = useState([]);
	const [sitemapEnabled, setSitemapEnabled] = useState(false);
	const [hasChanges, setHasChanges] = useState(false);
	const [notice, setNotice] = useState(null);
	// How /robots.txt is actually delivered right now — the textarea holds what
	// *should* be served, which is a different thing when a physical robots.txt
	// sits in the web root and bypasses WordPress entirely.
	const [effective, setEffective] = useState(null);
	// The AI crawler registry travels with the robots response rather than
	// being duplicated in the bundle, so a crawler added through the
	// `thinkrank_ai_crawlers` filter shows up here without a rebuild (#657).
	const [aiCrawlers, setAiCrawlers] = useState([]);
	useEffect(() => {
		loadSettings();
		loadSitemapUrls();
	}, []);

	/**
	 * Get default settings (copied from SiteIdentityTab)
	 */
	const getDefaultSettings = () => {
		return {
			enabled: true,
			robots_txt_enabled: true,
			robots_txt_content: '',
			allow_search_engines: true,
			query_protection: true,
			feed_excerpt_only: false,
			feed_source_link: false,
			feed_noindex: false,
			canonical_scheme: 'automatic',
			// Empty map = every crawler allowed. Never seed blocks here: this
			// object is also what a failed load would otherwise save.
			ai_crawler_rules: {},
		};
	};

	/**
	 * Load robots.txt settings from Site Identity
	 */
	const loadSettings = async () => {
		try {
			setIsLoading(true);
			// Robots.txt settings are part of Site Identity
			const response = await apiFetch({
				path: '/thinkrank/v1/site-identity/settings?context_type=site',
				method: 'GET',
			});

			if (response.success) {
				const merged = {
					...getDefaultSettings(),
					...response.data.settings,
				};

				// Populate the editor with the body that's actually served
				// (custom content, else the physical file, else auto-generated)
				// so it's never blank while /robots.txt has content. The
				// endpoint returns it header-stripped.
				try {
					const robots = await apiFetch({
						path: '/thinkrank/v1/site-identity/robots',
						method: 'GET',
					});
					if (robots.success && robots.data?.content) {
						// Only a seed when nothing is stored — with a real
						// override saved, this IS the user's own content.
						seededFromGeneratedRef.current = !(
							response.data.settings?.robots_txt_content || ''
						).trim();
						seededContentRef.current = robots.data.content;
						merged.robots_txt_content = robots.data.content;
					}
					if (robots.success && robots.data?.effective) {
						setEffective(robots.data.effective);
					}
					if (robots.success && robots.data?.ai_crawlers) {
						setAiCrawlers(robots.data.ai_crawlers);
						// The endpoint answers with every known crawler
						// resolved to allow/block, so a crawler with nothing
						// stored arrives explicitly allowed rather than as an
						// absence this component has to interpret.
						merged.ai_crawler_rules =
							robots.data.ai_crawler_rules || {};
					}
				} catch (robotsError) {
					logger.error(
						'Failed to load robots.txt content:',
						robotsError
					);
				}

				setSettings(merged);
				setLoadFailed(false);
			} else {
				throw new Error(
					response.error || 'Failed to load robots.txt settings'
				);
			}
		} catch (error) {
			// Deliberately no setSettings(getDefaultSettings()) here. The
			// defaults carry robots_txt_content: '', so one Save from a failed
			// load blanked the user's saved robots.txt body (#390).
			logger.error('Robots.txt settings load error:', error);
			setLoadFailed(true);
			setNotice({
				status: 'error',
				message: __(
					'Could not load your robots.txt settings. Nothing has been changed. Retry before editing.',
					'thinkrank'
				),
			});
		} finally {
			setIsLoading(false);
		}
	};

	/**
	 * Re-read how /robots.txt is currently delivered.
	 *
	 * Used after a save or generate, both of which can create or rewrite the
	 * physical file in the web root.
	 */
	const refreshEffective = async () => {
		try {
			const robots = await apiFetch({
				path: '/thinkrank/v1/site-identity/robots',
				method: 'GET',
			});

			if (robots.success && robots.data?.effective) {
				setEffective(robots.data.effective);
			}
		} catch (error) {
			// Non-fatal: the panel keeps showing its last known state.
			logger.error('Failed to refresh served robots.txt:', error);
		}
	};

	/**
	 * Drop a seeded-but-unedited robots.txt body from a save payload.
	 *
	 * The settings endpoint merges the patch it is given, so omitting the key
	 * leaves whatever is stored (nothing, in the case this guards) alone.
	 *
	 * @param {Object} next Settings about to be saved.
	 * @return {Object} The payload to send.
	 */
	const withoutSeededContent = (next) => {
		if (!seededFromGeneratedRef.current) {
			return next;
		}

		if ((next.robots_txt_content || '') !== seededContentRef.current) {
			return next;
		}

		const { robots_txt_content: seeded, ...rest } = next;
		return rest;
	};

	// Robots.txt management is a feature switch, so flipping it is the save
	// (see hooks/useAutosaveSettings.js). It shares the site-identity settings
	// blob, so the whole merged object goes back — the same payload the Save
	// button sends for the remaining fields.
	const { update: persistSetting, isSaving: isTogglingFeature } =
		useAutosaveSettings({
			settings,
			setSettings,
			save: async (next) => {
				const response = await apiFetch({
					path: '/thinkrank/v1/site-identity/settings',
					method: 'POST',
					data: {
						settings: withoutSeededContent(next),
						context_type: 'site',
					},
				});

				if (!response.success) {
					throw new Error(
						response.error ||
							__('Failed to save settings.', 'thinkrank')
					);
				}

				// Saving re-syncs any physical robots.txt to the stored content,
				// so re-read how /robots.txt is delivered instead of leaving a
				// stale "out of sync" warning on screen.
				refreshEffective();
			},
			logLabel: 'ThinkRank',
		});

	/**
	 * Whether a crawler is currently blocked.
	 *
	 * Absence means allowed — the permissive reading, so a rule map that has
	 * not caught up with a newly registered crawler never blocks it silently.
	 *
	 * @param {string} slug Crawler slug.
	 * @return {boolean} True when blocked.
	 */
	const isBlocked = (slug) =>
		(settings.ai_crawler_rules || {})[slug] === 'block';

	/**
	 * Flip one crawler between allow and block.
	 *
	 * Flipping the switch IS the save (see hooks/useAutosaveSettings.js) —
	 * these directives are written straight into the served robots.txt, and a
	 * switch that looks committed but is not would misreport what crawlers are
	 * being told.
	 *
	 * @param {string}  slug    Crawler slug.
	 * @param {string}  label   Crawler user-agent token, for the confirmation.
	 * @param {boolean} blocked Whether it should be blocked.
	 */
	const setCrawlerRule = (slug, label, blocked) => {
		// A function patch, not a plain object: this map is derived from the
		// previous one, and two toggles flipped in quick succession would both
		// derive from the pre-first-click map, so the second would silently
		// undo the first (see useAutosaveSettings).
		persistSetting(
			(current) => ({
				ai_crawler_rules: {
					...(current.ai_crawler_rules || {}),
					[slug]: blocked ? 'block' : 'allow',
				},
			}),
			blocked
				? sprintf(
						/* translators: %s: crawler user-agent token, e.g. GPTBot. */
						__('%s is now blocked in robots.txt.', 'thinkrank'),
						label
					)
				: sprintf(
						/* translators: %s: crawler user-agent token, e.g. GPTBot. */
						__('%s is allowed again.', 'thinkrank'),
						label
					)
		);
	};

	/**
	 * Set every known crawler at once.
	 *
	 * @param {boolean} blocked Whether all crawlers should be blocked.
	 */
	const setAllCrawlerRules = (blocked) => {
		const next = {};
		aiCrawlers.forEach((crawler) => {
			next[crawler.slug] = blocked ? 'block' : 'allow';
		});
		persistSetting(
			{ ai_crawler_rules: next },
			blocked
				? __('Every AI crawler is now blocked.', 'thinkrank')
				: __('Every AI crawler is allowed again.', 'thinkrank')
		);
	};

	const blockedCount = aiCrawlers.filter((crawler) =>
		isBlocked(crawler.slug)
	).length;

	/**
	 * The registry grouped by vendor, in the order the API returned it.
	 *
	 * @return {Array} Groups of {vendor, label, crawlers}.
	 */
	const crawlerGroups = () => {
		const groups = [];
		aiCrawlers.forEach((crawler) => {
			const existing = groups.find((g) => g.vendor === crawler.vendor);
			if (existing) {
				existing.crawlers.push(crawler);
				return;
			}
			groups.push({
				vendor: crawler.vendor,
				label: crawler.vendor_label,
				crawlers: [crawler],
			});
		});

		return groups;
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
	 * Load sitemap URLs from sitemap settings
	 */
	const loadSitemapUrls = async () => {
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/sitemap/robots-urls',
				method: 'GET',
			});

			if (response.success && response.data) {
				setSitemapUrls(response.data.sitemap_urls || []);
				setSitemapEnabled(response.data.enabled || false);
			} else {
				// Fallback to default if API fails
				setSitemapUrls([
					{
						url: '/sitemap.xml',
						full_url: `${window.thinkrankAdmin?.siteUrl || window.location.origin}/sitemap.xml`,
						type: 'general',
						type_label: __('General', 'thinkrank'),
					},
				]);
				setSitemapEnabled(true);
			}
		} catch (error) {
			logger.error('Failed to load sitemap URLs:', error);
			// Fallback to default
			setSitemapUrls([
				{
					url: '/sitemap.xml',
					full_url: `${window.thinkrankAdmin?.siteUrl || window.location.origin}/sitemap.xml`,
					type: 'general',
					type_label: __('General', 'thinkrank'),
				},
			]);
			setSitemapEnabled(true);
		}
	};

	/**
	 * Generate robots.txt content (exact copy from SiteIdentityTab)
	 */
	const generateRobotsTxt = async () => {
		try {
			setIsGeneratingRobots(true);

			// First, save current settings so they're available during generation
			const saveResponse = await apiFetch({
				path: '/thinkrank/v1/site-identity/settings',
				method: 'POST',
				data: {
					settings: withoutSeededContent(settings),
					context_type: 'site',
				},
			});

			if (!saveResponse.success) {
				throw new Error('Failed to save settings before generation');
			}

			// Now generate robots.txt with the saved settings
			const response = await apiFetch({
				path: '/thinkrank/v1/site-identity/robots',
				method: 'POST',
				data: {
					custom_rules: [],
					enable_management: settings.robots_txt_enabled,
					write_to_file: true,
				},
			});

			if (response.success) {
				handleSettingChange(
					'robots_txt_content',
					response.data.robots_data.content
				);

				// Generation may have just written the physical file, so refresh
				// what the served-output panel reports instead of leaving it
				// showing the pre-write state.
				if (response.data.robots_data.effective) {
					setEffective(response.data.robots_data.effective);
				}

				// Show detailed success message based on file write result
				const fileWriteResult = response.data.file_write_result;
				let message = __(
					'Robots.txt content generated successfully!',
					'thinkrank'
				);

				if (fileWriteResult.success) {
					message += ' ' + __('File written to server.', 'thinkrank');
				} else {
					message +=
						' ' +
						__('Note: File not written to server - ', 'thinkrank') +
						fileWriteResult.message;
				}

				setNotice({
					status: fileWriteResult.success ? 'success' : 'warning',
					message,
				});

				// Mark as no changes since we just saved
				setHasChanges(false);
			}
		} catch (error) {
			// Handle specific API error responses
			let errorMessage = __(
				'Failed to generate robots.txt content.',
				'thinkrank'
			);
			let noticeStatus = 'error';

			if (error.code) {
				switch (error.code) {
					case 'rate_limit_exceeded':
						errorMessage = __(
							'Too many requests. Please wait a few minutes before trying again.',
							'thinkrank'
						);
						noticeStatus = 'warning';
						break;
					case 'rest_forbidden':
						errorMessage = __(
							'You do not have permission to generate robots.txt files.',
							'thinkrank'
						);
						break;
					default:
						errorMessage = error.message || errorMessage;
				}
			}

			setNotice({
				status: noticeStatus,
				message: errorMessage,
			});
		} finally {
			setIsGeneratingRobots(false);
		}
	};

	/**
	 * Save settings
	 */
	const saveSettings = async () => {
		// Belt and braces: the form is not rendered while loadFailed is set.
		if (loadFailed) {
			return;
		}

		try {
			setIsSaving(true);
			setNotice(null);

			const response = await apiFetch({
				path: '/thinkrank/v1/site-identity/settings',
				method: 'POST',
				data: {
					settings: withoutSeededContent(settings),
					context_type: 'site',
				},
			});

			if (response.success) {
				// The settings endpoint re-syncs any physical robots.txt to the
				// saved content server-side, so the live /robots.txt always
				// matches the textarea. Re-read the delivery state to show that,
				// rather than leaving a stale "out of sync" warning on screen.
				refreshEffective();
				setHasChanges(false);
				setNotice({
					status: 'success',
					message: __(
						'Robots.txt settings saved successfully!',
						'thinkrank'
					),
				});
			} else {
				setNotice({
					status: 'error',
					message:
						response.error ||
						__('Failed to save settings.', 'thinkrank'),
				});
			}
		} catch (error) {
			// Handle specific API error responses
			let errorMessage = __(
				'Failed to save robots.txt settings.',
				'thinkrank'
			);
			let noticeStatus = 'error';

			if (error.code) {
				switch (error.code) {
					case 'rate_limit_exceeded':
						errorMessage = __(
							'Too many requests. Please wait a few minutes before trying again.',
							'thinkrank'
						);
						noticeStatus = 'warning';
						break;
					case 'rest_forbidden':
						errorMessage = __(
							'You do not have permission to modify robots.txt settings.',
							'thinkrank'
						);
						break;
					default:
						errorMessage = error.message || errorMessage;
				}
			}

			setNotice({
				status: noticeStatus,
				message: errorMessage,
			});
		} finally {
			setIsSaving(false);
		}
	};

	/**
	 * Get optimization button configuration
	 */
	const getOptimizationButtonConfig = () => {
		return [
			{
				type: 'generate',
				label: __('Generate Robots.txt', 'thinkrank'),
				loadingLabel: __('Generating…', 'thinkrank'),
				onClick: generateRobotsTxt,
				isBusy: isGeneratingRobots,
				disabled: !settings.robots_txt_enabled || isGeneratingRobots,
			},
		];
	};

	/**
	 * Plain-language explanation of where the served robots.txt comes from.
	 * @param {string} source One of file | custom | generated | wordpress.
	 */
	const describeSource = (source) => {
		switch (source) {
			case 'file':
				return __(
					'A physical robots.txt file in your site root. Your web server serves this file directly, so WordPress and the content below are bypassed.',
					'thinkrank'
				);
			case 'custom':
				return __(
					'Your custom content below, served by WordPress.',
					'thinkrank'
				);
			case 'generated':
				return __(
					'Auto-generated by ThinkRank from your settings, served by WordPress.',
					'thinkrank'
				);
			case 'wordpress':
				return __(
					"WordPress default. ThinkRank's robots.txt management is off, so your settings below are not being served.",
					'thinkrank'
				);
			default:
				return '';
		}
	};

	/**
	 * Short label for the served-output badge.
	 * @param {string} source One of file | custom | generated | wordpress.
	 */
	const describeSourceLabel = (source) => {
		switch (source) {
			case 'file':
				return __('Physical file', 'thinkrank');
			case 'custom':
				return __('Custom content', 'thinkrank');
			case 'generated':
				return __('Auto-generated', 'thinkrank');
			case 'wordpress':
				return __('WordPress default', 'thinkrank');
			default:
				return source;
		}
	};

	if (isLoading) {
		return (
			<SkeletonCard
				lines={4}
				label={__('Loading robots.txt settings…', 'thinkrank')}
			/>
		);
	}

	if (loadFailed) {
		return (
			<SettingsLoadError
				onRetry={loadSettings}
				isRetrying={isLoading}
				message={__(
					'Your robots.txt settings could not be read, so this screen has nothing to edit yet. Nothing has changed. Retry to load them.',
					'thinkrank'
				)}
			/>
		);
	}

	return (
		<div className="thinkrank-robots-management">
			<SettingsCard
				title={__('Robots.txt Management', 'thinkrank')}
				enabled={settings.robots_txt_enabled}
				onToggle={(value) =>
					persistSetting(
						{ robots_txt_enabled: value },
						value
							? __('Robots.txt management is on.', 'thinkrank')
							: __('Robots.txt management is off.', 'thinkrank')
					)
				}
				toggleLabel={__('Enable Robots.txt Management', 'thinkrank')}
				toggleBusy={isTogglingFeature}
				optimizationButtons={getOptimizationButtonConfig().map(
					(config, index) => (
						<OptimizationButton
							key={index}
							type={config.type}
							label={config.label}
							loadingLabel={config.loadingLabel}
							onClick={config.onClick}
							isBusy={config.isBusy}
							disabled={config.disabled}
						/>
					)
				)}
				notice={notice}
				onNoticeRemove={() => setNotice(null)}
				showDisabledWarning={true}
				disabledWarningMessage={__(
					'Robots.txt management is disabled. Enable to configure search engine access and generate robots.txt files.',
					'thinkrank'
				)}
			>
				{/* Exact content from SiteIdentityTab robots case */}
				{settings.robots_txt_enabled && (
					<Container direction="column" gap={4}>
						{effective && (
							<Card padding={false}>
								<SectionHeader
									title={__('Currently Served', 'thinkrank')}
									description={__(
										'What crawlers receive when they request /robots.txt right now.',
										'thinkrank'
									)}
									trailing={
										<span
											style={{
												background:
													'var(--tr-bg-muted, #f1f5f9)',
												color: 'var(--tr-text-secondary, #475569)',
												padding: '4px 10px',
												borderRadius: '4px',
												fontSize: '12px',
												fontWeight: '600',
												whiteSpace: 'nowrap',
											}}
										>
											{describeSourceLabel(
												effective.source
											)}
										</span>
									}
								/>
								<Container
									direction="column"
									gap={4}
									className="tr-px-6 tr-py-6"
								>
									<p
										style={{
											fontSize: '13px',
											color: 'var(--tr-text-muted, #64748b)',
											margin: 0,
										}}
									>
										{describeSource(effective.source)}
									</p>

									{/* The one state the admin cannot otherwise
									    detect: what crawlers receive no longer
									    matches the body shown in the editor —
									    either a physical file has drifted, or a
									    site-wide crawl block is overriding the
									    saved rules. */}
									{!effective.in_sync && (
										<div
											style={{
												background:
													'var(--tr-warning-bg, #fffbeb)',
												color: 'var(--tr-warning-text, #92400e)',
												border: '1px solid var(--tr-border-light, #e2e8f0)',
												borderRadius: '4px',
												padding: '12px 14px',
												fontSize: '13px',
												lineHeight: '1.5',
											}}
										>
											{effective.out_of_sync_reason ===
											'crawl_blocked'
												? __(
														'Search engines are discouraged from indexing this site, so crawlers receive “Disallow: /” instead of the rules below. Re-enable “Allow Search Engines” here and “Search engine visibility” under Settings → Reading to serve them.',
														'thinkrank'
													)
												: __(
														'The robots.txt file on your server no longer matches these settings, so your changes are not reaching crawlers. Use “Generate Robots.txt” to rewrite the file from the content below.',
														'thinkrank'
													)}
										</div>
									)}

									{effective.content ? (
										<pre
											style={{
												background:
													'var(--tr-bg-subtle, #f8fafc)',
												border: '1px solid var(--tr-border-light, #e2e8f0)',
												borderRadius: '4px',
												padding: '12px 14px',
												margin: 0,
												fontSize: '12px',
												lineHeight: '1.6',
												color: 'var(--tr-text-body, #334155)',
												whiteSpace: 'pre-wrap',
												wordBreak: 'break-word',
												maxHeight: '260px',
												overflow: 'auto',
											}}
										>
											{effective.content}
										</pre>
									) : (
										<p
											style={{
												fontSize: '13px',
												color: 'var(--tr-text-subtle, #94a3b8)',
												fontStyle: 'italic',
												margin: 0,
											}}
										>
											{__(
												'WordPress is serving its own default robots.txt.',
												'thinkrank'
											)}
										</p>
									)}
								</Container>
							</Card>
						)}

						<Card padding={false}>
							<SectionHeader
								title={__('Search Engine Access', 'thinkrank')}
								description={__(
									'Control how search engines can crawl your site.',
									'thinkrank'
								)}
							/>
							<Container
								direction="column"
								gap={6}
								className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
							>
								<Toggle
									label={__(
										'Allow Search Engines',
										'thinkrank'
									)}
									description={__(
										'Allow search engines to index your site',
										'thinkrank'
									)}
									checked={settings.allow_search_engines}
									onChange={(value) =>
										handleSettingChange(
											'allow_search_engines',
											value
										)
									}
									disabled={!settings.robots_txt_enabled}
								/>

								<FormField
									id="tr-canonical-scheme"
									label={__(
										'Canonical URL Scheme',
										'thinkrank'
									)}
									description={__(
										'The scheme ThinkRank publishes its own URLs with: the canonical tag, og:url, schema IDs, sitemap entries and the Sitemap line below. Leave it on Automatic unless your site sits behind a proxy that handles HTTPS and leaves WordPress reporting the wrong scheme.',
										'thinkrank'
									)}
									className="tr-pt-6"
								>
									<Select
										id="tr-canonical-scheme"
										className="tr-w-[260px]"
										value={
											settings.canonical_scheme ||
											'automatic'
										}
										options={[
											{
												value: 'automatic',
												label: __(
													'Automatic (follow WordPress)',
													'thinkrank'
												),
											},
											{
												value: 'https',
												label: __(
													'Always https',
													'thinkrank'
												),
											},
											{
												value: 'http',
												label: __(
													'Always http',
													'thinkrank'
												),
											},
										]}
										onChange={(e) =>
											handleSettingChange(
												'canonical_scheme',
												e.target.value
											)
										}
									/>
								</FormField>

								<div className="tr-pt-6">
									<Toggle
										id="query-protection"
										label={__(
											'Block Fabricated URLs',
											'thinkrank'
										)}
										description={__(
											'Answer 404 when a URL asks for content that does not exist, such as ?post_type=nosuchtype. Without this WordPress serves your blog listing at 200 for endless made-up variations. Tracking parameters, searches, pagination and previews are never affected.',
											'thinkrank'
										)}
										checked={!!settings.query_protection}
										onChange={(value) =>
											persistSetting(
												{ query_protection: value },
												value
													? __(
															'Fabricated URLs now answer 404.',
															'thinkrank'
														)
													: __(
															'Fabricated URLs are no longer blocked.',
															'thinkrank'
														)
											)
										}
										disabled={isTogglingFeature}
									/>
								</div>

								<div className="tr-pt-6">
									<h4
										style={{
											fontSize: '14px',
											fontWeight: '600',
											color: '#1e293b',
											margin: '0 0 4px 0',
										}}
									>
										{__('Sitemap URLs', 'thinkrank')}
									</h4>
									<p
										style={{
											fontSize: '13px',
											color: '#64748b',
											margin: '0 0 16px 0',
										}}
									>
										{sitemapEnabled
											? __(
													'Sitemap URLs are automatically included from your Sitemap settings configuration.',
													'thinkrank'
												)
											: __(
													'Sitemap generation is currently disabled. Enable it in Sitemap settings to include sitemap URLs in robots.txt.',
													'thinkrank'
												)}
									</p>

									<div
										style={{
											display: 'flex',
											justifyContent: 'space-between',
											alignItems: 'center',
											flexWrap: 'wrap',
											gap: '12px',
										}}
									>
										<div
											style={{
												display: 'flex',
												alignItems: 'center',
												gap: '8px',
												flexWrap: 'wrap',
											}}
										>
											<span
												style={{
													fontSize: '13px',
													fontWeight: '600',
													color: '#1ea293b',
												}}
											>
												{__(
													'Current sitemaps:',
													'thinkrank'
												)}
											</span>
											{sitemapUrls.map(
												(sitemap, index) => (
													<div
														key={index}
														style={{
															display: 'flex',
															alignItems:
																'center',
															gap: '6px',
														}}
													>
														<span
															style={{
																background:
																	'#f1f5f9',
																padding:
																	'4px 8px',
																borderRadius:
																	'4px',
																fontSize:
																	'13px',
																color: '#475569',
															}}
														>
															{sitemap.url}
														</span>
														<span
															style={{
																fontSize:
																	'12px',
																color: '#94a3b8',
															}}
														>
															(
															{sitemap.type_label}
															)
														</span>
													</div>
												)
											)}
											{(!sitemapUrls ||
												sitemapUrls.length === 0) && (
												<span
													style={{
														fontSize: '13px',
														color: '#94a3b8',
														fontStyle: 'italic',
													}}
												>
													{__(
														'No sitemaps configured',
														'thinkrank'
													)}
												</span>
											)}
										</div>
										<Button
											variant="link"
											onClick={() => {
												if (onNavigate) {
													onNavigate(
														'crawling-ai-indexing',
														'xml-sitemap'
													);
												}
											}}
											style={{
												padding: 0,
												textDecoration: 'underline',
												color: '#1e293b',
												fontSize: '13px',
												fontWeight: '500',
												height: 'auto',
											}}
										>
											{__(
												'Configure sitemaps in sitemap settings',
												'thinkrank'
											)}{' '}
											→
										</Button>
									</div>
								</div>
							</Container>
						</Card>

						{/*
						 * Feeds. Not gated on robots_txt_enabled: these change
						 * the feed itself and its response header, not
						 * robots.txt.
						 */}
						<Card padding={false}>
							<SectionHeader
								title={__('Feeds', 'thinkrank')}
								description={__(
									'Content scrapers read your RSS feed to republish posts wholesale. These are the standard defences.',
									'thinkrank'
								)}
							/>
							<Container
								direction="column"
								gap={6}
								className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
							>
								<Toggle
									id="feed-source-link"
									label={__(
										'Link Back To The Original',
										'thinkrank'
									)}
									description={__(
										'Sign each feed entry with a link to the post and to your site, so even a scraped copy links back to you.',
										'thinkrank'
									)}
									checked={!!settings.feed_source_link}
									onChange={(value) =>
										persistSetting(
											{ feed_source_link: value },
											value
												? __(
														'Feed entries now link back to the original.',
														'thinkrank'
													)
												: __(
														'Feed entries no longer link back.',
														'thinkrank'
													)
										)
									}
									disabled={isTogglingFeature}
								/>

								<div className="tr-pt-6">
									<Toggle
										id="feed-excerpt-only"
										label={__(
											'Shorten Entries To An Excerpt',
											'thinkrank'
										)}
										description={__(
											'Send an excerpt instead of the full post, so a scraper gets an intro rather than the article. Your subscribers see less too. Ignored when the Reading settings already send summaries.',
											'thinkrank'
										)}
										checked={!!settings.feed_excerpt_only}
										onChange={(value) =>
											persistSetting(
												{ feed_excerpt_only: value },
												value
													? __(
															'Feeds now send excerpts.',
															'thinkrank'
														)
													: __(
															'Feeds now send the full post.',
															'thinkrank'
														)
											)
										}
										disabled={isTogglingFeature}
									/>
								</div>

								<div className="tr-pt-6">
									<Toggle
										id="feed-noindex"
										label={__(
											'Keep Feeds Out Of Search',
											'thinkrank'
										)}
										description={__(
											'Serve feeds with a noindex header so a feed URL does not compete with the post it duplicates. Leave this off if you publish a podcast, whose feed has to stay indexable.',
											'thinkrank'
										)}
										checked={!!settings.feed_noindex}
										onChange={(value) =>
											persistSetting(
												{ feed_noindex: value },
												value
													? __(
															'Feeds are now kept out of search.',
															'thinkrank'
														)
													: __(
															'Feeds can now be indexed.',
															'thinkrank'
														)
											)
										}
										disabled={isTogglingFeature}
									/>
								</div>
							</Container>
						</Card>

						<Card padding={false}>
							<SectionHeader
								title={__('AI Crawlers', 'thinkrank')}
								description={__(
									'Choose which AI crawlers may read your content. Blocking one writes a rule for it into your robots.txt; everything else you have written there is left alone.',
									'thinkrank'
								)}
								trailing={
									aiCrawlers.length > 0 && (
										<Button
											variant="secondary"
											onClick={() =>
												setAllCrawlerRules(
													blockedCount !==
														aiCrawlers.length
												)
											}
											disabled={
												!settings.robots_txt_enabled ||
												isTogglingFeature
											}
											style={{
												borderColor: '#E2E6F1',
												padding: '0 12px',
												fontSize: '13px',
											}}
										>
											{blockedCount === aiCrawlers.length
												? __(
														'Allow all AI crawlers',
														'thinkrank'
													)
												: __(
														'Block all AI crawlers',
														'thinkrank'
													)}
										</Button>
									)
								}
							/>
							<Container
								direction="column"
								gap={6}
								className="tr-px-6 tr-py-6"
							>
								{aiCrawlers.length === 0 ? (
									<p className="tr-text-sm tr-text-tertiary">
										{__(
											'No AI crawlers are registered.',
											'thinkrank'
										)}
									</p>
								) : (
									<>
										<p className="tr-text-sm tr-text-tertiary">
											{__(
												'Google-Extended is a training control, like GPTBot and ClaudeBot: blocking it stops your content training Gemini. It does not affect Google Search, your rankings, or Google AI Overviews and AI Mode, which use Googlebot.',
												'thinkrank'
											)}
										</p>
										<p className="tr-text-xs tr-text-tertiary">
											{blockedCount === 0
												? __(
														'Every AI crawler is currently allowed, and your robots.txt carries no AI rules.',
														'thinkrank'
													)
												: sprintf(
														/* translators: 1: number of blocked crawlers. 2: total number of known crawlers. */
														__(
															'%1$d of %2$d AI crawlers blocked.',
															'thinkrank'
														),
														blockedCount,
														aiCrawlers.length
													)}
										</p>
										{crawlerGroups().map((group) => (
											<div
												key={group.vendor}
												className="tr-flex tr-flex-col tr-gap-4"
											>
												<h4 className="tr-text-xs tr-font-semibold tr-uppercase tr-tracking-wide tr-text-tertiary tr-m-0">
													{group.label}
												</h4>
												{group.crawlers.map(
													(crawler) => (
														<Toggle
															key={crawler.slug}
															id={`tr-ai-crawler-${crawler.slug}`}
															checked={isBlocked(
																crawler.slug
															)}
															onChange={(value) =>
																setCrawlerRule(
																	crawler.slug,
																	crawler.label,
																	value
																)
															}
															label={sprintf(
																/* translators: %s: crawler user-agent token, e.g. GPTBot. */
																__(
																	'Block %s',
																	'thinkrank'
																),
																crawler.label
															)}
															description={
																crawler.purpose
															}
															disabled={
																!settings.robots_txt_enabled ||
																isTogglingFeature
															}
														/>
													)
												)}
											</div>
										))}
									</>
								)}
							</Container>
						</Card>

						<Card padding={false}>
							<SectionHeader
								title={__('Robots.txt Content', 'thinkrank')}
								description={__(
									'Custom robots.txt content. Leave empty to use auto-generated content.',
									'thinkrank'
								)}
								trailing={
									<Button
										variant="secondary"
										// /robots.txt is served whenever management is
										// enabled — virtually via the robots_txt filter
										// even with no physical file — so gate on that,
										// not on a file existing on disk.
										href={
											settings.robots_txt_enabled
												? `${window.thinkrankAdmin?.siteUrl || window.location.origin}/robots.txt`
												: undefined
										}
										target="_blank"
										rel="noopener noreferrer"
										icon="external"
										disabled={!settings.robots_txt_enabled}
										title={
											!settings.robots_txt_enabled
												? __(
														'Enable Robots.txt management first',
														'thinkrank'
													)
												: undefined
										}
										style={{
											color: '#4451FF',
											borderColor: '#E2E6F1',
											background: '#ECFDF3',
											padding: '0 12px',
											fontSize: '13px',
										}}
									>
										{__('View Robots.txt', 'thinkrank')}
									</Button>
								}
							/>
							<Container
								direction="column"
								gap={6}
								className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
							>
								<FormField
									id="tr-robots-content"
									label={__(
										'Robots.txt Content',
										'thinkrank'
									)}
									description={__(
										'Custom robots.txt content. Leave empty to use auto-generated content.',
										'thinkrank'
									)}
									disabled={!settings.robots_txt_enabled}
								>
									<Textarea
										id="tr-robots-content"
										value={settings.robots_txt_content}
										onChange={(e) =>
											handleSettingChange(
												'robots_txt_content',
												e.target.value
											)
										}
										placeholder={__(
											'Enter a description…',
											'thinkrank'
										)}
										rows={8}
										disabled={!settings.robots_txt_enabled}
									/>
								</FormField>
							</Container>
						</Card>
					</Container>
				)}

				{/* Save Button */}
				<Flex justify="flex-end" className="thinkrank-mt-lg">
					<Button
						variant="primary"
						onClick={saveSettings}
						isBusy={isSaving}
						disabled={!hasChanges || isSaving}
						style={{
							background: '#4451FF',
							color: '#fff',
							border: 'none',
							borderRadius: '4px',
							padding: '6px 16px',
							fontWeight: '500',
							minHeight: '36px',
						}}
					>
						{isSaving
							? __('Saving…', 'thinkrank')
							: __('Save Change', 'thinkrank')}
					</Button>
				</Flex>
			</SettingsCard>
		</div>
	);
};

export default RobotsManagement;
