/**
 * Bulk SEO Optimization Tab Component
 *
 * Manages bulk SEO optimization settings for different post types including:
 * - Single Post Title
 * - Single Post Description
 * - Schema Type
 * - Article Type
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Card,
	Input,
	Select,
	Toggle,
	Button,
	Alert,
	Container,
	Label,
	Text,
	Title,
	CharacterCounter,
	SkeletonCard,
	SettingsLoadError,
} from '../ui';
import SearchPreview from '../common/SearchPreview';
import SectionHeader from '../common/SectionHeader';
import FormField from '../common/FormField';
import RobotMetaSettings from './RobotMetaSettings';
import ContentTypeMatrix from './ContentTypeMatrix';
import AutoAiSection from './AutoAiSection';
import BulkSnippetsTab from './bulk-snippets/BulkSnippetsTab';
import ThinContentTab from './thin-content/ThinContentTab';
import TemplatePreview from './TemplatePreview';
import notify from '../../utils/notify';
import { restUrl } from '../../utils/restUrl';
import logger from '../../../shared/utils/logger';

const TITLE_LENGTH = 60;
const DESCRIPTION_LENGTH = 160;
const TITLE_SEPARATOR = '-';

const SAMPLE_EXCERPT = __(
	'This is a sample excerpt showing how your description will appear in search results.',
	'thinkrank'
);

/**
 * Tokens that resolve on a WooCommerce product only. The same list the
 * editor's General panel offers on a product.
 */
const PRODUCT_VARIABLES = [
	{ label: __('+ Price', 'thinkrank'), value: '%price%' },
	{ label: __('+ Sale price', 'thinkrank'), value: '%sale_price%' },
	{ label: __('+ SKU', 'thinkrank'), value: '%sku%' },
	{ label: __('+ Stock status', 'thinkrank'), value: '%stock_status%' },
	{
		label: __('+ Short description', 'thinkrank'),
		value: '%short_description%',
	},
	{ label: __('+ Brand', 'thinkrank'), value: '%brand%' },
];

/**
 * A custom field (post meta) value. `field-name` is replaced with the key.
 */
const CUSTOM_FIELD_VARIABLE = {
	label: __('+ Custom field', 'thinkrank'),
	value: '%customfield(field-name)%',
};

const truncate = (str, max) => {
	if (!str) {
		return '';
	}
	if (str.length <= max) {
		return str;
	}
	return str.slice(0, max - 1).trimEnd() + '…';
};

const resolveTemplate = (template, vars) =>
	(template || '').replace(/%([a-z_]+)%/gi, (match, key) =>
		Object.prototype.hasOwnProperty.call(vars, key) ? vars[key] : match
	);

/**
 * Bulk SEO Optimization Tab Component
 *
 * @param {Object} props
 * @param {string} props.activeSubSection Active sub-section slug, e.g. "post-seo".
 */
