/**
 * Title Experiments: where an experiment stands, in words (#719).
 *
 * Shared by the editor card and the Analytics screen. The server owns every
 * judgement; this renders the dates, the two titles, and the verdict with the
 * numbers behind it. The copy never says "rank higher" — a title test is about
 * clicks at the same position.
 *
 * @package
 * @since 2.22.0
 */

import { __, _n, sprintf } from '@wordpress/i18n';

import { Alert, Badge } from '../ui';

/**
 * A UTC `Y-m-d` date as a local date.
 *
 * @param {string} ymd Date.
 * @return {string} Formatted date.
 */
export const formatDay = (ymd) => {
	const date = new Date(`${ymd}T00:00:00Z`);
	return Number.isNaN(date.getTime())
		? ymd
		: date.toLocaleDateString(undefined, { timeZone: 'UTC' });
};

const pct = (value) => `${(Math.round(value * 1000) / 10).toLocaleString()}%`;

const REASONS = {
	too_few_impressions: __(
		'Fewer than 200 impressions in one of the windows: too few searches to tell a real change from chance.',
		'thinkrank'
	),
	position_moved: __(
		'The page’s Google position moved by more than two places between the windows, so a change in clicks could be the ranking, not the title.',
		'thinkrank'
	),
	no_baseline: __(
		'The original title had no clicks to compare against.',
		'thinkrank'
	),
	measurement_failed: __(
		'Search Console could not be reached for a week, so the experiment was closed without a result.',
		'thinkrank'
	),
};

/**
 * The verdict line.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.result Result.
 */
const Verdict = ({ result }) => {
	const lift = null === result.lift ? null : Math.round(result.lift * 100);

	switch (result.outcome) {
		case 'variant_wins':
			return (
				<Alert
					variant="success"
					title={__('The new title won', 'thinkrank')}
				>
					{sprintf(
						/* translators: %s: percent more clicks, e.g. 31%. */
						__(
							'It earned %s more clicks at the same position, after adjusting for the rest of your site.',
							'thinkrank'
						),
						`${lift}%`
					)}
				</Alert>
			);
		case 'original_wins':
			return (
				<Alert
					variant="warning"
					title={__('The original title did better', 'thinkrank')}
				>
					{sprintf(
						/* translators: %s: percent fewer clicks, e.g. 12%. */
						__(
							'The new title earned %s fewer clicks at the same position. The original stays.',
							'thinkrank'
						),
						`${Math.abs(lift)}%`
					)}
				</Alert>
			);
		case 'tie':
			return (
				<Alert
					variant="info"
					title={__('No clear difference', 'thinkrank')}
				>
					{__(
						'Both titles earned about the same share of clicks. The original stays; try a bolder change next time.',
						'thinkrank'
					)}
				</Alert>
			);
		default:
			return (
				<Alert
					variant="info"
					title={__('Not enough to call it', 'thinkrank')}
				>
					{REASONS[result.reason] ||
						__(
							'The numbers could not support a result.',
							'thinkrank'
						)}
				</Alert>
			);
	}
};

/**
 * How each status reads, as a badge.
 *
 * @type {Object<string,{variant: string, label: string}>}
 */
export const STATUS = {
	running: { variant: 'info', label: __('Testing', 'thinkrank') },
	measuring: {
		variant: 'info',
		label: __('Collecting data', 'thinkrank'),
	},
	complete: { variant: 'good', label: __('Finished', 'thinkrank') },
	stopped: { variant: 'neutral', label: __('Stopped', 'thinkrank') },
};

/**
 * Why a stopped test ended. A test the owner stopped carries no reason; the
 * plugin ends one itself when the page is unpublished, changes address, or
 * can no longer serve the title (#1682).
 *
 * @param {string} reason `stopped_reason`, or '' when the owner stopped it.
 * @return {string} Sentence.
 */
