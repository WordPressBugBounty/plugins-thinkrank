/**
 * Regression Guard: one page's last check — what changed since the check
 * before it, and what the page serves now (#720).
 *
 * Shared by the SEO Analyzer screen and the editor's Advanced panel. The server
 * owns every judgement (severity, expected or not, noindex); this renders them.
 *
 * @package
 * @since 2.23.0
 */

import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Alert, Badge } from '../ui';

const SEVERITY_BADGE = {
	critical: { variant: 'poor', label: __('Critical', 'thinkrank') },
	warning: { variant: 'warning', label: __('Warning', 'thinkrank') },
	info: { variant: 'neutral', label: __('Info', 'thinkrank') },
};

const REASON_LABELS = {
	post_edited: __('this page was edited', 'thinkrank'),
	term_edited: __('this archive’s term was edited', 'thinkrank'),
	settings_changed: __(
		'ThinkRank or WordPress settings changed',
		'thinkrank'
	),
};

/**
 * A Unix timestamp as local date and time.
 *
 * @param {number} ts Seconds since the epoch.
 * @return {string} Formatted date.
 */
export const formatCheckedAt = (ts) =>
	ts ? new Date(ts * 1000).toLocaleString() : '';

/**
 * Before → after for one change. Long values wrap rather than stretch the
 * panel; an empty side reads as "(none)" so a removal is not a blank.
 *
 * @param {Object} props        Component props.
 * @param {string} props.before Previous value.
 * @param {string} props.after  Current value.
 */
const BeforeAfter = ({ before, after }) => {
	if (!before && !after) {
		return null;
	}
	const none = (
		<em className="tr-text-tertiary">{__('(none)', 'thinkrank')}</em>
	);

	return (
		<div className="tr-mt-1 tr-grid tr-gap-0.5 tr-text-xs tr-break-all">
			<div>
				<span className="tr-text-tertiary">
					{__('Before:', 'thinkrank')}{' '}
				</span>
				<span className="tr-text-secondary">{before || none}</span>
			</div>
			<div>
				<span className="tr-text-tertiary">
					{__('Now:', 'thinkrank')}{' '}
				</span>
				<span className="tr-text-primary">{after || none}</span>
			</div>
		</div>
	);
};

/**
 * The list of changes, most severe first (the server sorts them).
 *
 * @param {Object} props         Component props.
 * @param {Array}  props.changes Changes from the result.
 */
const ChangeList = ({ changes }) => (
	<ul className="tr-m-0 tr-p-0 tr-list-none tr-border tr-border-secondary tr-rounded-lg tr-divide-y tr-divide-secondary">
		{changes.map((change, i) => {
			const badge =
				SEVERITY_BADGE[change.severity] || SEVERITY_BADGE.info;
			return (
				<li
					key={`${change.code}-${i}`}
					className="tr-m-0 tr-px-3 tr-py-2.5"
				>
					<div className="tr-flex tr-items-start tr-gap-2">
						<Badge variant={badge.variant} className="tr-shrink-0">
							{badge.label}
						</Badge>
						<div className="tr-min-w-0 tr-flex-1">
							<div className="tr-text-sm tr-text-primary">
								{change.message}
							</div>
							<BeforeAfter
								before={change.before}
								after={change.after}
							/>
						</div>
					</div>
				</li>
			);
		})}
	</ul>
);

/**
 * The verdict line above the change list.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.result Diff result.
 * @param {number} props.since  When the compared check ran.
 */
const Verdict = ({ result, since }) => {
	const when = formatCheckedAt(since);

	if (!result.changed) {
		return (
			<Alert variant="success">
				{sprintf(
					/* translators: %s: date and time of the previous check. */
					__('Nothing changed since the check on %s.', 'thinkrank'),
					when
				)}
			</Alert>
		);
	}

	const total = result.changes.length;
	const title = sprintf(
		/* translators: 1: number of changes, 2: date and time of the previous check. */
		_n(
			'%1$d change since the check on %2$s',
			'%1$d changes since the check on %2$s',
			total,
			'thinkrank'
		),
		total,
		when
	);

	if (result.expected) {
		const reasons = result.reasons
			.map((reason) => REASON_LABELS[reason] || reason)
			.join(__(' and ', 'thinkrank'));
		return (
			<Alert variant="info" title={title}>
				{sprintf(
					/* translators: %s: what was edited, e.g. "this page was edited". */
					__(
						'Probably expected: %s between the two checks. Make sure each change below is one you meant.',
						'thinkrank'
					),
					reasons
				)}
			</Alert>
		);
	}

	return (
		<Alert
			variant={result.counts.critical > 0 ? 'error' : 'warning'}
			title={title}
		>
			{__(
				'Nobody edited this page or its settings in between, so something else made these changes: usually a plugin, theme or WordPress update.',
				'thinkrank'
			)}
		</Alert>
	);
};

/**
 * One row of the "what the page serves" summary.
 *
 * @param {Object}  props       Component props.
 * @param {string}  props.label Field name.
 * @param {*}       props.value Field value.
 * @param {boolean} props.bad   Whether to flag the value.
 */
