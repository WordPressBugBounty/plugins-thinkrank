/**
 * Term SEO panel — the ThinkRank SEO card on a taxonomy term's edit screen.
 *
 * Rendered inside WordPress's own term form. Nothing is saved from here: every
 * field is mirrored into a hidden `thinkrank_term_seo[…]` input and travels
 * with the form's "Update" submit, which Term_Seo_Fields saves on `edit_term`
 * (#885). Hidden inputs, not named visible controls, so a field on a tab that
 * is not showing is still submitted.
 *
 * @package
 * @since 2.16.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState, useRef, useEffect } from '@wordpress/element';
import Input, { Textarea } from '../../admin/components/ui/Input';
import Select from '../../admin/components/ui/Select';
import Button from '../../admin/components/ui/Button';
import Tabs, { TabPanel } from '../../admin/components/ui/Tabs';
import CharacterCounter from '../../admin/components/ui/CharacterCounter';
import PanelCard from '../../metabox/components/ui/PanelCard';
import Field from '../../metabox/components/ui/Field';
import VariableTags from '../../metabox/components/ui/VariableTags';
import { IconDocument } from '../../metabox/components/ui/icons';
import SerpPreview from '../../metabox/components/SerpPreview';
import MetaboxRobotsTab from '../../metabox/components/MetaboxRobotsTab';
import { resolvePattern } from '../../metabox/utils/resolvePattern';

/**
 * Robots flags a freshly enabled override starts from — the same defaults the
 * robots tab displays, so what is stored matches what the admin saw.
 */
const DEFAULT_ROBOTS = {
	index: true,
	noindex: false,
	nofollow: false,
	noarchive: false,
	noimageindex: false,
	nosnippet: false,
};

const DEFAULT_ADVANCED = {
	snippet_enabled: true,
	max_snippet: -1,
	video_preview_enabled: true,
	max_video_preview: -1,
	image_preview_enabled: true,
	max_image_preview: 'large',
};

/**
 * Robots flags as the robots tab shows them. An imported override often
 * stores only `noindex`, and the default `index: true` must not sit beside
 * it: index and noindex are one choice, and noindex wins, as on the front end.
 *
 * @since 2.16.0
 *
 * @param {Object} robots Stored or edited robots flags.
 * @return {Object} Every flag, with `index` false whenever `noindex` is on.
 */
export const normalizeRobots = (robots) => {
	const merged = { ...DEFAULT_ROBOTS, ...(robots || {}) };
	if (merged.noindex) {
		merged.index = false;
	}
	return merged;
};

/**
 * Whether a stored robots object holds anything worth resubmitting.
 *
 * @param {Object} value Stored object.
 * @return {boolean} True when it has at least one key.
 */
const hasKeys = (value) =>
	!!value && typeof value === 'object' && Object.keys(value).length > 0;

/** String fields, submitted as typed. */
const TEXT_FIELDS = [
	'title',
	'description',
	'canonical_url',
	'og_title',
	'og_description',
	'og_image',
	'twitter_title',
	'twitter_description',
	'twitter_image',
];

const TITLE_VARIABLES = [
	{ label: __('+ Term', 'thinkrank'), value: '%term%' },
	{ label: __('+ Site Title', 'thinkrank'), value: '%sitename%' },
	{ label: __('+ Separator', 'thinkrank'), value: '%sep%' },
];

const DESCRIPTION_VARIABLES = [
	{ label: __('+ Description', 'thinkrank'), value: '%excerpt%' },
	{ label: __('+ Term', 'thinkrank'), value: '%term%' },
	{ label: __('+ Site Title', 'thinkrank'), value: '%sitename%' },
];

const TABS = [
	{ id: 'general', label: __('General', 'thinkrank') },
	{ id: 'social', label: __('Social', 'thinkrank') },
	{ id: 'advanced', label: __('Advanced', 'thinkrank') },
];

/**
 * Initial form state from the localized config.
 *
 * @param {Object} config Localized `thinkrankTermSeo`.
 * @return {Object} Form state.
 */
const initialState = (config) => {
	const values = config.values || {};
	const state = {};
	TEXT_FIELDS.forEach((key) => {
		state[key] = typeof values[key] === 'string' ? values[key] : '';
	});
	state.robots_meta_enabled = !!values.robots_meta_enabled;
	state.robots_meta = values.robots_meta || {};
	state.advanced_robots_meta = values.advanced_robots_meta || {};
	state.redirect_url = config.redirect?.url || '';
	state.redirect_type = String(config.redirect?.type || 301);
	return state;
};