export const stoppedText = (reason) => {
	switch (reason) {
		case 'unpublished':
			return __(
				'Ended because the page was unpublished, so Google could no longer show it. The original title is back.',
				'thinkrank'
			);
		case 'url_changed':
			return __(
				"Ended because the page's address changed, and a test is measured on the address it started on. The original title is back.",
				'thinkrank'
			);
		case 'unservable':
			return __(
				'Ended because the page can no longer show the test title: it was set to noindex, its SEO titles were switched off, or it became the posts or Shop page. The original title is back.',
				'thinkrank'
			);
		default:
			return __(
				'Stopped before a result. The original title is back.',
				'thinkrank'
			);
	}
};

/**
 * Today as a UTC `Y-m-d`, the way the plan's dates are kept.
 *
 * @return {string} Date.
 */
const todayUtc = () => new Date().toISOString().slice(0, 10);

/**
 * Whole days from one UTC `Y-m-d` to another.
 *
 * @param {string} from First day.
 * @param {string} to   Last day.
 * @return {number} Days, negative when `to` is earlier.
 */
const daysBetween = (from, to) =>
	Math.round(
		(new Date(`${to}T00:00:00Z`) - new Date(`${from}T00:00:00Z`)) / 86400000
	);

/**
 * The stages of an experiment, with where today falls.
 *
 * Exported for its tests.
 *
 * @param {Object} plan  The plan from the endpoint.
 * @param {string} today Today, UTC `Y-m-d`.
 * @return {{steps: Array<{label: string, date: string, done: boolean}>, progress: number, daysLeft: number}} Stages.
 */
export const describeStages = (plan, today = todayUtc()) => {
	const first = plan.variants[0];
	const last = plan.variants[plan.variants.length - 1];
	const steps = [
		{ label: __('Started', 'thinkrank'), date: plan.start },
		{ label: __('Google recrawls', 'thinkrank'), date: first.serve_from },
		{ label: __('Measuring', 'thinkrank'), date: first.from },
		{ label: __('Last day measured', 'thinkrank'), date: last.to },
		{ label: __('Result', 'thinkrank'), date: plan.decide_on },
	].map((step) => ({ ...step, done: today >= step.date }));

	const total = Math.max(1, daysBetween(plan.start, plan.decide_on));
	const elapsed = Math.min(
		total,
		Math.max(0, daysBetween(plan.start, today))
	);

	return {
		steps,
		progress: Math.round((elapsed / total) * 100),
		daysLeft: Math.max(0, daysBetween(today, plan.decide_on)),
	};
};

/**
 * A bar from start to result, with the dates under it.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.plan The plan.
 * @return {JSX.Element} The progress.
 */
const StageProgress = ({ plan }) => {
	const { steps, progress, daysLeft } = describeStages(plan);
	// The grace days are served but not measured; skip the label so the
	// row reads Started, Measuring, Result for a plain one-title test.
	const shown = steps.filter((step, i) => i !== 1 && i !== 3);

	return (
		<div className="tr-rounded-lg tr-border tr-border-secondary tr-bg-white tr-p-4">
			<div className="tr-flex tr-flex-wrap tr-items-baseline tr-justify-between tr-gap-2 tr-mb-3">
				<span className="tr-text-sm tr-font-medium tr-text-primary">
					{daysLeft > 0
						? sprintf(
								/* translators: %d: days until the result. */
								_n(
									'Result in %d day',
									'Result in %d days',
									daysLeft,
									'thinkrank'
								),
								daysLeft
							)
						: __('Result due today', 'thinkrank')}
				</span>
				<span className="tr-text-xs tr-text-tertiary tr-tabular-nums">
					{sprintf(
						/* translators: %s: percent of the experiment done, e.g. 40%. */
						__('%s done', 'thinkrank'),
						`${progress}%`
					)}
				</span>
			</div>
			<div
				className="tr-h-2 tr-rounded-full tr-bg-line-2 tr-overflow-hidden"
				role="progressbar"
				aria-valuemin={0}
				aria-valuemax={100}
				aria-valuenow={progress}
				aria-label={__('Experiment progress', 'thinkrank')}
			>
				<div
					className="tr-h-full tr-rounded-full tr-bg-brand tr-transition-all"
					style={{ width: `${Math.max(progress, 3)}%` }}
				/>
			</div>
			<ol className="tr-grid tr-grid-cols-3 tr-gap-2 tr-list-none tr-m-0 tr-mt-3 tr-p-0">
				{shown.map((step, i) => (
					<li
						key={step.label}
						className={`tr-flex tr-flex-col tr-gap-0.5 ${
							0 === i
								? 'tr-items-start tr-text-left'
								: i === shown.length - 1
									? 'tr-items-end tr-text-right'
									: 'tr-items-center tr-text-center'
						}`}
					>
						<span
							className={`tr-text-xs tr-font-medium ${
								step.done
									? 'tr-text-primary'
									: 'tr-text-tertiary'
							}`}
						>
							{step.label}
						</span>
						<span className="tr-text-xs tr-text-tertiary tr-tabular-nums">
							{formatDay(step.date)}
						</span>
					</li>
				))}
			</ol>
		</div>
	);
};

