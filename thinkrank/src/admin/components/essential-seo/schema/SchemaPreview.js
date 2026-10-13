/**
 * Schema Preview Component
 *
 * Shows what the schema generator produced: the types it built, anything the
 * validator flagged, and the JSON-LD itself. Rendered only on the Deployment
 * screen — the per-type screens (Organization, Person, …) are for editing one
 * type, and repeating the whole generated payload under each of them buried
 * the form the user came for.
 *
 * @package
 * @since 1.0.0
 */

import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Badge, Button, Card, CardHeader } from '../../ui';

/* ── Icons ──────────────────────────────────────────────────────────────── */

const IconCopy = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<rect x="9" y="9" width="13" height="13" rx="2" />
		<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
	</svg>
);

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
		aria-hidden="true"
	>
		<polyline points="20 6 9 17 4 12" />
	</svg>
);

const IconChevron = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<polyline points="9 18 15 12 9 6" />
	</svg>
);

/* ── Helpers ────────────────────────────────────────────────────────────── */

/**
 * Recommendations arrive as plain strings or as objects carrying a message.
 * A validation failure is a different thing from a "you could also add…"
 * hint, so they are split rather than listed together.
 *
 * @param {Array} recommendations Raw recommendation list.
 * @return {{errors: string[], hints: string[]}} Split by severity.
 */
const splitRecommendations = (recommendations) => {
	const errors = [];
	const hints = [];

	(recommendations || []).forEach((rec) => {
		const text =
			typeof rec === 'string' ? rec : rec?.message || JSON.stringify(rec);

		const severity =
			typeof rec === 'object' ? rec?.severity || rec?.type : '';
		const looksLikeFailure =
			severity === 'error' ||
			/\b(failed|invalid|error|missing required)\b/i.test(text);

		(looksLikeFailure ? errors : hints).push(text);
	});

	return { errors, hints };
};

/* ── Copy-to-clipboard button ───────────────────────────────────────────── */

const CopyButton = ({ value, label }) => {
	const [copied, setCopied] = useState(false);

	const copy = async () => {
		try {
			await navigator.clipboard.writeText(value);
		} catch (e) {
			// Clipboard API needs a secure context and permission; fall back to
			// the legacy path so http:// installs are not left without copy.
			const ta = document.createElement('textarea');
			ta.value = value;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.select();
			document.execCommand('copy');
			document.body.removeChild(ta);
		}
		setCopied(true);
		window.setTimeout(() => setCopied(false), 2000);
	};

	return (
		<button
			type="button"
			onClick={(e) => {
				// Inside a <summary>; don't toggle the section when copying.
				e.preventDefault();
				e.stopPropagation();
				copy();
			}}
			aria-label={label}
			className={`tr-inline-flex tr-items-center tr-gap-1.5 tr-px-2.5 tr-py-1 tr-rounded tr-text-xs tr-font-medium tr-border tr-cursor-pointer tr-transition-colors tr-duration-150 ${
				copied
					? 'tr-bg-success-bg tr-text-success tr-border-success'
					: 'tr-bg-white tr-text-secondary tr-border-secondary hover:tr-text-brand hover:tr-border-brand'
			}`}
		>
			{copied ? <IconCheck /> : <IconCopy />}
			{copied ? __('Copied', 'thinkrank') : __('Copy', 'thinkrank')}
		</button>
	);
};

/* ── One collapsible schema block ───────────────────────────────────────── */

const SchemaBlock = ({ schemaType, schemaData, defaultOpen }) => {
	const json = JSON.stringify(schemaData, null, 2);
	const lineCount = json.split('\n').length;

	return (
		<details
			open={defaultOpen}
			className="tr-group tr-border tr-border-secondary tr-rounded-lg tr-overflow-hidden"
		>
			<summary className="tr-flex tr-items-center tr-gap-2 tr-px-4 tr-py-2.5 tr-bg-white tr-cursor-pointer tr-list-none marker:tr-hidden">
				<span className="tr-text-tertiary tr-transition-transform tr-duration-150 group-open:tr-rotate-90">
					<IconChevron />
				</span>
				<span className="tr-text-sm tr-font-medium tr-text-primary">
					{schemaType}
				</span>
				<span className="tr-text-xs tr-text-tertiary">
					{sprintf(
						/* translators: %d: number of lines of JSON-LD. */
						_n('%d line', '%d lines', lineCount, 'thinkrank'),
						lineCount
					)}
				</span>
				<span className="tr-ml-auto">
					<CopyButton
						value={json}
						label={sprintf(
							/* translators: %s: schema type, e.g. "Organization". */
							__('Copy %s schema', 'thinkrank'),
							schemaType
						)}
					/>
				</span>
			</summary>
			<pre className="tr-m-0 tr-px-4 tr-py-3 tr-bg-[#FAFBFC] tr-border-t tr-border-secondary tr-text-xs tr-leading-relaxed tr-text-primary tr-overflow-auto tr-max-h-80 tr-whitespace-pre-wrap tr-break-words tr-font-mono">
				{json}
			</pre>
		</details>
	);
};

