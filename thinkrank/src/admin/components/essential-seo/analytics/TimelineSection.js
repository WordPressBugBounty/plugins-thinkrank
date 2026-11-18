/**
 * SEO Timeline: every recorded change that can move search traffic.
 *
 * Deliberately not gated on the Analytics integration. The events are recorded
 * from WordPress itself and need no Google connection, and the moment this
 * screen is most needed is the moment somebody is looking for a cause, which is
 * the worst possible time to tell them to connect something first.
 *
 * @since 2.22.0
 */

import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Icon, backup, chevronRight } from '@wordpress/icons';

import {
	Alert,
	Button,
	Card,
	Container,
	EmptyState,
	Select,
	SkeletonCard,
} from '../../ui';
import SectionHeader from '../../common/SectionHeader';
import logger from '../../../../shared/utils/logger';

/**
 * How a severity reads, and how it looks.
 *
 * @type {Object<string,{label: string, className: string}>}
 */
const SEVERITY = {
	critical: {
		label: __('Critical', 'thinkrank'),
		className: 'tr-bg-danger-bg tr-text-danger tr-border-danger',
		dot: 'tr-bg-danger tr-ring-danger-bg',
		text: 'tr-text-danger',
	},
	warning: {
		label: __('Warning', 'thinkrank'),
		className: 'tr-bg-warning-bg tr-text-warning tr-border-warning',
		dot: 'tr-bg-warning tr-ring-warning-bg',
		text: 'tr-text-warning',
	},
	info: {
		label: __('Info', 'thinkrank'),
		className: 'tr-bg-line-2 tr-text-secondary tr-border-secondary',
		dot: 'tr-bg-ink-3 tr-ring-line-2',
		text: 'tr-text-tertiary',
	},
};

/**
 * Human names for the SEO fields a post event can list.
 *
 * @type {Object<string,string>}
 */
const FIELD_LABELS = {
	_thinkrank_seo_title: __('SEO title', 'thinkrank'),
	_thinkrank_meta_description: __('Meta description', 'thinkrank'),
	_thinkrank_canonical_url: __('Canonical URL', 'thinkrank'),
	_thinkrank_robots_meta_enabled: __('Robots meta override', 'thinkrank'),
	_thinkrank_robots_meta: __('Robots meta', 'thinkrank'),
	_thinkrank_advanced_robots_meta: __('Advanced robots meta', 'thinkrank'),
	_thinkrank_focus_keyword: __('Focus keyword', 'thinkrank'),
	_thinkrank_selected_schema_type: __('Schema type', 'thinkrank'),
	_thinkrank_schema_disabled: __('Schema disabled', 'thinkrank'),
};

/**
 * Settings sections as their screens name them.
 *
 * The section is stored as its internal category, several of them class
 * names, so spelling the slug out read "Llms Txt" and "Image Seo". Keep in
 * sync with Event_Recorder::section_labels(), which writes the same names
 * into the summary; a PHP test pins the two.
 *
 * @type {Object<string,string>}
 */
export const SECTION_LABELS = {
	site_identity: __('Site Identity', 'thinkrank'),
	sitemap: __('Sitemap', 'thinkrank'),
	social_meta: __('Social Media', 'thinkrank'),
	image_seo: __('Image SEO', 'thinkrank'),
	external_links: __('External Links', 'thinkrank'),
	llms_txt: __('llms.txt', 'thinkrank'),
	schema_management_system: __('Schema', 'thinkrank'),
	seo_settings: __('SEO Settings', 'thinkrank'),
	ai_content_analyzer: __('Content Analysis', 'thinkrank'),
	content_optimization_manager: __('Content Optimization', 'thinkrank'),
	performance_monitoring_manager: __('Performance', 'thinkrank'),
};

/**
 * Human names for post statuses.
 *
 * @type {Object<string,string>}
 */
const STATUS_LABELS = {
	publish: __('Published', 'thinkrank'),
	draft: __('Draft', 'thinkrank'),
	pending: __('Pending review', 'thinkrank'),
	private: __('Private', 'thinkrank'),
	future: __('Scheduled', 'thinkrank'),
	trash: __('Trash', 'thinkrank'),
	deleted: __('Deleted', 'thinkrank'),
};

/**
 * A slug or setting key as words: `site_identity` reads "Site Identity".
 *
 * @param {string} value Slug.
 * @return {string} Readable name.
 */
