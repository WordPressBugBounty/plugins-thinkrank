/**
 * Essential SEO → Analytics → Title Experiments (#719).
 *
 * The running experiment and recent results. An experiment starts from the
 * page's editor, where the title lives; this screen is where to see how they
 * are going. Pro adds concurrent experiments, auto-apply, history and
 * suggestions of pages worth testing through `thinkrank.analytics.titleExperimentsTab`.
 *
 * @package
 * @since 2.22.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Icon, backup, check, closeSmall, info } from '@wordpress/icons';

import { Badge, Card, Alert, EmptyState, SkeletonCard } from '../../ui';
import SectionHeader from '../../common/SectionHeader';
import ProGatedTab from '../../pro/ProGatedTab';
import ProTeaser from '../../pro/ProTeaser';
import { AuditHistoryIllustration } from '../../pro/pro-teaser-illustrations';
import ExperimentSummary, {
	STATUS,
	formatDay,
} from '../../title-experiments/ExperimentSummary';
import { EXPERIMENTS_PATH } from '../../title-experiments/useTitleExperiment';

/**
 * The look of an experiment's card: its icon, the tile colour,
 * and the short result shown in the header.
 *
 * Exported for its tests.
 *
 * @param {Object} experiment Experiment from the endpoint.
 * @return {{icon: Object, tile: string, outcome: ?{label: string, variant: string}}} Look.
 */
export const describeCard = (experiment) => {
	const { status, result } = experiment;

	if ('complete' === status && result) {
		const lift =
			null === result.lift || undefined === result.lift
				? null
				: Math.round(result.lift * 100);

		if ('variant_wins' === result.outcome) {
			return {
				icon: check,
				tile: 'tr-bg-success-bg tr-text-success',
				outcome: {
					variant: 'good',
					label: sprintf(
						/* translators: %s: percent more clicks, e.g. 31%. */
						__('+%s clicks', 'thinkrank'),
						`${lift}%`
					),
				},
			};
		}
		if ('original_wins' === result.outcome) {
			return {
				icon: info,
				tile: 'tr-bg-warning-bg tr-text-warning',
				outcome: {
					variant: 'warning',
					label: __('Original kept', 'thinkrank'),
				},
			};
		}
		return {
			icon: info,
			tile: 'tr-bg-line-2 tr-text-secondary',
			outcome: {
				variant: 'neutral',
				label:
					'tie' === result.outcome
						? __('No clear difference', 'thinkrank')
						: __('Not enough data', 'thinkrank'),
			},
		};
	}

	if ('stopped' === status) {
		return {
			icon: closeSmall,
			tile: 'tr-bg-line-2 tr-text-secondary',
			outcome: null,
		};
	}

	return {
		icon: backup,
		tile: 'tr-bg-brand-bg tr-text-brand',
		outcome: null,
	};
};

