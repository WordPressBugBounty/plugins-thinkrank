/**
 * Essential SEO → SEO Analyzer → Duplicate Tags (#733).
 *
 * What the home page and the latest post print twice in their <head> — a
 * theme's own <title>, a social plugin's Open Graph, a second canonical — and
 * one switch per tag family to take the foreign copies out of the page.
 * ThinkRank's own tags are always kept; a tag ThinkRank does not print is
 * never touched.
 *
 * @package
 * @since 2.23.0
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import {
	Card,
	Button,
	Badge,
	Toggle,
	Alert,
	SkeletonCard,
	SettingsLoadError,
} from '../ui';
import SectionHeader from '../common/SectionHeader';
import useAutosaveSettings from '../../hooks/useAutosaveSettings';
import { formatCheckedAt } from '../regression-guard/HeadCheckResult';
import { timeAgo } from './RegressionGuardTab';

export const DUPLICATE_TAGS_PATH = '/thinkrank/v1/duplicate-tags';

const FAMILIES = [
	{
		id: 'title',
		label: __('Title and meta description', 'thinkrank'),
		description: __(
			'A theme that prints its own <title>, or a second meta description.',
			'thinkrank'
		),
	},
	{
		id: 'canonical',
		label: __('Canonical URL', 'thinkrank'),
		description: __(
			'With two canonicals, search engines may ignore both.',
			'thinkrank'
		),
	},
	{
		id: 'robots',
		label: __('Robots meta tag', 'thinkrank'),
		description: __(
			'A second robots tag can quietly add noindex or nofollow.',
			'thinkrank'
		),
	},
	{
		id: 'social',
		label: __('Open Graph and Twitter cards', 'thinkrank'),
		description: __(
			'Two og:image tags and Facebook may show the wrong picture.',
			'thinkrank'
		),
	},
	{
		id: 'schema',
		label: __('Structured data (JSON-LD)', 'thinkrank'),
		description: __(
			'Removes another plugin’s block only when ThinkRank already publishes every type in it.',
			'thinkrank'
		),
	},
];

/**
 * A duplicate's tag as a reader knows it.
 *
 * @param {string} key Key from the report ("og:title", "schema:WebPage", …).
 * @return {string} Label.
 */
export const tagLabel = (key) => {
	if (key.startsWith('schema:')) {
		/* translators: %s: schema.org type. */
		return sprintf(__('%s schema', 'thinkrank'), key.slice(7));
	}
	const labels = {
		title: '<title>',
		'meta:description': __('meta description', 'thinkrank'),
		'link:canonical': __('canonical', 'thinkrank'),
	};
	if (labels[key]) {
		return labels[key];
	}

	return key.startsWith('meta:')
		? /* translators: %s: meta tag name such as robots. */
			sprintf(__('%s meta tag', 'thinkrank'), key.slice(5))
		: key;
};

/**
 * Two overlapping pages: the card's mark.
 */
const CopiesIcon = () => (
	<svg
		width="22"
		height="22"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.6"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<rect x="8.5" y="8.5" width="11" height="11" rx="2" />
		<path d="M15.5 8.5V6.5a2 2 0 0 0-2-2h-7a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h2" />
	</svg>
);

/**
 * Two curved arrows for "check again"; spins while a check runs.
 *
 * @param {Object}  props           Component props.
 * @param {boolean} props.className Classes from the Button.
 */
const RecheckIcon = ({ className }) => (
	<svg
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.8"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
		className={className}
	>
		<path d="M20 11a8 8 0 0 0-14.6-4.5L4 8" />
		<path d="M4 4v4h4" />
		<path d="M4 13a8 8 0 0 0 14.6 4.5L20 16" />
		<path d="M20 20v-4h-4" />
	</svg>
);

/**
 * The status pill beside the title.
 *
 * @param {Object} summary Report summary.
 * @return {{variant: string, label: string}} Badge variant and text.
 */
export const duplicateStatus = (summary) => {
	if (!summary || 0 === summary.checked) {
		return { variant: 'warning', label: __('Not checked', 'thinkrank') };
	}
	if (summary.critical > 0) {
		return {
			variant: 'poor',
			label: sprintf(
				/* translators: %d: number of critical duplicates. */
				_n('%d critical', '%d critical', summary.critical, 'thinkrank'),
				summary.critical
			),
		};
	}
	if (summary.duplicates > 0) {
		return {
			variant: 'warning',
			label: sprintf(
				/* translators: %d: number of duplicated tags. */
				_n('%d found', '%d found', summary.duplicates, 'thinkrank'),
				summary.duplicates
			),
		};
	}
	if (summary.removed > 0) {
		return { variant: 'good', label: __('Cleaned up', 'thinkrank') };
	}

	return { variant: 'good', label: __('No duplicates', 'thinkrank') };
};