export const humanize = (value) =>
	String(value || '')
		.replace(/[_-]+/g, ' ')
		.trim()
		.replace(/\b\w/g, (letter) => letter.toUpperCase());

/**
 * The details of an event as label and value pairs, instead of raw JSON.
 *
 * Exported for its tests. Values are plain strings or arrays of strings;
 * nothing here can render markup from the stored meta.
 *
 * @param {Object} event The event.
 * @return {Array<{label: string, value: (string|Array<string>), code: boolean}>} Rows.
 */
export const describeDetails = (event) => {
	const meta = event && event.meta ? event.meta : {};
	const rows = [];
	const add = (label, value, code = false) => {
		if (
			value === undefined ||
			value === null ||
			value === '' ||
			(Array.isArray(value) && value.length === 0)
		) {
			return;
		}
		rows.push({ label, value, code });
	};

	switch (event && event.event_type) {
		case 'search_visibility':
			add(
				__('Search engines', 'thinkrank'),
				Number(meta.blog_public) === 1
					? __('Allowed to index', 'thinkrank')
					: __('Discouraged from indexing', 'thinkrank')
			);
			break;
		case 'post_status':
			add(
				__('Before', 'thinkrank'),
				STATUS_LABELS[meta.from] || humanize(meta.from)
			);
			add(
				__('After', 'thinkrank'),
				STATUS_LABELS[meta.to] || humanize(meta.to)
			);
			break;
		case 'post_slug':
			add(
				__('Old URL', 'thinkrank'),
				meta.from ? `/${meta.from}/` : '',
				true
			);
			add(
				__('New URL', 'thinkrank'),
				meta.to ? `/${meta.to}/` : '',
				true
			);
			break;
		case 'permalink_structure':
			add(
				__('Before', 'thinkrank'),
				meta.from || __('Plain', 'thinkrank'),
				true
			);
			add(
				__('After', 'thinkrank'),
				meta.to || __('Plain', 'thinkrank'),
				true
			);
			break;
		case 'post_seo_fields': {
			const fields = Object.keys(meta.fields || {});
			add(
				__('Fields', 'thinkrank'),
				fields.map((field) => FIELD_LABELS[field] || field)
			);
			const posts = Object.keys(meta.post_ids || {}).length;
			if (posts > 1) {
				add(__('Posts', 'thinkrank'), String(posts));
			}
			break;
		}
		case 'settings_changed':
		case 'robots_txt':
		case 'llms_txt':
			add(
				__('Section', 'thinkrank'),
				SECTION_LABELS[meta.section] || humanize(meta.section)
			);
			add(__('Settings', 'thinkrank'), meta.keys || [], true);
			break;
		case 'plugin_updated':
		case 'theme_updated':
		case 'core_updated':
			add(__('Version before', 'thinkrank'), meta.from, true);
			add(__('Version after', 'thinkrank'), meta.to, true);
			break;
		default:
			Object.keys(meta).forEach((key) => {
				const value = meta[key];
				if (value !== null && typeof value === 'object') {
					return;
				}
				add(humanize(key), String(value), true);
			});
	}

	return rows;
};

/**
 * Human names for the recorded event types.
 *
 * @type {Object<string,string>}
 */
const TYPE_LABELS = {
	search_visibility: __('Search engine visibility', 'thinkrank'),
	permalink_structure: __('Permalink structure', 'thinkrank'),
	plugin_updated: __('Plugin updated', 'thinkrank'),
	theme_updated: __('Theme updated', 'thinkrank'),
	core_updated: __('WordPress updated', 'thinkrank'),
	plugin_activated: __('Plugin activated', 'thinkrank'),
	plugin_deactivated: __('Plugin deactivated', 'thinkrank'),
	theme_switched: __('Theme switched', 'thinkrank'),
	post_status: __('Published or unpublished', 'thinkrank'),
	post_slug: __('URL changed', 'thinkrank'),
	post_seo_fields: __('SEO fields edited', 'thinkrank'),
	settings_changed: __('Settings saved', 'thinkrank'),
	robots_txt: __('robots.txt', 'thinkrank'),
	llms_txt: __('llms.txt', 'thinkrank'),
};

/**
 * Events fetched per request, well under the endpoint ceiling of 200.
 */
export const PAGE_SIZE = 100;