/**
 * A breadcrumb like Google shows: "example.com › category › news".
 *
 * @param {string} url Term archive URL.
 * @return {string} Display URL, or '' when the URL cannot be parsed.
 */
export const breadcrumbFor = (url) => {
	try {
		const parsed = new URL(url);
		const parts = parsed.pathname.split('/').filter(Boolean);
		return [parsed.hostname, ...parts].join(' › ');
	} catch (e) {
		return '';
	}
};

/**
 * Open the media library and hand back the chosen image's URL.
 *
 * @param {Function} onSelect Called with the image URL.
 */
const pickImage = (onSelect) => {
	const media = window.wp?.media;
	if (!media) {
		return;
	}
	const frame = media({
		title: __('Select image', 'thinkrank'),
		library: { type: 'image' },
		multiple: false,
	});
	frame.on('select', () => {
		const attachment = frame.state().get('selection').first()?.toJSON();
		if (attachment?.url) {
			onSelect(attachment.url);
		}
	});
	frame.open();
};

const ImageField = ({ id, label, value, onChange, help }) => (
	<Field label={label} htmlFor={id} help={help}>
		<div className="tr-flex tr-gap-2">
			<Input
				id={id}
				type="url"
				className="tr-flex-1"
				value={value}
				onChange={(e) => onChange(e.target.value)}
				placeholder="https://"
			/>
			{window.wp?.media && (
				<Button
					type="button"
					variant="secondary"
					onClick={() => pickImage(onChange)}
				>
					{__('Choose image', 'thinkrank')}
				</Button>
			)}
		</div>
	</Field>
);

