/**
 * Answer readiness — the five answer-engine checks, for this post.
 *
 * The Site SEO Audit reports these as a ratio over a sample of up to a hundred
 * posts ("3 of 20 pages open with a direct answer"), which tells an author that
 * their content is not answer-ready and gives them nothing to act on in the
 * place they are actually working (#677).
 *
 * The verdicts come from the same server-side predicates the audit uses, on the
 * response the panel already fetches, so the two can never grade a page
 * differently. This renders them and nothing more: no thresholds and no rules
 * live on this side.
 */

import { __, sprintf } from '@wordpress/i18n';
import PanelCard from '../ui/PanelCard';
import { IconRobot } from '../ui/icons';

const IconPass = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<polyline points="20 6 9 17 4 12" />
	</svg>
);

const IconFail = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<line x1="18" y1="6" x2="6" y2="18" />
		<line x1="6" y1="6" x2="18" y2="18" />
	</svg>
);

/**
 * @param {Object} props                 Component props.
 * @param {Object} props.answerReadiness The `answer_readiness` block from the
 *                                       score response, or null before one
 *                                       has been fetched.
 * @return {JSX.Element|null} The card, or null when there is nothing to show.
 */
const AnswerReadinessCard = ({ answerReadiness }) => {
	// Nothing until an analysis has run. An empty card that says "unknown"
	// before the author has asked for anything is noise in a panel that is
	// already dense.
	if (!answerReadiness) {
		return null;
	}

	const { applicable, reason, passed, total, checks } = answerReadiness;

	if (!applicable) {
		return (
			<PanelCard
				icon={<IconRobot />}
				title={__('Answer readiness', 'thinkrank')}
				subtitle={__('Not graded for this page', 'thinkrank')}
				bodyClassName="tr-px-4 tr-py-3"
			>
				<p className="tr-text-xs tr-text-tertiary tr-m-0">{reason}</p>
			</PanelCard>
		);
	}

	const allPassed = passed === total;

	return (
		<PanelCard
			icon={<IconRobot />}
			title={__('Answer readiness', 'thinkrank')}
			subtitle={sprintf(
				/* translators: 1: checks passed, 2: checks in total. */
				__('%1$d of %2$d checks passed', 'thinkrank'),
				passed,
				total
			)}
			bodyClassName="tr-flex tr-flex-col tr-gap-3 tr-px-4 tr-py-3"
		>
			{allPassed && (
				<p className="tr-text-xs tr-text-tertiary tr-m-0">
					{__(
						'This page is shaped the way AI assistants prefer to quote.',
						'thinkrank'
					)}
				</p>
			)}

			<ul className="tr-list-none tr-m-0 tr-p-0 tr-flex tr-flex-col tr-gap-3">
				{(checks || []).map((check) => (
					<li
						key={check.id}
						className="tr-flex tr-items-start tr-gap-2"
					>
						<span
							className={
								check.passed
									? 'tr-text-success tr-shrink-0 tr-mt-0.5'
									: 'tr-text-warning tr-shrink-0 tr-mt-0.5'
							}
						>
							{check.passed ? <IconPass /> : <IconFail />}
						</span>
						<div className="tr-min-w-0">
							<p className="tr-text-xs tr-font-medium tr-text-primary tr-m-0">
								{/* The icon and its colour are decorative, so the
								    verdict is also said in words, read before
								    the label. */}
								<span className="screen-reader-text">
									{check.passed
										? __('Passed:', 'thinkrank')
										: __('Failed:', 'thinkrank')}{' '}
								</span>
								{check.label}
							</p>
							{check.detail && (
								<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
									{check.detail}
								</p>
							)}
							{/* The remedy only on a failure: on a passing row it
							    is advice for a problem the author does not have. */}
							{!check.passed && check.fix && (
								<p className="tr-text-xs tr-text-secondary tr-m-0 tr-mt-1">
									{check.fix}
								</p>
							)}
						</div>
					</li>
				))}
			</ul>

			<p className="tr-text-xs tr-text-tertiary tr-m-0">
				{__(
					'These checks are not part of the SEO score above. They are the same ones the Site SEO Audit reports for the whole site.',
					'thinkrank'
				)}
			</p>
		</PanelCard>
	);
};

export default AnswerReadinessCard;
