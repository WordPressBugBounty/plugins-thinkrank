/**
 * External Links Tab Component
 *
 * Outbound-link handling: rel="nofollow", target="_blank" (with the noopener
 * that has to ride along), and the per-domain exception list for partners the
 * site DOES want to pass equity to.
 *
 * The rewrite happens at render time — stored post content is never touched,
 * so both switches are fully reversible.
 *
 * Two sibling cards, each with its own header, no outer wrapper repeating the
 * breadcrumb: the house shape for a multi-group settings screen (see
 * GlobalSEOTab). The attributes card carries a live preview of the markup the
 * current switches produce, because "rel" and "target" are the whole subject of
 * the screen and a switch label is a poor substitute for seeing the anchor.
 *
 * @package
 * @since 2.5.0
 */

import { useState, useEffect, useMemo } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import {
	Alert,
	Badge,
	Button,
	Card,
	Container,
	SettingsLoadError,
	SkeletonCard,
	Textarea,
	Toggle,
} from '../ui';
import SectionHeader from '../common/SectionHeader';
import useAutosaveSettings from '../../hooks/useAutosaveSettings';
import logger from '../../../shared/utils/logger';

const API_PATH = '/thinkrank/v1/external-links/settings';

const DEFAULT_SETTINGS = {
	nofollow_external: false,
	open_external_in_new_tab: false,
	external_link_exceptions: [],
};

// Stand-ins for a link the author wrote with no rel or target of its own,
// which is the case the switches actually change. Several, because the preview
// would lie if its host happened to sit in the reader's own exception list —
// and `example.com` is exactly what someone types into that box first.
const SAMPLE_HOSTS = [
	'external-site.com',
	'some-other-site.com',
	'a-third-site.com',
];
const SAMPLE_PATH = '/article';

/**
 * The exception list is stored as hosts and edited as lines of text.
 * @param {Array} list Stored hosts.
 * @return {string} Textarea value.
 */
const listToText = (list) => (Array.isArray(list) ? list.join('\n') : '');

/**
 * @param {string} text Textarea value.
 * @return {Array} Hosts to send. The server normalizes them further.
 */
const textToList = (text) =>
	String(text || '')
		.split('\n')
		.map((line) => line.trim())
		.filter(Boolean);

/**
 * The attributes the current switches add to a plain outbound anchor.
 *
 * Mirrors External_Links_Manager::attribute_updates(): target is set only when
 * the anchor has none, and noopener rides along with any _blank target rather
 * than with the nofollow switch.
 *
 * @param {boolean} nofollow Whether rel="nofollow" is switched on.
 * @param {boolean} newTab   Whether target="_blank" is switched on.
 * @return {Array} Attribute pairs, in the order they are written.
 */
const previewAttributes = (nofollow, newTab) => {
	const rel = [];

	if (nofollow) {
		rel.push('nofollow');
	}
	if (newTab) {
		rel.push('noopener');
	}

	const attributes = [];

	if (newTab) {
		attributes.push(['target', '_blank']);
	}
	if (rel.length) {
		attributes.push(['rel', rel.join(' ')]);
	}

	return attributes;
};

/**
 * One anchor line, with the attributes the rewrite adds marked.
 *
 * @param {Object} props            Component props.
 * @param {string} props.href       The href to show.
 * @param {Array}  props.attributes Attribute pairs the rewrite adds.
 * @return {JSX.Element} The code line.
 */
const AnchorLine = ({ href, attributes }) => (
	<code
		className="tr-block tr-overflow-x-auto tr-whitespace-pre tr-text-xs tr-leading-relaxed tr-text-slate-700"
		// wp-admin styles bare <code> with its own grey background and padding,
		// which reads as a highlighted band inside a block that already has a
		// surface of its own.
		style={{ background: 'transparent', padding: 0, margin: 0 }}
	>
		<span>{`<a href="${href}"`}</span>
		{attributes.map(([name, value]) => (
			<span
				key={name}
				className="tr-rounded tr-bg-brand-bg tr-px-1 tr-font-semibold tr-text-brand"
			>
				{` ${name}="${value}"`}
			</span>
		))}
		<span>{`>`}</span>
	</code>
);

/**
 * The rendered anchor, with everything the rewrite adds marked.
 *
 * Takes the exception list into account twice over. The sample host is chosen
 * so it is not one the reader has excluded, because a preview showing
 * attributes added to a domain the rewriter actually skips is worse than no
 * preview at all. And when there are exclusions, one of them is shown beside
 * it, untouched, so both halves of the behaviour are visible at once.
 *
 * @param {Object}  props            Component props.
 * @param {boolean} props.nofollow   Whether rel="nofollow" is switched on.
 * @param {boolean} props.newTab     Whether target="_blank" is switched on.
 * @param {Array}   props.exceptions Hosts the rewrite leaves alone.
 * @return {JSX.Element} The preview block.
 */
