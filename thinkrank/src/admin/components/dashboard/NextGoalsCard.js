/**
 * Dashboard → "Your next goals": the top three SEO goals (#728).
 *
 * @package
 * @since 2.22.0
 */

import { useCallback, useEffect, useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, check, chevronRight, arrowRight } from '@wordpress/icons';

import { Card, SkeletonCard } from '../ui';
import SectionHeader from '../common/SectionHeader';
import { SEVERITY, EFFORT, SOURCE } from '../goals/GoalCard';
import { goalHref, followGoal } from '../goals/goalLink';
import { checklistProgress } from '../goals/goalStats';
import useGoals, { AUDIT_EVENT, RUN_AUDIT_GOAL } from '../goals/useGoals';
import { getPageUrl, navigate, isPlainLeftClick } from '../../utils/router';

const GOALS_PARAMS = { nav_section: 'seo-analyzer', nav_item: 'goals' };

const GoalRow = ({ goal, rank }) => {
	const severity = SEVERITY[goal.severity] || SEVERITY.medium;
	const meta = [severity.label, EFFORT[goal.effort], SOURCE[goal.source]]
		.filter(Boolean)
		.join(' · ');

	return (
		<li className="tr-m-0">
			<a
				href={goalHref(goal.link)}
				onClick={(event) => followGoal(event, goal.link)}
				className="tr-group tr-flex tr-items-center tr-gap-4 tr-px-6 tr-py-3.5 tr-no-underline tr-transition-colors hover:tr-bg-line-2/40"
			>
				<span
					aria-hidden="true"
					className={`tr-flex tr-shrink-0 tr-items-center tr-justify-center tr-w-9 tr-h-9 tr-rounded-lg tr-text-sm tr-font-semibold tr-tabular-nums ${severity.tile}`}
				>
					{rank}
				</span>
				<span className="tr-min-w-0 tr-flex-1">
					<span className="tr-block tr-text-sm tr-font-semibold tr-text-primary group-hover:tr-text-brand">
						{goal.title}
					</span>
					<span className="tr-block tr-mt-0.5 tr-text-xs tr-text-tertiary">
						{meta}
					</span>
				</span>
				<Icon
					icon={chevronRight}
					size={20}
					className="tr-shrink-0 tr-fill-current tr-text-tertiary group-hover:tr-text-brand"
				/>
			</a>
		</li>
	);
};