/**
 * Read a stored UTC datetime as a Date.
 *
 * @param {string} value Stored "Y-m-d H:i:s" in UTC.
 * @return {Date|null} The moment, or null when it cannot be read.
 */
const parseStored = (value) => {
	const parsed = new Date(String(value || '').replace(' ', 'T') + 'Z');

	return Number.isNaN(parsed.getTime()) ? null : parsed;
};

/**
 * The local calendar day a stored UTC datetime falls on.
 *
 * The rows show local times, so the day they are grouped under has to be the
 * local day too. Grouping on the stored UTC date put an event at 02:30 local
 * under the previous day's heading whenever the site's reader was ahead of UTC.
 *
 * @param {string} value Stored "Y-m-d H:i:s" in UTC.
 * @return {string} "Y-m-d" in local time, or '' when it cannot be read.
 */
export const localDay = (value) => {
	const parsed = parseStored(value);

	if (!parsed) {
		return '';
	}

	const pad = (n) => String(n).padStart(2, '0');

	return `${parsed.getFullYear()}-${pad(parsed.getMonth() + 1)}-${pad(
		parsed.getDate()
	)}`;
};

/**
 * Group events by the local calendar day they happened on, newest day first.
 *
 * Exported for its tests: the grouping is the only logic on this screen that
 * can be wrong in a way nobody notices, because a mis-grouped event still
 * renders.
 *
 * @param {Array<Object>} events Events, already newest first.
 * @return {Array<{day: string, events: Array<Object>}>} Grouped events.
 */
export const groupByDay = (events) => {
	const groups = [];
	const index = {};

	(events || []).forEach((event) => {
		const day = localDay(event.occurred_at);

		if (!day) {
			return;
		}

		if (index[day] === undefined) {
			index[day] = groups.length;
			groups.push({ day, events: [] });
		}

		groups[index[day]].events.push(event);
	});

	return groups;
};

/**
 * Format a stored UTC datetime as a local time.
 *
 * @param {string} value Stored "Y-m-d H:i:s" in UTC.
 * @return {string} Time, or '' when it cannot be read.
 */
export const formatTime = (value) => {
	const parsed = parseStored(value);

	if (!parsed) {
		return '';
	}

	return parsed.toLocaleTimeString(undefined, {
		hour: '2-digit',
		minute: '2-digit',
	});
};

/**
 * Format a day for a group heading.
 *
 * @param {string} day Local "Y-m-d", from localDay().
 * @return {string} Readable date.
 */
export const formatDay = (day) => {
	// Local noon, matching the local day the group was keyed on.
	const parsed = new Date(`${day}T12:00:00`);

	if (Number.isNaN(parsed.getTime())) {
		return day;
	}

	const today = new Date();
	today.setHours(12, 0, 0, 0);
	const daysAgo = Math.round((today - parsed) / 86400000);

	if (daysAgo === 0) {
		return __('Today', 'thinkrank');
	}
	if (daysAgo === 1) {
		return __('Yesterday', 'thinkrank');
	}

	return parsed.toLocaleDateString(undefined, {
		weekday: 'long',
		year: 'numeric',
		month: 'long',
		day: 'numeric',
	});
};

/**
 * The labelled details of an event, folded away until asked for.
 *
 * @param {Object} props      Component props.
 * @param {Array}  props.rows Rows from describeDetails().
 * @return {JSX.Element} The details.
 */
const EventDetails = ({ rows }) => {
	const [open, setOpen] = useState(false);

	return (
		<div className="tr-mt-1">
			<button
				type="button"
				aria-expanded={open}
				onClick={() => setOpen(!open)}
				className="tr-inline-flex tr-items-center tr-gap-0.5 tr-p-0 tr-bg-transparent tr-border-0 tr-cursor-pointer tr-text-xs tr-font-medium tr-text-tertiary hover:tr-text-primary"
			>
				<Icon
					icon={chevronRight}
					size={16}
					className={`tr-fill-current tr-transition-transform ${
						open ? 'tr-rotate-90' : ''
					}`}
				/>
				{open
					? __('Hide details', 'thinkrank')
					: __('Show details', 'thinkrank')}
			</button>
			{open && (
				<dl className="tr-grid tr-grid-cols-[max-content_1fr] tr-gap-x-5 tr-gap-y-2 tr-mt-2 tr-mb-0 tr-px-4 tr-py-3 tr-rounded-lg tr-border tr-border-secondary tr-bg-white tr-text-xs">
					{rows.map((row) => (
						<div key={row.label} className="tr-contents">
							<dt className="tr-text-tertiary">{row.label}</dt>
							<dd className="tr-m-0 tr-flex tr-flex-wrap tr-gap-1 tr-min-w-0">
								{(Array.isArray(row.value)
									? row.value
									: [row.value]
								).map((item) =>
									row.code ? (
										<code
											key={item}
											className="tr-text-[11px] tr-px-1.5 tr-py-0.5 tr-rounded tr-bg-line-2 tr-text-primary tr-break-all"
										>
											{item}
										</code>
									) : (
										<span
											key={item}
											className="tr-px-1.5 tr-py-0.5 tr-rounded tr-bg-line-2 tr-text-primary"
										>
											{item}
										</span>
									)
								)}
							</dd>
						</div>
					))}
				</dl>
			)}
		</div>
	);
};

