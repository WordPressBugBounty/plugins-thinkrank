/**
 * Opportunities Section
 *
 * Performance optimization opportunities from PageSpeed data.
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Card,
	CardHeader,
	CardBody,
	Button,
	__experimentalText as Text,
	__experimentalHeading as Heading,
} from '@wordpress/components';
import { SkeletonCard } from '../../ui';
import PerformanceErrorNotice from './PerformanceErrorNotice';

/**
 * OpportunitiesSection Component
 * @param root0
 * @param root0.opportunities
 * @param root0.isLoadingOpportunities
 * @param root0.opportunitiesError
 * @param root0.performanceData
 * @param root0.onNavigate
 * @param root0.onRetry
 */
const OpportunitiesSection = ({
	opportunities,
	isLoadingOpportunities,
	opportunitiesError,
	performanceData,
	onNavigate,
	onRetry,
}) => {
	/**
	 * Memoize total savings
	 */
	const totalSavings = useMemo(() => {
		return opportunities.reduce(
			(sum, opp) => sum + (opp.estimated_savings || 0),
			0
		);
	}, [opportunities]);

	/**
	 * Render opportunity card
	 */
	const renderOpportunityCard = useCallback((opportunity) => {
		const priorityMap = {
			Easy: {
				label: __('Low', 'thinkrank'),
				color: '#027A48',
				bg: '#dcfce7',
			},
			Medium: {
				label: __('Medium', 'thinkrank'),
				color: '#ea580c',
				bg: '#fff3ee',
			},
			Hard: {
				label: __('Critical', 'thinkrank'),
				color: '#921C13',
				bg: '#fee2e2',
			},
		};
		const priority =
			priorityMap[opportunity.difficulty] || priorityMap.Medium;

		const savings =
			opportunity.estimated_savings >= 1000
				? `${(opportunity.estimated_savings / 1000).toFixed(1)}s`
				: `${opportunity.estimated_savings}ms`;

		const rawDesc = opportunity.description || '';
		const bullets = rawDesc
			.split(/\n|(?<=\.)\s+/)
			.map((s) => s.trim().replace(/\.$/, ''))
			.filter(Boolean);

		let dataText = null;
		if (opportunity.details) {
			if (typeof opportunity.details === 'object') {
				dataText = opportunity.details.items
					? `${opportunity.details.items.length} ${__('items to review. Please refer to the optimization report for detailed insights.', 'thinkrank')}`
					: __(
							'Check the optimization report for detailed insights.',
							'thinkrank'
						);
			} else {
				dataText = opportunity.details;
			}
		}

		return (
			<div key={opportunity.id} className="thinkrank-opp-card">
				<div className="thinkrank-opp-header">
					<div className="thinkrank-opp-title-row">
						<span className="thinkrank-opp-title">
							{opportunity.title}
						</span>
						<span
							className="thinkrank-opp-badge"
							style={{
								color: priority.color,
								background: priority.bg,
							}}
						>
							{priority.label}
						</span>
					</div>
					<div className="thinkrank-opp-metric">
						<span className="thinkrank-opp-metric-value">
							{savings}
						</span>
						<span className="thinkrank-opp-metric-label">
							{__('Estimated improvement', 'thinkrank')}
						</span>
					</div>
				</div>

				{bullets.length > 0 && (
					<div className="thinkrank-opp-actions">
						<p className="thinkrank-opp-actions-label">
							{__('Action Items:', 'thinkrank')}
						</p>
						<ul className="thinkrank-opp-actions-list">
							{bullets.map((item, i) => (
								<li key={i}>{item}</li>
							))}
						</ul>
					</div>
				)}

				{dataText && (
					<div className="thinkrank-opp-data-box">
						<strong>{__('Data Items', 'thinkrank')}</strong>{' '}
						{dataText}
					</div>
				)}
			</div>
		);
	}, []);

	if (isLoadingOpportunities) {
		return (
			<div className="tr-empty-state">
				<SkeletonCard lines={3} />
			</div>
		);
	}

	if (opportunitiesError) {
		return (
			<PerformanceErrorNotice
				error={opportunitiesError}
				onNavigate={onNavigate}
				onRetry={onRetry}
				isRetrying={isLoadingOpportunities}
			/>
		);
	}

	if (opportunities.length === 0) {
		const hasApiConfigIssue =
			performanceData?.core_web_vitals?.error ||
			performanceData?.core_web_vitals?.message?.includes(
				'not connected'
			);

		if (hasApiConfigIssue) {
			return (
				<Card>
					<CardHeader>
						<Heading level={3}>
							{__('Performance Opportunities', 'thinkrank')}
						</Heading>
					</CardHeader>
					<CardBody>
						<div
							style={{
								textAlign: 'center',
								padding: '48px 24px',
							}}
						>
							<div
								style={{
									fontSize: '48px',
									marginBottom: '24px',
								}}
							>
								⚡
							</div>
							<div style={{ marginBottom: '32px' }}>
								<Heading
									level={4}
									style={{ marginBottom: '16px' }}
								>
									{__(
										'No Performance Data Available',
										'thinkrank'
									)}
								</Heading>
								<Text
									style={{
										color: 'var(--tr-text-secondary)',
										fontSize: '16px',
										marginBottom: '12px',
										display: 'block',
									}}
								>
									{__(
										'Add a PageSpeed API key or connect your Google account to view performance optimization opportunities.',
										'thinkrank'
									)}
								</Text>
								<Text
									variant="muted"
									style={{
										fontSize: '14px',
										display: 'block',
									}}
								>
									{__(
										'Performance opportunities help identify specific areas where your site can be optimized for better speed and user experience.',
										'thinkrank'
									)}
								</Text>
							</div>
							<div
								style={{
									display: 'flex',
									justifyContent: 'center',
									gap: '12px',
									flexWrap: 'wrap',
								}}
							>
								<Button
									variant="primary"
									onClick={() =>
										onNavigate &&
										onNavigate(
											'integrations',
											'google-services'
										)
									}
								>
									{__('Add PageSpeed API Key', 'thinkrank')}
								</Button>
								<Button
									variant="secondary"
									onClick={onRetry}
									disabled={isLoadingOpportunities}
								>
									{isLoadingOpportunities
										? __('Retrying…', 'thinkrank')
										: __('Retry', 'thinkrank')}
								</Button>
							</div>
						</div>
					</CardBody>
				</Card>
			);
		}

		return (
			<Card>
				<CardHeader>
					<Heading level={3}>
						{__('Performance Opportunities', 'thinkrank')}
					</Heading>
				</CardHeader>
				<CardBody>
					<div style={{ textAlign: 'center', padding: '48px 24px' }}>
						<div style={{ fontSize: '48px', marginBottom: '24px' }}>
							🎉
						</div>
						<div>
							<Heading level={4} style={{ marginBottom: '16px' }}>
								{__(
									'Great job! No performance opportunities found.',
									'thinkrank'
								)}
							</Heading>
							<Text
								style={{
									color: 'var(--tr-text-secondary)',
									fontSize: '16px',
									marginBottom: '12px',
									display: 'block',
								}}
							>
								{__(
									'Your site is already well-optimized for performance.',
									'thinkrank'
								)}
							</Text>
							<Text
								variant="muted"
								style={{ fontSize: '14px', display: 'block' }}
							>
								{__(
									'Keep monitoring your performance metrics to maintain optimal user experience.',
									'thinkrank'
								)}
							</Text>
						</div>
					</div>
				</CardBody>
			</Card>
		);
	}

	return (
		<div className="thinkrank-opp-grid">
			{opportunities.map((opportunity) =>
				renderOpportunityCard(opportunity)
			)}
		</div>
	);
};

export default OpportunitiesSection;
