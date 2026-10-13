/**
 * Schema Deployment Component
 *
 * The Schema Manager › Deployment screen: one authoritative status card that
 * says whether schema is live, what is blocking it, and what to do next.
 *
 * The deployment state is derived once (see `getState`) rather than by
 * rendering each banner from its own independent condition — the previous
 * version could show "Updated Schema Ready for Deployment" and "Schema Not
 * Ready for Deployment" stacked on top of each other, and left Deploy enabled
 * while validation was failing.
 *
 * @package
 * @since 1.0.0
 */

import { __, sprintf, _n } from '@wordpress/i18n';
import { Card, CardHeader, Alert, Badge, Button } from '../../ui';

/* ── Icons ─────────────────────────────────────────────────────────────── */

const DeployIcon = (props) => (
	<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" {...props}>
		<path d="M10 2.5a.75.75 0 0 1 .53.22l3.5 3.5a.75.75 0 1 1-1.06 1.06l-2.22-2.22V13a.75.75 0 0 1-1.5 0V5.06L7.03 7.28a.75.75 0 0 1-1.06-1.06l3.5-3.5A.75.75 0 0 1 10 2.5Z" />
		<path d="M3.5 12.25a.75.75 0 0 1 .75.75v2a.5.5 0 0 0 .5.5h10.5a.5.5 0 0 0 .5-.5v-2a.75.75 0 0 1 1.5 0v2A2 2 0 0 1 15.25 17H4.75a2 2 0 0 1-2-2v-2a.75.75 0 0 1 .75-.75Z" />
	</svg>
);

const RegenerateIcon = (props) => (
	<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" {...props}>
		<path
			fillRule="evenodd"
			d="M15.312 5.312a7 7 0 0 0-11.62 2.63.75.75 0 1 1-1.415-.5 8.5 8.5 0 0 1 14.1-3.19V2.75a.75.75 0 0 1 1.5 0v3.5a.75.75 0 0 1-.75.75h-3.5a.75.75 0 0 1 0-1.5h1.685ZM3.023 12.5a.75.75 0 0 1 .958.457 7 7 0 0 0 11.62 2.63H13.92a.75.75 0 0 1 0-1.5h3.5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-1.5a8.5 8.5 0 0 1-14.1-3.19.75.75 0 0 1 .453-1.147Z"
			clipRule="evenodd"
		/>
	</svg>
);

/* ── Small building blocks ─────────────────────────────────────────────── */

/**
 * A single schema type's validation outcome.
 *
 * @param root0
 * @param root0.type
 * @param root0.score
 * @param root0.errors
 * @param root0.warnings
 */
const ValidationRow = ({ type, score, errors, warnings }) => {
	const hasErrors = errors.length > 0;
	const hasWarnings = warnings.length > 0;
	const tone = hasErrors ? 'poor' : hasWarnings ? 'warning' : 'good';

	const summary = hasErrors
		? sprintf(
				/* translators: %d: number of blocking errors */
				_n('%d error', '%d errors', errors.length, 'thinkrank'),
				errors.length
			)
		: hasWarnings
			? sprintf(
					/* translators: %d: number of recommendations */
					_n(
						'%d recommendation',
						'%d recommendations',
						warnings.length,
						'thinkrank'
					),
					warnings.length
				)
			: __('Valid', 'thinkrank');

	return (
		<div className="tr-py-3 tr-border-b tr-border-secondary last:tr-border-b-0">
			<div className="tr-flex tr-items-center tr-justify-between tr-gap-3">
				<span className="tr-text-sm tr-font-medium tr-text-primary">
					{type}
				</span>
				<div className="tr-flex tr-items-center tr-gap-2 tr-shrink-0">
					<span className="tr-text-xs tr-text-tertiary">
						{sprintf(
							/* translators: %d: validation score out of 100 */
							__('Score %d/100', 'thinkrank'),
							score
						)}
					</span>
					<Badge variant={tone}>{summary}</Badge>
				</div>
			</div>

			{hasErrors && (
				<ul className="tr-mt-2 tr-mb-0 tr-pl-5 tr-list-disc tr-text-xs tr-text-danger tr-space-y-1">
					{errors.map((error, i) => (
						<li key={i}>{error}</li>
					))}
				</ul>
			)}

			{hasWarnings && (
				<ul className="tr-mt-2 tr-mb-0 tr-pl-5 tr-list-disc tr-text-xs tr-text-warning tr-space-y-1">
					{warnings.map((warning, i) => (
						<li key={i}>{warning}</li>
					))}
				</ul>
			)}
		</div>
	);
};

/**
 * Labelled row of schema-type pills.
 *
 * @param root0
 * @param root0.label
 * @param root0.types
 * @param root0.variant
 */