const Row = ({ label, value, bad = false }) => (
	<div className="tr-grid tr-grid-cols-[8rem_1fr] tr-gap-3 tr-py-1.5 tr-text-xs">
		<dt className="tr-text-tertiary">{label}</dt>
		<dd
			className={`tr-m-0 tr-break-all ${bad ? 'tr-text-danger tr-font-medium' : 'tr-text-primary'}`}
		>
			{value || (
				<em className="tr-text-tertiary">
					{__('(none)', 'thinkrank')}
				</em>
			)}
		</dd>
	</div>
);

/**
 * What the page served at the last check.
 *
 * @param {Object} props          Component props.
 * @param {Object} props.snapshot Snapshot from the check.
 */
export const SnapshotSummary = ({ snapshot }) => {
	const redirects = snapshot.status >= 300 && snapshot.status < 400;
	const robots = [
		...(snapshot.robots || []),
		...(snapshot.x_robots_tag || []).map((t) => `X-Robots-Tag: ${t}`),
	].join(', ');
	const types = [...new Set((snapshot.schema || []).map((e) => e.type))].join(
		', '
	);

	return (
		<dl className="tr-m-0 tr-divide-y tr-divide-secondary">
			<Row
				label={__('HTTP status', 'thinkrank')}
				value={
					redirects
						? `${snapshot.status} → ${snapshot.redirect_to}`
						: String(snapshot.status)
				}
				bad={200 !== snapshot.status}
			/>
			<Row
				label={__('Title', 'thinkrank')}
				value={snapshot.title}
				bad={200 === snapshot.status && !snapshot.title}
			/>
			<Row
				label={__('Meta description', 'thinkrank')}
				value={snapshot.description}
			/>
			<Row
				label={__('Robots', 'thinkrank')}
				value={
					snapshot.noindex
						? `${robots} (${__('not indexed', 'thinkrank')})`
						: robots
				}
				bad={snapshot.noindex}
			/>
			<Row
				label={__('Canonical', 'thinkrank')}
				value={
					snapshot.canonical_count > 1
						? `${snapshot.canonical} (${sprintf(
								/* translators: %d: number of canonical tags. */
								__('%d tags', 'thinkrank'),
								snapshot.canonical_count
							)})`
						: snapshot.canonical
				}
				bad={snapshot.canonical_count > 1}
			/>
			<Row label={__('Structured data', 'thinkrank')} value={types} />
			<Row
				label={__('og:image', 'thinkrank')}
				value={snapshot.og?.image}
			/>
			<Row
				label={__('hreflang', 'thinkrank')}
				value={Object.keys(snapshot.hreflang || {}).join(', ')}
			/>
			<Row
				label={__('H1 headings', 'thinkrank')}
				value={String(snapshot.h1_count)}
				bad={200 === snapshot.status && 0 === snapshot.h1_count}
			/>
		</dl>
	);
};

/**
 * A page's last check.
 *
 * @param {Object}      props           Component props.
 * @param {Object|null} props.view      View from the endpoint.
 * @param {boolean}     props.showServe Show the snapshot summary open by default.
 * @param {boolean}     props.quiet     Leave out "Nothing changed": the caller
 *                                      already says so in a badge.
 */
const HeadCheckResult = ({ view, showServe = false, quiet = false }) => {
	const [open, setOpen] = useState(showServe);

	if (!view) {
		return null;
	}

	return (
		<div className="tr-flex tr-flex-col tr-gap-3">
			{view.error && (
				<Alert
					variant="warning"
					title={__('The page could not be checked', 'thinkrank')}
				>
					{view.error.message}
				</Alert>
			)}

			{!view.checked_at && !view.error && (
				<p className="tr-text-sm tr-text-secondary tr-m-0">
					{__(
						'Not checked yet. The first check records what the page serves; each check after it shows what changed.',
						'thinkrank'
					)}
				</p>
			)}

			{view.checked_at && !view.result && (
				<Alert variant="info">
					{sprintf(
						/* translators: %s: date and time of the check. */
						__(
							'First check saved on %s. Check again after an update to see whether anything changed.',
							'thinkrank'
						),
						formatCheckedAt(view.checked_at)
					)}
				</Alert>
			)}

			{view.result && (view.result.changed || !quiet) && (
				<Verdict
					result={view.result}
					since={view.previous_checked_at}
				/>
			)}
			{view.result?.changed && (
				<ChangeList changes={view.result.changes} />
			)}

			{view.snapshot && (
				<div>
					<button
						type="button"
						className="tr-bg-transparent tr-border-0 tr-p-0 tr-text-xs tr-font-medium tr-text-brand tr-cursor-pointer"
						aria-expanded={open}
						onClick={() => setOpen(!open)}
					>
						{open
							? __('Hide what the page serves', 'thinkrank')
							: __('Show what the page serves', 'thinkrank')}
					</button>
					{open && (
						<div className="tr-mt-2">
							<SnapshotSummary snapshot={view.snapshot} />
						</div>
					)}
				</div>
			)}
		</div>
	);
};

export default HeadCheckResult;