/**
 * One event on the timeline: the time, a dot on the rail in the severity's
 * colour, and what happened.
 *
 * @param {Object}  props        Component props.
 * @param {Object}  props.event  The event.
 * @param {boolean} props.isLast Last event of its day, where the rail stops.
 * @return {JSX.Element} The row.
 */
export const TimelineEvent = ({ event, isLast = false }) => {
	const severity = SEVERITY[event.severity] || SEVERITY.info;
	const details = describeDetails(event);
	const isQuiet =
		event.severity !== 'critical' && event.severity !== 'warning';

	return (
		<li className="tr-relative tr-grid tr-grid-cols-[64px_20px_1fr] tr-gap-x-3 tr-pb-5 last:tr-pb-1">
			<span className="tr-pt-0.5 tr-text-right tr-text-xs tr-text-tertiary tr-whitespace-nowrap tr-tabular-nums">
				{formatTime(event.occurred_at)}
			</span>

			<span className="tr-relative tr-flex tr-justify-center">
				{!isLast && (
					<span
						aria-hidden="true"
						className="tr-absolute tr-top-4 tr--bottom-5 tr-w-px tr-bg-line-1"
					/>
				)}
				<span
					aria-hidden="true"
					className={`tr-relative tr-mt-1 tr-w-2.5 tr-h-2.5 tr-rounded-full tr-ring-4 ${severity.dot}`}
				/>
			</span>

			<div className="tr-min-w-0">
				<p className="tr-m-0 tr-text-sm tr-font-medium tr-text-primary tr-break-words">
					{event.summary}
				</p>
				<p className="tr-m-0 tr-mt-1 tr-flex tr-flex-wrap tr-items-center tr-gap-x-2 tr-gap-y-1 tr-text-xs tr-text-tertiary">
					<span
						className={
							isQuiet
								? 'tr-sr-only'
								: `tr-font-semibold ${severity.text}`
						}
					>
						{severity.label}
					</span>
					<span className="tr-px-1.5 tr-py-0.5 tr-rounded tr-bg-line-2 tr-text-secondary">
						{TYPE_LABELS[event.event_type] || event.event_type}
					</span>
					{event.actor && (
						<span>
							{sprintf(
								/* translators: %s: who made the change. */
								__('by %s', 'thinkrank'),
								event.actor
							)}
						</span>
					)}
					{event.edit_url && (
						<a
							href={event.edit_url}
							className="tr-font-medium tr-text-brand tr-no-underline hover:tr-underline"
						>
							{__('Edit post', 'thinkrank')}
						</a>
					)}
				</p>
				{details.length > 0 && <EventDetails rows={details} />}
			</div>
		</li>
	);
};

/**
 * The Timeline screen.
 *
 * @return {JSX.Element} The screen.
 */