/**
 * A title as a search result: the title line in link blue, the description
 * under it.
 *
 * @param {Object}  props             Component props.
 * @param {string}  props.label       Which title.
 * @param {string}  props.title       Title.
 * @param {string}  props.description Description, if any.
 * @param {boolean} props.isWinner    Marked as the winner.
 * @param {boolean} props.isNew       The title being tested.
 * @return {JSX.Element} The card.
 */
const TitleCard = ({ label, title, description, isWinner, isNew }) => (
	<div
		className={`tr-relative tr-flex tr-flex-col tr-gap-1.5 tr-rounded-lg tr-border tr-p-4 tr-min-w-0 ${
			isWinner
				? 'tr-border-success tr-bg-success-bg'
				: isNew
					? 'tr-border-brand/40 tr-bg-brand-bg/40'
					: 'tr-border-secondary tr-bg-white'
		}`}
	>
		<div className="tr-flex tr-items-center tr-justify-between tr-gap-2">
			<span className="tr-text-[11px] tr-font-semibold tr-uppercase tr-tracking-wide tr-text-tertiary">
				{label}
			</span>
			{isWinner && (
				<Badge variant="good">{__('Winner', 'thinkrank')}</Badge>
			)}
		</div>
		<span className="tr-text-base tr-leading-snug tr-text-[#1a0dab] tr-break-words">
			{title}
		</span>
		{description ? (
			<span className="tr-text-xs tr-leading-relaxed tr-text-secondary tr-break-words">
				{description}
			</span>
		) : (
			isNew && (
				<span className="tr-text-xs tr-italic tr-text-tertiary">
					{__('Keeps the current description', 'thinkrank')}
				</span>
			)
		)}
	</div>
);

/**
 * Both windows' numbers side by side.
 *
 * @param {Object} props         Component props.
 * @param {Array}  props.columns {label, range, window}.
 * @return {JSX.Element|null} The table.
 */
const NumbersTable = ({ columns }) => {
	const shown = columns.filter((column) => column.window);
	if (shown.length < 2) {
		return null;
	}
	const ctr = (w) => (w.impressions ? w.clicks / w.impressions : 0);
	const rows = [
		[__('Clicks', 'thinkrank'), (w) => w.clicks.toLocaleString()],
		[__('Impressions', 'thinkrank'), (w) => w.impressions.toLocaleString()],
		[__('Click-through rate', 'thinkrank'), (w) => pct(ctr(w))],
		[
			__('Average position', 'thinkrank'),
			(w) => w.position.toLocaleString(),
		],
	];

	return (
		<div className="tr-relative tr-overflow-x-auto tr-rounded-lg tr-border tr-border-secondary">
			<table className="tr-w-full tr-text-xs tr-border-collapse">
				<thead>
					<tr className="tr-bg-line-2/60">
						<th className="tr-text-left tr-font-medium tr-text-tertiary tr-px-3 tr-py-2" />
						{shown.map((column) => (
							<th
								key={column.label}
								className="tr-text-right tr-font-medium tr-text-primary tr-px-3 tr-py-2"
							>
								<div>{column.label}</div>
								<div className="tr-font-normal tr-text-tertiary">
									{column.range}
								</div>
							</th>
						))}
					</tr>
				</thead>
				<tbody>
					{rows.map(([label, value]) => (
						<tr
							key={label}
							className="tr-border-t tr-border-secondary"
						>
							<td className="tr-text-left tr-text-secondary tr-px-3 tr-py-2">
								{label}
							</td>
							{shown.map((column) => (
								<td
									key={column.label}
									className="tr-text-right tr-text-primary tr-tabular-nums tr-px-3 tr-py-2"
								>
									{value(column.window)}
								</td>
							))}
						</tr>
					))}
				</tbody>
			</table>
		</div>
	);
};