const GlobalSEOTab = ({ activeSubSection }) => {
	// The matrix is its own screen inside this section: it edits every content
	// type at once rather than the single post type the rest of this tab does.
	const isMatrix = activeSubSection === 'content-type-matrix';
	// Auto AI is the Pro slot for metadata on publish — the same job as the
	// rest of this section, for the posts that don't exist yet (#707).
	const isAutoAi = activeSubSection === 'auto-ai';
	// Bulk Snippets edits per-post values across a post type; it has no
	// per-type settings object to load either (#727).
	const isBulkSnippets = activeSubSection === 'bulk-snippets';
	// Thin Content reports across every post type at once and owns its own
	// data, so it has no per-type settings object to load either (#565).
	const isThinContent = activeSubSection === 'thin-content';

	const [settings, setSettings] = useState({});
	const [isSaving, setIsSaving] = useState(false);
	const [isTogglingFeature, setIsTogglingFeature] = useState(false);
	const [isLoading, setIsLoading] = useState(true);
	// True once a settings GET has failed. The form is not rendered while it is
	// set, so the defaults object can never be saved over stored values (#390).
	const [loadFailed, setLoadFailed] = useState(false);
	const [hasChanges, setHasChanges] = useState(false);
	const [notice, setNotice] = useState(null);

	// Get current post type from activeSubSection (e.g., 'post-seo' -> 'post')
	const currentPostType = activeSubSection
		? activeSubSection.replace('-seo', '')
		: 'post';

	// These settings are stored per post type, and switching post type reloads
	// the whole object. Keep the post type on screen in a ref so an in-flight
	// autosave can tell whether its rollback would land on the settings it
	// actually came from, or on another post type's freshly loaded values.
	const postTypeRef = useRef(currentPostType);
	useEffect(() => {
		postTypeRef.current = currentPostType;
	}, [currentPostType]);

	// Get post type data from localized script
	const postTypes = window.thinkrankAdmin?.postTypes || [];
	const currentPostTypeData =
		postTypes.find((pt) => pt.slug === currentPostType) || {};

	// Schema type options
	const schemaTypeOptions = [
		{ label: __('Article', 'thinkrank'), value: 'Article' },
		{ label: __('WebPage', 'thinkrank'), value: 'WebPage' },
		{ label: __('Media', 'thinkrank'), value: 'Media' },
		{ label: __('Product', 'thinkrank'), value: 'Product' },
	];

	// Article type options
	const articleTypeOptions = [
		{ label: __('Article', 'thinkrank'), value: 'Article' },
		{ label: __('NewsArticle', 'thinkrank'), value: 'NewsArticle' },
		{ label: __('BlogPosting', 'thinkrank'), value: 'BlogPosting' },
	];

	// Media type options
	const mediaTypeOptions = [
		{ label: __('ImageObject', 'thinkrank'), value: 'ImageObject' },
		{ label: __('VideoObject', 'thinkrank'), value: 'VideoObject' },
	];

	// Load settings on mount and when post type changes. Reset the dirty flag so
	// a stale hasChanges from the previous post type doesn't leave Save enabled
	// against freshly-loaded data (which would POST the wrong type's values).
	useEffect(() => {
		let cancelled = false;

		// The matrix and Auto AI screens own their own data; there is no post
		// type to load.
		if (isMatrix || isAutoAi || isBulkSnippets || isThinContent) {
			return undefined;
		}

		setHasChanges(false);
		loadSettings(currentPostType, () => cancelled);

		// Switch Post → Page → Post faster than the responses land and the Page
		// response could arrive last, populating the form while the dropdown
		// read "Post". Editing any field then enabled Save, and the save writes
		// to `currentPostType` — so Page's title template, schema type and
		// robots meta went onto Post (#404). These are raw fetch() calls, so
		// they also bypass the apiCache in-flight dedupe that would otherwise
		// mask the ordering. The feature-switch autosave on this screen already
		// pins the post type as an explicit argument for the same reason.
		return () => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [currentPostType, isMatrix, isAutoAi, isBulkSnippets, isThinContent]);

	const loadSettings = async (
		postType = currentPostType,
		isCancelled = () => false
	) => {
		setIsLoading(true);
		try {
			// Built through restUrl() rather than by pasting a `?` onto
			// apiUrl: under Plain permalinks apiUrl is already
			// `index.php?rest_route=...`, and the second `?` was folded into
			// the route value, so every load 404'd and this screen could never
			// leave its error state (#531).
			const response = await fetch(
				restUrl('global-seo/settings', { post_type: postType }),
				{
					headers: {
						'X-WP-Nonce': window.thinkrankAdmin.restNonce,
					},
				}
			);

			if (isCancelled()) {
				return;
			}

			if (!response.ok) {
				throw new Error(`Settings request failed: ${response.status}`);
			}

			const data = await response.json();

			if (isCancelled()) {
				return;
			}

			if (!data.success || !data.data) {
				throw new Error('Settings response carried no settings');
			}

			// The endpoint merges saved values over the per-post-type
			// defaults, so a post type that has never been saved still comes
			// back populated — an empty response is a failure, not an
			// unconfigured post type.
			setSettings(data.data);
			setLoadFailed(false);
		} catch (error) {
			// The failed load deliberately leaves `settings` alone. Falling
			// back to a hard-coded defaults object rendered a form that looked
			// like the user's own configuration, and the next Save posted it
			// over their stored values — so that fallback (and the local
			// defaults table it needed) is gone (#390).
			if (isCancelled()) {
				return;
			}

			logger.error(
				'Error loading bulk SEO optimization settings:',
				error
			);
			setLoadFailed(true);
			setNotice({
				status: 'error',
				message: __(
					'Could not load your Global SEO settings. Nothing has been changed. Retry before editing.',
					'thinkrank'
				),
			});
		} finally {
			if (!isCancelled()) {
				setIsLoading(false);
			}
		}
	};

	/**
	 * POST a settings object for one post type, throwing on failure.
	 *
	 * The post type is an explicit argument rather than read from the closure so
	 * the autosave path can pin the type it started with.
	 * @param {string} postType Post type slug the settings belong to.
	 * @param {Object} payload  Settings object to persist.
	 */
	const postSettings = async (postType, payload) => {
		const response = await fetch(restUrl('global-seo/settings'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.thinkrankAdmin.restNonce,
			},
			body: JSON.stringify({
				post_type: postType,
				settings: payload,
			}),
		});

		if (!response.ok) {
			throw new Error('Failed to save settings');
		}
	};

	const saveSettings = async () => {
		// Belt and braces: the form is not rendered while loadFailed is set.
		if (loadFailed) {
			return;
		}

		setIsSaving(true);
		setNotice(null);

		try {
			await postSettings(currentPostType, settings);

			setNotice({
				status: 'success',
				message: __(
					'Bulk SEO Optimization settings saved successfully!',
					'thinkrank'
				),
			});
			setHasChanges(false);
		} catch (error) {
			setNotice({
				status: 'error',
				message: __(
					'Failed to save settings. Please try again.',
					'thinkrank'
				),
			});
		} finally {
			setIsSaving(false);
		}
	};

	const updateSetting = (key, value) => {
		setSettings((prev) => ({ ...prev, [key]: value }));
		setHasChanges(true);
	};

	/**
	 * Enable/disable Link Suggestions for the post type on screen.
	 *
	 * House rule: the feature switch IS the save — set optimistically, persist,
	 * roll back on failure, and never mark the form dirty. The post type is
	 * captured at flip time and the request always carries it, so a save can
	 * never be attributed to whichever post type the user switched to while it
	 * was in flight; the rollback is likewise skipped once the screen has moved
	 * on, so it can't stamp a stale value onto freshly loaded settings.
	 * @param {boolean} value New link_suggestions value.
	 */
	const persistLinkSuggestions = async (value) => {
		const postType = currentPostType;
		const previous = settings.link_suggestions;

		setSettings((prev) => ({ ...prev, link_suggestions: value }));
		setIsTogglingFeature(true);

		try {
			await postSettings(postType, {
				...settings,
				link_suggestions: value,
			});

			notify.success(
				value
					? __('Link suggestions are on.', 'thinkrank')
					: __('Link suggestions are off.', 'thinkrank')
			);
		} catch (error) {
			logger.error('ThinkRank: autosave failed', error);

			if (postTypeRef.current === postType) {
				setSettings((prev) => ({
					...prev,
					link_suggestions: previous,
				}));
			}

			notify.error(__('Couldn’t save that change.', 'thinkrank'));
		} finally {
			setIsTogglingFeature(false);
		}
	};

	const postTypeLabel =
		currentPostTypeData.singular_name ||
		currentPostTypeData.label ||
		currentPostType;
	const postTypePluralLabel =
		currentPostTypeData.plural_name ||
		currentPostTypeData.label ||
		currentPostType;
	const postTypePluralLower = postTypePluralLabel.toLowerCase();

	// Variable picker — click to insert into a field
	const insertVariable = (key, value) => {
		let current = settings[key] || '';
		if (current.length > 0 && !current.endsWith(' ')) {
			current += ' ';
		}
		updateSetting(key, current + value);
	};

	const renderVariableHints = (key, variables) => (
		<Text as="div" size={12} color="help" className="tr-mt-1">
			{__('Use variables:', 'thinkrank')}{' '}
			{variables.map((variable, index) => (
				<button
					type="button"
					key={index}
					onClick={() => insertVariable(key, variable.value)}
					className="thinkrank-variable-tag"
					title={__('Click to insert', 'thinkrank')}
				>
					{variable.label}
				</button>
			))}
		</Text>
	);

	// The product tokens resolve to nothing off a WooCommerce product, so
	// they are offered on the product screen only, as in the editor (#715).
	// A custom field resolves on any type, so it is offered everywhere; the
	// chip inserts the token with a placeholder key to replace (#1277).
	const extraVariables = [
		...('product' === currentPostType ? PRODUCT_VARIABLES : []),
		CUSTOM_FIELD_VARIABLE,
	];

	const titleVariables = [
		{ label: __('+ Title', 'thinkrank'), value: '%title%' },
		{ label: __('+ Site Title', 'thinkrank'), value: '%sitename%' },
		{ label: __('+ Separator', 'thinkrank'), value: '%sep%' },
		...extraVariables,
	];

	const descriptionVariables = [
		{ label: __('+ Excerpt', 'thinkrank'), value: '%excerpt%' },
		{ label: __('+ Site Title', 'thinkrank'), value: '%sitename%' },
		{ label: __('+ Title', 'thinkrank'), value: '%title%' },
		{ label: __('+ Separator', 'thinkrank'), value: '%sep%' },
		...extraVariables,
	];

	const siteName = window.thinkrankAdmin?.siteName || '';
	const siteUrl =
		window.thinkrankAdmin?.siteUrl ||
		(typeof window !== 'undefined' ? window.location.origin : '');
	const faviconURL = window.thinkrankAdmin?.faviconUrl || '';

	const previewVars = {
		// Name the content type being configured. This was hardcoded to
		// "Sample Post Title", so the Pages / Media / PDF Documents screens all
		// previewed as if they were posts — the one part of the preview that
		// tells you which screen you're on was the part that lied.
		title: sprintf(
			/* translators: %s: singular content type name, e.g. Page, Media. */
			__('Sample %s Title', 'thinkrank'),
			postTypeLabel
		),
		sitename: siteName,
		sep: TITLE_SEPARATOR,
		excerpt: SAMPLE_EXCERPT,
	};

	const titleTemplate = settings.title || '';
	const descriptionTemplate = settings.description || '';

	const renderSeoSettingsCard = () => (
		<Card padding={false}>
			<SectionHeader
				title={`${postTypePluralLabel} ${__('SEO Settings', 'thinkrank')}`}
				description={`${__('Configure default SEO settings for all', 'thinkrank')} ${postTypePluralLower}.`}
			/>
			<Container
				direction="column"
				gap={6}
				className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
			>
				<FormField
					id="tr-gseo-title"
					label={__('Title Format', 'thinkrank')}
					description={__(
						'Default title template for this post type.',
						'thinkrank'
					)}
					counter={
						<CharacterCounter
							value={resolveTemplate(titleTemplate, previewVars)}
							max={TITLE_LENGTH}
						/>
					}
				>
					<Input
						id="tr-gseo-title"
						value={titleTemplate}
						onChange={(e) => updateSetting('title', e.target.value)}
						placeholder="%title% %sep% %sitename%"
					/>
					{renderVariableHints('title', titleVariables)}
				</FormField>

				<FormField
					id="tr-gseo-description"
					label={__('Description Format', 'thinkrank')}
					description={__(
						'Default meta description template for this post type.',
						'thinkrank'
					)}
					counter={
						<CharacterCounter
							value={resolveTemplate(
								descriptionTemplate,
								previewVars
							)}
							max={DESCRIPTION_LENGTH}
						/>
					}
					className="tr-pt-6"
				>
					<Input
						id="tr-gseo-description"
						value={descriptionTemplate}
						onChange={(e) =>
							updateSetting('description', e.target.value)
						}
						placeholder="%excerpt%"
					/>
					{renderVariableHints('description', descriptionVariables)}
				</FormField>

				<FormField
					id="tr-gseo-schema-type"
					label={__('Default Schema Type', 'thinkrank')}
					description={__(
						'Structured-data type assigned to each item by default.',
						'thinkrank'
					)}
					className="tr-pt-6"
				>
					<Select
						id="tr-gseo-schema-type"
						value={settings.schema_type || 'WebPage'}
						options={schemaTypeOptions}
						onChange={(e) =>
							updateSetting('schema_type', e.target.value)
						}
					/>
				</FormField>

				{settings.schema_type === 'Article' && (
					<FormField
						id="tr-gseo-article-type"
						label={__('Default Article Type', 'thinkrank')}
						description={__(
							'Sub-type used when the schema type is Article.',
							'thinkrank'
						)}
						className="tr-pt-6"
					>
						<Select
							id="tr-gseo-article-type"
							value={settings.article_type || ''}
							options={articleTypeOptions}
							onChange={(e) =>
								updateSetting('article_type', e.target.value)
							}
						/>
					</FormField>
				)}

				{settings.schema_type === 'Media' && (
					<FormField
						id="tr-gseo-media-type"
						label={__('Default Media Type', 'thinkrank')}
						description={__(
							'Media sub-type used when the schema type is Media.',
							'thinkrank'
						)}
						className="tr-pt-6"
					>
						<Select
							id="tr-gseo-media-type"
							value={settings.media_type || ''}
							options={mediaTypeOptions}
							onChange={(e) =>
								updateSetting('media_type', e.target.value)
							}
						/>
					</FormField>
				)}

				<div className="tr-pt-6">
					<Container direction="column" gap={2.5}>
						<Label>
							{__('Search Engine Preview', 'thinkrank')}
						</Label>
						<SearchPreview
							siteTitle={siteName}
							permalink={siteUrl}
							faviconURL={faviconURL}
							title={truncate(
								resolveTemplate(titleTemplate, previewVars),
								TITLE_LENGTH
							)}
							description={truncate(
								resolveTemplate(
									descriptionTemplate,
									previewVars
								),
								DESCRIPTION_LENGTH
							)}
						/>
					</Container>
				</div>

				<div className="tr-pt-6">
					<TemplatePreview
						postType={currentPostType}
						title={titleTemplate}
						description={descriptionTemplate}
					/>
				</div>
			</Container>
		</Card>
	);

	const renderAdvancedRobotsField = ({
		checkedKey,
		label,
		inputKey,
		options = null,
		description,
	}) => {
		const advanced = settings.advanced_robots_meta || {};
		const enabled = advanced[checkedKey] ?? true;
		const checkboxId = `tr-advrobots-${checkedKey}`;

		// The checkbox has always had a label; the value control beside it had no
		// name of any kind, so a screen reader announced an unnamed spin button
		// reading "-1" and an unnamed combo box (#842). The visible label is the
		// checkbox's, which reads as the pair's heading rather than as this
		// control's name, so the name is supplied here and hidden from view: the
		// layout is unchanged and only assistive tech gains anything.
		const valueId = `tr-advrobots-${inputKey}`;
		const valueLabel = options
			? sprintf(
					/* translators: %s: robots directive, e.g. Image Preview. */
					__('%s size', 'thinkrank'),
					label
				)
			: sprintf(
					/* translators: %s: robots directive, e.g. Snippet. */
					__('%s limit', 'thinkrank'),
					label
				);
		return (
			<Container direction="column" gap={2}>
				<div className="tr-flex tr-items-center tr-gap-2">
					<input
						id={checkboxId}
						type="checkbox"
						checked={enabled}
						onChange={(e) =>
							updateSetting('advanced_robots_meta', {
								...advanced,
								[checkedKey]: e.target.checked,
							})
						}
					/>
					<label
						htmlFor={checkboxId}
						className="tr-text-sm tr-text-secondary tr-m-0 tr-cursor-pointer"
					>
						{label}
					</label>
				</div>
				{options ? (
					<Select
						id={valueId}
						label={valueLabel}
						hideLabelFromVision
						value={
							advanced[inputKey] ||
							(options[0] && options[0].value)
						}
						options={options}
						onChange={(e) =>
							updateSetting('advanced_robots_meta', {
								...advanced,
								[inputKey]: e.target.value,
							})
						}
						disabled={!enabled}
						description={description}
					/>
				) : (
					<Input
						id={valueId}
						label={valueLabel}
						hideLabelFromVision
						type="number"
						min={-1}
						value={String(
							Number.isFinite(advanced[inputKey])
								? advanced[inputKey]
								: -1
						)}
						onChange={(e) => {
							const n = parseInt(e.target.value, 10);
							updateSetting('advanced_robots_meta', {
								...advanced,
								[inputKey]: Number.isNaN(n) ? -1 : n,
							});
						}}
						placeholder="-1"
						disabled={!enabled}
						description={description}
					/>
				)}
			</Container>
		);
	};

	const renderRobotsMetaCard = () => (
		<Card padding={false}>
			<SectionHeader
				title={`${postTypeLabel} ${__('Robots Meta', 'thinkrank')}`}
				description={`${__('Select custom robots meta, such as `nofollow`, `noarchive`, etc. for single', 'thinkrank')} ${postTypeLabel} ${__('pages. Otherwise the default meta will be used, as set in the Global Meta tab.', 'thinkrank')}`}
				withBorder={!!settings.robots_meta_enabled}
				trailing={
					<Toggle
						checked={settings.robots_meta_enabled || false}
						onChange={(val) =>
							updateSetting('robots_meta_enabled', val)
						}
					/>
				}
			/>

			{settings.robots_meta_enabled && (
				<Container direction="column" gap={4} className="tr-p-6">
					<RobotMetaSettings
						values={settings.robots_meta || {}}
						onChange={(newValues) =>
							updateSetting('robots_meta', newValues)
						}
					/>

					<Container
						direction="column"
						gap={5}
						className="tr-mt-2 tr-pt-5 tr-border-t tr-border-secondary"
					>
						<Title tag="h4" size="xs">
							{`${postTypeLabel} ${__('Advanced Robots Meta', 'thinkrank')}`}
						</Title>

						<div className="tr-grid tr-grid-cols-3 tr-gap-5">
							{renderAdvancedRobotsField({
								checkedKey: 'snippet_enabled',
								label: __('Snippet', 'thinkrank'),
								inputKey: 'max_snippet',
								description: __(
									'Snippet length (-1 for none)',
									'thinkrank'
								),
							})}
							{renderAdvancedRobotsField({
								checkedKey: 'video_preview_enabled',
								label: __('Video Preview', 'thinkrank'),
								inputKey: 'max_video_preview',
								description: __(
									'Set the max video preview duration. Use -1 for unlimited.',
									'thinkrank'
								),
							})}
							{renderAdvancedRobotsField({
								checkedKey: 'image_preview_enabled',
								label: __('Image Preview', 'thinkrank'),
								inputKey: 'max_image_preview',
								options: [
									{
										label: __('None', 'thinkrank'),
										value: 'none',
									},
									{
										label: __('Standard', 'thinkrank'),
										value: 'standard',
									},
									{
										label: __('Large', 'thinkrank'),
										value: 'large',
									},
								],
								description: __(
									'Set the max image preview size.',
									'thinkrank'
								),
							})}
						</div>
					</Container>
				</Container>
			)}
		</Card>
	);

	const renderLinkSuggestionsCard = () => (
		<Card padding={false}>
			<SectionHeader
				withBorder={false}
				title={__('Link Suggestions', 'thinkrank')}
				description={__(
					'Enable link suggestions and pillar content feature for this post type.',
					'thinkrank'
				)}
				trailing={
					<Toggle
						label={
							isTogglingFeature
								? __('Saving…', 'thinkrank')
								: __('Enable link suggestions', 'thinkrank')
						}
						checked={settings.link_suggestions !== false}
						onChange={persistLinkSuggestions}
						disabled={isTogglingFeature}
					/>
				}
			/>
		</Card>
	);

	// Rendered before the loading/error states below: those describe the
	// per-post-type fetch these screens never run.
	if (isMatrix) {
		return <ContentTypeMatrix />;
	}
	if (isBulkSnippets) {
		return <BulkSnippetsTab />;
	}
	if (isThinContent) {
		return <ThinContentTab />;
	}
	if (isAutoAi) {
		return <AutoAiSection />;
	}

	if (isLoading) {
		return (
			<SkeletonCard
				lines={5}
				label={__('Loading settings…', 'thinkrank')}
			/>
		);
	}

	// A URL naming a post type that is no longer an eligible Global SEO target
	// (a bookmark of the Media screen on a site that does not serve attachment
	// pages, #843). The REST call refuses it, which arrives here as a load
	// failure, and "your settings could not be read" is both alarming and wrong:
	// nothing failed, the type simply has no settings to show. Checked before
	// loadFailed so the accurate message wins.
	if (postTypes.length > 0 && !currentPostTypeData.slug) {
		return (
			<Alert variant="info">
				{__(
					'This content type has no Global SEO settings on this site, because WordPress does not render a page for it. Media is the usual case: attachment URLs redirect straight to the file unless attachment pages are enabled, so a title, robots meta or schema set here would never be served. Alt text and image metadata are handled under Image SEO instead.',
					'thinkrank'
				)}
			</Alert>
		);
	}

	if (loadFailed) {
		return (
			<SettingsLoadError
				onRetry={() => loadSettings()}
				isRetrying={isLoading}
				message={__(
					'Your Global SEO settings could not be read, so this screen has nothing to edit yet. Nothing has changed. Retry to load them.',
					'thinkrank'
				)}
			/>
		);
	}

	return (
		<div className="tr-flex tr-flex-col tr-gap-6">
			{notice && (
				<Alert
					variant={notice.status === 'success' ? 'success' : 'error'}
					onDismiss={() => setNotice(null)}
				>
					{notice.message}
				</Alert>
			)}

			<Container direction="column" gap={6}>
				{renderSeoSettingsCard()}
				{renderRobotsMetaCard()}
				{renderLinkSuggestionsCard()}
			</Container>

			<div className="tr-flex tr-justify-end">
				<Button
					variant="primary"
					size="sm"
					loading={isSaving}
					disabled={!hasChanges || isSaving}
					onClick={saveSettings}
				>
					{__('Save Settings', 'thinkrank')}
				</Button>
			</div>
		</div>
	);
};

export default GlobalSEOTab;