const MarkupPreview = ({ nofollow, newTab, exceptions }) => {
	const attributes = previewAttributes(nofollow, newTab);

	const excluded = (exceptions || []).map((host) =>
		String(host).toLowerCase()
	);
	const sampleHost =
		SAMPLE_HOSTS.find(
			(host) =>
				!excluded.some(
					(entry) => entry === host || entry.endsWith(`.${host}`)
				)
		) || SAMPLE_HOSTS[0];

	const excludedSample = excluded[0];

	return (
		<div className="tr-rounded-lg tr-border tr-border-secondary tr-bg-slate-50 tr-p-4">
			<p className="tr-m-0 tr-mb-2 tr-text-xs tr-font-semibold tr-uppercase tr-tracking-wide tr-text-slate-500">
				{__('An outbound link renders as', 'thinkrank')}
			</p>

			<AnchorLine
				href={`https://${sampleHost}${SAMPLE_PATH}`}
				attributes={attributes}
			/>

			{attributes.length > 0 && excludedSample && (
				<>
					<p className="tr-m-0 tr-mb-2 tr-mt-4 tr-text-xs tr-font-semibold tr-uppercase tr-tracking-wide tr-text-slate-500">
						{__('A link you excluded renders as', 'thinkrank')}
					</p>
					<AnchorLine
						href={`https://${excludedSample}${SAMPLE_PATH}`}
						attributes={[]}
					/>
				</>
			)}

			<p className="tr-m-0 tr-mt-2 tr-text-xs tr-text-tertiary">
				{attributes.length === 0
					? __(
							'Both switches are off, so outbound links render exactly as written.',
							'thinkrank'
						)
					: __(
							'Highlighted attributes are added at render time. Anything the author already wrote is kept, and no value is ever added twice.',
							'thinkrank'
						)}
			</p>
		</div>
	);
};

