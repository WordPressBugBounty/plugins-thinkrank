/**
 * Site Identity Validation Component
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Card } from '../../ui';

const IconCheck = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<polyline points="20 6 9 17 4 12" />
	</svg>
);

const IconX = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<line x1="18" y1="6" x2="6" y2="18" />
		<line x1="6" y1="6" x2="18" y2="18" />
	</svg>
);

const IconWarning = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
		<line x1="12" y1="9" x2="12" y2="13" />
		<line x1="12" y1="17" x2="12.01" y2="17" />
	</svg>
);

const IconInfo = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<circle cx="12" cy="12" r="10" />
		<line x1="12" y1="16" x2="12" y2="12" />
		<line x1="12" y1="8" x2="12.01" y2="8" />
	</svg>
);

const SiteIdentityValidation = ({ validationResults, activeSubSection }) => {
	const noValidationTabs = ['robots-txt'];
	if (!validationResults || noValidationTabs.includes(activeSubSection))
		return null;

	const calculateCompletion = () => {
		if (
			!validationResults.field_details ||
			validationResults.field_details.length === 0
		) {
			return { configured: 0, total: 0, percentage: 0 };
		}
		const total = validationResults.field_details.length;
		const valid = validationResults.field_details.filter(
			(f) => f.status === 'valid'
		).length;
		return {
			configured: valid,
			total,
			percentage: total > 0 ? Math.round((valid / total) * 100) : 0,
		};
	};

	const completion = calculateCompletion();
	const hasErrors =
		validationResults.errors && validationResults.errors.length > 0;
	const hasWarnings =
		validationResults.warnings && validationResults.warnings.length > 0;
	const hasSuggestions =
		validationResults.suggestions &&
		validationResults.suggestions.length > 0;
	const isValid = !hasErrors;

	const statusConfig =
		isValid && !hasWarnings
			? {
					label: __('Validation Passed', 'thinkrank'),
					bg: 'tr-bg-success-bg',
					border: 'tr-border-success',
					text: 'tr-text-success',
					icon: <IconCheck />,
					iconBg: 'tr-bg-success-bg tr-text-success',
					bar: 'tr-bg-success',
				}
			: hasWarnings && isValid
				? {
						label: __('Passed with Warnings', 'thinkrank'),
						bg: 'tr-bg-warning-bg',
						border: 'tr-border-warning',
						text: 'tr-text-warning',
						icon: <IconWarning />,
						iconBg: 'tr-bg-warning-bg tr-text-warning',
						bar: 'tr-bg-warning',
					}
				: {
						label: __('Validation Failed', 'thinkrank'),
						bg: 'tr-bg-danger-bg',
						border: 'tr-border-danger',
						text: 'tr-text-danger',
						icon: <IconX />,
						iconBg: 'tr-bg-danger-bg tr-text-danger',
						bar: 'tr-bg-danger',
					};

	return (
		<Card padding={false} className="tr-mt-4">
			{/* Header */}
			<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary tr-flex tr-items-center tr-justify-between">
				<div>
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Form Data Validation', 'thinkrank')}
					</h3>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Field-by-field validation results for this section',
							'thinkrank'
						)}
					</p>
				</div>
				{/* Status badge */}
				<span
					className={`tr-inline-flex tr-items-center tr-gap-1.5 tr-text-xs tr-font-medium tr-px-2.5 tr-py-1 tr-rounded-full tr-border ${statusConfig.bg} ${statusConfig.border} ${statusConfig.text}`}
				>
					<span
						className={`tr-inline-flex tr-items-center tr-justify-center tr-w-4 tr-h-4 tr-rounded-full ${statusConfig.iconBg}`}
					>
						{statusConfig.icon}
					</span>
					{statusConfig.label}
				</span>
			</div>

			<div className="tr-p-5 tr-flex tr-flex-col tr-gap-5">
				{/* Completion progress */}
				{completion.total > 0 && (
					<div>
						<div className="tr-flex tr-items-center tr-justify-between tr-mb-1.5">
							<span className="tr-text-xs tr-font-medium tr-text-secondary">
								{__('Completion', 'thinkrank')}
							</span>
							<span className="tr-text-xs tr-font-semibold tr-text-primary">
								{completion.configured}/{completion.total}{' '}
								{__('fields', 'thinkrank')}:{' '}
								{completion.percentage}%
							</span>
						</div>
						<div className="tr-w-full tr-bg-white tr-rounded-full tr-h-2">
							<div
								className={`tr-h-2 tr-rounded-full tr-transition-all ${statusConfig.bar}`}
								style={{ width: `${completion.percentage}%` }}
							/>
						</div>
					</div>
				)}

				{/* Field-by-field breakdown */}
				{validationResults.field_details &&
					validationResults.field_details.length > 0 && (
						<div>
							<p className="tr-text-xs tr-font-semibold tr-text-secondary tr-uppercase tr-tracking-wide tr-m-0 tr-mb-2">
								{__('Field Details', 'thinkrank')}
							</p>
							<div className="tr-grid tr-grid-cols-2 tr-gap-1.5">
								{validationResults.field_details.map(
									(field, index) => {
										const isFieldValid =
											field.status === 'valid';
										const isFieldError =
											field.status === 'error';
										return (
											<div
												key={index}
												className={`tr-flex tr-items-center tr-gap-2 tr-px-3 tr-py-2 tr-rounded tr-text-xs ${
													isFieldValid
														? 'tr-bg-success-bg tr-text-success'
														: isFieldError
															? 'tr-bg-danger-bg tr-text-danger'
															: 'tr-bg-warning-bg tr-text-warning'
												}`}
											>
												<span className="tr-shrink-0">
													{isFieldValid ? (
														<IconCheck />
													) : isFieldError ? (
														<IconX />
													) : (
														<IconWarning />
													)}
												</span>
												<span className="tr-font-medium">
													{field.label}
												</span>
											</div>
										);
									}
								)}
							</div>
						</div>
					)}

				{/* Fallback when no field_details */}
				{(!validationResults.field_details ||
					validationResults.field_details.length === 0) && (
					<>
						{isValid && !hasWarnings && (
							<div className="tr-flex tr-flex-col tr-gap-1.5">
								{[
									__(
										'All required fields are properly configured.',
										'thinkrank'
									),
									__(
										'Site identity settings are valid and complete.',
										'thinkrank'
									),
									__(
										'Ready for deployment and search engine optimization.',
										'thinkrank'
									),
								].map((msg, i) => (
									<div
										key={i}
										className="tr-flex tr-items-center tr-gap-2 tr-text-xs tr-text-success"
									>
										<IconCheck />
										<span>{msg}</span>
									</div>
								))}
							</div>
						)}

						{hasErrors && (
							<div className="tr-flex tr-flex-col tr-gap-1.5">
								{validationResults.errors.map((error, i) => (
									<div
										key={i}
										className="tr-flex tr-items-start tr-gap-2 tr-text-xs tr-text-danger"
									>
										<span className="tr-shrink-0 tr-mt-0.5">
											<IconX />
										</span>
										<span>{error}</span>
									</div>
								))}
							</div>
						)}
					</>
				)}

				{/* Warnings */}
				{hasWarnings && (
					<div>
						<p className="tr-text-xs tr-font-semibold tr-text-secondary tr-uppercase tr-tracking-wide tr-m-0 tr-mb-2">
							{__('Warnings', 'thinkrank')}
						</p>
						<div className="tr-flex tr-flex-col tr-gap-1.5">
							{validationResults.warnings.map((w, i) => (
								<div
									key={i}
									className="tr-flex tr-items-start tr-gap-2 tr-text-xs tr-text-warning tr-bg-warning-bg tr-rounded tr-px-3 tr-py-2"
								>
									<span className="tr-shrink-0 tr-mt-0.5">
										<IconWarning />
									</span>
									<span>{w}</span>
								</div>
							))}
						</div>
					</div>
				)}

				{/* Suggestions */}
				{hasSuggestions && (
					<div>
						<p className="tr-text-xs tr-font-semibold tr-text-secondary tr-uppercase tr-tracking-wide tr-m-0 tr-mb-2">
							{__('Recommendations', 'thinkrank')}
						</p>
						<div className="tr-flex tr-flex-col tr-gap-1.5">
							{validationResults.suggestions.map((s, i) => (
								<div
									key={i}
									className="tr-flex tr-items-start tr-gap-2 tr-text-xs tr-text-brand tr-bg-brand-bg tr-rounded tr-px-3 tr-py-2"
								>
									<span className="tr-shrink-0 tr-mt-0.5">
										<IconInfo />
									</span>
									<span>{s}</span>
								</div>
							))}
						</div>
					</div>
				)}
			</div>
		</Card>
	);
};

export default SiteIdentityValidation;