/* ── Main component ─────────────────────────────────────────────────────── */

/**
 * Schema Preview Component
 *
 * @param {Object}   root0                     Props.
 * @param {Object}   root0.generatedSchemas    Result of the last generate call.
 * @param {Object}   root0.schemaPreview       Rich-snippet preview payload.
 * @param {boolean}  root0.isGeneratingPreview A preview request is in flight.
 * @param {Function} root0.onGeneratePreview   Re-request the rich-snippet preview.
 */
const SchemaPreview = ({
	generatedSchemas,
	schemaPreview,
	isGeneratingPreview = false,
	onGeneratePreview,
}) => {
	if (!generatedSchemas) {
		return null;
	}

	const schemas = generatedSchemas.generated_schemas || {};
	const types = Object.keys(schemas);
	const { errors, hints } = splitRecommendations(
		generatedSchemas.optimization_recommendations
	);

	const allJson = JSON.stringify(
		types.length === 1 ? schemas[types[0]] : Object.values(schemas),
		null,
		2
	);

	return (
		<>
			<Card className="tr-mt-6">
				<CardHeader
					title={__('Generated Schema Markup', 'thinkrank')}
					description={__(
						'What the generator produced from your current settings.',
						'thinkrank'
					)}
					action={
						types.length > 0 && (
							<CopyButton
								value={allJson}
								label={__('Copy all schema', 'thinkrank')}
							/>
						)
					}
				/>

				{/* ── Types produced ─────────────────────────────── */}
				{types.length > 0 && (
					<div className="tr-flex tr-flex-wrap tr-items-center tr-gap-2 tr-mb-5">
						<span className="tr-text-xs tr-font-medium tr-text-tertiary tr-uppercase tr-tracking-wide">
							{__('Types', 'thinkrank')}
						</span>
						{types.map((schemaType) => (
							<Badge key={schemaType} variant="info">
								{schemaType}
							</Badge>
						))}
						{generatedSchemas.deployment_ready && (
							<Badge variant="good" className="tr-ml-auto">
								{__('Ready to deploy', 'thinkrank')}
							</Badge>
						)}
					</div>
				)}

				{/* ── Validation failures ────────────────────────── */}
				{errors.length > 0 && (
					<div className="tr-mb-4 tr-rounded-lg tr-border tr-border-danger tr-bg-danger-bg tr-px-4 tr-py-3">
						<p className="tr-text-xs tr-font-semibold tr-text-danger tr-m-0 tr-mb-1.5">
							{sprintf(
								/* translators: %d: number of validation problems. */
								_n(
									'%d problem to fix',
									'%d problems to fix',
									errors.length,
									'thinkrank'
								),
								errors.length
							)}
						</p>
						<ul className="tr-m-0 tr-pl-4 tr-text-sm tr-text-danger">
							{errors.map((text, i) => (
								<li key={i}>{text}</li>
							))}
						</ul>
					</div>
				)}

				{/* ── Soft recommendations ───────────────────────── */}
				{hints.length > 0 && (
					<div className="tr-mb-4 tr-rounded-lg tr-border tr-border-secondary tr-bg-white tr-px-4 tr-py-3">
						<p className="tr-text-xs tr-font-semibold tr-text-secondary tr-m-0 tr-mb-1.5">
							{__('Recommended additions', 'thinkrank')}
						</p>
						<ul className="tr-m-0 tr-pl-4 tr-text-sm tr-text-tertiary">
							{hints.map((text, i) => (
								<li key={i}>{text}</li>
							))}
						</ul>
					</div>
				)}

				{/* ── The markup itself ──────────────────────────── */}
				{types.length > 0 && (
					<div className="tr-flex tr-flex-col tr-gap-2">
						{types.map((schemaType, index) => (
							<SchemaBlock
								key={schemaType}
								schemaType={schemaType}
								schemaData={schemas[schemaType]}
								defaultOpen={index === 0}
							/>
						))}
					</div>
				)}
			</Card>

			{/* ── Rich snippets preview ──────────────────────────────
			    Rendered whether or not a preview has been built yet. It used
			    to appear only after clicking, with the button sitting in the
			    card above — so the control was nowhere near what it produced
			    and its effect could land below the fold. */}
			<Card className="tr-mt-6">
				<CardHeader
					title={__('Rich Snippets Preview', 'thinkrank')}
					description={__(
						'How your content might appear in search results with this markup.',
						'thinkrank'
					)}
					action={
						<Button
							variant="secondary"
							size="sm"
							onClick={onGeneratePreview}
							loading={isGeneratingPreview}
							disabled={isGeneratingPreview || types.length === 0}
						>
							{schemaPreview
								? __('Refresh Preview', 'thinkrank')
								: __('Generate Preview', 'thinkrank')}
						</Button>
					}
				/>
				{schemaPreview ? (
					<RichSnippets schemaPreview={schemaPreview} />
				) : (
					<div className="tr-rounded-lg tr-border tr-border-dashed tr-border-primary tr-px-4 tr-py-6 tr-text-center">
						<p className="tr-text-sm tr-text-tertiary tr-m-0">
							{types.length === 0
								? __(
										'Generate schema first, then preview how it appears in search results.',
										'thinkrank'
									)
								: __(
										'Preview how this markup appears in a Google search result.',
										'thinkrank'
									)}
						</p>
					</div>
				)}
			</Card>
		</>
	);
};

