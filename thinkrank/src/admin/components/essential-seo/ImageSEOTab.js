/**
 * Image SEO Tab Component
 *
 * Handles image-specific SEO settings and optimization:
 * - Missing ALT attribute management
 * - Missing TITLE attribute management
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
	Toggle,
	Select,
	Button,
	Alert,
	Container,
} from '../ui';
import apiFetch from '@wordpress/api-fetch';
import SettingsCard from '../common/SettingsCard';
import SectionHeader from '../common/SectionHeader';
import FormField from '../common/FormField';
import useAutosaveSettings from '../../hooks/useAutosaveSettings';
import logger from '../../../shared/utils/logger';

const separatorMap = {
	pipe: '|',
	dash: '-',
	bullet: '•',
	colon: ':',
	greater: '>',
	tilde: '~',
};

/**
 * Image SEO Tab Component
 */
const ImageSEOTab = () => {
	// State management
	const [isLoading, setIsLoading] = useState(true);
	const [isSaving, setIsSaving] = useState(false);
	const [settings, setSettings] = useState({
		add_missing_alt: false,
		alt_format: '%filename%',
		add_missing_title: false,
		title_format: '%title% %separator% %sitename%',
		save_alt_to_media: false,
		auto_fill_on_upload: false,
		media_alt_overwrite: false,
	});
	const [hasChanges, setHasChanges] = useState(false);

	const [notice, setNotice] = useState(null);
	const [globalSeparator, setGlobalSeparator] = useState('-');

	// Media Library alt-text state
	const [mediaStats, setMediaStats] = useState(null);
	const [isFilling, setIsFilling] = useState(false);
	const [fillProgress, setFillProgress] = useState(0);

	// Image SEO variables
	const imageVars = [
		{ label: __('+ Site Title', 'thinkrank'), value: '%sitename%' },
		{ label: __('+ Post Title', 'thinkrank'), value: '%title%' },
		{ label: __('+ Filename', 'thinkrank'), value: '%filename%' },
		{ label: __('+ Image Title', 'thinkrank'), value: '%image_title%' },
		{ label: __('+ Image Caption', 'thinkrank'), value: '%image_caption%' },
		{ label: __('+ Separator', 'thinkrank'), value: '%separator%' },
		{ label: __('+ Count', 'thinkrank'), value: '%count%' },
	];

	// Mock data for examples. Keep in step with
	// Image_SEO_Manager::generate_attribute_value() — the preview is the only
	// feedback a user gets while choosing a format, so it has to apply the same
	// rules the backend does.
	//
	// %separator% is deliberately absent: the backend does not substitute it,
	// it SPLITS on it (see getExamplePreview below).
	const mockData = {
		'%sitename%': 'ThinkRank',
		'%title%': 'Advanced Image SEO',
		// The backend turns - and _ into spaces, so preview the normalised form.
		'%filename%': 'mountain landscape',
		'%image_title%': 'Mountain Landscape',
		'%image_caption%': 'A beautiful view of the mountains at sunset',
		'%count%': '1',
	};

	// The same map with the attachment-only tokens empty, to show what an image
	// carrying no title or caption actually produces — the empty-segment
	// collapsing is invisible otherwise.
	const mockDataNoMeta = {
		...mockData,
		'%image_title%': '',
		'%image_caption%': '',
	};

	/**
	 * Render a format the way the backend does: substitute tokens, then split on
	 * the separator token, drop segments that resolved to nothing, and re-join
	 * with a spaced separator.
	 * @param {string} format Format string containing %tokens%.
	 * @param {Object} data   Token => value map.
	 * @return {string} Rendered preview.
	 */
	const renderFormat = (format, data) => {
		if (!format) return '';

		let preview = format;
		Object.keys(data).forEach((key) => {
			preview = preview.split(key).join(data[key]);
		});

		const segments = preview
			.split(/%sep(?:arator)?%/)
			.map((segment) => segment.trim())
			.filter((segment) => segment !== '');

		return segments
			.join(` ${globalSeparator} `)
			.replace(/\s+/g, ' ')
			.trim();
	};

	/**
	 * Get example preview string
	 * @param {string} format Format string containing %tokens%.
	 * @return {string} Rendered preview.
	 */
	const getExamplePreview = (format) => renderFormat(format, mockData);

	/**
	 * Same format, for an image with no title or caption of its own.
	 * Returns '' when it matches the main preview, so the extra line is only
	 * rendered when the format actually depends on attachment metadata.
	 * @param {string} format Format string containing %tokens%.
	 * @return {string} Rendered preview, or '' when it matches the main one.
	 */
	const getNoMetaPreview = (format) => {
		const withMeta = renderFormat(format, mockData);
		const withoutMeta = renderFormat(format, mockDataNoMeta);

		return withoutMeta === withMeta ? '' : withoutMeta;
	};

	useEffect(() => {
		loadSettings();
		loadGlobalSettings();
	}, []);

	// Only query Media Library coverage stats once the feature is enabled, so
	// opening the tab with the feature off never runs the count query.
	useEffect(() => {
		if (settings.save_alt_to_media && !mediaStats) {
			loadMediaStats();
		}
	}, [settings.save_alt_to_media]);

	/**
	 * Load Media Library alt-text coverage stats
	 */
	const loadMediaStats = async () => {
		try {
			const data = await apiFetch({
				path: '/thinkrank/v1/image-seo/media-alt/stats',
			});
			if (data) {
				setMediaStats(data);
			}
		} catch (error) {
			logger.error('Failed to load media alt stats:', error);
		}
	};

	/**
	 * Bulk-fill missing alt text into the Media Library, one batch at a time.
	 */
	const runMediaAltFill = async () => {
		// Overwrite mode replaces alt text on every image, including hand-written
		// values, so require an explicit confirmation before starting.
		if (settings.media_alt_overwrite) {
			const proceed = confirm(
				__(
					'Overwrite is on. This will replace the alt text on every image in your Media Library, including any you wrote by hand, using the Alt attribute format above. This cannot be undone. Continue?',
					'thinkrank'
				)
			);
			if (!proceed) {
				return;
			}
		}
		setIsFilling(true);
		setFillProgress(0);
		let offset = 0;
		let totalUpdated = 0;
		let totalLowQuality = 0;
		// AI source only: images the vision call failed for, and the first
		// reason, so a run that fell back to the template says so (#1039).
		let aiFailed = 0;
		let aiError = '';
		let completed = false;
		try {
			// Loop batches until the server reports it has processed the whole
			// library. The cap is a backstop, not an expected exit: at limit 100
			// it covers 200k images, so hitting it means the server never said
			// done and we must not claim success (#322).
			for (let i = 0; i < 2000; i++) {
				const res = await apiFetch({
					path: '/thinkrank/v1/image-seo/media-alt/run',
					method: 'POST',
					data: {
						offset,
						limit: 100,
						overwrite: !!settings.media_alt_overwrite,
					},
				});
				totalUpdated += res.updated || 0;
				totalLowQuality += res.skipped_low_quality || 0;
				aiFailed += res.ai_failed || 0;
				if (!aiError && res.ai_error) {
					aiError = res.ai_error;
				}
				offset = res.next_offset;
				if (res.total > 0) {
					setFillProgress(
						Math.min(100, Math.round((offset / res.total) * 100))
					);
				}
				if (res.done) {
					completed = true;
					break;
				}
			}

			if (!completed) {
				setNotice({
					status: 'error',
					message: sprintf(
						/* translators: %d: number of images updated before stopping */
						__(
							'Stopped before finishing. Some images may not have been processed. Alt text was saved for %d image(s).',
							'thinkrank'
						),
						totalUpdated
					),
				});
				await loadMediaStats();
				return;
			}

			// Images the format would only have given filler ("1", "image", or
			// a default name like "IMG_1234") are left empty on purpose, so say
			// how many (#1124, #1211).
			const lowQualityNote =
				totalLowQuality > 0
					? ' ' +
						sprintf(
							/* translators: %d: number of images left without alt text */
							__(
								'%d image(s) were left unchanged because your format would only give them filler: a number, a word like "image", or a default name such as "IMG_1234" or "Screenshot". Review their alt text by hand, or set Alt text source to AI.',
								'thinkrank'
							),
							totalLowQuality
						)
					: '';

			if (aiFailed > 0) {
				setNotice({
					status: 'error',
					message:
						sprintf(
							/* translators: 1: number of images updated, 2: number of images the AI failed on, 3: the AI provider's error message */
							__(
								'Done. Alt text saved for %1$d image(s), but the AI could not describe %2$d image(s): %3$s Those images kept their alt text, or fell back to the Alt attribute format when they had none.',
								'thinkrank'
							),
							totalUpdated,
							aiFailed,
							aiError
						) + lowQualityNote,
				});
				await loadMediaStats();
				return;
			}

			setNotice({
				status: 'success',
				message:
					sprintf(
						/* translators: %d: number of images updated */
						__(
							'Done. Alt text saved for %d image(s) in the Media Library.',
							'thinkrank'
						),
						totalUpdated
					) + lowQualityNote,
			});
			await loadMediaStats();
		} catch (error) {
			logger.error('Failed to fill media alt text:', error);
			setNotice({
				status: 'error',
				message: __(
					'An error occurred while filling alt text.',
					'thinkrank'
				),
			});
		} finally {
			setIsFilling(false);
			setFillProgress(0);
		}
	};

	/**
	 * Load settings from API
	 */
	const loadSettings = async () => {
		try {
			const data = await apiFetch({
				path: '/thinkrank/v1/image-seo/settings',
			});
			if (data) {
				setSettings(data);
			}
		} catch (error) {
			logger.error('Failed to load image SEO settings:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to load settings. Please try again.',
					'thinkrank'
				),
			});
		} finally {
			setIsLoading(false);
		}
	};

	/**
	 * Load global site identity settings to get separator
	 */
	const loadGlobalSettings = async () => {
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/site-identity/settings',
			});
			if (response.success && response.data.settings.title_separator) {
				const sepSlug = response.data.settings.title_separator;
				setGlobalSeparator(separatorMap[sepSlug] || '-');
			}
		} catch (error) {
			logger.error('Failed to load global settings:', error);
		}
	};

	/**
	 * Save settings to API
	 * @param data
	 */
	const saveSettings = async (data = null) => {
		setIsSaving(true);
		// If data is a React event (from onClick), ignore it and use values from state
		const settingsToSave =
			data && typeof data.preventDefault === 'function'
				? settings
				: data || settings;
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/image-seo/settings',
				method: 'POST',
				data: settingsToSave,
			});

			if (response.success) {
				setHasChanges(false);
				setNotice({
					status: 'success',
					message: __(
						'Image SEO settings saved successfully.',
						'thinkrank'
					),
				});
			} else {
				setNotice({
					status: 'error',
					message:
						response.message ||
						__('Failed to save settings.', 'thinkrank'),
				});
			}
		} catch (error) {
			logger.error('Failed to save image SEO settings:', error);
			setNotice({
				status: 'error',
				message: __(
					'An error occurred while saving settings.',
					'thinkrank'
				),
			});
		} finally {
			setIsSaving(false);
		}
	};

	// The alt, title and Media Library feature switches ARE the save:
	// flipping one persists immediately and rolls back if the request fails.
	// The formats and the Media Library options under the switch keep using
	// the Save Settings button.
	const { update: updateFeature, isSaving: isTogglingFeature } =
		useAutosaveSettings({
			settings,
			setSettings,
			save: async (next) => {
				const response = await apiFetch({
					path: '/thinkrank/v1/image-seo/settings',
					method: 'POST',
					data: next,
				});

				if (!response.success) {
					throw new Error(
						response.message ||
							__('Failed to save settings.', 'thinkrank')
					);
				}
			},
			logLabel: 'Image SEO',
		});

	// AI alt text needs a provider that can read images and AI not paused.
	// The stats response says whether it can run; until it arrives, and
	// whenever it cannot, AI is listed but not selectable, with the reason
	// shown under the control (#1215).
	const aiUnavailable = mediaStats ? mediaStats.ai_unavailable : undefined;
	const aiReady = Boolean(mediaStats) && !aiUnavailable;
	const altSource = settings.alt_source || 'template';
	const altSourceOptions = [
		{
			value: 'template',
			label: __('Alt attribute format', 'thinkrank'),
		},
		{
			value: 'ai',
			label: aiReady
				? __('AI description', 'thinkrank')
				: __('AI description (not available)', 'thinkrank'),
			disabled: !aiReady,
		},
	];

	let altSourceHelp;
	if (aiUnavailable) {
		altSourceHelp = aiUnavailable;
	} else if ('ai' === altSource) {
		altSourceHelp = __(
			'Your AI provider describes each image: one paid request per image, 10 images per batch. An image it cannot describe gets the alt attribute format instead.',
			'thinkrank'
		);
	} else {
		altSourceHelp = __(
			'Alt text is built from the alt attribute format above. Choose AI description to have your AI provider describe each image instead, one paid request per image.',
			'thinkrank'
		);
	}

	/**
	 * Handle reset settings
	 */
	const handleReset = () => {
		if (
			confirm(
				__(
					'Are you sure you want to reset all options? This will restore default formats for Alt and Title attributes and save them immediately.',
					'thinkrank'
				)
			)
		) {
			const defaultSettings = {
				add_missing_alt: false,
				alt_format: '%filename%',
				add_missing_title: false,
				title_format: '%title% %separator% %sitename%',
				save_alt_to_media: false,
				auto_fill_on_upload: false,
				media_alt_overwrite: false,
				alt_source: 'template',
			};
			setSettings(defaultSettings);
			saveSettings(defaultSettings);
		}
	};

	/**
	 * Handle setting change
	 * @param key
	 * @param value
	 */
	const handleSettingChange = (key, value) => {
		setSettings((prev) => ({ ...prev, [key]: value }));
		setHasChanges(true);
	};

	/**
	 * Insert variable into field
	 * @param key
	 * @param variableValue
	 */
	const insertVariable = (key, variableValue) => {
		let currentVal = settings[key] || '';
		if (currentVal.length > 0 && !currentVal.endsWith(' ')) {
			currentVal += ' ';
		}
		handleSettingChange(key, currentVal + variableValue);
	};

	/**
	 * Render variable tags help
	 * @param settingKey
	 * @param disabled
	 */
	const renderVariableTags = (settingKey, disabled) => (
		<span
			className={`thinkrank-variable-tags-container ${disabled ? 'is-disabled' : ''}`}
			style={{ display: 'block' }}
		>
			{__('Use variables:', 'thinkrank')}{' '}
			{imageVars.map((variable, index) => (
				<button
					key={index}
					type="button"
					disabled={disabled}
					onClick={
						disabled
							? undefined
							: () => insertVariable(settingKey, variable.value)
					}
					className={`thinkrank-variable-tag ${disabled ? 'is-disabled' : ''}`}
					title={
						disabled
							? undefined
							: __('Click to insert', 'thinkrank')
					}
				>
					{variable.label}
				</button>
			))}
		</span>
	);

	/**
	 * Render help text with example
	 * @param settingKey
	 * @param format
	 * @param disabled
	 */
	const renderHelp = (settingKey, format, disabled) => (
		<span className="thinkrank-image-seo-help" style={{ display: 'block' }}>
			{!disabled && (
				<span
					className="thinkrank-example-preview thinkrank-mb-sm"
					style={{ display: 'block', marginBottom: '8px' }}
				>
					<span
						className="thinkrank-example-label"
						style={{
							fontSize: '10px',
							fontWeight: '700',
							textTransform: 'uppercase',
							color: '#787c82',
							marginRight: '8px',
							letterSpacing: '1px',
						}}
					>
						{__('EXAMPLE', 'thinkrank')}
					</span>
					<span
						className="thinkrank-example-value"
						style={{ color: '#2271b1' }}
					>
						{getExamplePreview(format)}
					</span>
				</span>
			)}
			{Boolean(format) && Boolean(getNoMetaPreview(format)) && (
				<span
					className="thinkrank-example-preview"
					style={{ display: 'block', marginTop: '2px' }}
				>
					<span
						style={{
							fontSize: '11px',
							fontWeight: '600',
							textTransform: 'uppercase',
							color: '#787c82',
							marginRight: '8px',
							letterSpacing: '1px',
						}}
					>
						{__('NO CAPTION', 'thinkrank')}
					</span>
					<span
						className="thinkrank-example-value"
						style={{ color: '#787c82' }}
					>
						{getNoMetaPreview(format)}
					</span>
				</span>
			)}
			<span
				className="thinkrank-help-text thinkrank-mb-xs"
				style={{ display: 'block', marginBottom: '4px' }}
			>
				{settingKey === 'alt_format'
					? __(
							'Format used for the new alt attribute values.',
							'thinkrank'
						)
					: __(
							'Format used for the new title attribute values.',
							'thinkrank'
						)}
			</span>
			{renderVariableTags(settingKey, disabled)}
		</span>
	);

	if (isLoading) {
		return (
			<div className="tr-flex tr-justify-center tr-mt-8">
				<SkeletonCard lines={3} />
			</div>
		);
	}

	/**
	 * Whether the bulk fill would be a no-op, so the action can be disabled.
	 *
	 * An empty library has nothing to write. A fully-covered library also has
	 * nothing to write *unless* overwrite mode is on, in which case the fill
	 * intentionally replaces existing alt text.
	 */
	const nothingToFill =
		!!mediaStats &&
		(mediaStats.total === 0 ||
			(mediaStats.missing === 0 && !settings.media_alt_overwrite));

	return (
		<div className="tab-content">
			{notice && (
				<Alert
					variant={notice.status === 'success' ? 'success' : 'error'}
					onDismiss={() => setNotice(null)}
					className="tr-mb-4"
				>
					{notice.message}
				</Alert>
			)}

			<SettingsCard
				title={__('Image SEO Settings', 'thinkrank')}
				notice={null}
				onNoticeRemove={() => setNotice(null)}
			>
				<Container direction="column" gap={4}>
					{/* Alt Attribute Settings Section */}
					<Card padding={false}>
						<SectionHeader
							title={__('Alt Attribute Settings', 'thinkrank')}
							description={__(
								'Automatically generate missing alt attributes for images.',
								'thinkrank'
							)}
							trailing={
								<Toggle
									checked={settings.add_missing_alt}
									onChange={(val) =>
										updateFeature(
											{ add_missing_alt: val },
											val
												? __(
														'Image alt text is on.',
														'thinkrank'
													)
												: __(
														'Image alt text is off.',
														'thinkrank'
													)
										)
									}
									label={__(
										'Enable alt attributes',
										'thinkrank'
									)}
									disabled={isTogglingFeature}
								/>
							}
						/>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							{!settings.add_missing_alt && (
								<Alert variant="warning">
									{__(
										'This feature is disabled. Enable it to access configuration options.',
										'thinkrank'
									)}
								</Alert>
							)}
							{settings.add_missing_alt &&
								!settings.alt_format?.trim() && (
									<Alert variant="warning">
										{
											/* translators: %filename% is a literal token variable and must not be translated */
											__(
												'Empty formats detected: Enable variables like %filename% to generate attributes automatically.',
												'thinkrank'
											)
										}
									</Alert>
								)}
							<FormField
								id="tr-is-alt-format"
								label={__('Alt attribute format', 'thinkrank')}
								disabled={!settings.add_missing_alt}
								className={
									!settings.add_missing_alt ||
									(settings.add_missing_alt &&
										!settings.alt_format?.trim())
										? 'tr-pt-6'
										: ''
								}
							>
								<Input
									id="tr-is-alt-format"
									value={settings.alt_format}
									onChange={(e) =>
										handleSettingChange(
											'alt_format',
											e.target.value
										)
									}
									placeholder="%filename%"
									disabled={!settings.add_missing_alt}
								/>
								{renderHelp(
									'alt_format',
									settings.alt_format,
									!settings.add_missing_alt
								)}
							</FormField>
						</Container>
					</Card>

					{/* Title Attribute Settings Section */}
					<Card padding={false}>
						<SectionHeader
							title={__('Title Attribute Settings', 'thinkrank')}
							description={__(
								'Automatically generate missing title attributes for images.',
								'thinkrank'
							)}
							trailing={
								<Toggle
									checked={settings.add_missing_title}
									onChange={(val) =>
										updateFeature(
											{ add_missing_title: val },
											val
												? __(
														'Image title text is on.',
														'thinkrank'
													)
												: __(
														'Image title text is off.',
														'thinkrank'
													)
										)
									}
									label={__(
										'Enable title attributes',
										'thinkrank'
									)}
									disabled={isTogglingFeature}
								/>
							}
						/>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							{!settings.add_missing_title && (
								<Alert variant="warning">
									{__(
										'This feature is disabled. Enable it to access configuration options.',
										'thinkrank'
									)}
								</Alert>
							)}
							{settings.add_missing_title &&
								!settings.title_format?.trim() && (
									<Alert variant="warning">
										{
											/* translators: %filename% is a literal token variable and must not be translated */
											__(
												'Empty formats detected: Enable variables like %filename% to generate attributes automatically.',
												'thinkrank'
											)
										}
									</Alert>
								)}
							<FormField
								id="tr-is-title-format"
								label={__(
									'Title attribute format',
									'thinkrank'
								)}
								disabled={!settings.add_missing_title}
								className={
									!settings.add_missing_title ||
									(settings.add_missing_title &&
										!settings.title_format?.trim())
										? 'tr-pt-6'
										: ''
								}
							>
								<Input
									id="tr-is-title-format"
									value={settings.title_format}
									onChange={(e) =>
										handleSettingChange(
											'title_format',
											e.target.value
										)
									}
									placeholder="%title% %separator% %sitename%"
									disabled={!settings.add_missing_title}
								/>
								{renderHelp(
									'title_format',
									settings.title_format,
									!settings.add_missing_title
								)}
							</FormField>
						</Container>
					</Card>

					{/* Media Library Alt Text Section */}
					<Card padding={false}>
						<SectionHeader
							title={__('Media Library alt text', 'thinkrank')}
							description={__(
								'Save generated alt text onto the image record itself, so it works everywhere not just in rendered content.',
								'thinkrank'
							)}
							trailing={
								<Toggle
									checked={settings.save_alt_to_media}
									onChange={(val) =>
										updateFeature(
											{ save_alt_to_media: val },
											val
												? __(
														'Media Library alt text is on.',
														'thinkrank'
													)
												: __(
														'Media Library alt text is off.',
														'thinkrank'
													)
										)
									}
									label={__(
										'Save alt text to the Media Library',
										'thinkrank'
									)}
									disabled={isTogglingFeature}
								/>
							}
						/>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							{!settings.save_alt_to_media && (
								<Alert variant="warning">
									{__(
										'This feature is disabled. Enable it to fill and manage Media Library alt text.',
										'thinkrank'
									)}
								</Alert>
							)}

							{settings.save_alt_to_media && (
								<>
									{mediaStats &&
										(mediaStats.total === 0 ? (
											// An empty library is neither a success nor a warning:
											// "All 0 images ... have alt text" read as a nonsense
											// green tick.
											<Alert variant="info">
												{__(
													'No images found in your Media Library.',
													'thinkrank'
												)}
											</Alert>
										) : (
											<Alert
												variant={
													mediaStats.missing > 0
														? 'warning'
														: 'success'
												}
											>
												{mediaStats.missing > 0
													? sprintf(
															/* translators: 1: images missing descriptive alt text, 2: total images */
															__(
																'%1$d of %2$d images in your Media Library have missing or non-descriptive alt text.',
																'thinkrank'
															),
															mediaStats.missing,
															mediaStats.total
														)
													: sprintf(
															/* translators: %d: total images */
															__(
																'All %d images in your Media Library have descriptive alt text.',
																'thinkrank'
															),
															mediaStats.total
														)}
											</Alert>
										))}

									<FormField
										id="tr-is-alt-source"
										label={__(
											'Alt text source',
											'thinkrank'
										)}
										description={__(
											'Where Media Library alt text comes from when it is filled.',
											'thinkrank'
										)}
										className="tr-pt-6"
									>
										<div style={{ maxWidth: '320px' }}>
											<Select
												id="tr-is-alt-source"
												value={
													settings.alt_source ||
													'template'
												}
												options={altSourceOptions}
												disabled={isTogglingFeature}
												description={altSourceHelp}
												onChange={(e) =>
													updateFeature(
														{
															alt_source:
																e.target.value,
														},
														'ai' === e.target.value
															? __(
																	'Alt text will be written by AI.',
																	'thinkrank'
																)
															: __(
																	'Alt text will be built from your alt attribute format.',
																	'thinkrank'
																)
													)
												}
											/>
										</div>
									</FormField>

									<FormField
										id="tr-is-auto-fill"
										label={__(
											'Fill alt text on upload',
											'thinkrank'
										)}
										className="tr-pt-6"
									>
										<Toggle
											checked={
												settings.auto_fill_on_upload
											}
											onChange={(val) =>
												handleSettingChange(
													'auto_fill_on_upload',
													val
												)
											}
											label={__(
												'Automatically save alt text when a new image is uploaded.',
												'thinkrank'
											)}
										/>
									</FormField>

									<FormField
										id="tr-is-overwrite"
										label={__(
											'Overwrite existing alt text',
											'thinkrank'
										)}
										className="tr-pt-6"
									>
										<Toggle
											checked={
												settings.media_alt_overwrite
											}
											onChange={(val) =>
												handleSettingChange(
													'media_alt_overwrite',
													val
												)
											}
											label={__(
												'Replace alt text that is already set, instead of only filling images missing it.',
												'thinkrank'
											)}
										/>
									</FormField>

									{/*
									 * The bulk action goes last: both toggles above change what it
									 * does (overwrite in particular), and the fill uses the *saved*
									 * settings, so the controls should be configured and saved
									 * before the action is reached.
									 */}
									<FormField
										id="tr-is-fill-now"
										label={__(
											'Fill missing alt text now',
											'thinkrank'
										)}
										className="tr-pt-6"
									>
										<div
											className="tr-flex tr-items-center"
											style={{ gap: '12px' }}
										>
											{/*
											 * tr-shrink-0 + tr-whitespace-nowrap: this Button is a flex
											 * child next to a long help string. Flex items shrink by
											 * default, which squeezed the button until its label broke
											 * onto two lines ("Fill missing / alt text").
											 */}
											<Button
												variant="primary"
												size="md"
												className="tr-shrink-0 tr-whitespace-nowrap"
												onClick={runMediaAltFill}
												loading={isFilling}
												disabled={
													isFilling ||
													hasChanges ||
													nothingToFill
												}
											>
												{isFilling
													? `${__('Filling…', 'thinkrank')} ${fillProgress}%`
													: __(
															'Fill missing alt text',
															'thinkrank'
														)}
											</Button>
											<span
												className="thinkrank-help-text tr-min-w-0"
												style={{
													fontSize: '12px',
													color: '#787c82',
												}}
											>
												{hasChanges
													? __(
															'Save your settings before filling: the fill uses the saved Alt attribute format.',
															'thinkrank'
														)
													: mediaStats?.total === 0
														? __(
																'There are no images to fill.',
																'thinkrank'
															)
														: nothingToFill
															? __(
																	'Every image already has alt text. Turn on “Overwrite existing alt text” to replace them.',
																	'thinkrank'
																)
															: __(
																	'Uses the saved Alt attribute format above.',
																	'thinkrank'
																)}
											</span>
										</div>
									</FormField>
								</>
							)}
						</Container>
					</Card>
				</Container>

				{/* Actions */}
				<div
					className="tr-flex tr-justify-end tr-mt-6"
					style={{ gap: '12px' }}
				>
					<button
						onClick={handleReset}
						disabled={isSaving}
						style={{
							background: 'none',
							border: 'none',
							cursor: isSaving ? 'not-allowed' : 'pointer',
							color: '#921C13',
							fontSize: '13px',
							padding: '0',
							textDecoration: 'underline',
						}}
					>
						{__('Reset Options', 'thinkrank')}
					</button>
					<Button
						variant="primary"
						size="md"
						onClick={saveSettings}
						loading={isSaving}
						disabled={isSaving || !hasChanges}
					>
						{__('Save Settings', 'thinkrank')}
					</Button>
				</div>
			</SettingsCard>
		</div>
	);
};

export default ImageSEOTab;
