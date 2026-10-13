/**
 * LLMs.txt Validation Component
 *
 * Handles LLMs.txt validation interface and results display following Schema Manager patterns.
 * Provides validation feedback, errors, warnings, and suggestions for LLMs.txt configuration.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Card, Button, Alert } from '../../ui';

// ---------------------------------------------------------------------------
// Inline SVG icons — no emoji, no external icon library
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// LLMs.txt Validation Component
// ---------------------------------------------------------------------------

const LLMsTxtValidation = ({
	validationResults,
	isValidating,
	onValidate,
	settings,
	fileStatus,
}) => {
	// -----------------------------------------------------------------------
	// Status badge (valid / warning / error)
	// -----------------------------------------------------------------------
	const renderStatusBadge = (valid) => {
		if (valid) {
			return (
				<span className="tr-inline-flex tr-items-center tr-gap-1.5 tr-text-xs tr-font-medium tr-px-2.5 tr-py-1 tr-rounded-full tr-border tr-bg-success-bg tr-border-success tr-text-success">
					<IconCheck />
					{__('Valid', 'thinkrank')}
				</span>
			);
		}
		return (
			<span className="tr-inline-flex tr-items-center tr-gap-1.5 tr-text-xs tr-font-medium tr-px-2.5 tr-py-1 tr-rounded-full tr-border tr-bg-danger-bg tr-border-danger tr-text-danger">
				<IconX />
				{__('Invalid', 'thinkrank')}
			</span>
		);
	};

	// -----------------------------------------------------------------------
	// Score badge (coloured pill)
	// -----------------------------------------------------------------------
	const renderScoreBadge = (score) => {
		let colorClass = 'tr-bg-success';
		let label = __('Excellent', 'thinkrank');

		if (score < 60) {
			colorClass = 'tr-bg-danger';
			label = __('Poor', 'thinkrank');
		} else if (score < 80) {
			colorClass = 'tr-bg-warning';
			label = __('Good', 'thinkrank');
		}

		return (
			<span
				className={`tr-text-xs tr-font-bold tr-px-2 tr-py-1 tr-rounded tr-text-white ${colorClass}`}
			>
				{score}/100 · {label}
			</span>
		);
	};

	// -----------------------------------------------------------------------
	// Check row helpers
	// -----------------------------------------------------------------------
	const CheckRowSuccess = ({ label, detail }) => (
		<div className="tr-flex tr-items-start tr-gap-2 tr-px-3 tr-py-2 tr-rounded tr-text-xs tr-bg-success-bg tr-text-success">
			<span className="tr-shrink-0 tr-mt-px">
				<IconCheck />
			</span>
			<span>
				<strong>{label}</strong>
				{detail ? `: ${detail}` : ''}
			</span>
		</div>
	);

	const CheckRowWarning = ({ label, detail }) => (
		<div className="tr-flex tr-items-start tr-gap-2 tr-px-3 tr-py-2 tr-rounded tr-text-xs tr-bg-warning-bg tr-text-warning">
			<span className="tr-shrink-0 tr-mt-px">
				<IconWarning />
			</span>
			<span>
				<strong>{label}</strong>
				{detail ? `: ${detail}` : ''}
			</span>
		</div>
	);

	const CheckRowError = ({ label, detail }) => (
		<div className="tr-flex tr-items-start tr-gap-2 tr-px-3 tr-py-2 tr-rounded tr-text-xs tr-bg-danger-bg tr-text-danger">
			<span className="tr-shrink-0 tr-mt-px">
				<IconX />
			</span>
			<span>
				<strong>{label}</strong>
				{detail ? `: ${detail}` : ''}
			</span>
		</div>
	);

	// -----------------------------------------------------------------------
	// Detailed validation breakdown
	// -----------------------------------------------------------------------
	const renderDetailedValidation = (validation) => {
		const hasErrors = validation.errors && validation.errors.length > 0;
		const hasWarnings =
			validation.warnings && validation.warnings.length > 0;

		const confirmations = (validation.suggestions || []).filter((s) =>
			s.startsWith('✓')
		);
		const actualSuggestions = (validation.suggestions || []).filter(
			(s) => !s.startsWith('✓')
		);

		return (
			<div className="tr-flex tr-flex-col tr-gap-3">
				{/* Required Fields */}
				{hasErrors ? (
					<div className="tr-flex tr-flex-col tr-gap-1">
						<CheckRowError
							label={__('Required Fields', 'thinkrank')}
							detail={`${validation.errors.length} ${__('missing', 'thinkrank')}`}
						/>
						<div className="tr-flex tr-flex-col tr-gap-1 tr-pl-4">
							{validation.errors.map((error, index) => (
								<div
									key={index}
									className="tr-flex tr-items-start tr-gap-2 tr-px-3 tr-py-2 tr-rounded tr-text-xs tr-bg-danger-bg tr-text-danger"
								>
									<span className="tr-shrink-0 tr-mt-px">
										<IconX />
									</span>
									<span>{error}</span>
								</div>
							))}
						</div>
					</div>
				) : (
					<CheckRowSuccess
						label={__('Required Fields', 'thinkrank')}
						detail={__('Complete', 'thinkrank')}
					/>
				)}

				{/* Content Quality */}
				{hasWarnings ? (
					<div className="tr-flex tr-flex-col tr-gap-1">
						<CheckRowWarning
							label={__('Content Quality', 'thinkrank')}
							detail={`${validation.warnings.length} ${__('improvements', 'thinkrank')}`}
						/>
						<div className="tr-flex tr-flex-col tr-gap-1 tr-pl-4">
							{validation.warnings.map((warning, index) => (
								<div
									key={index}
									className="tr-flex tr-items-start tr-gap-2 tr-px-3 tr-py-2 tr-rounded tr-text-xs tr-bg-warning-bg tr-text-warning"
								>
									<span className="tr-shrink-0 tr-mt-px">
										<IconWarning />
									</span>
									<span>{warning}</span>
								</div>
							))}
						</div>
					</div>
				) : (
					<CheckRowSuccess
						label={__('Content Quality', 'thinkrank')}
						detail={__('Optimized', 'thinkrank')}
					/>
				)}

				{/* File Permissions */}
				{fileStatus?.writable ? (
					<CheckRowSuccess
						label={__('File Permissions', 'thinkrank')}
						detail={__('Writable', 'thinkrank')}
					/>
				) : (
					<CheckRowError
						label={__('File Permissions', 'thinkrank')}
						detail={__('Not writable', 'thinkrank')}
					/>
				)}

				{/* LLMs.txt Standards */}
				<CheckRowSuccess
					label={__('LLMs.txt Standards', 'thinkrank')}
					detail={__('Compliant', 'thinkrank')}
				/>

				{/* Configuration Status — confirmations starting with ✓ */}
				{confirmations.length > 0 && (
					<div>
						<p className="tr-text-xs tr-font-semibold tr-text-secondary tr-uppercase tr-tracking-wide tr-m-0 tr-mb-2">
							{__('Configuration Status', 'thinkrank')}
						</p>
						<div className="tr-flex tr-flex-col tr-gap-1">
							{confirmations.map((item, index) => (
								<div
									key={index}
									className="tr-flex tr-items-start tr-gap-2 tr-px-3 tr-py-2 tr-rounded tr-text-xs tr-bg-success-bg tr-text-success"
								>
									<span className="tr-shrink-0 tr-mt-px">
										<IconCheck />
									</span>
									<span>{item}</span>
								</div>
							))}
						</div>
					</div>
				)}

				{/* Suggestions for Improvement */}
				{actualSuggestions.length > 0 && (
					<div>
						<p className="tr-text-xs tr-font-semibold tr-text-secondary tr-uppercase tr-tracking-wide tr-m-0 tr-mb-2">
							{__('Suggestions for Improvement', 'thinkrank')}
						</p>
						<div className="tr-flex tr-flex-col tr-gap-1">
							{actualSuggestions.map((suggestion, index) => (
								<div
									key={index}
									className="tr-flex tr-items-start tr-gap-2 tr-px-3 tr-py-2 tr-rounded tr-text-xs tr-bg-brand-bg tr-text-brand"
								>
									<span className="tr-shrink-0 tr-mt-px">
										<IconInfo />
									</span>
									<span>{suggestion}</span>
								</div>
							))}
						</div>
					</div>
				)}
			</div>
		);
	};

	// -----------------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------------
	return (
		<Card padding={false}>
			{/* Card header */}
			<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary tr-flex tr-items-center tr-justify-between">
				<h3 className="tr-text-base tr-font-semibold tr-text-primary tr-m-0">
					{__('LLMs.txt Validation', 'thinkrank')}
				</h3>
				<div className="tr-flex tr-items-center tr-gap-3">
					{validationResults &&
						renderStatusBadge(validationResults.valid)}
					{validationResults &&
						validationResults.score !== undefined &&
						renderScoreBadge(validationResults.score)}
					<Button
						variant="secondary"
						size="sm"
						onClick={onValidate}
						disabled={isValidating || !settings.enabled}
						loading={isValidating}
					>
						{isValidating
							? __('Validating…', 'thinkrank')
							: __('Validate Configuration', 'thinkrank')}
					</Button>
				</div>
			</div>

			{/* Card body */}
			<div className="tr-p-5 tr-flex tr-flex-col tr-gap-5">
				{!settings.enabled && (
					<Alert variant="warning">
						{__(
							'LLMs.txt is disabled. Enable it to run validation.',
							'thinkrank'
						)}
					</Alert>
				)}

				{!validationResults ? (
					<div className="tr-bg-white tr-border tr-border-secondary tr-rounded tr-p-4 tr-text-sm tr-text-tertiary">
						{__(
							'Click "Validate Configuration" to check your LLMs.txt setup for errors and optimization opportunities.',
							'thinkrank'
						)}
					</div>
				) : (
					renderDetailedValidation(validationResults)
				)}
			</div>
		</Card>
	);
};

export default LLMsTxtValidation;
