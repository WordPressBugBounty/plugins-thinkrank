/**
 * Diagnostics Section
 *
 * Summary stat cards and two-column diagnostic grid.
 *
 * @package
 * @since 1.0.0 (refactored 1.11.0)
 */

import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { SkeletonCard, EmptyState } from '../../ui';
import PerformanceErrorNotice from './PerformanceErrorNotice';

/**
 * Render a single diagnostic card
 * @param diagnostic
 */
const renderDiagnosticCard = (diagnostic) => {
	const statusConfig = {
		passed: {
			label: __('Passed', 'thinkrank'),
			color: '#027A48',
			bg: '#dcfce7',
		},
		warning: {
			label: __('Warnings', 'thinkrank'),
			color: '#ea580c',
			bg: '#fff7ed',
		},
		failed: {
			label: __('Failed', 'thinkrank'),
			color: '#921C13',
			bg: '#fee2e2',
		},
	};
	const cfg = statusConfig[diagnostic.status] || statusConfig.warning;

	const hasDetails = !!diagnostic.details;
	const detailText =
		hasDetails && typeof diagnostic.details === 'object'
			? diagnostic.details.items
				? `${diagnostic.details.items.length} items to review.`
				: null
			: diagnostic.details || null;

	return (
		<div key={diagnostic.id} className="thinkrank-diag-card">
			<div className="thinkrank-diag-card-header">
				<div className="thinkrank-diag-card-title-row">
					<span className="thinkrank-diag-card-title">
						{diagnostic.title}
					</span>
					<span
						className="thinkrank-diag-badge"
						style={{ color: cfg.color, background: cfg.bg }}
					>
						{cfg.label}
					</span>
				</div>
				{(diagnostic.category || diagnostic.impact) && (
					<span className="thinkrank-diag-category">
						{diagnostic.category || diagnostic.impact}
					</span>
				)}
			</div>
			<p className="thinkrank-diag-desc">
				{diagnostic.description}
				{detailText && <> {detailText}</>}
				{hasDetails && (
					<strong>
						{__(
							' Check page speed report for detailed data.',
							'thinkrank'
						)}
					</strong>
				)}
			</p>
		</div>
	);
};

/**
 * DiagnosticsSection Component
 * @param root0
 * @param root0.diagnostics
 * @param root0.isLoadingDiagnostics
 * @param root0.diagnosticsError
 * @param root0.onNavigate
 * @param root0.onRetry
 */
const DiagnosticsSection = ({
	diagnostics,
	isLoadingDiagnostics,
	diagnosticsError,
	onNavigate,
	onRetry,
}) => {
	if (isLoadingDiagnostics) {
		return (
			<div className="tr-empty-state">
				<SkeletonCard lines={3} />
			</div>
		);
	}

	if (diagnosticsError) {
		return (
			<PerformanceErrorNotice
				error={diagnosticsError}
				onNavigate={onNavigate}
				onRetry={onRetry}
				isRetrying={isLoadingDiagnostics}
			/>
		);
	}

	if (diagnostics.length === 0) {
		return (
			<div className="thinkrank-cwv-wrapper">
				<EmptyState
					size="md"
					title={__('No Diagnostic Data Available', 'thinkrank')}
					description={__(
						'Add a PageSpeed API key or connect your Google account to view detailed performance diagnostics.',
						'thinkrank'
					)}
					action={
						<div className="tr-flex tr-justify-center tr-gap-3">
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
								disabled={isLoadingDiagnostics}
							>
								{isLoadingDiagnostics
									? __('Retrying…', 'thinkrank')
									: __('Retry', 'thinkrank')}
							</Button>
						</div>
					}
				/>
			</div>
		);
	}

	const passedCount = diagnostics.filter((d) => d.status === 'passed').length;
	const warningCount = diagnostics.filter(
		(d) => d.status === 'warning'
	).length;
	const failedCount = diagnostics.filter((d) => d.status === 'failed').length;

	return (
		<div className="thinkrank-cwv-wrapper">
			<div className="thinkrank-diag-wrap">
				<div className="thinkrank-diag-stats">
					<div className="thinkrank-diag-stat-card">
						<div className="thinkrank-diag-stat-top">
							<span className="thinkrank-diag-stat-label">
								{__('Passed', 'thinkrank')}
							</span>
							<span className="thinkrank-diag-stat-icon">
								<svg
									width="22"
									height="22"
									viewBox="0 0 22 22"
									fill="none"
								>
									<circle
										cx="11"
										cy="11"
										r="9"
										stroke="#4353cc"
										strokeWidth="1.8"
									/>
									<path
										d="M7 11.5l3 3 5-6"
										stroke="#4353cc"
										strokeWidth="1.8"
										strokeLinecap="round"
										strokeLinejoin="round"
									/>
								</svg>
							</span>
						</div>
						<span className="thinkrank-diag-stat-count">
							{passedCount}
						</span>
					</div>
					<div className="thinkrank-diag-stat-card">
						<div className="thinkrank-diag-stat-top">
							<span className="thinkrank-diag-stat-label">
								{__('Warnings', 'thinkrank')}
							</span>
							<span className="thinkrank-diag-stat-icon">
								<svg
									width="22"
									height="22"
									viewBox="0 0 22 22"
									fill="none"
								>
									<path
										d="M11 3L2 19h18L11 3z"
										stroke="#4353cc"
										strokeWidth="1.8"
										strokeLinejoin="round"
									/>
									<path
										d="M11 9v5"
										stroke="#4353cc"
										strokeWidth="1.8"
										strokeLinecap="round"
									/>
									<circle
										cx="11"
										cy="16"
										r="1"
										fill="#4353cc"
									/>
								</svg>
							</span>
						</div>
						<span className="thinkrank-diag-stat-count">
							{warningCount}
						</span>
					</div>
					<div className="thinkrank-diag-stat-card">
						<div className="thinkrank-diag-stat-top">
							<span className="thinkrank-diag-stat-label">
								{__('Failed', 'thinkrank')}
							</span>
							<span className="thinkrank-diag-stat-icon">
								<svg
									width="22"
									height="22"
									viewBox="0 0 22 22"
									fill="none"
								>
									<circle
										cx="11"
										cy="11"
										r="9"
										stroke="#4353cc"
										strokeWidth="1.8"
									/>
									<path
										d="M8 8l6 6M14 8l-6 6"
										stroke="#4353cc"
										strokeWidth="1.8"
										strokeLinecap="round"
									/>
								</svg>
							</span>
						</div>
						<span className="thinkrank-diag-stat-count">
							{failedCount}
						</span>
					</div>
				</div>

				<div className="thinkrank-diag-grid">
					{diagnostics.map((d) => renderDiagnosticCard(d))}
				</div>
			</div>
		</div>
	);
};

export default DiagnosticsSection;