const ExperimentRow = ({ experiment }) => {
	const look = describeCard(experiment);
	const status = STATUS[experiment.status];
	const plan = experiment.plan || {};

	return (
		<li className="tr-m-0 tr-overflow-hidden tr-rounded-xl tr-border tr-border-secondary tr-bg-white tr-shadow-sm hover:tr-shadow-md tr-transition-shadow">
			<div className="tr-flex tr-flex-wrap tr-items-start tr-gap-4 tr-px-5 tr-py-4 tr-border-b tr-border-secondary tr-bg-line-2/30">
				<span
					aria-hidden="true"
					className={`tr-shrink-0 tr-flex tr-items-center tr-justify-center tr-w-10 tr-h-10 tr-rounded-lg ${look.tile}`}
				>
					<Icon
						icon={look.icon}
						size={22}
						className="tr-fill-current"
					/>
				</span>

				<div className="tr-flex-1 tr-min-w-0">
					<div className="tr-flex tr-flex-wrap tr-items-center tr-gap-2">
						<a
							href={experiment.url}
							target="_blank"
							rel="noreferrer"
							className="tr-text-base tr-font-semibold tr-text-primary tr-no-underline hover:tr-text-brand tr-break-words"
						>
							{experiment.post_title || experiment.url}
						</a>
						{status && (
							<Badge variant={status.variant}>
								{status.label}
							</Badge>
						)}
						{look.outcome && (
							<Badge
								variant={look.outcome.variant}
								className="tr-font-semibold"
							>
								{look.outcome.label}
							</Badge>
						)}
					</div>
					<div className="tr-flex tr-flex-wrap tr-items-center tr-gap-x-3 tr-gap-y-1 tr-mt-1 tr-text-xs tr-text-tertiary">
						<span className="tr-truncate tr-max-w-full">
							{experiment.url}
						</span>
						{plan.start && (
							<span className="tr-whitespace-nowrap">
								{sprintf(
									/* translators: %s: date the experiment started. */
									__('Started %s', 'thinkrank'),
									formatDay(plan.start)
								)}
							</span>
						)}
						{plan.window && (
							<span className="tr-whitespace-nowrap">
								{sprintf(
									/* translators: %d: days measured per title. */
									_n(
										'%d-day window',
										'%d-day window',
										plan.window,
										'thinkrank'
									),
									plan.window
								)}
							</span>
						)}
					</div>
				</div>

				{experiment.edit_url && (
					<a
						href={experiment.edit_url}
						className="tr-shrink-0 tr-inline-flex tr-items-center tr-h-9 tr-px-3.5 tr-rounded-md tr-border tr-border-primary tr-bg-white tr-text-sm tr-font-medium tr-text-primary tr-no-underline hover:tr-border-brand hover:tr-text-brand"
					>
						{__('Edit page', 'thinkrank')}
					</a>
				)}
			</div>

			<div className="tr-px-5 tr-py-5">
				<ExperimentSummary experiment={experiment} hideStatus />
			</div>
		</li>
	);
};

/**
 * The three steps of an experiment, so a first-time reader knows what
 * happens before starting one.
 *
 * @return {JSX.Element} The steps.
 */
const HowItWorks = () => {
	const steps = [
		[
			__('Pick a page', 'thinkrank'),
			__(
				'In the editor: ThinkRank, General, Title experiment. Best for a page with plenty of impressions but few clicks for its position.',
				'thinkrank'
			),
		],
		[
			__('ThinkRank serves the new title', 'thinkrank'),
			__(
				'Only the search title and description change. Your stored fields, social tags and schema stay as they are.',
				'thinkrank'
			),
		],
		[
			__('Google’s clicks decide', 'thinkrank'),
			__(
				'Click-through rate is compared at the same position, adjusted for your whole site’s ups and downs.',
				'thinkrank'
			),
		],
	];

	return (
		<ol className="tr-grid tr-gap-3 md:tr-grid-cols-3 tr-list-none tr-m-0 tr-p-0">
			{steps.map(([title, text], i) => (
				<li
					key={title}
					className="tr-flex tr-gap-3 tr-rounded-lg tr-bg-line-2/50 tr-p-4"
				>
					<span className="tr-shrink-0 tr-flex tr-items-center tr-justify-center tr-w-6 tr-h-6 tr-rounded-full tr-bg-brand tr-text-white tr-text-xs tr-font-semibold">
						{i + 1}
					</span>
					<span className="tr-flex tr-flex-col tr-gap-1">
						<span className="tr-text-sm tr-font-medium tr-text-primary">
							{title}
						</span>
						<span className="tr-text-xs tr-leading-relaxed tr-text-secondary">
							{text}
						</span>
					</span>
				</li>
			))}
		</ol>
	);
};

/**
 * A titled group of experiments.
 *
 * @param {Object} props             Component props.
 * @param {string} props.title       Group heading.
 * @param {Array}  props.experiments Experiments.
 * @return {JSX.Element|null} The group.
 */