const TypeList = ({ label, types, variant = 'info' }) => (
	<div>
		<p className="tr-text-xs tr-font-medium tr-text-tertiary tr-uppercase tr-tracking-wide tr-m-0 tr-mb-2">
			{label}
		</p>
		<div className="tr-flex tr-flex-wrap tr-gap-1.5">
			{types.map((type) => (
				<Badge key={type} variant={variant}>
					{type}
				</Badge>
			))}
		</div>
	</div>
);

/**
 * Key-order-independent serialisation, so a regenerated schema that differs
 * only in property order is not reported as an update.
 *
 * @param {*} value Any JSON-serialisable value.
 * @return {string} Stable string form.
 */
const stableStringify = (value) => {
	if (Array.isArray(value)) {
		return `[${value.map(stableStringify).join(',')}]`;
	}
	if (value && typeof value === 'object') {
		return `{${Object.keys(value)
			.sort()
			.map((k) => `${JSON.stringify(k)}:${stableStringify(value[k])}`)
			.join(',')}}`;
	}
	return JSON.stringify(value);
};

/* ── Component ─────────────────────────────────────────────────────────── */

/**
 * Schema Deployment Component
 * @param root0
 * @param root0.generatedSchemas
 * @param root0.deployedSchemas
 * @param root0.isDeploying
 * @param root0.onDeploySchema
 * @param root0.onGenerateSchema
 * @param root0.isGenerating
 * @param root0.settings
 */