/* ── Rich snippet previews ──────────────────────────────────────────────── */

const RichSnippets = ({ schemaPreview }) => {
	const snippets = schemaPreview.rich_snippets || {};
	const keys = Object.keys(snippets);

	if (keys.length === 0) {
		return (
			<div className="tr-rounded-lg tr-border tr-border-dashed tr-border-primary tr-px-4 tr-py-6 tr-text-center">
				<p className="tr-text-sm tr-text-tertiary tr-m-0">
					{__(
						'No rich snippets preview available. Generate schema markup first.',
						'thinkrank'
					)}
				</p>
			</div>
		);
	}

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{keys.map((schemaType) => {
				let data = snippets[schemaType];

				// Some entries nest the real preview one level down.
				if (
					data &&
					typeof data === 'object' &&
					!data.title &&
					!data.url &&
					!data.description
				) {
					const nested = Object.keys(data);
					if (nested.length > 0) {
						data = data[nested[0]];
					}
				}

				const asText = (v) =>
					typeof v === 'string' ? v : JSON.stringify(v);

				return (
					<div key={schemaType}>
						<p className="tr-text-xs tr-font-medium tr-text-tertiary tr-m-0 tr-mb-1.5">
							{schemaType}
						</p>

						{!data || typeof data !== 'object' ? (
							<div className="tr-rounded-lg tr-border tr-border-secondary tr-px-4 tr-py-3 tr-text-sm tr-text-tertiary tr-text-center">
								{__(
									'Preview data not available for this schema type.',
									'thinkrank'
								)}
							</div>
						) : (
							<div className="tr-rounded-lg tr-border tr-border-secondary tr-px-4 tr-py-3">
								{data.title && (
									<div className="tr-text-[18px] tr-leading-snug tr-text-[#1a0dab]">
										{asText(data.title)}
									</div>
								)}
								{data.url && (
									<div className="tr-text-xs tr-text-[#006621] tr-mt-0.5">
										{asText(data.url)}
									</div>
								)}
								{data.description && (
									<div className="tr-text-[13px] tr-leading-relaxed tr-text-secondary tr-mt-1">
										{asText(data.description)}
									</div>
								)}
								{data.additional_info && (
									<div className="tr-text-xs tr-text-tertiary tr-mt-2">
										{typeof data.additional_info ===
										'object' ? (
											Object.entries(
												data.additional_info
											).map(([key, value]) => (
												<div key={key}>
													<strong>{key}:</strong>{' '}
													{asText(value)}
												</div>
											))
										) : (
											<div>
												{asText(data.additional_info)}
											</div>
										)}
									</div>
								)}
							</div>
						)}
					</div>
				);
			})}
		</div>
	);
};

export default SchemaPreview;