/**
 * One figure in the summary strip.
 *
 * @param {Object} props       Component props.
 * @param {string} props.label What the figure counts.
 * @param {*}      props.value The figure.
 * @param {string} props.hint  Small print under it.
 * @param {string} props.tone  Text colour class for the figure.
 */
const Stat = ({ label, value, hint, tone = 'tr-text-primary' }) => (
	<div className="tr-min-w-0 tr-px-6 tr-py-4">
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
 * One duplicated tag on a page.
 *
 * @param {Object} props     Component props.
 * @param {Object} props.row Duplicate from the report.
 */
const DuplicateRow = ({ row }) => (
	<li className="tr-m-0 tr-flex tr-flex-wrap tr-items-center tr-gap-x-4 tr-gap-y-1 tr-py-3 tr-border-t tr-border-secondary first:tr-border-t-0">
		<code className="tr-min-w-[9rem] tr-text-xs tr-font-medium tr-text-primary">
			{tagLabel(row.key)}
		</code>
		<span className="tr-text-sm tr-text-secondary">
			{sprintf(
				/* translators: 1: copies in all, 2: ThinkRank's copies, 3: other copies. */
				__('%1$d copies: ThinkRank %2$d, others %3$d', 'thinkrank'),
				row.count,
				row.ours,
				row.foreign
			)}
		</span>
		<span className="tr-text-xs tr-text-tertiary">
			{row.sources.length > 0
				? sprintf(
						/* translators: %s: plugin names. */
						__('probably %s', 'thinkrank'),
						row.sources.join(', ')
					)
				: __('another plugin or your theme', 'thinkrank')}
		</span>
		<span className="tr-ml-auto tr-inline-flex tr-gap-1">
			{row.critical && (
				<Badge variant="poor">{__('Critical', 'thinkrank')}</Badge>
			)}
			{!row.removable && (
				<Badge variant="neutral">
					{__('Not ours to remove', 'thinkrank')}
				</Badge>
			)}
		</span>
	</li>
);

/**
 * One checked page and what it prints twice.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.page Page from the report.
 */
const PageReport = ({ page }) => {
	const removed = Object.values(page.removed || {}).reduce(
		(sum, n) => sum + n,
		0
	);

	return (
		<li className="tr-m-0 tr-px-6 tr-py-4 tr-border-t tr-border-secondary">
			<div className="tr-flex tr-flex-wrap tr-items-center tr-justify-between tr-gap-2">
				<a
					href={page.url}
					target="_blank"
					rel="noreferrer"
					className="tr-text-sm tr-font-semibold tr-text-primary tr-no-underline tr-break-all"
				>
					{page.url}
				</a>
				<span className="tr-inline-flex tr-gap-1">
					{page.ok && page.duplicates.length > 0 && (
						<Badge variant="warning">
							{sprintf(
								/* translators: %d: number of duplicated tags. */
								_n(
									'%d duplicate',
									'%d duplicates',
									page.duplicates.length,
									'thinkrank'
								),
								page.duplicates.length
							)}
						</Badge>
					)}
					{page.ok && 0 === page.duplicates.length && (
						<Badge variant="good">{__('Clean', 'thinkrank')}</Badge>
					)}
					{removed > 0 && (
						<Badge variant="info">
							{sprintf(
								/* translators: %d: number of tags. */
								_n(
									'%d removed',
									'%d removed',
									removed,
									'thinkrank'
								),
								removed
							)}
						</Badge>
					)}
				</span>
			</div>
			{!page.ok && (
				<p className="tr-text-sm tr-text-danger tr-mt-2 tr-mb-0">
					{page.error}
				</p>
			)}
			{page.ok && page.duplicates.length > 0 && (
				<ul className="tr-mt-2 tr-mb-0 tr-p-0 tr-list-none">
					{page.duplicates.map((row) => (
						<DuplicateRow key={row.key} row={row} />
					))}
				</ul>
			)}
		</li>
	);
};

/**
 * The header card: what the guard does, its state, and the summary strip.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.data     Endpoint data.
 * @param {string}   props.error    Last error.
 * @param {boolean}  props.checking Whether a fresh check is running.
 * @param {Function} props.onCheck  Run a fresh check.
 */
export const Overview = ({ data, error, checking, onCheck }) => {
	const { summary, checked_at: checkedAt } = data.report;
	const status = duplicateStatus(summary);
	const on = FAMILIES.filter((f) => data.settings?.[f.id]).length;

	return (
		<Card padding={false}>
			<div className="tr-flex tr-flex-wrap tr-items-start tr-justify-between tr-gap-4 tr-px-6 tr-pt-5 tr-pb-4 tr-border-b tr-border-secondary">
				<div className="tr-flex tr-min-w-0 tr-flex-1 tr-basis-80 tr-items-start tr-gap-3">
					<span className="tr-flex tr-h-10 tr-w-10 tr-shrink-0 tr-items-center tr-justify-center tr-rounded-lg tr-bg-brand-bg tr-text-brand">
						<CopiesIcon />
					</span>
					<div className="tr-min-w-0">
						<div className="tr-flex tr-flex-wrap tr-items-center tr-gap-2">
							<h3 className="tr-m-0 tr-text-base tr-font-semibold tr-text-primary">
								{__('Duplicate Tags', 'thinkrank')}
							</h3>
							<Badge variant={status.variant}>
								{status.label}
							</Badge>
						</div>
						<p className="tr-mt-1 tr-mb-0 tr-text-sm tr-text-secondary">
							{__(
								'Themes and other plugins often print their own title, canonical or Open Graph tags next to ThinkRank’s. Two canonicals and Google may ignore both; two og:image tags and Facebook may pick the wrong one.',
								'thinkrank'
							)}
						</p>
					</div>
				</div>
				<Button
					variant="ghost"
					onClick={onCheck}
					loading={checking}
					icon={RecheckIcon}
				>
					{checking
						? __('Checking…', 'thinkrank')
						: __('Check again', 'thinkrank')}
				</Button>
			</div>

			<div className="tr-grid tr-grid-cols-1 sm:tr-grid-cols-3 tr-divide-y sm:tr-divide-y-0 sm:tr-divide-x tr-divide-secondary">
				<Stat
					label={__('Tags printed twice', 'thinkrank')}
					value={summary.duplicates}
					tone={
						summary.duplicates > 0
							? 'tr-text-warning'
							: 'tr-text-primary'
					}
					hint={
						summary.critical > 0
							? sprintf(
									/* translators: %d: number of critical duplicates. */
									_n(
										'%d of them critical',
										'%d of them critical',
										summary.critical,
										'thinkrank'
									),
									summary.critical
								)
							: sprintf(
									/* translators: %d: number of pages checked. */
									_n(
										'On %d page checked',
										'Across %d pages checked',
										summary.checked,
										'thinkrank'
									),
									summary.checked
								)
					}
				/>
				<Stat
					label={__('Removed by ThinkRank', 'thinkrank')}
					value={summary.removed}
					tone={
						summary.removed > 0
							? 'tr-text-success'
							: 'tr-text-primary'
					}
					hint={sprintf(
						/* translators: 1: switches on, 2: switches in all. */
						__('%1$d of %2$d switches on', 'thinkrank'),
						on,
						FAMILIES.length
					)}
				/>
				<Stat
					label={__('Last check', 'thinkrank')}
					value={
						checkedAt
							? timeAgo(checkedAt)
							: __('Never', 'thinkrank')
					}
					hint={formatCheckedAt(checkedAt)}
				/>
			</div>

			{(error || data.kill_switch) && (
				<div className="tr-flex tr-flex-col tr-gap-3 tr-px-6 tr-pb-5">
					{error && <Alert variant="error">{error}</Alert>}
					{data.kill_switch && (
						<Alert variant="warning">
							{__(
								'THINKRANK_DISABLE_DUPLICATE_TAG_GUARD is set in wp-config.php, so nothing is removed whatever these switches say.',
								'thinkrank'
							)}
						</Alert>
					)}
				</div>
			)}
		</Card>
	);
};

/**
 * One switch per tag family, as settings rows.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.settings Stored switches.
 * @param {Object}   props.found    Duplicates found per family.
 * @param {boolean}  props.isSaving Whether a switch is saving.
 * @param {Function} props.update   Autosave update.
 */
const FamilySwitches = ({ settings, found, isSaving, update }) => (
	<Card padding={false}>
		<SectionHeader
			title={__('Remove duplicates', 'thinkrank')}
			description={__(
				'Switch a family on to take the other copies out of your pages. ThinkRank’s own tags are always kept, and tags ThinkRank does not print are never touched.',
				'thinkrank'
			)}
			withBorder={false}
		/>
		<ul className="tr-m-0 tr-p-0 tr-list-none">
			{FAMILIES.map((family) => {
				const id = `thinkrank-duplicate-tags-${family.id}`;
				return (
					<li
						key={family.id}
						className="tr-m-0 tr-flex tr-flex-wrap tr-items-center tr-justify-between tr-gap-x-6 tr-gap-y-2 tr-px-6 tr-py-4 tr-border-t tr-border-secondary"
					>
						<label
							htmlFor={id}
							className="tr-min-w-0 tr-flex-1 tr-basis-60 tr-cursor-pointer"
						>
							<span className="tr-block tr-text-sm tr-font-medium tr-text-primary">
								{family.label}
							</span>
							<span className="tr-block tr-mt-0.5 tr-text-xs tr-text-tertiary">
								{family.description}
							</span>
						</label>
						<span className="tr-inline-flex tr-items-center tr-gap-3">
							{found[family.id] > 0 && (
								<Badge variant="warning">
									{sprintf(
										/* translators: %d: number of duplicated tags. */
										_n(
											'%d found',
											'%d found',
											found[family.id],
											'thinkrank'
										),
										found[family.id]
									)}
								</Badge>
							)}
							<Toggle
								id={id}
								checked={!!settings[family.id]}
								disabled={isSaving}
								onChange={(on) =>
									update(
										{ [family.id]: on },
										on
											? sprintf(
													/* translators: %s: tag family. */
													__(
														'Duplicate %s tags are now removed. A page cache may serve the old page until it refreshes.',
														'thinkrank'
													),
													family.label
												)
											: sprintf(
													/* translators: %s: tag family. */
													__(
														'Duplicate %s tags are no longer removed.',
														'thinkrank'
													),
													family.label
												)
									)
								}
							/>
						</span>
					</li>
				);
			})}
		</ul>
	</Card>
);

const DuplicateTagsTab = () => {
	const [data, setData] = useState(null);
	const [settings, setSettings] = useState(null);
	const [error, setError] = useState('');
	const [checking, setChecking] = useState(false);

	const { update, isSaving } = useAutosaveSettings({
		settings,
		setSettings,
		save: (next) =>
			apiFetch({
				path: `${DUPLICATE_TAGS_PATH}/settings`,
				method: 'POST',
				data: next,
			}).then((res) => res?.data),
		errorText: __('Couldn’t save the setting.', 'thinkrank'),
	});

	const load = useCallback((fresh = false) => {
		setError('');
		setChecking(fresh);
		return apiFetch(
			fresh
				? { path: `${DUPLICATE_TAGS_PATH}/scan`, method: 'POST' }
				: { path: DUPLICATE_TAGS_PATH }
		)
			.then((res) => {
				setData(res?.data || null);
				setSettings(res?.data?.settings || null);
			})
			.catch((e) =>
				setError(
					e?.message ||
						__('Duplicate tags could not be checked.', 'thinkrank')
				)
			)
			.finally(() => setChecking(false));
	}, []);

	useEffect(() => {
		load();
	}, [load]);

	if (error && !data) {
		return <SettingsLoadError message={error} onRetry={() => load()} />;
	}

	if (!data || !settings) {
		return <SkeletonCard lines={5} />;
	}

	const { pages } = data.report;
	const found = {};
	pages.forEach((page) =>
		page.duplicates.forEach((row) => {
			found[row.family] = (found[row.family] || 0) + 1;
		})
	);

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			<Overview
				data={{ ...data, settings }}
				error={error}
				checking={checking}
				onCheck={() => load(true)}
			/>
			<FamilySwitches
				settings={settings}
				found={found}
				isSaving={isSaving}
				update={update}
			/>
			<Card padding={false}>
				<SectionHeader
					title={__('Pages checked', 'thinkrank')}
					description={__(
						'The home page and the latest post, fetched the way a visitor sees them.',
						'thinkrank'
					)}
					withBorder={false}
				/>
				<ul className="tr-m-0 tr-p-0 tr-list-none">
					{pages.map((page) => (
						<PageReport key={page.url} page={page} />
					))}
				</ul>
			</Card>
		</div>
	);
};

export default DuplicateTagsTab;