const TermSeoPanel = ({ config }) => {
	const [form, setForm] = useState(() => initialState(config));
	const [activeTab, setActiveTab] = useState('general');

	// The URL fields are type="url", so the browser validates them when the
	// term form submits. One on a hidden tab cannot be shown or focused, and
	// the Update button would silently do nothing. Open that tab, then focus
	// the field and show the browser's message once it is visible.
	const invalidControl = useRef(null);
	const invalidHandled = useRef(false);

	const handleInvalid = (event) => {
		// One submit fires `invalid` for every bad field, in document order.
		// Only the first decides, and it may already be on the visible tab.
		if (invalidHandled.current) {
			return;
		}
		invalidHandled.current = true;
		setTimeout(() => {
			invalidHandled.current = false;
		}, 0);

		const panel = event.target.closest?.('[role="tabpanel"]');
		if (!panel || !panel.hidden) {
			return;
		}
		invalidControl.current = event.target;
		setActiveTab(panel.id.replace(/^tr-panel-/, ''));
	};

	useEffect(() => {
		const control = invalidControl.current;
		if (!control) {
			return;
		}
		invalidControl.current = null;
		control.focus();
		control.reportValidity?.();
	}, [activeTab]);
	// Robots inputs are only submitted once the override has been on, so
	// saving a term never writes robots meta the admin did not touch.
	const [robotsTouched] = useState(
		() => !!config.values?.robots_meta_enabled
	);
	// Which robots objects the admin edited in this visit. An untouched
	// object is submitted exactly as stored (or not at all when nothing is
	// stored); only an edited one is filled out with the defaults the tab
	// displays. Otherwise an untouched Update would add max-snippet and
	// friends to an imported override that never had them.
	const [robotsDirty, setRobotsDirty] = useState(false);
	const [advancedDirty, setAdvancedDirty] = useState(false);

	const vars = config.patternVariables || {};
	const fieldName = config.fieldName || 'thinkrank_term_seo';
	const redirect = config.redirect || {};

	const set = (key, value) => setForm((prev) => ({ ...prev, [key]: value }));

	const insertVariable = (key, token) => {
		const current = form[key] || '';
		set(
			key,
			current && !current.endsWith(' ')
				? `${current} ${token}`
				: `${current}${token}`
		);
	};

	const resolvedTitle = resolvePattern(form.title, vars);
	const resolvedDescription = resolvePattern(form.description, vars);
	const submitRobots = robotsTouched || form.robots_meta_enabled;

	return (
		<div
			className="tr-root tr-font-sans thinkrank-term-seo-panel"
			onInvalidCapture={handleInvalid}
		>
			{TEXT_FIELDS.map((key) => (
				<input
					key={key}
					type="hidden"
					name={`${fieldName}[${key}]`}
					value={form[key]}
				/>
			))}
			{submitRobots && (
				<>
					<input
						type="hidden"
						name={`${fieldName}[robots_meta_enabled]`}
						value={form.robots_meta_enabled ? '1' : '0'}
					/>
					{(robotsDirty || hasKeys(form.robots_meta)) && (
						<input
							type="hidden"
							name={`${fieldName}[robots_meta]`}
							value={JSON.stringify(
								robotsDirty
									? normalizeRobots(form.robots_meta)
									: form.robots_meta
							)}
						/>
					)}
					{(advancedDirty || hasKeys(form.advanced_robots_meta)) && (
						<input
							type="hidden"
							name={`${fieldName}[advanced_robots_meta]`}
							value={JSON.stringify(
								advancedDirty
									? {
											...DEFAULT_ADVANCED,
											...form.advanced_robots_meta,
										}
									: form.advanced_robots_meta
							)}
						/>
					)}
				</>
			)}
			{redirect.supported && (
				<>
					<input
						type="hidden"
						name={`${fieldName}[redirect_url]`}
						value={form.redirect_url}
					/>
					<input
						type="hidden"
						name={`${fieldName}[redirect_type]`}
						value={form.redirect_type}
					/>
				</>
			)}

			<PanelCard
				icon={<IconDocument />}
				title={__('ThinkRank SEO', 'thinkrank')}
				subtitle={__(
					'How this archive appears in search results and when shared. Leave a field empty to use the site-wide template. Saved with the Update button below.',
					'thinkrank'
				)}
				bodyClassName="tr-flex tr-flex-col tr-gap-5"
			>
				{/* The layout classes sit on a wrapper inside each TabPanel: a
				display utility on the panel itself overrides its `hidden`
				attribute and every tab would show at once. */}
				<Tabs
					tabs={TABS}
					activeTab={activeTab}
					onTabChange={setActiveTab}
				/>

				<TabPanel tabId="general" activeTab={activeTab}>
					<div className="tr-flex tr-flex-col tr-gap-5">
						<Field
							label={__('SEO Title', 'thinkrank')}
							htmlFor="thinkrank_term_seo_title"
							counter={
								<CharacterCounter
									value={resolvedTitle}
									max={60}
								/>
							}
							help={__(
								'Keep it under 60 characters for best results.',
								'thinkrank'
							)}
						>
							<Input
								id="thinkrank_term_seo_title"
								value={form.title}
								onChange={(e) => set('title', e.target.value)}
								placeholder={__(
									'Site-wide archive title template',
									'thinkrank'
								)}
							/>
							<VariableTags
								variables={TITLE_VARIABLES}
								onInsert={(token) =>
									insertVariable('title', token)
								}
								value={form.title}
								vars={vars}
							/>
						</Field>

						<Field
							label={__('Meta Description', 'thinkrank')}
							htmlFor="thinkrank_term_meta_description"
							counter={
								<CharacterCounter
									value={resolvedDescription}
									max={160}
								/>
							}
						>
							<Textarea
								id="thinkrank_term_meta_description"
								rows="3"
								value={form.description}
								onChange={(e) =>
									set('description', e.target.value)
								}
								placeholder={__(
									'Site-wide archive description template',
									'thinkrank'
								)}
							/>
							<VariableTags
								variables={DESCRIPTION_VARIABLES}
								onInsert={(token) =>
									insertVariable('description', token)
								}
								value={form.description}
								vars={vars}
							/>
						</Field>

						<SerpPreview
							title={resolvedTitle}
							description={resolvedDescription}
							postTitle={config.termName}
							homeUrl={config.homeUrl}
							siteName={config.siteName}
							faviconUrl={config.faviconUrl}
							displayUrl={breadcrumbFor(config.termUrl)}
						/>
					</div>
				</TabPanel>

				<TabPanel tabId="social" activeTab={activeTab}>
					<div className="tr-flex tr-flex-col tr-gap-5">
						<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Facebook / Open Graph', 'thinkrank')}
						</h4>
						<Field
							label={__('Title', 'thinkrank')}
							htmlFor="thinkrank_term_og_title"
						>
							<Input
								id="thinkrank_term_og_title"
								value={form.og_title}
								onChange={(e) =>
									set('og_title', e.target.value)
								}
								placeholder={__(
									'Defaults to the SEO title',
									'thinkrank'
								)}
							/>
						</Field>
						<Field
							label={__('Description', 'thinkrank')}
							htmlFor="thinkrank_term_og_description"
						>
							<Textarea
								id="thinkrank_term_og_description"
								rows="2"
								value={form.og_description}
								onChange={(e) =>
									set('og_description', e.target.value)
								}
								placeholder={__(
									'Defaults to the meta description',
									'thinkrank'
								)}
							/>
						</Field>
						<ImageField
							id="thinkrank_term_og_image"
							label={__('Image', 'thinkrank')}
							value={form.og_image}
							onChange={(url) => set('og_image', url)}
							help={__(
								'Recommended: 1200 × 630 pixels.',
								'thinkrank'
							)}
						/>

						<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0 tr-pt-2">
							{__('X (Twitter)', 'thinkrank')}
						</h4>
						<Field
							label={__('Title', 'thinkrank')}
							htmlFor="thinkrank_term_twitter_title"
						>
							<Input
								id="thinkrank_term_twitter_title"
								value={form.twitter_title}
								onChange={(e) =>
									set('twitter_title', e.target.value)
								}
								placeholder={__(
									'Defaults to the Open Graph title',
									'thinkrank'
								)}
							/>
						</Field>
						<Field
							label={__('Description', 'thinkrank')}
							htmlFor="thinkrank_term_twitter_description"
						>
							<Textarea
								id="thinkrank_term_twitter_description"
								rows="2"
								value={form.twitter_description}
								onChange={(e) =>
									set('twitter_description', e.target.value)
								}
								placeholder={__(
									'Defaults to the Open Graph description',
									'thinkrank'
								)}
							/>
						</Field>
						<ImageField
							id="thinkrank_term_twitter_image"
							label={__('Image', 'thinkrank')}
							value={form.twitter_image}
							onChange={(url) => set('twitter_image', url)}
						/>
					</div>
				</TabPanel>

				<TabPanel tabId="advanced" activeTab={activeTab}>
					<div className="tr-flex tr-flex-col tr-gap-5">
						<Field
							label={__('Canonical URL', 'thinkrank')}
							htmlFor="thinkrank_term_canonical_url"
							help={__(
								'Leave empty to point at this archive itself.',
								'thinkrank'
							)}
						>
							<Input
								id="thinkrank_term_canonical_url"
								type="url"
								value={form.canonical_url}
								onChange={(e) =>
									set('canonical_url', e.target.value)
								}
								placeholder={config.termUrl || 'https://'}
							/>
						</Field>

						{redirect.supported && (
							<Field
								label={__('Redirect', 'thinkrank')}
								htmlFor="thinkrank_term_redirect_url"
								help={__(
									'Send visitors from this archive to another URL. Leave empty for no redirect.',
									'thinkrank'
								)}
							>
								<div className="tr-flex tr-gap-2">
									<Input
										id="thinkrank_term_redirect_url"
										type="url"
										className="tr-flex-1"
										value={form.redirect_url}
										onChange={(e) =>
											set('redirect_url', e.target.value)
										}
										placeholder="https://"
									/>
									<Select
										id="thinkrank_term_redirect_type"
										label={__('Redirect type', 'thinkrank')}
										hideLabelFromVision
										value={form.redirect_type}
										onChange={(e) =>
											set('redirect_type', e.target.value)
										}
										options={(redirect.types || [301]).map(
											(code) => ({
												value: String(code),
												label: String(code),
											})
										)}
									/>
								</div>
							</Field>
						)}

						<MetaboxRobotsTab
							robotsMeta={normalizeRobots(form.robots_meta)}
							advancedRobotsMeta={form.advanced_robots_meta}
							robotsEnabled={form.robots_meta_enabled}
							description={sprintf(
								/* translators: %s: term name. */
								__(
									'Override the global and taxonomy robots meta directives for “%s”. When disabled, defaults apply.',
									'thinkrank'
								),
								config.termName || ''
							)}
							noindexNotice={__(
								'This archive will not be indexed by search engines.',
								'thinkrank'
							)}
							onChange={(patch) => {
								if ('robotsEnabled' in patch) {
									set(
										'robots_meta_enabled',
										patch.robotsEnabled
									);
									// Switching the override on stores what
									// the tab now shows.
									if (patch.robotsEnabled) {
										setRobotsDirty(true);
										setAdvancedDirty(true);
									}
								}
								if ('robotsMeta' in patch) {
									set('robots_meta', patch.robotsMeta);
									setRobotsDirty(true);
								}
								if ('advancedRobotsMeta' in patch) {
									set(
										'advanced_robots_meta',
										patch.advancedRobotsMeta
									);
									setAdvancedDirty(true);
								}
							}}
						/>
					</div>
				</TabPanel>
			</PanelCard>
		</div>
	);
};

export default TermSeoPanel;