const ExperimentGroup = ({ title, experiments }) =>
	experiments.length > 0 ? (
		<section className="tr-flex tr-flex-col tr-gap-3">
			<h4 className="tr-flex tr-items-center tr-gap-2 tr-m-0 tr-text-xs tr-font-semibold tr-uppercase tr-tracking-wide tr-text-secondary">
				{title}
				<span className="tr-px-1.5 tr-rounded-full tr-bg-line-2 tr-text-tertiary tr-font-normal tr-normal-case">
					{experiments.length}
				</span>
			</h4>
			<ul className="tr-flex tr-flex-col tr-gap-3 tr-m-0 tr-p-0 tr-list-none">
				{experiments.map((experiment) => (
					<ExperimentRow
						key={experiment.post_id}
						experiment={experiment}
					/>
				))}
			</ul>
		</section>
	) : null;

const TitleExperimentsTab = ({ onNavigate }) => {
	const [data, setData] = useState(null);
	const [error, setError] = useState('');

	useEffect(() => {
		apiFetch({ path: EXPERIMENTS_PATH })
			.then((res) => setData(res?.data || null))
			.catch((e) =>
				setError(
					e?.message ||
						__(
							'Title experiments could not be loaded.',
							'thinkrank'
						)
				)
			);
	}, []);

	if (!data && !error) {
		return <SkeletonCard lines={4} />;
	}

	const active = data?.active || [];
	const recent = data?.recent || [];

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			<Card padding={false}>
				<SectionHeader
					title={__('Title Experiments', 'thinkrank')}
					description={__(
						'Test a new SEO title on a page and let Google’s real clicks decide which one earns more clicks at the same position.',
						'thinkrank'
					)}
					trailing={
						data ? (
							<span className="tr-text-xs tr-font-medium tr-text-secondary tr-bg-line-2 tr-rounded-full tr-px-2.5 tr-py-1 tr-whitespace-nowrap">
								{sprintf(
									/* translators: %d: experiments running. */
									_n(
										'%d running',
										'%d running',
										active.length,
										'thinkrank'
									),
									active.length
								)}
							</span>
						) : null
					}
				/>
				<div className="tr-px-6 tr-py-5 tr-flex tr-flex-col tr-gap-5">
					{error && <Alert variant="error">{error}</Alert>}
					{data && !data.gsc_connected && (
						<Alert
							variant="warning"
							title={__(
								'Search Console is not connected',
								'thinkrank'
							)}
						>
							{__(
								'Experiments are measured on Search Console clicks, so they need it connected.',
								'thinkrank'
							)}{' '}
							<button
								type="button"
								className="tr-p-0 tr-bg-transparent tr-border-0 tr-text-brand tr-underline tr-cursor-pointer"
								onClick={() =>
									onNavigate?.(
										'integrations',
										'google-services'
									)
								}
							>
								{__('Connect it', 'thinkrank')}
							</button>
						</Alert>
					)}

					<HowItWorks />

					<ExperimentGroup
						title={__('Running', 'thinkrank')}
						experiments={active}
					/>
					<ExperimentGroup
						title={__('Recent results', 'thinkrank')}
						experiments={recent}
					/>

					{data && 0 === active.length && 0 === recent.length && (
						<EmptyState
							size="sm"
							title={__('No experiments yet', 'thinkrank')}
							description={__(
								'Open a page in the editor and start one from ThinkRank, General, Title experiment.',
								'thinkrank'
							)}
						/>
					)}
				</div>
			</Card>

			<ProGatedTab
				filter="thinkrank.analytics.titleExperimentsTab"
				teaser={
					<ProTeaser
						illustration={<AuditHistoryIllustration />}
						title={__('Experiments at scale', 'thinkrank')}
						description={__(
							'Free runs one experiment at a time with one new title. Pro tests your top pages at once, finds the ones worth testing, and keeps every result.',
							'thinkrank'
						)}
						features={[
							__(
								'Up to 25 experiments at once, up to four titles each',
								'thinkrank'
							),
							__(
								'Pages worth testing: high impressions, low clicks for their position',
								'thinkrank'
							),
							__(
								'Keep the winning title automatically',
								'thinkrank'
							),
							__(
								'Full history, and results in your email report',
								'thinkrank'
							),
						]}
					/>
				}
			/>
		</div>
	);
};

export default TitleExperimentsTab;
