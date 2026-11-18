/**
 * Title Experiments in the editor (#719): test a new SEO title on this page
 * against the current one, on real Google clicks.
 *
 * While a test runs the page's SEO title and description are locked — they are
 * what the new title is being compared with — so the card tells the General
 * panel through `onLockChange`.
 */

import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Button from '../../admin/components/ui/Button';
import Input, { Textarea } from '../../admin/components/ui/Input';
import Select from '../../admin/components/ui/Select';
import CharacterCounter from '../../admin/components/ui/CharacterCounter';
import { SkeletonText } from '../../admin/components/ui/Skeleton';
import ExperimentSummary from '../../admin/components/title-experiments/ExperimentSummary';
import useTitleExperiment from '../../admin/components/title-experiments/useTitleExperiment';
import PanelCard from './ui/PanelCard';
import Field from './ui/Field';
import { IconGauge } from './ui/icons';

const WINDOWS = [7, 14, 21, 28];

const EMPTY_DRAFT = { title: '', description: '', source: 'manual' };

const TitleExperimentCard = ({ postId, onLockChange }) => {
	const { state, loading, busy, error, start, stop, apply, suggest, reload } =
		useTitleExperiment(postId);
	const [composing, setComposing] = useState(false);
	// One draft per new title. The free plugin tests one; Pro raises
	// max_variants (which counts the original) to test up to three.
	const [drafts, setDrafts] = useState([EMPTY_DRAFT]);
	const [windowDays, setWindowDays] = useState(14);
	const [candidates, setCandidates] = useState([]);
	const maxNew = Math.max(1, (state?.capabilities?.max_variants ?? 2) - 1);

	const locked = !!state?.locked;
	// Until the page's state has loaded, nobody knows whether a test is
	// running, so the title and description stay locked: while loading, and
	// after a failed load until a retry succeeds. Failing open would let the
	// baseline change mid-test.
	let lockReason = '';
	if (loading) {
		lockReason = 'loading';
	} else if (!state) {
		lockReason = 'unknown';
	} else if (locked) {
		lockReason = 'experiment';
	}
	useEffect(() => {
		onLockChange?.(lockReason);
	}, [lockReason, onLockChange]);

	const experiment = state?.experiment || null;
	const finished =
		experiment && ['complete', 'stopped'].includes(experiment.status);

	const askAi = async () => {
		const data = await suggest();
		setCandidates(data?.variants || []);
	};

	const updateDraft = (index, changes) =>
		setDrafts((current) =>
			current.map((draft, i) =>
				i === index ? { ...draft, ...changes } : draft
			)
		);

	// A suggestion fills the first empty title, or the last one when all
	// are taken.
	const pick = (candidate) => {
		const empty = drafts.findIndex((d) => '' === d.title.trim());
		updateDraft(-1 === empty ? drafts.length - 1 : empty, {
			title: candidate.title,
			description: candidate.description || '',
			source: 'ai',
		});
	};

	const filled = drafts.filter((d) => '' !== d.title.trim());

	const begin = async () => {
		const data = await start({ variants: filled, window: windowDays });
		if (data) {
			setComposing(false);
			setCandidates([]);
			setDrafts([EMPTY_DRAFT]);
		}
	};

	let body;
	if (loading) {
		body = <SkeletonText lines={2} />;
	} else if (!state) {
		body = (
			<div className="tr-flex tr-flex-col tr-items-start tr-gap-2">
				<p className="tr-text-xs tr-text-secondary tr-m-0">{error}</p>
				<Button variant="secondary" size="sm" onClick={reload}>
					{__('Try again', 'thinkrank')}
				</Button>
			</div>
		);
	} else if (!state.gsc_connected && !experiment) {
		body = (
			<p className="tr-text-xs tr-text-secondary tr-m-0">
				{__(
					'Title experiments are measured on real Google clicks, so they need Search Console. Connect it under ThinkRank → Essential SEO → Integrations → Google Services.',
					'thinkrank'
				)}
			</p>
		);
	} else if (composing) {
		body = (
			<div className="tr-flex tr-flex-col tr-gap-4">
				<div className="tr-text-xs tr-text-secondary">
					{sprintf(
						/* translators: %s: the page's current SEO title. */
						__('Current title: %s', 'thinkrank'),
						state.current.title
					)}
				</div>

				{state.ai_configured && (
					<div className="tr-flex tr-flex-col tr-gap-2">
						<div>
							<Button
								variant="secondary"
								size="sm"
								loading={'suggest' === busy}
								onClick={askAi}
							>
								{__('Suggest titles with AI', 'thinkrank')}
							</Button>
						</div>
						{candidates.map((candidate, i) => (
							<button
								type="button"
								key={i}
								onClick={() => pick(candidate)}
								className={`tr-text-left tr-p-3 tr-rounded-lg tr-border tr-cursor-pointer tr-bg-white ${
									drafts.some(
										(d) => d.title === candidate.title
									)
										? 'tr-border-brand'
										: 'tr-border-secondary'
								}`}
							>
								<div className="tr-text-sm tr-font-medium tr-text-primary">
									{candidate.title}
								</div>
								{candidate.description && (
									<div className="tr-text-xs tr-text-secondary tr-mt-1">
										{candidate.description}
									</div>
								)}
							</button>
						))}
					</div>
				)}

				{drafts.map((draft, i) => (
					<div
						key={i}
						className="tr-flex tr-flex-col tr-gap-3 tr-pt-3 tr-border-t tr-border-secondary first:tr-border-t-0 first:tr-pt-0"
					>
						<Field
							label={
								1 === drafts.length
									? __('New title to test', 'thinkrank')
									: sprintf(
											/* translators: %d: 1-based number of the new title. */
											__('New title %d', 'thinkrank'),
											i + 1
										)
							}
							htmlFor={`thinkrank_experiment_title_${i}`}
							counter={
								<CharacterCounter
									value={draft.title}
									max={60}
								/>
							}
						>
							<Input
								id={`thinkrank_experiment_title_${i}`}
								value={draft.title}
								maxLength="80"
								onChange={(e) =>
									updateDraft(i, {
										title: e.target.value,
										source: 'manual',
									})
								}
							/>
						</Field>
						<Field
							label={__(
								'New description (optional)',
								'thinkrank'
							)}
							htmlFor={`thinkrank_experiment_description_${i}`}
							counter={
								<CharacterCounter
									value={draft.description}
									max={160}
								/>
							}
							help={__(
								'Leave empty to test the title alone with the current description.',
								'thinkrank'
							)}
						>
							<Textarea
								id={`thinkrank_experiment_description_${i}`}
								rows="2"
								maxLength="160"
								value={draft.description}
								onChange={(e) =>
									updateDraft(i, {
										description: e.target.value,
									})
								}
							/>
						</Field>
						{i > 0 && (
							<div>
								<Button
									variant="ghost"
									size="sm"
									onClick={() =>
										setDrafts((current) =>
											current.filter((_, j) => j !== i)
										)
									}
								>
									{__('Remove this title', 'thinkrank')}
								</Button>
							</div>
						)}
					</div>
				))}
				{drafts.length < maxNew && (
					<div>
						<Button
							variant="secondary"
							size="sm"
							onClick={() =>
								setDrafts((current) => [
									...current,
									EMPTY_DRAFT,
								])
							}
						>
							{__('Add another title', 'thinkrank')}
						</Button>
					</div>
				)}
				<Field
					label={__('Days to measure', 'thinkrank')}
					htmlFor="thinkrank_experiment_window"
					help={sprintf(
						/* translators: 1: days measured, 2: days until the result. */
						__(
							'The last %1$d days are the original’s. Each new title is measured for the same number of days after Google recrawls the page; the result arrives about %2$d days from now.',
							'thinkrank'
						),
						windowDays,
						drafts.length * (windowDays + 3) + 4
					)}
				>
					<Select
						id="thinkrank_experiment_window"
						value={String(windowDays)}
						onChange={(e) => setWindowDays(Number(e.target.value))}
						options={WINDOWS.map((d) => ({
							value: String(d),
							label: sprintf(
								/* translators: %d: number of days. */
								__('%d days', 'thinkrank'),
								d
							),
						}))}
					/>
				</Field>
				<div className="tr-flex tr-gap-2">
					<Button
						loading={'start' === busy}
						disabled={0 === filled.length}
						onClick={begin}
					>
						{__('Start the experiment', 'thinkrank')}
					</Button>
					<Button variant="ghost" onClick={() => setComposing(false)}>
						{__('Cancel', 'thinkrank')}
					</Button>
				</div>
			</div>
		);
	} else {
		body = (
			<div className="tr-flex tr-flex-col tr-gap-3">
				{experiment ? (
					<ExperimentSummary experiment={experiment} />
				) : (
					<p className="tr-text-xs tr-text-secondary tr-m-0">
						{__(
							'Try a new title on this page and let Google’s real clicks decide. ThinkRank serves it for a few weeks, compares its click-through rate with the current title’s, and tells you which earned more clicks at the same position.',
							'thinkrank'
						)}
					</p>
				)}
				<div className="tr-flex tr-flex-wrap tr-gap-2">
					{locked && (
						<Button
							variant="secondary"
							size="sm"
							loading={'stop' === busy}
							onClick={stop}
						>
							{__('Stop the experiment', 'thinkrank')}
						</Button>
					)}
					{'complete' === experiment?.status &&
						'variant_wins' === experiment.result?.outcome &&
						(null === experiment.applied ||
							undefined === experiment.applied) && (
							<Button
								size="sm"
								loading={'apply' === busy}
								onClick={apply}
							>
								{__('Keep the new title', 'thinkrank')}
							</Button>
						)}
					{!locked && (!experiment || finished) && (
						<Button
							variant={experiment ? 'secondary' : 'primary'}
							size="sm"
							onClick={() => setComposing(true)}
						>
							{__('Test a new title', 'thinkrank')}
						</Button>
					)}
				</div>
			</div>
		);
	}

	return (
		<PanelCard
			icon={<IconGauge />}
			title={__('Title experiment', 'thinkrank')}
			subtitle={__(
				'Test a new title on real Google clicks.',
				'thinkrank'
			)}
			bodyClassName="tr-flex tr-flex-col tr-gap-3"
		>
			{body}
			{error && state && (
				<p className="tr-text-xs tr-text-danger tr-m-0">{error}</p>
			)}
		</PanelCard>
	);
};

export default TitleExperimentCard;
