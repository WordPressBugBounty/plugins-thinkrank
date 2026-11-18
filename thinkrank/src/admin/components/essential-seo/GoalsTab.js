/**
 * Essential SEO → SEO Analyzer → Goals (#728).
 *
 * Everything ThinkRank knows the site should do next, most important first:
 * setup still missing, failing audit checks, pages missing descriptions or
 * focus keywords, searches that rank but rarely get the click. Each goal says
 * why, how, and links to the screen that fixes it.
 *
 * @package
 * @since 2.22.0
 */

import { useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, check, chevronDown, chevronUp } from '@wordpress/icons';

import { Card, Button, SkeletonCard, EmptyState, Alert } from '../ui';
import SectionHeader from '../common/SectionHeader';
import GoalCard, { SEVERITY } from '../goals/GoalCard';
import useGoals from '../goals/useGoals';
import { checklistProgress, filterGoals } from '../goals/goalStats';
import ProGatedTab from '../pro/ProGatedTab';
import ProTeaser from '../pro/ProTeaser';
import { AuditHistoryIllustration } from '../pro/pro-teaser-illustrations';

// Goals shown before "Show all": enough to act on, few enough to finish.
const FEED_CAP = 12;

const LEVELS = ['critical', 'high', 'medium', 'low'];

const Stat = ({ label, value, hint, children }) => (
	<div className="tr-flex-1 tr-min-w-[150px] tr-rounded-lg tr-border tr-border-secondary tr-bg-white tr-px-4 tr-py-3">
		<div className="tr-text-xs tr-font-medium tr-text-secondary">
			{label}
		</div>
		<div className="tr-mt-1 tr-text-2xl tr-font-semibold tr-text-primary tr-tabular-nums">
			{value}
		</div>
		{hint && (
			<div className="tr-mt-0.5 tr-text-xs tr-text-tertiary">{hint}</div>
		)}
		{children}
	</div>
);

