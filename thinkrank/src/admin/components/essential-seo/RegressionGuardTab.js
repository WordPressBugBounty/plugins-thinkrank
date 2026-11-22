/**
 * Essential SEO → SEO Analyzer → Regression Guard (#720).
 *
 * Free: check the homepage, and re-check any post already checked from its
 * editor, by hand. Each check is compared with the one before it, so running it
 * before and after an update shows exactly what the update changed in what the
 * page serves. Scheduled checks after every update, alerts and history are
 * Pro's, in the `thinkrank.seoAnalyzer.regressionGuardTab` slot below.
 *
 * @package
 * @since 2.23.0
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { Card, Button, Badge, SkeletonCard } from '../ui';
import SectionHeader from '../common/SectionHeader';
import notify from '../../utils/notify';
import ProGatedTab from '../pro/ProGatedTab';
import ProTeaser from '../pro/ProTeaser';
import { RegressionGuardIllustration } from '../pro/pro-teaser-illustrations';
import HeadCheckResult, {
	formatCheckedAt,
} from '../regression-guard/HeadCheckResult';
import useHeadCheck, {
	REGRESSION_GUARD_PATH,
} from '../regression-guard/useHeadCheck';

/**
 * Shield line icon for the card header, the same mark Pro's monitoring card
 * uses so the two cards read as one feature.
 */
