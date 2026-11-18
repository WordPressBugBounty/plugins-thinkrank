/**
 * SEO Goals: one goal — what, why, how, where, and done/ignore (#728).
 *
 * @package
 * @since 2.22.0
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Icon,
	arrowRight,
	check,
	chevronDown,
	chevronUp,
	undo,
} from '@wordpress/icons';

import { Badge, Button } from '../ui';
import { goalHref, followGoal } from './goalLink';

export const SEVERITY = {
	critical: {
		variant: 'poor',
		label: __('Do this first', 'thinkrank'),
		tile: 'tr-bg-danger-bg tr-text-danger',
		dot: 'tr-bg-danger',
	},
	high: {
		variant: 'warning',
		label: __('Important', 'thinkrank'),
		tile: 'tr-bg-warning-bg tr-text-warning',
		dot: 'tr-bg-warning',
	},
	medium: {
		variant: 'info',
		label: __('Worth doing', 'thinkrank'),
		tile: 'tr-bg-brand-bg tr-text-brand',
		dot: 'tr-bg-brand',
	},
	low: {
		variant: 'neutral',
		label: __('Nice to have', 'thinkrank'),
		tile: 'tr-bg-line-2 tr-text-secondary',
		dot: 'tr-bg-ink-3',
	},
};

export const EFFORT = {
	S: __('Quick', 'thinkrank'),
	M: __('A little while', 'thinkrank'),
	L: __('Bigger job', 'thinkrank'),
};

// Where a goal came from, in the words the rest of the plugin uses.
export const SOURCE = {
	setup: __('Setup', 'thinkrank'),
	analyzer: __('Site audit', 'thinkrank'),
	content: __('Your pages', 'thinkrank'),
	opportunities: __('Search Console', 'thinkrank'),
};

const ClockIcon = () => (
	<svg
		aria-hidden="true"
		viewBox="0 0 16 16"
		width="12"
		height="12"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
		strokeLinecap="round"
	>
		<circle cx="8" cy="8" r="6.25" />
		<path d="M8 4.75V8l2.25 1.5" />
	</svg>
);

/**
 * @param {Object}        props            Component props.
 * @param {Object}        props.goal       Goal from the feed.
 * @param {number}        props.rank       Position in the list, 1-based (0 hides it).
 * @param {boolean}       props.featured   The goal to start with.
 * @param {boolean}       props.busy       An action on this goal is running.
 * @param {Function}      props.onMark     (id, status) => void.
 * @param {Function}      props.onFix      (goal) => void, for an analyzer one-click fix.
 * @param {Function|null} props.onNavigate Essential SEO section switcher, when on that page.
 * @param {boolean}       props.ignored    Rendered in the ignored list.
 */