const GoalsTab = ({ onNavigate }) => {
	const { feed, loading, error, busy, mark, fix, reload } = useGoals();
	const [showAll, setShowAll] = useState(false);
	const [showIgnored, setShowIgnored] = useState(false);
	const [filter, setFilter] = useState('');

	const goals = useMemo(() => feed?.goals || [], [feed]);
	const ignored = feed?.ignored || [];
	const progress = useMemo(() => checklistProgress(feed?.checklists), [feed]);
	const counts = useMemo(() => {
		const byLevel = { quick: 0 };
		goals.forEach((goal) => {
			byLevel[goal.severity] = (byLevel[goal.severity] || 0) + 1;
			if ('S' === goal.effort) {
				byLevel.quick += 1;
			}
		});
		return byLevel;
	}, [goals]);

	if (loading && !feed) {
		return <SkeletonCard lines={5} />;
	}

	const matching = filterGoals(goals, filter);
	const visible = showAll ? matching : matching.slice(0, FEED_CAP);
	const urgent = (counts.critical || 0) + (counts.high || 0);
	const percent =
		progress.total > 0
			? Math.round((progress.done / progress.total) * 100)
			: 0;

	const chip = (value, label, count, dot = '') => {
		const active = filter === value;
		return (
			<button
				key={value || 'all'}
				type="button"
				aria-pressed={active}
				onClick={() => {
					setFilter(value);
					setShowAll(false);
				}}
				className={`tr-inline-flex tr-items-center tr-gap-2 tr-h-9 tr-px-3 tr-rounded-md tr-border tr-text-sm tr-cursor-pointer tr-transition-colors ${
					active
						? 'tr-bg-white tr-border-primary tr-text-primary tr-shadow-sm tr-font-medium'
						: 'tr-bg-transparent tr-border-transparent tr-text-secondary hover:tr-text-primary'
				}`}
			>
				{dot && (
					<span
						aria-hidden="true"
						className={`tr-w-2 tr-h-2 tr-rounded-full ${dot}`}
					/>
				)}
				{label}
				<span className="tr-min-w-[20px] tr-px-1.5 tr-rounded-full tr-bg-line-2 tr-text-xs tr-text-secondary tr-tabular-nums">
					{count}
				</span>
			</button>
		);
	};

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			<Card padding={false}>
				<SectionHeader
					title={__('Your SEO goals', 'thinkrank')}
					description={__(
						'What to do next on this site, most important first. ThinkRank builds this list from what it already knows: your settings, the site audit, your pages and Search Console. It takes a goal off as soon as it is done.',
						'thinkrank'
					)}
					trailing={
						<Button
							variant="secondary"
							size="sm"
							loading={loading}
							onClick={() => reload(true)}
						>
							{__('Refresh', 'thinkrank')}
						</Button>
					}
				/>

				<div className="tr-flex tr-flex-wrap tr-gap-3 tr-px-6 tr-pt-5">
					<Stat
						label={__('Open goals', 'thinkrank')}
						value={goals.length}
						hint={
							ignored.length > 0
								? sprintf(
										/* translators: %d: number of ignored goals. */
										__('%d ignored', 'thinkrank'),
										ignored.length
									)
								: __('Nothing ignored', 'thinkrank')
						}
					/>
					<Stat
						label={__('Important', 'thinkrank')}
						value={urgent}
						hint={__('Do these first', 'thinkrank')}
					/>
					<Stat
						label={__('Quick wins', 'thinkrank')}
						value={counts.quick}
						hint={__('A few minutes each', 'thinkrank')}
					/>
					<Stat
						label={__('Checks done', 'thinkrank')}
						value={
							progress.total > 0
								? `${progress.done}/${progress.total}`
								: '0'
						}
					>
						<div
							className="tr-mt-2 tr-h-1.5 tr-rounded-full tr-bg-line-2 tr-overflow-hidden"
							role="progressbar"
							aria-valuemin={0}
							aria-valuemax={100}
							aria-valuenow={percent}
							aria-label={__('Checks done', 'thinkrank')}
						>
							<div
								className="tr-h-full tr-rounded-full tr-bg-success"
								style={{ width: `${percent}%` }}
							/>
						</div>
					</Stat>
				</div>

				{goals.length > 0 && (
					<div
						role="group"
						aria-label={__('Filter goals', 'thinkrank')}
						className="tr-flex tr-flex-wrap tr-items-center tr-gap-1 tr-mx-6 tr-mt-5 tr-px-1 tr-py-1 tr-rounded-lg tr-bg-line-2/50"
					>
						{chip('', __('All', 'thinkrank'), goals.length)}
						{LEVELS.filter((level) => counts[level] > 0).map(
							(level) =>
								chip(
									level,
									SEVERITY[level].label,
									counts[level],
									SEVERITY[level].dot
								)
						)}
						{counts.quick > 0 &&
							chip(
								'quick',
								__('Quick wins', 'thinkrank'),
								counts.quick
							)}
					</div>
				)}

				<div className="tr-px-6 tr-py-5">
					{error && (
						<div className="tr-mb-4">
							<Alert variant="error">{error}</Alert>
						</div>
					)}
					{0 === goals.length ? (
						<EmptyState
							size="sm"
							icon={(props) => <Icon icon={check} {...props} />}
							title={__('All caught up', 'thinkrank')}
							description={__(
								'Nothing left to do right now. New goals appear here as ThinkRank finds them.',
								'thinkrank'
							)}
						/>
					) : (
						<ul className="tr-m-0 tr-p-0 tr-list-none tr-flex tr-flex-col tr-gap-3">
							{visible.map(({ goal, rank }) => (
								<GoalCard
									key={goal.id}
									goal={goal}
									rank={rank}
									featured={1 === rank}
									busy={busy === goal.id}
									onMark={mark}
									onFix={fix}
									onNavigate={onNavigate}
								/>
							))}
						</ul>
					)}
					{matching.length > FEED_CAP && (
						<div className="tr-pt-4 tr-text-center">
							<Button
								variant="secondary"
								size="sm"
								onClick={() => setShowAll(!showAll)}
							>
								{showAll
									? __('Show fewer', 'thinkrank')
									: sprintf(
											/* translators: %d: number of goals. */
											__(
												'Show all %d goals',
												'thinkrank'
											),
											matching.length
										)}
							</Button>
						</div>
					)}
				</div>
			</Card>

			{ignored.length > 0 && (
				<Card padding={false}>
					<button
						type="button"
						aria-expanded={showIgnored}
						onClick={() => setShowIgnored(!showIgnored)}
						className="tr-w-full tr-flex tr-justify-between tr-items-center tr-gap-3 tr-px-6 tr-py-4 tr-bg-transparent tr-border-0 tr-cursor-pointer tr-text-left"
					>
						<span>
							<span className="tr-block tr-text-sm tr-font-semibold tr-text-primary">
								{sprintf(
									/* translators: %d: number of ignored goals. */
									_n(
										'%d ignored goal',
										'%d ignored goals',
										ignored.length,
										'thinkrank'
									),
									ignored.length
								)}
							</span>
							<span className="tr-block tr-text-xs tr-text-tertiary tr-mt-0.5">
								{__(
									'Hidden from the list until you restore them.',
									'thinkrank'
								)}
							</span>
						</span>
						<span className="tr-inline-flex tr-items-center tr-text-sm tr-font-medium tr-text-brand">
							{showIgnored
								? __('Hide', 'thinkrank')
								: __('Show', 'thinkrank')}
							<Icon
								icon={showIgnored ? chevronUp : chevronDown}
								size={18}
								className="tr-fill-current"
							/>
						</span>
					</button>
					{showIgnored && (
						<ul className="tr-m-0 tr-px-6 tr-pb-5 tr-list-none tr-flex tr-flex-col tr-gap-3">
							{ignored.map((goal) => (
								<GoalCard
									key={goal.id}
									goal={goal}
									busy={busy === goal.id}
									onMark={mark}
									onFix={fix}
									onNavigate={onNavigate}
									ignored
								/>
							))}
						</ul>
					)}
				</Card>
			)}

			<ProGatedTab
				filter="thinkrank.seoAnalyzer.goalsHistory"
				teaser={
					<ProTeaser
						illustration={<AuditHistoryIllustration />}
						title={__('Goal history', 'thinkrank')}
						description={__(
							'A goal leaves this list once it is done, and so does the record that you did it. Pro keeps both: when each goal appeared and when it was completed. It also adds goals from Pro-only signals.',
							'thinkrank'
						)}
						features={[
							__(
								'Goals completed this month, and a timeline of every one',
								'thinkrank'
							),
							__(
								'Goals from Index Status, Rank Tracker, Traffic Recovery and Focus Pages',
								'thinkrank'
							),
							__(
								'Goals completed, in your email report',
								'thinkrank'
							),
						]}
					/>
				}
			/>
		</div>
	);
};

export default GoalsTab;