const ExternalLinksTab = () => {
	const [settings, setSettings] = useState(DEFAULT_SETTINGS);
	const [exceptionsText, setExceptionsText] = useState('');
	const [isLoading, setIsLoading] = useState(true);
	const [loadFailed, setLoadFailed] = useState(false);
	const [isSavingExceptions, setIsSavingExceptions] = useState(false);
	const [hasExceptionChanges, setHasExceptionChanges] = useState(false);
	const [notice, setNotice] = useState(null);

	// A failed load must NOT leave the form standing on DEFAULT_SETTINGS. Every
	// switch here autosaves, and the exception list is posted as a whole array,
	// so a screen showing both switches off and an empty exception list would
	// destroy the user's stored list on the first keystroke — a save from a
	// state that was never loaded. Same guard as ContentTypeMatrix (#390).
	const load = async () => {
		try {
			setIsLoading(true);
			const data = await apiFetch({ path: API_PATH });
			const next = { ...DEFAULT_SETTINGS, ...(data || {}) };
			setSettings(next);
			setExceptionsText(listToText(next.external_link_exceptions));
			setLoadFailed(false);
		} catch (error) {
			logger.error('Error loading external link settings:', error);
			setLoadFailed(true);
		} finally {
			setIsLoading(false);
		}
	};

	useEffect(() => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, []);

	// Flipping a switch IS the save — see hooks/useAutosaveSettings.js. The
	// exception list is a text field the user edits then submits, so it keeps
	// its own Save button.
	const { update, isSaving: isToggling } = useAutosaveSettings({
		settings,
		setSettings,
		save: async (next) => {
			const response = await apiFetch({
				path: API_PATH,
				method: 'POST',
				data: next,
			});

			return response?.settings;
		},
		logLabel: 'External Links',
		onSuccess: (message) => setNotice({ status: 'success', message }),
		onError: (message) => setNotice({ status: 'error', message }),
	});

	const saveExceptions = async () => {
		setIsSavingExceptions(true);
		setNotice(null);

		const list = textToList(exceptionsText);

		try {
			const response = await apiFetch({
				path: API_PATH,
				method: 'POST',
				data: { ...settings, external_link_exceptions: list },
			});

			const confirmed =
				response?.settings?.external_link_exceptions ?? list;

			setSettings((prev) => ({
				...prev,
				external_link_exceptions: confirmed,
			}));
			// Show what was actually stored: the server normalizes a pasted
			// URL or a "www." prefix down to the host it will match on.
			setExceptionsText(listToText(confirmed));
			setHasExceptionChanges(false);
			setNotice({
				status: 'success',
				message: __('Exception list saved.', 'thinkrank'),
			});
		} catch (error) {
			logger.error('Error saving external link exceptions:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to save the exception list. Please try again.',
					'thinkrank'
				),
			});
		} finally {
			setIsSavingExceptions(false);
		}
	};

	const nofollow = !!settings.nofollow_external;
	const newTab = !!settings.open_external_in_new_tab;
	const isRewriting = nofollow || newTab;

	const savedCount = (settings.external_link_exceptions || []).length;
	const draftCount = useMemo(
		() => textToList(exceptionsText).length,
		[exceptionsText]
	);

	if (isLoading) {
		return (
			<div className="thinkrank-loading-container">
				<SkeletonCard lines={4} />
			</div>
		);
	}

	if (loadFailed) {
		return (
			<SettingsLoadError
				message={__(
					'Could not load your external link settings. Nothing has been changed, retry before editing.',
					'thinkrank'
				)}
				onRetry={load}
			/>
		);
	}

	return (
		<Container direction="column" gap={4}>
			{notice && (
				<Alert
					variant={notice.status === 'success' ? 'success' : 'error'}
					onDismiss={() => setNotice(null)}
				>
					{notice.message}
				</Alert>
			)}

			{/* ── Link attributes ────────────────────────────────────── */}
			<Card padding={false}>
				<SectionHeader
					title={__('Link attributes', 'thinkrank')}
					description={__(
						'Applied while the page renders. Your post content is never modified, so switching these off restores the original markup.',
						'thinkrank'
					)}
					trailing={
						isRewriting ? (
							<Badge variant="info">
								{__('Rewriting', 'thinkrank')}
							</Badge>
						) : null
					}
				/>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Toggle
						checked={nofollow}
						disabled={isToggling}
						label={__(
							'Add nofollow to external links',
							'thinkrank'
						)}
						description={__(
							'Adds rel="nofollow" to every link pointing at another domain.',
							'thinkrank'
						)}
						onChange={(value) =>
							update(
								{ nofollow_external: value },
								value
									? __(
											'External links now carry rel="nofollow".',
											'thinkrank'
										)
									: __(
											'External links no longer carry rel="nofollow".',
											'thinkrank'
										)
							)
						}
					/>

					<Toggle
						checked={newTab}
						disabled={isToggling}
						label={__(
							'Open external links in a new tab',
							'thinkrank'
						)}
						description={__(
							'Adds target="_blank" along with the rel="noopener" it requires.',
							'thinkrank'
						)}
						onChange={(value) =>
							update(
								{ open_external_in_new_tab: value },
								value
									? __(
											'External links now open in a new tab.',
											'thinkrank'
										)
									: __(
											'External links now open in the same tab.',
											'thinkrank'
										)
							)
						}
					/>

					<MarkupPreview
						nofollow={nofollow}
						newTab={newTab}
						exceptions={settings.external_link_exceptions}
					/>
				</div>
			</Card>

			{/* ── Exceptions ─────────────────────────────────────────── */}
			<Card padding={false}>
				<SectionHeader
					title={__('Exceptions', 'thinkrank')}
					description={__(
						'Domains listed here keep the markup the author wrote, so neither switch above touches them. One per line; subdomains are included.',
						'thinkrank'
					)}
					trailing={
						savedCount > 0 ? (
							<Badge variant="info">
								{sprintf(
									/* translators: %d: how many domains are excluded. */
									_n(
										'%d domain',
										'%d domains',
										savedCount,
										'thinkrank'
									),
									savedCount
								)}
							</Badge>
						) : null
					}
				/>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					{!isRewriting && (
						<Alert variant="info">
							{__(
								'Nothing is being rewritten right now, so this list has no effect. Turn on a switch above for it to apply.',
								'thinkrank'
							)}
						</Alert>
					)}

					<Textarea
						label={__('Excluded domains', 'thinkrank')}
						value={exceptionsText}
						rows={6}
						placeholder={'partner.com\naffiliate.example.org'}
						description={__(
							'A full URL or a "www." prefix is fine; it is reduced to the host it matches on.',
							'thinkrank'
						)}
						onChange={(event) => {
							setExceptionsText(event.target.value);
							setHasExceptionChanges(true);
						}}
					/>

					<div className="tr-flex tr-items-center tr-justify-end tr-gap-4">
						{hasExceptionChanges && (
							<p className="tr-m-0 tr-mr-auto tr-text-xs tr-text-tertiary">
								{sprintf(
									/* translators: %d: how many domains the edited list holds. */
									_n(
										'%d domain in this list, not saved yet.',
										'%d domains in this list, not saved yet.',
										draftCount,
										'thinkrank'
									),
									draftCount
								)}
							</p>
						)}

						<Button
							variant="primary"
							onClick={saveExceptions}
							disabled={
								!hasExceptionChanges || isSavingExceptions
							}
							loading={isSavingExceptions}
						>
							{isSavingExceptions
								? __('Saving…', 'thinkrank')
								: __('Save Exceptions', 'thinkrank')}
						</Button>
					</div>
				</div>
			</Card>
		</Container>
	);
};

export default ExternalLinksTab;