const ShieldIcon = () => (
	<svg
		width="22"
		height="22"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M12 3l7 3v5.5c0 4.6-3 8.3-7 9.5-4-1.2-7-4.9-7-9.5V6l7-3z"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinejoin="round"
		/>
		<path
			d="M9 12l2 2 4-4.5"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

/**
 * How long ago a check ran, in words.
 *
 * @param {number} ts  Seconds since the epoch.
 * @param {number} now Current time in seconds; injectable for tests.
 * @return {string} Relative time, or '' when never checked.
 */
export const timeAgo = (ts, now = Math.floor(Date.now() / 1000)) => {
	if (!ts) {
		return '';
	}
	const seconds = Math.max(0, now - ts);
	if (seconds < 60) {
		return __('Just now', 'thinkrank');
	}
	const minutes = Math.floor(seconds / 60);
	if (minutes < 60) {
		return sprintf(
			/* translators: %d: number of minutes. */
			_n('%d minute ago', '%d minutes ago', minutes, 'thinkrank'),
			minutes
		);
	}
	const hours = Math.floor(minutes / 60);
	if (hours < 24) {
		return sprintf(
			/* translators: %d: number of hours. */
			_n('%d hour ago', '%d hours ago', hours, 'thinkrank'),
			hours
		);
	}
	const days = Math.floor(hours / 24);
	/* translators: %d: number of days. */
	return sprintf(_n('%d day ago', '%d days ago', days, 'thinkrank'), days);
};

/**
 * What every checked page's last check adds up to: the homepage plus the
 * pages checked from the editor.
 *
 * @param {Object|null} home Homepage view from useHeadCheck.
 * @param {Array}       rows Rows from /regression-guard/pages.
 * @return {{pages: number, critical: number, warning: number, compared: boolean, lastCheckedAt: number}} Totals.
 */
export const summarize = (home, rows) => {
	const entries = (rows || []).map((row) => ({
		counts: row.counts || null,
		checkedAt: row.checked_at || 0,
	}));
	if (home?.checked_at) {
		entries.push({
			counts: home.result?.counts || null,
			checkedAt: home.checked_at,
		});
	}

	return entries.reduce(
		(sum, entry) => ({
			pages: sum.pages + 1,
			critical: sum.critical + (entry.counts?.critical || 0),
			warning: sum.warning + (entry.counts?.warning || 0),
			compared: sum.compared || !!entry.counts,
			lastCheckedAt: Math.max(sum.lastCheckedAt, entry.checkedAt),
		}),
		{ pages: 0, critical: 0, warning: 0, compared: false, lastCheckedAt: 0 }
	);
};

/**
 * The status pill beside the title: the worst thing any last check found.
 *
 * @param {Object} summary Totals from summarize().
 * @return {{variant: string, label: string}} Badge variant and text.
 */
export const overviewStatus = (summary) => {
	if (0 === summary.pages) {
		return {
			variant: 'neutral',
			label: __('Not checked yet', 'thinkrank'),
		};
	}
	if (summary.critical > 0) {
		return {
			variant: 'poor',
			label: sprintf(
				/* translators: %d: number of critical changes. */
				_n(
					'%d critical change',
					'%d critical changes',
					summary.critical,
					'thinkrank'
				),
				summary.critical
			),
		};
	}
	if (summary.warning > 0) {
		return {
			variant: 'warning',
			label: sprintf(
				/* translators: %d: number of warnings. */
				_n('%d warning', '%d warnings', summary.warning, 'thinkrank'),
				summary.warning
			),
		};
	}
	if (!summary.compared) {
		return { variant: 'info', label: __('Baseline saved', 'thinkrank') };
	}

	return { variant: 'good', label: __('No changes', 'thinkrank') };
};

/**
 * One figure in the summary strip.
 *
 * @param {Object} props       Component props.
 * @param {string} props.label What the figure counts.
 * @param {*}      props.value The figure.
 * @param {string} props.hint  Small print under it.
 * @param {string} props.title Hover text, such as the exact date.
 * @param {string} props.tone  Text colour class for the figure.
 */
const Stat = ({ label, value, hint, title, tone = 'tr-text-primary' }) => (
	<div className="tr-min-w-0 tr-px-6 tr-py-4" title={title}>
		<div className="tr-text-xs tr-font-medium tr-text-tertiary">
			{label}
		</div>
		<div className={`tr-mt-1 tr-text-xl tr-font-semibold ${tone}`}>
			{value}
		</div>
		{hint && (
			<div className="tr-mt-0.5 tr-text-xs tr-text-tertiary">{hint}</div>
		)}
	</div>
);

/**
 * Counts from a stored result as badges; nothing for a first check.
 *
 * @param {Object}      props          Component props.
 * @param {Object|null} props.counts   Severity counts.
 * @param {boolean}     props.expected Whether the changes were expected.
 */
export const CountBadges = ({ counts, expected }) => {
	if (!counts) {
		return (
			<Badge variant="neutral">{__('First check', 'thinkrank')}</Badge>
		);
	}
	const total = counts.critical + counts.warning + counts.info;
	if (0 === total) {
		return <Badge variant="good">{__('No changes', 'thinkrank')}</Badge>;
	}

	return (
		<span className="tr-inline-flex tr-flex-wrap tr-gap-1">
			{counts.critical > 0 && (
				<Badge variant="poor">
					{sprintf(
						/* translators: %d: number of critical changes. */
						_n(
							'%d critical',
							'%d critical',
							counts.critical,
							'thinkrank'
						),
						counts.critical
					)}
				</Badge>
			)}
			{counts.warning > 0 && (
				<Badge variant="warning">
					{sprintf(
						/* translators: %d: number of warnings. */
						_n(
							'%d warning',
							'%d warnings',
							counts.warning,
							'thinkrank'
						),
						counts.warning
					)}
				</Badge>
			)}
			{counts.info > 0 && (
				<Badge variant="neutral">
					{sprintf(
						/* translators: %d: number of informational changes. */
						_n('%d info', '%d info', counts.info, 'thinkrank'),
						counts.info
					)}
				</Badge>
			)}
			{expected && (
				<Badge variant="info">{__('Expected', 'thinkrank')}</Badge>
			)}
		</span>
	);
};

/**
 * The toast for a finished check: what changed on the page, in one line.
 *
 * @param {string}      label Page name, such as "Homepage".
 * @param {Object|null} data  Check response from useHeadCheck().check().
 * @return {{type: string, message: string}|null} Toast, or null for no response.
 */
export const checkToast = (label, data) => {
	if (!data) {
		return null;
	}
	const counts = data.result?.counts;
	if (!counts) {
		return {
			type: 'success',
			message: sprintf(
				/* translators: %s: page name. */
				__(
					'%s checked. Check it again after an update to see what changed.',
					'thinkrank'
				),
				label
			),
		};
	}
	if (counts.critical > 0) {
		return {
			type: 'error',
			message: sprintf(
				/* translators: 1: page name, 2: number of critical changes. */
				_n(
					'%1$s: %2$d critical change since the last check.',
					'%1$s: %2$d critical changes since the last check.',
					counts.critical,
					'thinkrank'
				),
				label,
				counts.critical
			),
		};
	}
	const total = counts.warning + counts.info;
	if (total > 0) {
		return {
			type: counts.warning > 0 ? 'warning' : 'info',
			message: sprintf(
				/* translators: 1: page name, 2: number of changes. */
				_n(
					'%1$s: %2$d change since the last check.',
					'%1$s: %2$d changes since the last check.',
					total,
					'thinkrank'
				),
				label,
				total
			),
		};
	}

	return {
		type: 'success',
		message: sprintf(
			/* translators: %s: page name. */
			__('%s: nothing changed since the last check.', 'thinkrank'),
			label
		),
	};
};

/**
 * Run a check from a row and say how it went in a toast. A failed check
 * keeps its message under the row too.
 *
 * @param {Function} check useHeadCheck().check.
 * @param {string}   label Page name.
 * @return {Promise<Object|null>} The check response.
 */
const checkWithToast = async (check, label) => {
	const data = await check();
	const toast = checkToast(label, data);
	if (toast) {
		notify[toast.type](toast.message);
	} else {
		notify.error(
			sprintf(
				/* translators: %s: page name. */
				__('%s could not be checked.', 'thinkrank'),
				label
			)
		);
	}
	return data;
};

/**
 * One checked post, re-checkable in place.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.row       Row from /regression-guard/pages.
 * @param {Function} props.onChecked Called with the post id and the new check.
 */
const CheckedPage = ({ row, onChecked }) => {
	const { view, checking, error, check } = useHeadCheck(row.post_id, {
		load: false,
	});
	// The row arrives with counts only. Its last result is read on request,
	// so what that check found can be seen without running a new one, which
	// would compare against it and leave its changes behind.
	const [stored, setStored] = useState(null);
	const [showStored, setShowStored] = useState(false);
	const [opening, setOpening] = useState(false);
	const [storedError, setStoredError] = useState('');
	const toggleStored = async () => {
		if (showStored) {
			setShowStored(false);
			return;
		}
		if (stored) {
			setShowStored(true);
			return;
		}
		setOpening(true);
		setStoredError('');
		try {
			const response = await apiFetch({
				path: `${REGRESSION_GUARD_PATH}?post_id=${row.post_id}`,
			});
			setStored(response?.data || null);
			setShowStored(true);
		} catch (e) {
			setStoredError(
				e?.message ||
					__('The last check could not be loaded.', 'thinkrank')
			);
		} finally {
			setOpening(false);
		}
	};
	const recheck = async () => {
		const data = await checkWithToast(check, row.label || row.url);
		if (data && onChecked) {
			onChecked(row.post_id, data);
		}
	};
	const counts = view ? (view.result?.counts ?? null) : row.counts;
	const expected = view ? !!view.result?.expected : row.expected;
	const checkedAt = view?.checked_at ?? row.checked_at;
	const hasChanges =
		!!row.counts &&
		row.counts.critical + row.counts.warning + row.counts.info > 0;
	const shown = view || (showStored ? stored : null);

	return (
		<li className="tr-m-0 tr-px-6 tr-py-4">
			<div className="tr-flex tr-flex-wrap tr-items-center tr-justify-between tr-gap-3">
				<div className="tr-min-w-0">
					<div className="tr-text-sm tr-font-medium tr-text-primary">
						{row.label || row.url}
					</div>
					<div className="tr-text-xs tr-text-tertiary tr-break-all">
						{row.url}
					</div>
				</div>
				<div className="tr-flex tr-items-center tr-gap-3">
					<CountBadges counts={counts} expected={expected} />
					<span className="tr-text-xs tr-text-tertiary">
						{formatCheckedAt(checkedAt)}
					</span>
					<Button
						variant="secondary"
						size="sm"
						loading={checking}
						onClick={recheck}
					>
						{__('Check again', 'thinkrank')}
					</Button>
				</div>
			</div>
			{!view && hasChanges && (
				<button
					type="button"
					className="tr-mt-2 tr-bg-transparent tr-border-0 tr-p-0 tr-text-xs tr-font-medium tr-text-brand tr-cursor-pointer disabled:tr-cursor-default disabled:tr-opacity-60"
					aria-expanded={showStored}
					disabled={opening}
					onClick={toggleStored}
				>
					{showStored
						? __('Hide changes', 'thinkrank')
						: __('Show changes', 'thinkrank')}
				</button>
			)}
			{(error || storedError) && (
				<p className="tr-text-xs tr-text-danger tr-mt-2 tr-mb-0">
					{error || storedError}
				</p>
			)}
			{shown && (
				<div className="tr-mt-3">
					<HeadCheckResult view={shown} quiet />
				</div>
			)}
		</li>
	);
};

/**
 * The homepage, pinned first in the page list. It is checked from here, the
 * way a post is checked from its editor, so its button says what it does
 * the first time and matches the other rows after that.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.home State and check action from useHeadCheck(0).
 */
export const HomepageRow = ({ home }) => {
	const { view, checking, error, check } = home;
	const checked = !!view?.checked_at;

	return (
		<li className="tr-m-0 tr-px-6 tr-py-4">
			<div className="tr-flex tr-flex-wrap tr-items-center tr-justify-between tr-gap-3">
				<div className="tr-min-w-0">
					<div className="tr-text-sm tr-font-medium tr-text-primary">
						{__('Homepage', 'thinkrank')}
					</div>
					{view?.url && (
						<div className="tr-text-xs tr-text-tertiary tr-break-all">
							{view.url}
						</div>
					)}
				</div>
				<div className="tr-flex tr-items-center tr-gap-3">
					{checked ? (
						<CountBadges
							counts={view.result?.counts ?? null}
							expected={!!view.result?.expected}
						/>
					) : (
						<Badge variant="neutral">
							{__('Not checked yet', 'thinkrank')}
						</Badge>
					)}
					{checked && (
						<span className="tr-text-xs tr-text-tertiary">
							{formatCheckedAt(view.checked_at)}
						</span>
					)}
					<Button
						variant="secondary"
						size="sm"
						loading={checking}
						onClick={() =>
							checkWithToast(check, __('Homepage', 'thinkrank'))
						}
					>
						{checked
							? __('Check again', 'thinkrank')
							: __('Check now', 'thinkrank')}
					</Button>
				</div>
			</div>
			{error && (
				<p className="tr-text-xs tr-text-danger tr-mt-2 tr-mb-0">
					{error}
				</p>
			)}
			{checked && (
				<div className="tr-mt-3">
					<HeadCheckResult view={view} quiet />
				</div>
			)}
		</li>
	);
};

/**
 * The overview card: what Regression Guard does, the worst thing any last
 * check found, and a summary strip. Checks run from the page list below.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.home State and check action from useHeadCheck(0).
 * @param {Array}  props.rows Pages checked from the editor, or null while loading.
 */
export const Overview = ({ home, rows }) => {
	const { view, loading } = home;

	if (loading) {
		return <SkeletonCard />;
	}

	const summary = summarize(view, rows);
	const status = overviewStatus(summary);
	let issuesTone = 'tr-text-primary';
	if (summary.critical > 0) {
		issuesTone = 'tr-text-danger';
	} else if (summary.warning > 0) {
		issuesTone = 'tr-text-warning';
	}

	return (
		<Card padding={false}>
			<div className="tr-flex tr-flex-wrap tr-items-start tr-justify-between tr-gap-4 tr-px-6 tr-pt-5 tr-pb-4 tr-border-b tr-border-secondary">
				<div className="tr-flex tr-min-w-0 tr-flex-1 tr-basis-80 tr-items-start tr-gap-3">
					<span className="tr-flex tr-h-10 tr-w-10 tr-shrink-0 tr-items-center tr-justify-center tr-rounded-lg tr-bg-brand-bg tr-text-brand">
						<ShieldIcon />
					</span>
					<div className="tr-min-w-0">
						<div className="tr-flex tr-flex-wrap tr-items-center tr-gap-2">
							<h3 className="tr-m-0 tr-text-base tr-font-semibold tr-text-primary">
								{__('Regression Guard', 'thinkrank')}
							</h3>
							<Badge variant={status.variant}>
								{status.label}
							</Badge>
						</div>
						<p className="tr-mt-1 tr-mb-0 tr-text-sm tr-text-secondary">
							{__(
								'Plugin, theme and WordPress updates can quietly change what your pages serve to Google: a noindex, a canonical pointing elsewhere, structured data that disappears. ThinkRank compares each check with the one before it and shows exactly what changed.',
								'thinkrank'
							)}
						</p>
					</div>
				</div>
			</div>

			<div className="tr-grid tr-grid-cols-1 sm:tr-grid-cols-3 tr-divide-y sm:tr-divide-y-0 sm:tr-divide-x tr-divide-secondary">
				<Stat
					label={__('Pages checked', 'thinkrank')}
					value={null === rows ? '…' : summary.pages}
					hint={__(
						'Homepage and pages checked from the editor',
						'thinkrank'
					)}
				/>
				<Stat
					label={__('Critical changes', 'thinkrank')}
					value={summary.critical}
					tone={issuesTone}
					hint={
						summary.warning > 0
							? sprintf(
									/* translators: %d: number of warnings. */
									_n(
										'and %d warning',
										'and %d warnings',
										summary.warning,
										'thinkrank'
									),
									summary.warning
								)
							: __('In each page’s last check', 'thinkrank')
					}
				/>
				<Stat
					label={__('Last check', 'thinkrank')}
					value={
						summary.lastCheckedAt
							? timeAgo(summary.lastCheckedAt)
							: __('Never', 'thinkrank')
					}
					title={formatCheckedAt(summary.lastCheckedAt)}
					hint={formatCheckedAt(summary.lastCheckedAt)}
				/>
			</div>
		</Card>
	);
};

/**
 * Every page Regression Guard watches: the homepage first, then the posts
 * checked from their editor.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.home      State and check action from useHeadCheck(0).
 * @param {Array}    props.rows      Rows, or null while loading.
 * @param {string}   props.error     Load error.
 * @param {Function} props.onChecked Called after a row is checked again.
 */
export const CheckedPages = ({ home, rows, error, onChecked }) => {
	if (null === rows || home.loading) {
		return <SkeletonCard />;
	}

	return (
		<Card padding={false}>
			<SectionHeader
				title={__('Checked pages', 'thinkrank')}
				description={__(
					'Check a page before and after an update to see what changed. To add a post, page or product, open it in the editor: ThinkRank panel → Advanced → What this page serves.',
					'thinkrank'
				)}
			/>
			{error && (
				<p className="tr-text-sm tr-text-danger tr-px-6 tr-pt-4 tr-m-0">
					{error}
				</p>
			)}
			<ul className="tr-m-0 tr-p-0 tr-list-none tr-divide-y tr-divide-secondary">
				<HomepageRow home={home} />
				{rows.map((row) => (
					<CheckedPage
						key={row.post_id}
						row={row}
						onChecked={onChecked}
					/>
				))}
			</ul>
		</Card>
	);
};

/**
 * Loads the checked pages once and keeps each row current after a re-check,
 * so the overview's totals follow what is on screen.
 *
 * @return {{rows: Array|null, error: string, onChecked: Function}} State.
 */
const useCheckedPages = () => {
	const [rows, setRows] = useState(null);
	const [error, setError] = useState('');

	useEffect(() => {
		let current = true;
		apiFetch({ path: `${REGRESSION_GUARD_PATH}/pages` })
			.then((response) => current && setRows(response?.data || []))
			.catch((e) => {
				if (!current) {
					return;
				}
				setRows([]);
				setError(
					e?.message ||
						__(
							'The checked pages could not be loaded.',
							'thinkrank'
						)
				);
			});
		return () => {
			current = false;
		};
	}, []);

	const onChecked = useCallback((postId, data) => {
		setRows((previous) =>
			(previous || []).map((row) =>
				row.post_id === postId
					? {
							...row,
							counts: data.result?.counts ?? null,
							expected: !!data.result?.expected,
							checked_at: data.checked_at ?? row.checked_at,
						}
					: row
			)
		);
	}, []);

	return { rows, error, onChecked };
};

const RegressionGuardTab = () => {
	const home = useHeadCheck(0);
	const pages = useCheckedPages();

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			<Overview home={home} rows={pages.rows} />
			<CheckedPages
				home={home}
				rows={pages.rows}
				error={pages.error}
				onChecked={pages.onChecked}
			/>
			<ProGatedTab
				filter="thinkrank.seoAnalyzer.regressionGuardTab"
				teaser={
					<ProTeaser
						illustration={<RegressionGuardIllustration />}
						title={__(
							'Automatic checks after every update',
							'thinkrank'
						)}
						description={__(
							'A manual check only helps if you remember to run it. Pro checks your key pages every night and right after any plugin, theme or WordPress update, and tells you when something changed that nobody asked for.',
							'thinkrank'
						)}
						features={[
							__(
								'Checks after every update and theme switch, plus nightly',
								'thinkrank'
							),
							__(
								'Covers Focus Pages, the homepage and one page of every content type',
								'thinkrank'
							),
							__(
								'Email or Slack alert only for unexpected critical changes',
								'thinkrank'
							),
							__(
								'History of every check, grouped by the update that caused the change',
								'thinkrank'
							),
						]}
					/>
				}
			/>
		</div>
	);
};

export default RegressionGuardTab;
