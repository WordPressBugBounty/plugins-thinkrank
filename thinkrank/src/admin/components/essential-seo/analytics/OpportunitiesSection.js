/**
 * Opportunities Section Component
 *
 * SEO opportunities with Quick Wins, Impact/Effort Matrix, and keyword analysis.
 * Extracted from AnalyticsTab for maintainability.
 *
 * @package
 * @since 1.11.0
 */

import { __ } from '@wordpress/i18n';
import { Select, SkeletonCard } from '../../ui';
import {
	Card,
	CardBody,
	CardHeader,
	Flex,
	__experimentalText as Text,
} from '@wordpress/components';
import { dateRangeOptions } from './utils';

/**
 * OpportunitiesSection Component
 * @param root0
 * @param root0.dateRange
 * @param root0.setDateRange
 * @param root0.seoOpportunities
 * @param root0.isRefreshing
 */
const OpportunitiesSection = ({
	dateRange,
	setDateRange,
	seoOpportunities,
	isRefreshing,
}) => {
	return (
		<div className="thinkrank-space-y-6">
			<Flex justify="space-between" align="center">
				<div>
					<Text variant="muted">
						{__(
							'SEO opportunities and actionable insights based on your search performance data.',
							'thinkrank'
						)}
					</Text>
				</div>
				<div>
					<Select
						id="thinkrank-opportunities-date-range"
						label={__('Date Range', 'thinkrank')}
						value={dateRange}
						options={dateRangeOptions}
						onChange={(e) => setDateRange(e.target.value)}
					/>
				</div>
			</Flex>

			{/* Traditional SEO Opportunities Data (Enhanced) */}
			{seoOpportunities && (
				<div className="thinkrank-space-y-6">
					{/* Keyword Opportunities */}
					{seoOpportunities.keyword_opportunities &&
						seoOpportunities.keyword_opportunities
							.opportunities && (
							<Card
								size="small"
								className="thinkrank-mb-lg thinkrank-border thinkrank-border-light thinkrank-rounded-lg"
							>
								<CardHeader className="thinkrank-border-b thinkrank-border-light thinkrank-bg-gray-100">
									<h3>
										{__(
											'Keyword Opportunities',
											'thinkrank'
										)}
									</h3>
									<Text variant="muted">
										{__(
											'Keywords with potential for ranking improvements',
											'thinkrank'
										)}
									</Text>
								</CardHeader>
								<CardBody className="thinkrank-p-lg">
									<div className="thinkrank-space-y-3">
										{seoOpportunities.keyword_opportunities.opportunities
											.slice(0, 10)
											.map((opportunity, index) => (
												<div
													key={index}
													className="thinkrank-opportunity-card"
												>
													<Flex
														justify="space-between"
														align="center"
													>
														<div>
															<div>
																<Text weight="600">
																	{
																		opportunity.query
																	}
																</Text>
																<div className="thinkrank-opportunity-reasons">
																	{opportunity.reasons.map(
																		(
																			reason,
																			reasonIndex
																		) => (
																			<span
																				key={
																					reasonIndex
																				}
																				className="thinkrank-reason-tag"
																			>
																				{
																					reason
																				}
																			</span>
																		)
																	)}
																</div>
															</div>
														</div>
														<div>
															<div className="thinkrank-opportunity-metrics">
																<div className="thinkrank-metric-small">
																	<span className="thinkrank-metric-label">
																		{__(
																			'Position:',
																			'thinkrank'
																		)}
																	</span>
																	<span className="thinkrank-metric-value">
																		{
																			opportunity.position
																		}
																	</span>
																</div>
																<div className="thinkrank-metric-small">
																	<span className="thinkrank-metric-label">
																		{__(
																			'Impressions:',
																			'thinkrank'
																		)}
																	</span>
																	<span className="thinkrank-metric-value">
																		{
																			opportunity.impressions
																		}
																	</span>
																</div>
																<div className="thinkrank-metric-small">
																	<span className="thinkrank-metric-label">
																		{__(
																			'CTR:',
																			'thinkrank'
																		)}
																	</span>
																	<span className="thinkrank-metric-value">
																		{
																			opportunity.ctr
																		}
																		%
																	</span>
																</div>
																<div
																	className={`thinkrank-opportunity-score thinkrank-score-${opportunity.opportunity_score >= 70 ? 'high' : opportunity.opportunity_score >= 40 ? 'medium' : 'low'}`}
																>
																	{__(
																		'Score:',
																		'thinkrank'
																	)}{' '}
																	{
																		opportunity.opportunity_score
																	}
																</div>
															</div>
														</div>
													</Flex>
												</div>
											))}
									</div>
								</CardBody>
							</Card>
						)}

					{/* Device Performance Insights */}
					{seoOpportunities.device_insights &&
						seoOpportunities.device_insights.devices && (
							<Card
								size="small"
								className="thinkrank-mb-lg thinkrank-border thinkrank-border-light thinkrank-rounded-lg"
							>
								<CardHeader className="thinkrank-border-b thinkrank-border-light thinkrank-bg-gray-100">
									<h3>
										{__('Device Performance', 'thinkrank')}
									</h3>
									<Text variant="muted">
										{__(
											'Performance breakdown by device type',
											'thinkrank'
										)}
									</Text>
								</CardHeader>
								<CardBody className="thinkrank-p-lg">
									<div className="thinkrank-grid thinkrank-grid-cols-3 thinkrank-gap-3">
										{Object.entries(
											seoOpportunities.device_insights
												.devices
										).map(([device, data]) => (
											<div
												key={device}
												className="thinkrank-device-card"
											>
												<div className="thinkrank-device-name">
													{device
														.charAt(0)
														.toUpperCase() +
														device.slice(1)}
												</div>
												<div className="thinkrank-device-metrics">
													<div className="thinkrank-metric-row">
														<span>
															{__(
																'Clicks:',
																'thinkrank'
															)}
														</span>
														<span>
															{data.clicks}
														</span>
													</div>
													<div className="thinkrank-metric-row">
														<span>
															{__(
																'CTR:',
																'thinkrank'
															)}
														</span>
														<span>{data.ctr}%</span>
													</div>
													<div className="thinkrank-metric-row">
														<span>
															{__(
																'Position:',
																'thinkrank'
															)}
														</span>
														<span>
															{data.position}
														</span>
													</div>
												</div>
											</div>
										))}
									</div>
								</CardBody>
							</Card>
						)}

					{/* Search Appearance */}
					{seoOpportunities.search_appearance &&
						seoOpportunities.search_appearance.appearances && (
							<Card
								size="small"
								className="thinkrank-mb-lg thinkrank-border thinkrank-border-light thinkrank-rounded-lg"
							>
								<CardHeader className="thinkrank-border-b thinkrank-border-light thinkrank-bg-gray-100">
									<h3>
										{__('Search Appearance', 'thinkrank')}
									</h3>
									<Text variant="muted">
										{__(
											'Rich results and search appearance features',
											'thinkrank'
										)}
									</Text>
								</CardHeader>
								<CardBody className="thinkrank-p-lg">
									<div className="thinkrank-space-y-3">
										{Object.entries(
											seoOpportunities.search_appearance
												.appearances
										).map(([appearance, data]) => (
											<Flex
												key={appearance}
												justify="space-between"
												align="center"
											>
												<div>
													<Text weight="600">
														{appearance
															.replace(/_/g, ' ')
															.replace(
																/\b\w/g,
																(l) =>
																	l.toUpperCase()
															)}
													</Text>
												</div>
												<div>
													<div className="thinkrank-flex thinkrank-gap-3">
														<span className="thinkrank-metric-small">
															{data.clicks}{' '}
															{__(
																'clicks',
																'thinkrank'
															)}
														</span>
														<span className="thinkrank-metric-small">
															{data.impressions}{' '}
															{__(
																'impressions',
																'thinkrank'
															)}
														</span>
														<span className="thinkrank-metric-small">
															{data.ctr}%{' '}
															{__(
																'CTR',
																'thinkrank'
															)}
														</span>
													</div>
												</div>
											</Flex>
										))}
									</div>
								</CardBody>
							</Card>
						)}
				</div>
			)}

			{!seoOpportunities && !isRefreshing && (
				<Card
					size="small"
					className="thinkrank-mb-lg thinkrank-border thinkrank-border-light thinkrank-rounded-lg"
				>
					<CardBody className="thinkrank-p-lg">
						<div className="thinkrank-text-center thinkrank-p-lg">
							<Text variant="muted">
								{__(
									'No SEO opportunities data available yet. Search Console data collection may take 24-48 hours after API configuration.',
									'thinkrank'
								)}
							</Text>
						</div>
					</CardBody>
				</Card>
			)}

			{isRefreshing && (
				<div className="thinkrank-flex thinkrank-justify-center thinkrank-p-lg">
					<SkeletonCard lines={3} />
				</div>
			)}
		</div>
	);
};

export default OpportunitiesSection;