const TimelineSection = () => {
	const [isLoading, setIsLoading] = useState(true);
	const [loadFailed, setLoadFailed] = useState(false);
	const [events, setEvents] = useState([]);
	const [total, setTotal] = useState(0);
	const [counts, setCounts] = useState(null);
	const [retentionDays, setRetentionDays] = useState(30);
	const [severity, setSeverity] = useState('');
	const [type, setType] = useState('');

	const [isLoadingMore, setIsLoadingMore] = useState(false);
	const [loadMoreFailed, setLoadMoreFailed] = useState(false);

	// Every request takes a number, and only the latest may touch state. A
	// filter change while an earlier request is still out otherwise let the
	// slower, older response land last and show the previous filter's events
	// under the current one.
	const latestRequest = useRef(0);

	/**
	 * Load one page of events.
	 *
	 * @param {number} offset 0 replaces the list; more appends the next page.
	 */
	const load = useCallback(
		async (offset = 0) => {
			const request = ++latestRequest.current;
			const isMore = offset > 0;

			if (isMore) {
				setIsLoadingMore(true);
				setLoadMoreFailed(false);
			} else {
				setIsLoading(true);
			}

			try {
				const query = new URLSearchParams({
					limit: String(PAGE_SIZE),
					offset: String(offset),
				});

				// Repeated keys, because both filters are arrays server-side
				// and a comma-joined string would arrive as one unknown value.
				if (severity) {
					query.append('severity[]', severity);
				}
				if (type) {
					query.append('type[]', type);
				}

				const response = await apiFetch({
					path: `/thinkrank/v1/analytics/timeline?${query.toString()}`,
					method: 'GET',
				});

				if (request !== latestRequest.current) {
					return;
				}

				if (!response.success) {
					throw new Error(
						response.error || 'Failed to load the timeline'
					);
				}

				const page = response.data.events || [];

				// An event recorded between two pages shifts the offset by
				// one, so the next page can repeat the last row it saw.
				setEvents((previous) => {
					if (!isMore) {
						return page;
					}
					const seen = new Set(previous.map((event) => event.id));
					return [
						...previous,
						...page.filter((event) => !seen.has(event.id)),
					];
				});
				setTotal(response.data.total || 0);
				if (!isMore) {
					setCounts(response.data.counts || null);
				}
				setRetentionDays(response.data.retention_days || 30);
				setLoadFailed(false);
			} catch (error) {
				if (request !== latestRequest.current) {
					return;
				}
				logger.error('Timeline load error:', error);
				if (isMore) {
					setLoadMoreFailed(true);
				} else {
					setLoadFailed(true);
				}
			} finally {
				if (request === latestRequest.current) {
					setIsLoading(false);
					setIsLoadingMore(false);
				}
			}
		},
		[severity, type]
	);

	useEffect(() => {
		load(0);
	}, [load]);

	const grouped = useMemo(() => groupByDay(events), [events]);

	const filtered = Boolean(severity || type);

	const severityChip = (level) => {
		const active = severity === level;
		const isAll = level === '';
		const count = isAll
			? Object.values(counts || {}).reduce((sum, n) => sum + n, 0)
			: (counts && counts[level]) || 0;

		return (
			<button
				key={level || 'all'}
				type="button"
				aria-pressed={active}
				onClick={() => setSeverity(level)}
				className={`tr-inline-flex tr-items-center tr-gap-2 tr-h-9 tr-px-3 tr-rounded-md tr-border tr-text-sm tr-cursor-pointer tr-transition-colors ${
					active
						? 'tr-bg-white tr-border-primary tr-text-primary tr-shadow-sm tr-font-medium'
						: 'tr-bg-transparent tr-border-transparent tr-text-secondary hover:tr-text-primary'
				}`}
			>
				{!isAll && (
					<span
						aria-hidden="true"
						className={`tr-w-2 tr-h-2 tr-rounded-full ${SEVERITY[level].dot}`}
					/>
				)}
				{isAll ? __('All', 'thinkrank') : SEVERITY[level].label}
				<span className="tr-min-w-[20px] tr-px-1.5 tr-rounded-full tr-bg-line-2 tr-text-xs tr-text-secondary tr-tabular-nums">
					{count}
				</span>
			</button>
		);
	};

	return (
		<Container direction="column" gap={6}>
			<Card padding={false}>
				<SectionHeader
					title={__('SEO Timeline', 'thinkrank')}
					description={sprintf(
						/* translators: %d: number of days of history kept. */
						_n(
							'Every change that can move your search traffic, newest first. Kept for %d day.',
							'Every change that can move your search traffic, newest first. Kept for %d days.',
							retentionDays,
							'thinkrank'
						),
						retentionDays
					)}
					trailing={
						!isLoading && !loadFailed ? (
							<span className="tr-text-xs tr-font-medium tr-text-secondary tr-bg-line-2 tr-rounded-full tr-px-2.5 tr-py-1 tr-whitespace-nowrap">
								{sprintf(
									/* translators: %d: number of recorded changes. */
									_n(
										'%d change',
										'%d changes',
										total,
										'thinkrank'
									),
									total
								)}
							</span>
						) : null
					}
				/>

				<div className="tr-flex tr-flex-wrap tr-items-center tr-justify-between tr-gap-3 tr-px-6 tr-py-3 tr-border-b tr-border-secondary tr-bg-line-2/40">
					<div
						role="group"
						aria-label={__('Severity', 'thinkrank')}
						className="tr-flex tr-flex-wrap tr-items-center tr-gap-1"
					>
						{['', 'critical', 'warning', 'info'].map(severityChip)}
					</div>
					<Select
						id="tr-timeline-type"
						aria-label={__('Event type', 'thinkrank')}
						className="tr-w-[240px]"
						value={type}
						onChange={(event) => setType(event.target.value)}
						options={[
							{
								value: '',
								label: __('All event types', 'thinkrank'),
							},
							...Object.entries(TYPE_LABELS).map(
								([value, label]) => ({ value, label })
							),
						]}
					/>
				</div>

				{isLoading && (
					<div className="tr-p-6">
						<SkeletonCard lines={4} />
					</div>
				)}

				{!isLoading && loadFailed && (
					<div className="tr-p-6">
						<Alert variant="error">
							{__(
								'Could not load the timeline. Nothing has been changed; retry in a moment.',
								'thinkrank'
							)}
						</Alert>
					</div>
				)}

				{!isLoading && !loadFailed && grouped.length === 0 && (
					<EmptyState
						size="sm"
						icon={(props) => <Icon icon={backup} {...props} />}
						title={
							filtered
								? __('No matching changes', 'thinkrank')
								: __('Nothing recorded yet', 'thinkrank')
						}
						description={
							filtered
								? __(
										'No recorded change matches those filters.',
										'thinkrank'
									)
								: __(
										'Changes are recorded as they happen: a plugin update, a published post, a changed URL, a settings save.',
										'thinkrank'
									)
						}
						action={
							filtered ? (
								<Button
									variant="secondary"
									size="sm"
									onClick={() => {
										setSeverity('');
										setType('');
									}}
								>
									{__('Clear filters', 'thinkrank')}
								</Button>
							) : null
						}
					/>
				)}

				{!isLoading && !loadFailed && grouped.length > 0 && (
					<div className="tr-px-6 tr-pt-5 tr-pb-2">
						{grouped.map((group) => (
							<section key={group.day} className="tr-mb-4">
								<h4 className="tr-flex tr-items-center tr-gap-3 tr-m-0 tr-mb-4 tr-text-xs tr-font-semibold tr-uppercase tr-tracking-wide tr-text-secondary">
									<span>{formatDay(group.day)}</span>
									<span className="tr-flex-1 tr-h-px tr-bg-line-2" />
									<span className="tr-font-normal tr-normal-case tr-tracking-normal tr-text-tertiary">
										{sprintf(
											/* translators: %d: number of changes that day. */
											_n(
												'%d change',
												'%d changes',
												group.events.length,
												'thinkrank'
											),
											group.events.length
										)}
									</span>
								</h4>
								<ul className="tr-list-none tr-m-0 tr-p-0">
									{group.events.map((event, index) => (
										<TimelineEvent
											key={event.id}
											event={event}
											isLast={
												index ===
												group.events.length - 1
											}
										/>
									))}
								</ul>
							</section>
						))}
					</div>
				)}

				{!isLoading && !loadFailed && total > events.length && (
					<div className="tr-flex tr-items-center tr-justify-between tr-gap-3 tr-px-6 tr-py-3 tr-border-t tr-border-secondary">
						<p className="tr-m-0 tr-text-xs tr-text-tertiary">
							{loadMoreFailed
								? __(
										'Could not load more changes. Retry in a moment.',
										'thinkrank'
									)
								: sprintf(
										/* translators: 1: number shown, 2: total recorded. */
										__(
											'Showing %1$d of %2$d recorded changes.',
											'thinkrank'
										),
										events.length,
										total
									)}
						</p>
						<Button
							variant="secondary"
							size="sm"
							loading={isLoadingMore}
							disabled={isLoadingMore}
							onClick={() => load(events.length)}
						>
							{__('Load more', 'thinkrank')}
						</Button>
					</div>
				)}
			</Card>
		</Container>
	);
};

export default TimelineSection;