const GoalCard = ({
	goal,
	rank = 0,
	featured = false,
	busy = false,
	onMark,
	onFix,
	onNavigate = null,
	ignored = false,
}) => {
	const [open, setOpen] = useState(false);
	const severity = SEVERITY[goal.severity] || SEVERITY.medium;
	const href = goalHref(goal.link);
	const source = SOURCE[goal.source];
	const hasFix = !ignored && goal.action?.type === 'analyzer_fix';

	return (
		<li
			className={`tr-m-0 tr-p-4 tr-rounded-lg tr-border tr-border-secondary tr-bg-white tr-transition-colors hover:tr-border-primary ${
				ignored ? 'tr-opacity-80' : ''
			}`}
		>
			<div className="tr-flex tr-items-start tr-gap-4">
				<span
					aria-hidden="true"
					className={`tr-flex tr-shrink-0 tr-items-center tr-justify-center tr-w-9 tr-h-9 tr-rounded-lg tr-text-sm tr-font-semibold tr-tabular-nums ${severity.tile}`}
				>
					{rank > 0 ? (
						rank
					) : (
						<span
							className={`tr-w-2 tr-h-2 tr-rounded-full ${severity.dot}`}
						/>
					)}
				</span>

				<div className="tr-min-w-0 tr-flex-1">
					<div className="tr-flex tr-flex-wrap tr-items-center tr-gap-x-3 tr-gap-y-1">
						{featured && (
							<span className="tr-text-xs tr-font-semibold tr-uppercase tr-tracking-wide tr-text-brand">
								{__('Start here', 'thinkrank')}
							</span>
						)}
						<Badge variant={severity.variant}>
							{severity.label}
						</Badge>
						{EFFORT[goal.effort] && (
							<span className="tr-inline-flex tr-items-center tr-gap-1 tr-text-xs tr-text-tertiary">
								<ClockIcon />
								{EFFORT[goal.effort]}
							</span>
						)}
						{source && (
							<span className="tr-text-xs tr-text-tertiary">
								{source}
							</span>
						)}
					</div>

					<a
						href={href}
						onClick={(e) => followGoal(e, goal.link, onNavigate)}
						className="tr-block tr-mt-2 tr-text-base tr-font-semibold tr-text-primary tr-no-underline hover:tr-text-brand"
					>
						{goal.title}
					</a>
					{goal.why && (
						<p className="tr-text-sm tr-text-secondary tr-mt-1 tr-mb-0 tr-leading-relaxed">
							{goal.why}
						</p>
					)}

					{open && goal.how?.length > 0 && (
						<ol className="tr-mt-3 tr-mb-0 tr-p-0 tr-list-none tr-rounded-md tr-bg-line-2/60 tr-px-4 tr-py-3">
							{goal.how.map((step, i) => (
								<li
									key={i}
									className="tr-flex tr-gap-3 tr-text-sm tr-text-secondary tr-mb-2 last:tr-mb-0"
								>
									<span className="tr-flex tr-shrink-0 tr-items-center tr-justify-center tr-w-5 tr-h-5 tr-rounded-full tr-bg-white tr-border tr-border-secondary tr-text-xs tr-font-medium tr-text-primary tr-tabular-nums">
										{i + 1}
									</span>
									<span>{step}</span>
								</li>
							))}
						</ol>
					)}

					<div className="tr-flex tr-flex-wrap tr-items-center tr-justify-between tr-gap-3 tr-mt-3">
						<div className="tr-flex tr-flex-wrap tr-items-center tr-gap-2">
							{hasFix && (
								<Button
									size="sm"
									loading={busy}
									onClick={() => onFix(goal)}
									title={goal.action.warning || undefined}
								>
									{goal.action.label ||
										__('Fix it for me', 'thinkrank')}
								</Button>
							)}
							{!ignored && (
								<a
									href={href}
									onClick={(e) =>
										followGoal(e, goal.link, onNavigate)
									}
									className={`tr-inline-flex tr-items-center tr-gap-1 tr-h-8 tr-px-3 tr-rounded-md tr-text-sm tr-font-medium tr-no-underline ${
										hasFix || !featured
											? 'tr-border tr-border-secondary tr-bg-white tr-text-primary hover:tr-border-primary'
											: 'tr-bg-brand tr-text-white hover:tr-text-white hover:tr-opacity-90'
									}`}
								>
									{__('Take me there', 'thinkrank')}
									<Icon
										icon={arrowRight}
										size={16}
										className="tr-fill-current"
									/>
								</a>
							)}
							{goal.how?.length > 0 && (
								<button
									type="button"
									aria-expanded={open}
									onClick={() => setOpen(!open)}
									className="tr-inline-flex tr-items-center tr-gap-0.5 tr-h-8 tr-px-2 tr-bg-transparent tr-border-0 tr-rounded-md tr-text-sm tr-font-medium tr-text-brand tr-cursor-pointer hover:tr-bg-brand-bg"
								>
									{open
										? __('Hide steps', 'thinkrank')
										: __('Show me how', 'thinkrank')}
									<Icon
										icon={open ? chevronUp : chevronDown}
										size={18}
										className="tr-fill-current"
									/>
								</button>
							)}
						</div>

						<div className="tr-flex tr-items-center tr-gap-1">
							{ignored ? (
								<Button
									variant="secondary"
									size="sm"
									disabled={busy}
									onClick={() => onMark(goal.id, 'open')}
								>
									<Icon
										icon={undo}
										size={16}
										className="tr-fill-current"
									/>
									{__('Restore', 'thinkrank')}
								</Button>
							) : (
								<>
									<Button
										variant="ghost"
										size="sm"
										disabled={busy}
										onClick={() =>
											onMark(goal.id, 'ignored')
										}
									>
										{__('Ignore', 'thinkrank')}
									</Button>
									<Button
										variant="secondary"
										size="sm"
										disabled={busy}
										onClick={() => onMark(goal.id, 'done')}
									>
										<Icon
											icon={check}
											size={16}
											className="tr-fill-current"
										/>
										{__('Mark done', 'thinkrank')}
									</Button>
								</>
							)}
						</div>
					</div>
				</div>
			</div>
		</li>
	);
};

export default GoalCard;