const NextGoalsCard = () => {
	const { feed, loading, error, reload } = useGoals();

	// The Site SEO Audit card sits under this one and can change what the
	// list is built from after it loaded. With no audit cached the list says
	// "Run the site SEO audit" while that card runs one, and it kept saying
	// so beside the finished audit until a reload.
	const audits = useRef(0);
	const answered = useRef(0);
	const feedRef = useRef(feed);
	feedRef.current = feed;

	const follow = useCallback(() => {
		const current = feedRef.current;
		if (!current || audits.current === answered.current) {
			return;
		}
		// The audit card's first load only matters when the list was built
		// without it. Any later change (Re-analyze, a fix) can move a goal.
		const stale =
			audits.current > 1 ||
			(current.goals || []).some((goal) => goal.id === RUN_AUDIT_GOAL);
		answered.current = audits.current;
		if (stale) {
			reload();
		}
	}, [reload]);

	useEffect(() => {
		const onAudit = () => {
			audits.current += 1;
			follow();
		};
		window.addEventListener(AUDIT_EVENT, onAudit);
		return () => window.removeEventListener(AUDIT_EVENT, onAudit);
	}, [follow]);

	// An audit that landed before the list did is answered once it arrives.
	useEffect(() => {
		follow();
	}, [feed, follow]);

	if (loading && !feed) {
		return <SkeletonCard lines={3} />;
	}

	// A dashboard card with an error message in it is noise; the Goals
	// screen says what went wrong.
	if (error || !feed) {
		return null;
	}

	const goals = feed.goals || [];
	const top = goals.slice(0, 3);
	const href = getPageUrl('essential-seo', GOALS_PARAMS);
	const progress = checklistProgress(feed.checklists);
	const percent =
		progress.total > 0
			? Math.round((progress.done / progress.total) * 100)
			: 0;
	const important = goals.filter((goal) =>
		['critical', 'high'].includes(goal.severity)
	).length;
	const quick = goals.filter((goal) => 'S' === goal.effort).length;

	const openGoals = (event) => {
		if (isPlainLeftClick(event)) {
			event.preventDefault();
			navigate('essential-seo', GOALS_PARAMS);
		}
	};

	return (
		<Card padding={false}>
			<SectionHeader
				title={__('Your next goals', 'thinkrank')}
				description={
					0 === goals.length
						? __('Nothing left to do right now.', 'thinkrank')
						: __(
								'The most important things to do on this site right now.',
								'thinkrank'
							)
				}
				trailing={
					progress.total > 0 ? (
						<div className="tr-w-[160px]">
							<div className="tr-flex tr-items-baseline tr-justify-between tr-text-xs">
								<span className="tr-text-secondary">
									{__('Checks done', 'thinkrank')}
								</span>
								<span className="tr-font-semibold tr-text-primary tr-tabular-nums">
									{`${progress.done}/${progress.total}`}
								</span>
							</div>
							<div
								className="tr-mt-1.5 tr-h-1.5 tr-rounded-full tr-bg-line-2 tr-overflow-hidden"
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
						</div>
					) : null
				}
			/>

			{0 === top.length ? (
				<div className="tr-flex tr-items-center tr-gap-3 tr-px-6 tr-py-5">
					<span className="tr-flex tr-items-center tr-justify-center tr-w-9 tr-h-9 tr-rounded-full tr-bg-success-bg tr-text-success">
						<Icon
							icon={check}
							size={20}
							className="tr-fill-current"
						/>
					</span>
					<span className="tr-text-sm tr-text-secondary">
						{__(
							'All caught up. New goals appear here as ThinkRank finds them.',
							'thinkrank'
						)}
					</span>
				</div>
			) : (
				<ul className="tr-m-0 tr-py-2 tr-list-none tr-divide-y tr-divide-secondary">
					{top.map((goal, index) => (
						<GoalRow key={goal.id} goal={goal} rank={index + 1} />
					))}
				</ul>
			)}

			<div className="tr-flex tr-flex-wrap tr-items-center tr-justify-between tr-gap-3 tr-px-6 tr-py-4 tr-border-t tr-border-secondary tr-bg-line-2/30">
				<span className="tr-text-xs tr-text-secondary">
					{goals.length > 0
						? [
								sprintf(
									/* translators: %d: number of open goals. */
									_n(
										'%d open goal',
										'%d open goals',
										goals.length,
										'thinkrank'
									),
									goals.length
								),
								important > 0 &&
									sprintf(
										/* translators: %d: number of important goals. */
										__('%d important', 'thinkrank'),
										important
									),
								quick > 0 &&
									sprintf(
										/* translators: %d: number of quick goals. */
										_n(
											'%d quick win',
											'%d quick wins',
											quick,
											'thinkrank'
										),
										quick
									),
							]
								.filter(Boolean)
								.join(' · ')
						: __('No open goals', 'thinkrank')}
				</span>
				<a
					href={href}
					onClick={openGoals}
					className="tr-inline-flex tr-items-center tr-gap-1 tr-text-sm tr-font-semibold tr-text-brand tr-no-underline hover:tr-text-brand"
				>
					{goals.length > top.length
						? sprintf(
								/* translators: %d: number of goals. */
								__('See all %d goals', 'thinkrank'),
								goals.length
							)
						: __('Open Goals', 'thinkrank')}
					<Icon
						icon={arrowRight}
						size={16}
						className="tr-fill-current"
					/>
				</a>
			</div>
		</Card>
	);
};

export default NextGoalsCard;