/**
 * @param {Object} props            Component props.
 * @param {Object} props.experiment Experiment from the endpoint.
 * @param          props.hideStatus
 */
const ExperimentSummary = ({ experiment, hideStatus = false }) => {
	const { status, plan, original, variants = [], result } = experiment;
	const several = variants.length > 1;
	const winner = result && null !== result.winner ? result.winner : null;
	const originalWon = result && 'original_wins' === result.outcome;

	const statusBadge = STATUS[status];

	const variantLabel = (i) =>
		several
			? sprintf(
					/* translators: %d: 1-based number of the new title. */
					__('New title %d', 'thinkrank'),
					i + 1
				)
			: __('New title', 'thinkrank');

	const range = (from, to) =>
		sprintf(
			/* translators: 1: first day, 2: last day. */
			__('%1$s to %2$s', 'thinkrank'),
			formatDay(from),
			formatDay(to)
		);

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{statusBadge &&
				(!hideStatus ||
					'measuring' === status ||
					'stopped' === status) && (
					<div className="tr-flex tr-flex-wrap tr-items-center tr-gap-2 tr-text-xs tr-text-secondary">
						{!hideStatus && (
							<Badge variant={statusBadge.variant}>
								{statusBadge.label}
							</Badge>
						)}
						{'measuring' === status &&
							sprintf(
								/* translators: %s: result date. */
								__(
									'The original is back. Search Console needs a few days for the last dates. Result on %s.',
									'thinkrank'
								),
								formatDay(plan.decide_on)
							)}
						{'stopped' === status &&
							stoppedText(experiment.stopped_reason || '')}
					</div>
				)}

			{('running' === status || 'measuring' === status) && (
				<StageProgress plan={plan} />
			)}

			<div className="tr-grid tr-gap-3 md:tr-grid-cols-2">
				<TitleCard
					label={__('Original', 'thinkrank')}
					title={original.title}
					description={original.description}
					isWinner={originalWon}
				/>
				{variants.map((variant, i) => (
					<TitleCard
						key={i}
						label={
							several
								? `${variantLabel(i)} · ${range(
										plan.variants[i].serve_from,
										plan.variants[i].to
									)}`
								: variantLabel(i)
						}
						title={variant.title}
						description={variant.description}
						isWinner={winner === i}
						isNew
					/>
				))}
			</div>

			{'complete' === status && result && (
				<>
					<Verdict result={result} />
					{result.original_window && (
						<NumbersTable
							columns={[
								{
									label: __('Original', 'thinkrank'),
									range: range(
										plan.original.from,
										plan.original.to
									),
									window: result.original_window,
								},
								...(result.variants || []).map(
									(measured, i) => ({
										label: variantLabel(i),
										range: range(
											plan.variants[i].from,
											plan.variants[i].to
										),
										window: measured.window,
									})
								),
							]}
						/>
					)}
				</>
			)}
			{experiment.error && 'measuring' === status && (
				<p className="tr-text-xs tr-text-secondary tr-m-0">
					{sprintf(
						/* translators: %s: error message. */
						__(
							'Last attempt to read Search Console failed: %s. Retrying daily.',
							'thinkrank'
						),
						experiment.error
					)}
				</p>
			)}
		</div>
	);
};

export default ExperimentSummary;