const SchemaDeployment = ({
	generatedSchemas,
	deployedSchemas,
	isDeploying = false,
	onDeploySchema,
	onGenerateSchema,
	isGenerating = false,
	settings = {},
}) => {
	const generated = generatedSchemas?.generated_schemas || {};
	const validation = generatedSchemas?.validation_results || {};

	const generatedTypes = Object.keys(generated);
	const deployedTypes = Object.keys(deployedSchemas || {});

	const hasGenerated = generatedTypes.length > 0;
	const hasDeployed = deployedTypes.length > 0;
	const isReady = !!generatedSchemas?.deployment_ready;

	// Per-type validation outcomes, plus totals for the headline.
	const rows = generatedTypes.map((type) => {
		const result = validation[type] || {};
		return {
			type,
			score:
				typeof result.validation_score === 'number'
					? result.validation_score
					: 100,
			errors: result.errors || [],
			warnings: result.warnings || [],
		};
	});

	// A deploy leaves the generated set in state, so "pending" has to mean
	// "differs from what is live" rather than merely "generated".
	const isPublished =
		hasGenerated &&
		hasDeployed &&
		generatedTypes.slice().sort().join('|') ===
			deployedTypes.slice().sort().join('|') &&
		generatedTypes.every(
			(type) =>
				stableStringify(generated[type]) ===
				stableStringify(deployedSchemas[type]?.data)
		);

	const errorCount = rows.reduce((n, r) => n + r.errors.length, 0);
	const warningCount = rows.reduce((n, r) => n + r.warnings.length, 0);
	const blockedTypes = rows.filter((r) => r.errors.length > 0);

	/**
	 * One state drives the whole card, so the messages can never contradict.
	 */
	const getState = () => {
		if (!hasGenerated && !hasDeployed) return 'empty';
		if (hasGenerated && !isReady) return 'blocked';
		if (hasGenerated && isReady && hasDeployed)
			return isPublished ? 'live' : 'update';
		if (hasGenerated && isReady) return 'ready';
		return 'live';
	};

	const state = getState();
	const isDisabledFeature = settings.enabled === false;
	const canDeploy =
		(state === 'ready' || state === 'update') &&
		!isDisabledFeature &&
		!isDeploying;

	const STATUS = {
		empty: { variant: 'neutral', label: __('Not deployed', 'thinkrank') },
		blocked: {
			variant: 'poor',
			label: __('Needs attention', 'thinkrank'),
		},
		ready: {
			variant: 'warning',
			label: __('Ready to deploy', 'thinkrank'),
		},
		update: {
			variant: 'warning',
			label: __('Update available', 'thinkrank'),
		},
		live: { variant: 'good', label: __('Live', 'thinkrank') },
	};

	const DESCRIPTION = {
		empty: __(
			'Publish your structured data to the site as JSON-LD in the document head.',
			'thinkrank'
		),
		blocked: __(
			'Validation has to pass before this schema can be published.',
			'thinkrank'
		),
		ready: __(
			'Your schema is validated and ready to publish to the site.',
			'thinkrank'
		),
		update: __(
			'Newly generated schema is waiting to replace the version currently on your site.',
			'thinkrank'
		),
		live: __(
			'Your schema markup is published and served on the front end.',
			'thinkrank'
		),
	};

	const deployLabel =
		state === 'update' || state === 'live'
			? __('Redeploy Schema', 'thinkrank')
			: __('Deploy Schema', 'thinkrank');

	return (
		<div className="tab-content">
			<Card>
				<CardHeader
					title={__('Deployment', 'thinkrank')}
					description={DESCRIPTION[state]}
					action={
						<Badge variant={STATUS[state].variant}>
							{STATUS[state].label}
						</Badge>
					}
				/>

				{/* ── State message ─────────────────────────────────── */}
				{state === 'empty' && (
					<Alert
						variant="info"
						title={__('No schema generated yet', 'thinkrank')}
					>
						{__(
							'Generate your schema markup first, then come back here to publish it.',
							'thinkrank'
						)}
					</Alert>
				)}

				{state === 'blocked' && (
					<Alert
						variant="error"
						title={sprintf(
							/* translators: %1$s: error count phrase, %2$s: schema type list */
							__('%1$s blocking deployment in %2$s', 'thinkrank'),
							sprintf(
								/* translators: %d: number of blocking errors */
								_n(
									'%d error',
									'%d errors',
									errorCount,
									'thinkrank'
								),
								errorCount
							),
							blockedTypes.map((r) => r.type).join(', ')
						)}
					>
						{__(
							'Fix the required properties listed below, regenerate, and deploy again.',
							'thinkrank'
						)}
					</Alert>
				)}

				{state === 'ready' && (
					<Alert
						variant="success"
						title={__('Validated and ready', 'thinkrank')}
					>
						{warningCount > 0
							? sprintf(
									/* translators: %d: number of optional recommendations */
									_n(
										'All required properties are present. %d optional recommendation is listed below.',
										'All required properties are present. %d optional recommendations are listed below.',
										warningCount,
										'thinkrank'
									),
									warningCount
								)
							: __(
									'All required properties are present.',
									'thinkrank'
								)}
					</Alert>
				)}

				{state === 'update' && (
					<Alert
						variant="warning"
						title={__('Update ready to publish', 'thinkrank')}
					>
						{__(
							'The schema on your site is still the previous version until you redeploy.',
							'thinkrank'
						)}
					</Alert>
				)}

				{state === 'live' && (
					<Alert
						variant="success"
						title={__('Schema is live', 'thinkrank')}
					>
						{__(
							'Regenerate after changing your schema settings to pick up the new values.',
							'thinkrank'
						)}
					</Alert>
				)}

				{isDisabledFeature && (
					<Alert
						variant="warning"
						title={__('Schema Manager is disabled', 'thinkrank')}
						className="tr-mt-3"
					>
						{__(
							'Enable Schema Manager to deploy structured data to your site.',
							'thinkrank'
						)}
					</Alert>
				)}

				{/* ── What is live / what is staged ─────────────────── */}
				{(hasDeployed || hasGenerated) && (
					<div className="tr-flex tr-flex-wrap tr-gap-x-10 tr-gap-y-4 tr-mt-5">
						{hasDeployed && (
							<TypeList
								label={__('Currently live', 'thinkrank')}
								types={deployedTypes}
								variant="good"
							/>
						)}
						{hasGenerated && !isPublished && (
							<TypeList
								label={__(
									'Generated, pending deploy',
									'thinkrank'
								)}
								types={generatedTypes}
								variant={isReady ? 'info' : 'poor'}
							/>
						)}
					</div>
				)}

				{/* ── Validation breakdown ──────────────────────────── */}
				{hasGenerated && (errorCount > 0 || warningCount > 0) && (
					<>
						<p className="tr-text-sm tr-font-semibold tr-text-primary tr-mt-5 tr-mb-1">
							{__('Validation', 'thinkrank')}
						</p>
						<div>
							{rows.map((row) => (
								<ValidationRow key={row.type} {...row} />
							))}
						</div>
					</>
				)}

				{/* ── Actions ───────────────────────────────────────── */}
				<div className="tr-flex tr-flex-wrap tr-items-center tr-gap-3 tr-mt-5">
					{state !== 'empty' && (
						<Button
							variant="primary"
							icon={DeployIcon}
							onClick={onDeploySchema}
							loading={isDeploying}
							disabled={!canDeploy}
						>
							{isDeploying
								? __('Deploying…', 'thinkrank')
								: deployLabel}
						</Button>
					)}

					<Button
						variant={state === 'empty' ? 'primary' : 'secondary'}
						icon={RegenerateIcon}
						onClick={onGenerateSchema}
						loading={isGenerating}
						disabled={isGenerating}
					>
						{isGenerating
							? __('Generating…', 'thinkrank')
							: hasGenerated || hasDeployed
								? __('Regenerate Schema', 'thinkrank')
								: __('Generate Schema', 'thinkrank')}
					</Button>
				</div>

				<p className="tr-text-xs tr-text-tertiary tr-mt-3 tr-mb-0">
					{state === 'blocked'
						? __(
								'Deployment stays unavailable until every required property validates.',
								'thinkrank'
							)
						: __(
								'Schema is published as JSON-LD in the document head of your site.',
								'thinkrank'
							)}
				</p>
			</Card>
		</div>
	);
};

export default SchemaDeployment;
