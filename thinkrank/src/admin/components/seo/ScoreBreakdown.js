/**
 * SEO Score Breakdown Component
 *
 * Displays detailed breakdown of the 10-factor SEO scoring system
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

/* Bar + text tone for a factor's fill percentage. */
const factorTone = (pct) => {
	if (pct >= 70) return { bar: 'tr-bg-success', text: 'tr-text-success' };
	if (pct >= 50) return { bar: 'tr-bg-warning', text: 'tr-text-warning' };
	return { bar: 'tr-bg-danger', text: 'tr-text-danger' };
};

/**
 * Score Breakdown Component
 *
 * @param {Object}   props              Component props
 * @param {Object}   props.scoreData    Complete scoring data from API
 * @param {Function} props.onRefresh    Callback for refreshing scores (optional)
 * @param {boolean}  props.showOverview Whether to show the overview card (default: true)
 * @return {JSX.Element} Score breakdown component
 */
const ScoreBreakdown = ({
	scoreData,
	onRefresh = null,
	showOverview = true,
}) => {
	const [expandedSections, setExpandedSections] = useState({});
	const [isRefreshing, setIsRefreshing] = useState(false);

	// 2025 SEO Algorithm Factors (Google Q1 2025 Update)
	// Based on First Page Sage research and latest Google algorithm changes
	const scoreFactors = {
		satisfying_content: {
			label: __('Satisfying Content', 'thinkrank'),
			description: __(
				'#1 ranking factor - Consistent publication of satisfying content (23%)',
				'thinkrank'
			),
			maxScore: 23,
			icon: '🎯',
		},
		title_optimization: {
			label: __('Title Optimization', 'thinkrank'),
			description: __(
				'Keyword in meta title with looser matching requirements (14%)',
				'thinkrank'
			),
			maxScore: 14,
			icon: '📝',
		},
		niche_expertise: {
			label: __('Niche Expertise', 'thinkrank'),
			description: __(
				'Hub & spoke content clusters demonstrating expertise (13%)',
				'thinkrank'
			),
			maxScore: 13,
			icon: '🧠',
		},
		searcher_engagement: {
			label: __('Searcher Engagement', 'thinkrank'),
			description: __(
				'Dwell time, bounce rate, pages per session metrics (12%)',
				'thinkrank'
			),
			maxScore: 12,
			icon: '👥',
		},
		backlink_authority: {
			label: __('Backlink Authority', 'thinkrank'),
			description: __(
				'Quality backlinks - declining but still important (13%)',
				'thinkrank'
			),
			maxScore: 13,
			icon: '🔗',
		},
		content_freshness: {
			label: __('Content Freshness', 'thinkrank'),
			description: __(
				'Quarterly content updates and maintenance (6%)',
				'thinkrank'
			),
			maxScore: 6,
			icon: '🔄',
		},
		mobile_experience: {
			label: __('Mobile Experience Score', 'thinkrank'),
			description: __(
				'NEW 2025: Mobile Experience Score (MES) replacing mobile-first (5%)',
				'thinkrank'
			),
			maxScore: 5,
			icon: '📱',
		},
		trustworthiness: {
			label: __('Trustworthiness', 'thinkrank'),
			description: __(
				'E-A-T signals and brand authority indicators (5%)',
				'thinkrank'
			),
			maxScore: 5,
			icon: '🛡️',
		},
		link_diversity: {
			label: __('Link Distribution Diversity', 'thinkrank'),
			description: __(
				'Diverse link profile and natural link patterns (4%)',
				'thinkrank'
			),
			maxScore: 4,
			icon: '🌐',
		},
		core_web_vitals: {
			label: __('Core Web Vitals 2.0', 'thinkrank'),
			description: __(
				'Updated 2025 performance metrics and user experience (3%)',
				'thinkrank'
			),
			maxScore: 3,
			icon: '⚡',
		},
		site_security: {
			label: __('Site Security', 'thinkrank'),
			description: __(
				'HTTPS, security headers, and trust signals (1%)',
				'thinkrank'
			),
			maxScore: 1,
			icon: '🔒',
		},
		internal_linking: {
			label: __('Internal Linking', 'thinkrank'),
			description: __(
				'Strategic internal link structure and content clusters (1%)',
				'thinkrank'
			),
			maxScore: 1,
			icon: '🔗',
		},
	};

	/**
	 * Toggle expanded state for a section
	 * @param factorKey
	 */
	const toggleSection = (factorKey) => {
		setExpandedSections((prev) => ({
			...prev,
			[factorKey]: !prev[factorKey],
		}));
	};

	/**
	 * Get score color class based on percentage
	 * @param score
	 * @param maxScore
	 */
	const getScoreColor = (score, maxScore) => {
		const percentage = (score / maxScore) * 100;
		if (percentage >= 90) return 'score-excellent';
		if (percentage >= 70) return 'score-good';
		if (percentage >= 50) return 'score-fair';
		return 'score-poor';
	};

	/**
	 * Handle refresh scores
	 */
	const handleRefresh = async () => {
		if (!onRefresh) return;

		setIsRefreshing(true);
		try {
			await onRefresh();
		} finally {
			setIsRefreshing(false);
		}
	};

	/**
	 * Human label for a Core Web Vitals rating.
	 *
	 * The raw values are the performance module's own statuses, so they are
	 * mapped rather than printed: "needs_improvement" is not a sentence.
	 *
	 * @param {string} status Metric status from the performance module.
	 * @return {string} Translated label.
	 */
	const vitalStatusLabel = (status) => {
		switch (status) {
			case 'good':
				return __('Good', 'thinkrank');
			case 'needs_improvement':
				return __('Needs improvement', 'thinkrank');
			case 'poor':
				return __('Poor', 'thinkrank');
			default:
				return __('Unknown', 'thinkrank');
		}
	};

	/**
	 * Convert technical details to human-readable format
	 * @param factorKey
	 * @param details
	 */
	const formatFactorDetails = (factorKey, details) => {
		if (!details || typeof details !== 'object') return null;

		const formatters = {
			satisfying_content: (d) => [
				`Word count: ${d.word_count || 0} words (saved content)`,
				`Content satisfaction: ${d.intent_satisfaction || 0}%`,
				`Content uniqueness: ${d.uniqueness_score || 0}%`,
				`Content depth: ${d.content_depth || 'Basic'}`,
			],
			title_optimization: (d) => [
				`Title length: ${d.title_length || 0} characters`,
				`Keyword in title: ${d.keyword_in_title ? 'Yes' : 'No'}`,
				`Title optimization: ${d.title_score || 0}%`,
			],
			niche_expertise: (d) => [
				`Topic coverage: ${d.topic_coverage || 'Basic'}`,
				`Content depth: ${d.content_depth || 'Standard'}`,
				`Expertise signals: ${d.expertise_score || 0}%`,
			],
			searcher_engagement: (d) => [
				`Content engagement: ${d.engagement_score || 0}%`,
				`Reading time: ${d.estimated_reading_time || 'Unknown'}`,
				`User experience: ${d.ux_score || 'Good'}`,
			],
			backlink_authority: (d) => [
				`Authority score: ${d.authority_score || 0}%`,
				`Link quality: ${d.link_quality || 'Unknown'}`,
				`Domain authority: ${d.domain_authority || 'Not assessed'}`,
			],
			content_freshness: (d) => [
				`Freshness status: ${d.freshness_status || 'Current'}`,
				`Last updated: ${d.last_updated ? new Date(d.last_updated).toLocaleDateString() : 'Unknown'}`,
			],
			// Reads what the scorer actually emits. The previous version read
			// mes_score, mobile_friendly and page_speed — three keys the PHP has
			// never produced — so every site saw the same three hardcoded
			// fallbacks no matter what it scored (#537).
			mobile_experience: (d) =>
				d.measured
					? [
							`Mobile PageSpeed score: ${d.mobile_score}/100`,
							__(
								'Measured from your last PageSpeed run',
								'thinkrank'
							),
						]
					: [
							__(
								'Not measured, full credit assumed',
								'thinkrank'
							),
							__(
								'Connect Google PageSpeed to score this from real data',
								'thinkrank'
							),
						],
			core_web_vitals: (d) => {
				if (!d.measured) {
					return [
						__('Not measured, full credit assumed', 'thinkrank'),
						__(
							'Connect Google PageSpeed to score this from real data',
							'thinkrank'
						),
					];
				}

				const statuses = d.vitals_status || {};
				const entries = Object.entries(statuses);

				if (!entries.length) {
					return [
						__('No Core Web Vitals collected yet', 'thinkrank'),
					];
				}

				return entries.map(
					([metric, status]) =>
						`${metric.toUpperCase()}: ${vitalStatusLabel(status)}`
				);
			},
			technical_factors: (d) => [
				`Meta description: ${d.meta_description_length || 0} characters`,
				`Schema markup: ${d.schema_present ? 'Present' : 'Missing'}`,
				`Technical score: ${d.technical_score || 0}%`,
			],
		};

		const formatter = formatters[factorKey];
		return formatter ? formatter(details) : null;
	};

	if (!scoreData || !scoreData.score_breakdown) {
		return (
			<p className="tr-text-xs tr-text-tertiary tr-m-0">
				{__(
					'No scoring data available. Run an SEO analysis to see detailed breakdown.',
					'thinkrank'
				)}
			</p>
		);
	}

	const { overall_score, score_breakdown, grade, calculated_at } = scoreData;

	return (
		<div className="thinkrank-score-breakdown">
			{/* Overall Score Header - Only show if showOverview is true */}
			{showOverview && (
				<div className="score-overview-card tr-mb-4">
					<div className="tr-flex tr-items-center tr-justify-between tr-mb-3">
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('SEO Score Breakdown', 'thinkrank')}
						</h3>
						{onRefresh && (
							<button
								type="button"
								onClick={handleRefresh}
								disabled={isRefreshing}
								className="tr-inline-flex tr-items-center tr-h-7 tr-px-2.5 tr-rounded tr-border tr-border-primary tr-bg-white tr-text-xs tr-font-medium tr-text-secondary hover:tr-bg-white disabled:tr-opacity-50 tr-cursor-pointer tr-border-0"
							>
								{isRefreshing
									? __('Refreshing…', 'thinkrank')
									: __('Refresh', 'thinkrank')}
							</button>
						)}
					</div>
					<div className="overall-score-display">
						<div
							className={`score-circle ${getScoreColor(overall_score, 100)}`}
						>
							<span className="score-number">
								{overall_score}
							</span>
							<span className="score-grade">{grade}</span>
						</div>
						<div className="score-info">
							<p className="score-description">
								{overall_score >= 80 &&
									__(
										'Excellent SEO optimization!',
										'thinkrank'
									)}
								{overall_score >= 60 &&
									overall_score < 80 &&
									__(
										'Good SEO with room for improvement',
										'thinkrank'
									)}
								{overall_score >= 40 &&
									overall_score < 60 &&
									__(
										'Fair SEO - needs attention',
										'thinkrank'
									)}
								{overall_score < 40 &&
									__(
										'Poor SEO - requires significant work',
										'thinkrank'
									)}
							</p>
							{calculated_at && (
								<p className="score-timestamp">
									{__('Last updated:', 'thinkrank')}{' '}
									{new Date(calculated_at).toLocaleString()}
								</p>
							)}
						</div>
					</div>
				</div>
			)}

			{/* Detailed Factor Breakdown */}
			<div className="tr-flex tr-flex-col tr-gap-2">
				{Object.entries(scoreFactors).map(
					([factorKey, factorConfig]) => {
						const factorData = score_breakdown[factorKey];
						if (!factorData) return null;

						const isExpanded = expandedSections[factorKey];
						const pct = factorData.max_score
							? (factorData.score / factorData.max_score) * 100
							: 0;
						const tone = factorTone(pct);
						const detailLines = formatFactorDetails(
							factorKey,
							factorData.details
						);

						return (
							<div
								key={factorKey}
								className="tr-rounded-lg tr-border tr-border-secondary tr-bg-white tr-overflow-hidden"
							>
								<button
									type="button"
									onClick={() => toggleSection(factorKey)}
									aria-expanded={isExpanded}
									className="tr-w-full tr-flex tr-items-center tr-gap-3 tr-p-3 tr-text-left tr-bg-transparent tr-border-0 tr-cursor-pointer hover:tr-bg-line-2/50 tr-transition-colors"
								>
									<span className="tr-text-lg tr-leading-none tr-shrink-0">
										{factorConfig.icon}
									</span>
									<div className="tr-flex-1 tr-min-w-0">
										<div className="tr-flex tr-items-center tr-justify-between tr-gap-2">
											<span className="tr-text-sm tr-font-medium tr-text-primary tr-truncate">
												{factorConfig.label}
											</span>
											<span
												className={`tr-text-xs tr-font-bold tr-tabular-nums tr-shrink-0 ${tone.text}`}
											>
												{factorData.score}/
												{factorData.max_score}
											</span>
										</div>
										<div className="tr-mt-1.5 tr-h-1.5 tr-rounded-full tr-bg-line-2 tr-overflow-hidden">
											<div
												className={`tr-h-full tr-rounded-full ${tone.bar}`}
												style={{
													width: `${Math.max(0, Math.min(100, pct))}%`,
												}}
											/>
										</div>
									</div>
									<svg
										className={`tr-w-4 tr-h-4 tr-text-tertiary tr-shrink-0 tr-transition-transform ${isExpanded ? 'tr-rotate-180' : ''}`}
										viewBox="0 0 24 24"
										fill="none"
										aria-hidden="true"
									>
										<path
											d="M6 9l6 6 6-6"
											stroke="currentColor"
											strokeWidth="2"
											strokeLinecap="round"
											strokeLinejoin="round"
										/>
									</svg>
								</button>

								{isExpanded && (
									<div className="tr-px-3 tr-pb-3 tr-pt-2 tr-border-t tr-border-secondary tr-flex tr-flex-col tr-gap-3">
										<p className="tr-text-xs tr-text-tertiary tr-m-0">
											{factorConfig.description}
										</p>
										{factorData.suggestions &&
											factorData.suggestions.length >
												0 && (
												<div>
													<h5 className="tr-text-xs tr-font-semibold tr-text-secondary tr-m-0 tr-mb-1">
														{__(
															'Suggestions',
															'thinkrank'
														)}
													</h5>
													<ul className="tr-list-disc tr-pl-4 tr-m-0 tr-flex tr-flex-col tr-gap-0.5">
														{factorData.suggestions.map(
															(
																suggestion,
																index
															) => (
																<li
																	key={index}
																	className="tr-text-xs tr-text-tertiary"
																>
																	{suggestion}
																</li>
															)
														)}
													</ul>
												</div>
											)}
										{detailLines &&
											detailLines.length > 0 && (
												<div>
													<h5 className="tr-text-xs tr-font-semibold tr-text-secondary tr-m-0 tr-mb-1">
														{__(
															'Details',
															'thinkrank'
														)}
													</h5>
													<div className="tr-flex tr-flex-col tr-gap-0.5">
														{detailLines.map(
															(detail, index) => (
																<div
																	key={index}
																	className="tr-text-xs tr-text-tertiary"
																>
																	{detail}
																</div>
															)
														)}
													</div>
												</div>
											)}
									</div>
								)}
							</div>
						);
					}
				)}
			</div>
		</div>
	);
};

export default ScoreBreakdown;
