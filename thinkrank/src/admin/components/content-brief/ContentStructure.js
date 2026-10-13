/**
 * Content Structure Component
 *
 * Displays the generated content brief — redesigned per docs/ui/design-system.md.
 * Uses tr- Tailwind classes and ui/ components only (no @wordpress/components).
 *
 * @package
 * @since 1.0.0
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '../ui';
import ModelBadge from '../shared/ModelBadge';
import logger from '../../../shared/utils/logger';

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Render `**bold**` / `*emphasis*` as React elements.
 *
 * Brief fields come from an external AI provider, whose prompt can include text
 * fetched from competitor URLs, so they are untrusted. This used to build an
 * HTML string and hand it to dangerouslySetInnerHTML behind a regex blacklist.
 * That blacklist was bypassable two ways — each replace is a single
 * non-recursive pass, so `javascjavascript:ript:` collapsed back into a live
 * scheme, and the filter ran before the parser decoded character references, so
 * `java&#x73;cript:` was never matched at all. Both produced a working
 * javascript: URL in the admin origin (#365).
 *
 * Emitting React nodes removes the sink entirely: React escapes text children,
 * so no markup is ever parsed and no blacklist has to be kept correct.
 */
const MARKDOWN_EMPHASIS = /(\*\*[\s\S]+?\*\*|\*[\s\S]+?\*)/g;

const FormattedText = ({ children, className = '' }) => {
	if (!children) return null;

	const parts = String(children).split(MARKDOWN_EMPHASIS);

	return (
		<span className={className}>
			{parts.map((part, i) => {
				// Length guards so a stray `*` or `**` in the text is rendered
				// as-is rather than collapsing into an empty element.
				if (
					part.length > 4 &&
					part.startsWith('**') &&
					part.endsWith('**')
				) {
					return <strong key={i}>{part.slice(2, -2)}</strong>;
				}
				if (
					part.length > 2 &&
					part.startsWith('*') &&
					part.endsWith('*')
				) {
					return <em key={i}>{part.slice(1, -1)}</em>;
				}
				return part;
			})}
		</span>
	);
};

// ─── Icons ────────────────────────────────────────────────────────────────────

const IconCopy = ({ className = 'tr-w-3.5 tr-h-3.5' }) => (
	<svg
		className={className}
		fill="none"
		stroke="currentColor"
		viewBox="0 0 24 24"
	>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth={2}
			d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"
		/>
	</svg>
);

// H-level color map
const H_LEVEL_STYLE = {
	1: { badge: 'tr-bg-brand-bg tr-text-brand', border: '#4451FF' },
	2: { badge: 'tr-bg-brand-bg tr-text-brand', border: '#06B6D4' },
	3: { badge: 'tr-bg-white tr-text-secondary', border: '#CBD5E1' },
};

// Character-count badge used in SEO / social sections
const CharBadge = ({ len, max }) => (
	<span
		className={`tr-inline-flex tr-items-center tr-px-1.5 tr-py-0.5 tr-rounded tr-text-xs tr-font-medium ${len <= max ? 'tr-bg-success-bg tr-text-success' : 'tr-bg-warning-bg tr-text-warning'}`}
	>
		{len}/{max}
	</span>
);

// ─── SectionCard ──────────────────────────────────────────────────────────────
// Static titled card. Section show/hide is handled by the sidebar tabs in the
// main component, so this no longer collapses — it just renders its heading +
// body. Kept the `CollapsibleSection` name so the section renderers are untouched.

const CollapsibleSection = ({ title, icon, children, count, headerAction }) => (
	<div className="tr-bg-white tr-rounded-lg tr-border tr-border-secondary tr-shadow-sm tr-overflow-hidden">
		<div className="tr-px-5 tr-py-3.5 tr-flex tr-items-center tr-gap-2.5 tr-border-b tr-border-secondary">
			{icon && (
				<span className="tr-flex tr-items-center tr-justify-center tr-w-7 tr-h-7 tr-rounded-md tr-bg-brand tr-shrink-0">
					{icon}
				</span>
			)}
			<span className="tr-text-sm tr-font-semibold tr-text-primary">
				{title}
			</span>
			{count !== undefined && count > 0 && (
				<span className="tr-inline-flex tr-items-center tr-px-2 tr-py-0.5 tr-rounded-full tr-text-xs tr-font-medium tr-bg-brand-bg tr-text-brand">
					{count}
				</span>
			)}
			{headerAction && (
				<div className="tr-ml-auto tr-shrink-0">{headerAction}</div>
			)}
		</div>
		<div className="tr-px-5 tr-pt-4 tr-pb-5">{children}</div>
	</div>
);

// ─── Section icons ────────────────────────────────────────────────────────────

const iconTitle = (
	<svg
		className="tr-w-4 tr-h-4 tr-text-white"
		fill="none"
		stroke="currentColor"
		viewBox="0 0 24 24"
	>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth={2}
			d="M4 6h16M4 10h12M4 14h8"
		/>
	</svg>
);

const iconOutline = (
	<svg
		className="tr-w-4 tr-h-4 tr-text-white"
		fill="none"
		stroke="currentColor"
		viewBox="0 0 24 24"
	>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth={2}
			d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
		/>
	</svg>
);

const iconSEO = (
	<svg
		className="tr-w-4 tr-h-4 tr-text-white"
		fill="none"
		stroke="currentColor"
		viewBox="0 0 24 24"
	>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth={2}
			d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
		/>
	</svg>
);

const iconSocial = (
	<svg
		className="tr-w-4 tr-h-4 tr-text-white"
		fill="none"
		stroke="currentColor"
		viewBox="0 0 24 24"
	>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth={2}
			d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"
		/>
	</svg>
);

const iconSchema = (
	<svg
		className="tr-w-4 tr-h-4 tr-text-white"
		fill="none"
		stroke="currentColor"
		viewBox="0 0 24 24"
	>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth={2}
			d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"
		/>
	</svg>
);

const iconVisual = (
	<svg
		className="tr-w-4 tr-h-4 tr-text-white"
		fill="none"
		stroke="currentColor"
		viewBox="0 0 24 24"
	>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth={2}
			d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
		/>
	</svg>
);

const iconCompetitor = (
	<svg
		className="tr-w-4 tr-h-4 tr-text-white"
		fill="none"
		stroke="currentColor"
		viewBox="0 0 24 24"
	>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth={2}
			d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
		/>
	</svg>
);

const iconGaps = (
	<svg
		className="tr-w-4 tr-h-4 tr-text-white"
		fill="none"
		stroke="currentColor"
		viewBox="0 0 24 24"
	>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth={2}
			d="M13 10V3L4 14h7v7l9-11h-7z"
		/>
	</svg>
);

const iconCTA = (
	<svg
		className="tr-w-4 tr-h-4 tr-text-white"
		fill="none"
		stroke="currentColor"
		viewBox="0 0 24 24"
	>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth={2}
			d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"
		/>
	</svg>
);

const iconCode = (
	<svg
		className="tr-w-4 tr-h-4 tr-text-white"
		fill="none"
		stroke="currentColor"
		viewBox="0 0 24 24"
	>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth={2}
			d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"
		/>
	</svg>
);

// ─── Display value maps ───────────────────────────────────────────────────────

const DISPLAY_MAPS = {
	content_type: {
		blog_post: __('Blog Post', 'thinkrank'),
		article: __('Article', 'thinkrank'),
		landing_page: __('Landing Page', 'thinkrank'),
		product_page: __('Product Page', 'thinkrank'),
		product_description: __('Product Description', 'thinkrank'),
		tutorial: __('Tutorial', 'thinkrank'),
	},
	target_audience: {
		general: __('General Audience', 'thinkrank'),
		beginners: __('Beginners', 'thinkrank'),
		professionals: __('Professionals', 'thinkrank'),
		experts: __('Experts', 'thinkrank'),
		students: __('Students', 'thinkrank'),
		business_owners: __('Business Owners', 'thinkrank'),
	},
	content_length: {
		short: __('Short (500–800 words)', 'thinkrank'),
		medium: __('Medium (1,000–1,500 words)', 'thinkrank'),
		long: __('Long (2,000+ words)', 'thinkrank'),
	},
	tone: {
		professional: __('Professional', 'thinkrank'),
		casual: __('Casual', 'thinkrank'),
		friendly: __('Friendly', 'thinkrank'),
		authoritative: __('Authoritative', 'thinkrank'),
		conversational: __('Conversational', 'thinkrank'),
		technical: __('Technical', 'thinkrank'),
	},
};

const getDisplayValue = (key, value) => DISPLAY_MAPS[key]?.[value] || value;

// ─── Main component ───────────────────────────────────────────────────────────

const ContentStructure = ({ briefData, formData }) => {
	const [activeSection, setActiveSection] = useState('overview');

	if (!briefData) return null;

	const copyToClipboard = async (text) => {
		try {
			if (navigator.clipboard?.writeText) {
				await navigator.clipboard.writeText(text);
				return;
			}
			const el = document.createElement('textarea');
			el.value = text;
			el.style.cssText = 'position:fixed;left:-999999px;top:-999999px';
			document.body.appendChild(el);
			el.focus();
			el.select();
			try {
				document.execCommand('copy');
			} catch (e) {
				logger.error('Copy fallback failed', e);
			}
			document.body.removeChild(el);
		} catch (err) {
			logger.error('Copy to clipboard failed:', err);
		}
	};

	// ── Overview ──────────────────────────────────────────────────────────────

	const renderOverview = () => {
		const params = briefData.generation_params || formData;
		if (!params) return null;

		const metaFields = [
			{
				label: __('Content Type', 'thinkrank'),
				value: getDisplayValue('content_type', params.content_type),
			},
			{
				label: __('Target Audience', 'thinkrank'),
				value: getDisplayValue(
					'target_audience',
					params.target_audience
				),
			},
			{
				label: __('Content Length', 'thinkrank'),
				value: getDisplayValue('content_length', params.content_length),
			},
			{
				label: __('Tone', 'thinkrank'),
				value: getDisplayValue('tone', params.tone),
			},
		];

		const validKeywords =
			params.target_keywords?.filter((k) => k.trim()) || [];
		const validUrls = params.competitor_urls?.filter((u) => u.trim()) || [];

		// Build the overview as label/value rows so the card renders as a table.
		const overviewRows = [
			validKeywords.length > 0 && {
				label: __('Target Keywords', 'thinkrank'),
				content: (
					<div className="tr-flex tr-flex-wrap tr-gap-1.5">
						{validKeywords.map((kw, i) => (
							<span
								key={i}
								className="tr-inline-flex tr-items-center tr-px-2.5 tr-py-1 tr-rounded-full tr-text-xs tr-font-medium tr-bg-brand-bg tr-text-brand"
							>
								{kw}
							</span>
						))}
					</div>
				),
			},
			...metaFields.map(({ label, value }) => ({
				label,
				content: (
					<span className="tr-text-sm tr-font-medium tr-text-secondary">
						{value || '—'}
					</span>
				),
			})),
			validUrls.length > 0 && {
				label: __('Competitor URLs', 'thinkrank'),
				content: (
					<div className="tr-flex tr-flex-col tr-gap-1">
						{validUrls.map((url, i) => (
							<a
								key={i}
								href={url}
								target="_blank"
								rel="noopener noreferrer"
								className="tr-text-sm tr-text-brand hover:tr-text-brand tr-truncate"
							>
								{url}
							</a>
						))}
					</div>
				),
			},
			params.additional_context && {
				label: __('Additional Context', 'thinkrank'),
				content: (
					<p className="tr-text-sm tr-text-secondary tr-m-0 tr-leading-relaxed">
						{params.additional_context}
					</p>
				),
			},
		].filter(Boolean);

		return (
			<div className="tr-bg-white tr-rounded-lg tr-border tr-border-secondary tr-shadow-sm tr-overflow-hidden">
				{/* Card header */}
				<div className="tr-px-5 tr-py-3.5 tr-border-b tr-border-secondary tr-flex tr-items-center tr-justify-between">
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Brief Overview', 'thinkrank')}
					</h3>
					<div className="tr-flex tr-items-center tr-gap-3">
						{briefData.generation_meta && (
							<ModelBadge
								provider={briefData.generation_meta.provider}
								model={briefData.generation_meta.model}
							/>
						)}
					</div>
				</div>

				<table className="tr-w-full tr-border-collapse">
					<tbody>
						{overviewRows.map(({ label, content }) => (
							<tr
								key={label}
								className="tr-border-b tr-border-secondary last:tr-border-b-0"
							>
								<th
									scope="row"
									className="tr-align-top tr-text-left tr-w-1/3 tr-px-5 tr-py-3 tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-whitespace-nowrap"
								>
									{label}
								</th>
								<td className="tr-align-top tr-px-5 tr-py-3">
									{content}
								</td>
							</tr>
						))}
					</tbody>
				</table>
			</div>
		);
	};

	// ── Title Suggestions ─────────────────────────────────────────────────────

	const renderTitleSuggestions = () => {
		if (!briefData.title || !Array.isArray(briefData.title)) return null;

		return (
			<CollapsibleSection
				title={__('Title Suggestions', 'thinkrank')}
				count={briefData.title.length}
				defaultOpen={true}
				icon={iconTitle}
			>
				<div className="tr-flex tr-flex-col tr-gap-2">
					{briefData.title.map((title, i) => (
						<div
							key={i}
							className="tr-flex tr-items-center tr-gap-3 tr-p-3 tr-rounded-lg tr-bg-white tr-border tr-border-secondary"
						>
							<span className="tr-flex tr-items-center tr-justify-center tr-w-6 tr-h-6 tr-rounded-full tr-bg-brand-bg tr-text-brand tr-text-xs tr-font-bold tr-shrink-0">
								{i + 1}
							</span>
							<span className="tr-flex-1 tr-text-sm tr-text-primary tr-font-medium tr-leading-snug">
								{title}
							</span>
							<button
								type="button"
								onClick={() => copyToClipboard(title)}
								title={__('Copy', 'thinkrank')}
								className="tr-shrink-0 tr-flex tr-items-center tr-justify-center tr-w-7 tr-h-7 tr-rounded tr-text-tertiary hover:tr-text-brand hover:tr-bg-brand-bg tr-transition-colors"
							>
								<IconCopy />
							</button>
						</div>
					))}
				</div>
			</CollapsibleSection>
		);
	};

	// ── Content Outline ───────────────────────────────────────────────────────

	const renderContentOutline = () => {
		if (!briefData.outline || !Array.isArray(briefData.outline))
			return null;

		return (
			<CollapsibleSection
				title={__('Content Outline', 'thinkrank')}
				count={briefData.outline.length}
				defaultOpen={true}
				icon={iconOutline}
			>
				<div className="tr-flex tr-flex-col tr-gap-3">
					{briefData.outline.map((item, i) => {
						const level = item.level || 1;
						const style =
							H_LEVEL_STYLE[Math.min(level, 3)] ||
							H_LEVEL_STYLE[3];

						return (
							<div
								key={i}
								className="tr-border-l-2 tr-pl-4 tr-py-1"
								style={{
									marginLeft: `${(level - 1) * 16}px`,
									borderColor: style.border,
								}}
							>
								<div className="tr-flex tr-items-start tr-gap-2 tr-mb-1">
									<span
										className={`tr-inline-flex tr-items-center tr-px-1.5 tr-py-0.5 tr-rounded tr-text-xs tr-font-bold tr-shrink-0 ${style.badge}`}
									>
										H{level}
									</span>
									<span className="tr-flex-1 tr-text-sm tr-font-semibold tr-text-primary tr-leading-snug">
										<FormattedText>
											{item.heading}
										</FormattedText>
									</span>
									{item.word_count > 0 && (
										<span className="tr-inline-flex tr-items-center tr-px-1.5 tr-py-0.5 tr-rounded tr-text-xs tr-bg-white tr-text-tertiary tr-shrink-0">
											{item.word_count}w
										</span>
									)}
								</div>

								{item.key_points?.length > 0 && (
									<ul className="tr-m-0 tr-pl-4 tr-flex tr-flex-col tr-gap-0.5 tr-mt-1">
										{item.key_points.map((pt, pi) => (
											<li
												key={pi}
												className="tr-text-xs tr-text-tertiary tr-leading-relaxed"
											>
												<FormattedText>
													{pt}
												</FormattedText>
											</li>
										))}
									</ul>
								)}

								{item.keywords?.length > 0 && (
									<div className="tr-flex tr-flex-wrap tr-gap-1 tr-mt-2">
										{item.keywords.map((kw, ki) => (
											<span
												key={ki}
												className="tr-inline-flex tr-items-center tr-px-1.5 tr-py-0.5 tr-rounded tr-text-xs tr-bg-brand-bg tr-text-brand"
											>
												{kw}
											</span>
										))}
									</div>
								)}
							</div>
						);
					})}
				</div>

				{briefData.estimated_word_count && (
					<div className="tr-mt-4 tr-p-3 tr-rounded-lg tr-bg-brand-bg tr-border tr-border-brand tr-flex tr-items-center tr-justify-between">
						<span className="tr-text-sm tr-text-brand tr-font-medium">
							{__('Estimated word count', 'thinkrank')}
						</span>
						<span className="tr-text-sm tr-font-bold tr-text-brand">
							{briefData.estimated_word_count}
						</span>
					</div>
				)}
			</CollapsibleSection>
		);
	};

	// ── SEO Recommendations ───────────────────────────────────────────────────

	const renderSEORecommendations = () => {
		if (!briefData.seo_recommendations) return null;

		const {
			title_suggestions,
			meta_descriptions,
			meta_description,
			url_slugs,
			focus_keyword_analysis,
			internal_links,
			related_keywords,
			long_tail_keywords,
		} = briefData.seo_recommendations;

		const hasContent =
			title_suggestions?.length ||
			meta_descriptions?.length ||
			meta_description ||
			url_slugs?.length ||
			internal_links?.length ||
			related_keywords?.length ||
			long_tail_keywords?.length ||
			focus_keyword_analysis?.primary_placement?.length;

		if (!hasContent) return null;

		return (
			<CollapsibleSection
				title={__('SEO Recommendations', 'thinkrank')}
				defaultOpen={false}
				icon={iconSEO}
			>
				<div className="tr-flex tr-flex-col tr-gap-6">
					{/* Title suggestions */}
					{title_suggestions?.length > 0 && (
						<div>
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-mb-2 tr-m-0">
								{__('Title Suggestions', 'thinkrank')}
							</p>
							<ul className="tr-m-0 tr-pl-4 tr-flex tr-flex-col tr-gap-1">
								{title_suggestions.map((t, i) => (
									<li
										key={i}
										className="tr-text-sm tr-text-secondary"
									>
										{t}
									</li>
								))}
							</ul>
						</div>
					)}

					{/* Meta descriptions */}
					{(meta_description || meta_descriptions?.length > 0) && (
						<div>
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-mb-3 tr-m-0">
								{__('Meta Descriptions', 'thinkrank')}
							</p>
							{meta_description && (
								<div className="tr-p-3 tr-rounded-lg tr-bg-brand-bg tr-border tr-border-brand tr-mb-2">
									<div className="tr-flex tr-items-center tr-justify-between tr-mb-1.5">
										<span className="tr-text-xs tr-font-medium tr-text-brand">
											{__('Primary', 'thinkrank')}
										</span>
										<CharBadge
											len={meta_description.length}
											max={160}
										/>
									</div>
									<p className="tr-text-sm tr-text-secondary tr-m-0 tr-leading-relaxed">
										<FormattedText>
											{meta_description}
										</FormattedText>
									</p>
								</div>
							)}
							{meta_descriptions?.map((desc, i) => (
								<div
									key={i}
									className="tr-p-3 tr-rounded-lg tr-bg-white tr-border tr-border-secondary tr-mb-2"
								>
									<div className="tr-flex tr-items-center tr-justify-between tr-mb-1.5">
										<span className="tr-text-xs tr-font-medium tr-text-tertiary">
											{__('Variation', 'thinkrank')}{' '}
											{i + 1}
										</span>
										<CharBadge
											len={desc.length}
											max={160}
										/>
									</div>
									<p className="tr-text-sm tr-text-secondary tr-m-0 tr-leading-relaxed">
										<FormattedText>{desc}</FormattedText>
									</p>
								</div>
							))}
						</div>
					)}

					{/* URL slugs */}
					{url_slugs?.length > 0 && (
						<div>
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-mb-2 tr-m-0">
								{__('URL Slug Suggestions', 'thinkrank')}
							</p>
							<div className="tr-flex tr-flex-col tr-gap-2">
								{url_slugs.map((slug, i) => (
									<div
										key={i}
										className="tr-flex tr-items-center tr-gap-2"
									>
										<code className="tr-flex-1 tr-px-3 tr-py-1.5 tr-rounded tr-bg-white tr-text-secondary tr-text-xs tr-font-mono">
											{slug}
										</code>
										<button
											type="button"
											onClick={() =>
												copyToClipboard(slug)
											}
											className="tr-flex tr-items-center tr-justify-center tr-w-7 tr-h-7 tr-rounded tr-text-tertiary hover:tr-text-brand hover:tr-bg-brand-bg tr-transition-colors"
										>
											<IconCopy />
										</button>
									</div>
								))}
							</div>
						</div>
					)}

					{/* Related keywords */}
					{related_keywords?.length > 0 && (
						<div>
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-mb-2 tr-m-0">
								{__('Related Keywords', 'thinkrank')}
							</p>
							<div className="tr-flex tr-flex-wrap tr-gap-1.5">
								{related_keywords.map((kw, i) => (
									<span
										key={i}
										className="tr-inline-flex tr-items-center tr-px-2.5 tr-py-1 tr-rounded-full tr-text-xs tr-font-medium tr-bg-brand-bg tr-text-brand"
									>
										<FormattedText>{kw}</FormattedText>
									</span>
								))}
							</div>
						</div>
					)}

					{/* Long-tail keywords */}
					{long_tail_keywords?.length > 0 && (
						<div>
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-mb-2 tr-m-0">
								{__('Long-tail Variations', 'thinkrank')}
							</p>
							<div className="tr-flex tr-flex-wrap tr-gap-1.5">
								{long_tail_keywords.map((kw, i) => (
									<span
										key={i}
										className="tr-inline-flex tr-items-center tr-px-2.5 tr-py-1 tr-rounded-full tr-text-xs tr-font-medium tr-bg-success-bg tr-text-success"
									>
										{kw}
									</span>
								))}
							</div>
						</div>
					)}

					{/* Internal links */}
					{internal_links?.length > 0 && (
						<div>
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-mb-2 tr-m-0">
								{__(
									'Internal Linking Opportunities',
									'thinkrank'
								)}
							</p>
							<ul className="tr-m-0 tr-pl-4 tr-flex tr-flex-col tr-gap-1.5">
								{internal_links.map((link, i) => (
									<li
										key={i}
										className="tr-text-sm tr-text-secondary tr-leading-relaxed"
									>
										<FormattedText>{link}</FormattedText>
									</li>
								))}
							</ul>
						</div>
					)}

					{/* Focus keyword analysis */}
					{(focus_keyword_analysis?.primary_placement?.length ||
						focus_keyword_analysis?.secondary_integration?.length ||
						focus_keyword_analysis?.density_guidelines?.length) && (
						<div className="tr-flex tr-flex-col tr-gap-4">
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-m-0">
								{__('Focus Keyword Analysis', 'thinkrank')}
							</p>
							{focus_keyword_analysis.primary_placement?.length >
								0 && (
								<div>
									<p className="tr-text-xs tr-font-semibold tr-text-secondary tr-mb-1.5 tr-m-0">
										{__('Primary Placement', 'thinkrank')}
									</p>
									<ul className="tr-m-0 tr-pl-4 tr-flex tr-flex-col tr-gap-1">
										{focus_keyword_analysis.primary_placement.map(
											(pt, i) => (
												<li
													key={i}
													className="tr-text-sm tr-text-secondary"
												>
													<FormattedText>
														{pt}
													</FormattedText>
												</li>
											)
										)}
									</ul>
								</div>
							)}
							{focus_keyword_analysis.secondary_integration
								?.length > 0 && (
								<div>
									<p className="tr-text-xs tr-font-semibold tr-text-secondary tr-mb-1.5 tr-m-0">
										{__(
											'Secondary Integration',
											'thinkrank'
										)}
									</p>
									<ul className="tr-m-0 tr-pl-4 tr-flex tr-flex-col tr-gap-1">
										{focus_keyword_analysis.secondary_integration.map(
											(pt, i) => (
												<li
													key={i}
													className="tr-text-sm tr-text-secondary"
												>
													<FormattedText>
														{pt}
													</FormattedText>
												</li>
											)
										)}
									</ul>
								</div>
							)}
							{focus_keyword_analysis.density_guidelines?.length >
								0 && (
								<div>
									<p className="tr-text-xs tr-font-semibold tr-text-secondary tr-mb-1.5 tr-m-0">
										{__('Density Guidelines', 'thinkrank')}
									</p>
									<ul className="tr-m-0 tr-pl-4 tr-flex tr-flex-col tr-gap-1">
										{focus_keyword_analysis.density_guidelines.map(
											(pt, i) => (
												<li
													key={i}
													className="tr-text-sm tr-text-secondary"
												>
													<FormattedText>
														{pt}
													</FormattedText>
												</li>
											)
										)}
									</ul>
								</div>
							)}
						</div>
					)}
				</div>
			</CollapsibleSection>
		);
	};

	// ── Social Media ──────────────────────────────────────────────────────────

	const renderSocialMedia = () => {
		if (!briefData.social_media) return null;
		const { open_graph, twitter_card } = briefData.social_media;
		if (
			!open_graph?.title &&
			!open_graph?.description &&
			!twitter_card?.title &&
			!twitter_card?.description
		)
			return null;

		return (
			<CollapsibleSection
				title={__('Social Media Meta Tags', 'thinkrank')}
				defaultOpen={false}
				icon={iconSocial}
			>
				<div className="tr-flex tr-flex-col tr-gap-6">
					{(open_graph?.title || open_graph?.description) && (
						<div>
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-mb-3 tr-m-0">
								Open Graph
							</p>
							{open_graph.title && (
								<div className="tr-p-3 tr-rounded-lg tr-bg-brand-bg tr-border tr-border-brand tr-mb-2">
									<div className="tr-flex tr-items-center tr-justify-between tr-mb-1">
										<span className="tr-text-xs tr-font-medium tr-text-brand">
											og:title
										</span>
										<CharBadge
											len={open_graph.title.length}
											max={55}
										/>
									</div>
									<p className="tr-text-sm tr-text-secondary tr-m-0">
										<FormattedText>
											{open_graph.title}
										</FormattedText>
									</p>
								</div>
							)}
							{open_graph.description && (
								<div className="tr-p-3 tr-rounded-lg tr-bg-brand-bg tr-border tr-border-brand">
									<div className="tr-flex tr-items-center tr-justify-between tr-mb-1">
										<span className="tr-text-xs tr-font-medium tr-text-brand">
											og:description
										</span>
										<CharBadge
											len={open_graph.description.length}
											max={125}
										/>
									</div>
									<p className="tr-text-sm tr-text-secondary tr-m-0">
										<FormattedText>
											{open_graph.description}
										</FormattedText>
									</p>
								</div>
							)}
						</div>
					)}

					{(twitter_card?.title || twitter_card?.description) && (
						<div>
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-mb-3 tr-m-0">
								Twitter Card
							</p>
							{twitter_card.title && (
								<div className="tr-p-3 tr-rounded-lg tr-bg-white tr-border tr-border-secondary tr-mb-2">
									<div className="tr-flex tr-items-center tr-justify-between tr-mb-1">
										<span className="tr-text-xs tr-font-medium tr-text-tertiary">
											twitter:title
										</span>
										<CharBadge
											len={twitter_card.title.length}
											max={70}
										/>
									</div>
									<p className="tr-text-sm tr-text-secondary tr-m-0">
										<FormattedText>
											{twitter_card.title}
										</FormattedText>
									</p>
								</div>
							)}
							{twitter_card.description && (
								<div className="tr-p-3 tr-rounded-lg tr-bg-white tr-border tr-border-secondary">
									<div className="tr-flex tr-items-center tr-justify-between tr-mb-1">
										<span className="tr-text-xs tr-font-medium tr-text-tertiary">
											twitter:description
										</span>
										<CharBadge
											len={
												twitter_card.description.length
											}
											max={125}
										/>
									</div>
									<p className="tr-text-sm tr-text-secondary tr-m-0">
										<FormattedText>
											{twitter_card.description}
										</FormattedText>
									</p>
								</div>
							)}
						</div>
					)}
				</div>
			</CollapsibleSection>
		);
	};

	// ── Schema Markup ─────────────────────────────────────────────────────────

	const renderSchemaMarkup = () => {
		if (!briefData.schema_markup) return null;
		const { recommended_types, key_properties, faq_questions } =
			briefData.schema_markup;
		if (
			!recommended_types?.length &&
			!key_properties?.length &&
			!faq_questions?.length
		)
			return null;

		return (
			<CollapsibleSection
				title={__('Schema Markup', 'thinkrank')}
				defaultOpen={false}
				icon={iconSchema}
			>
				<div className="tr-flex tr-flex-col tr-gap-5">
					{recommended_types?.length > 0 && (
						<div>
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-mb-2 tr-m-0">
								{__('Recommended Types', 'thinkrank')}
							</p>
							<div className="tr-flex tr-flex-wrap tr-gap-1.5">
								{recommended_types.map((t, i) => (
									<span
										key={i}
										className="tr-inline-flex tr-items-center tr-px-2.5 tr-py-1 tr-rounded-full tr-text-xs tr-font-medium tr-bg-brand-bg tr-text-brand"
									>
										<FormattedText>{t}</FormattedText>
									</span>
								))}
							</div>
						</div>
					)}
					{key_properties?.length > 0 && (
						<div>
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-mb-2 tr-m-0">
								{__('Key Properties', 'thinkrank')}
							</p>
							<ul className="tr-m-0 tr-pl-4 tr-flex tr-flex-col tr-gap-1">
								{key_properties.map((p, i) => (
									<li
										key={i}
										className="tr-text-sm tr-text-secondary"
									>
										<FormattedText>{p}</FormattedText>
									</li>
								))}
							</ul>
						</div>
					)}
					{faq_questions?.length > 0 && (
						<div>
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-mb-2 tr-m-0">
								{__('FAQ Questions', 'thinkrank')}
							</p>
							<div className="tr-flex tr-flex-col tr-gap-2">
								{faq_questions.map((q, i) => (
									<div
										key={i}
										className="tr-p-3 tr-rounded-lg tr-bg-warning-bg tr-border tr-border-warning tr-flex tr-items-start tr-gap-2"
									>
										<span className="tr-inline-flex tr-items-center tr-justify-center tr-w-5 tr-h-5 tr-rounded-full tr-bg-warning tr-text-white tr-text-xs tr-font-bold tr-shrink-0">
											Q
										</span>
										<p className="tr-text-sm tr-text-secondary tr-m-0 tr-leading-relaxed">
											<FormattedText>{q}</FormattedText>
										</p>
									</div>
								))}
							</div>
						</div>
					)}
				</div>
			</CollapsibleSection>
		);
	};

	// ── Visual Content ────────────────────────────────────────────────────────

	const renderVisualContent = () => {
		if (!briefData.visual_content) return null;
		const {
			image_recommendations,
			alt_text_suggestions,
			infographic_opportunities,
		} = briefData.visual_content;
		if (
			!image_recommendations?.length &&
			!alt_text_suggestions?.length &&
			!infographic_opportunities?.length
		)
			return null;

		return (
			<CollapsibleSection
				title={__('Visual Content Strategy', 'thinkrank')}
				defaultOpen={false}
				icon={iconVisual}
			>
				<div className="tr-flex tr-flex-col tr-gap-5">
					{image_recommendations?.length > 0 && (
						<div>
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-mb-2 tr-m-0">
								{__('Image Recommendations', 'thinkrank')}
							</p>
							<ul className="tr-m-0 tr-pl-4 tr-flex tr-flex-col tr-gap-1">
								{image_recommendations.map((r, i) => (
									<li
										key={i}
										className="tr-text-sm tr-text-secondary tr-leading-relaxed"
									>
										<FormattedText>{r}</FormattedText>
									</li>
								))}
							</ul>
						</div>
					)}
					{alt_text_suggestions?.length > 0 && (
						<div>
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-mb-2 tr-m-0">
								{__('Alt Text Suggestions', 'thinkrank')}
							</p>
							<div className="tr-flex tr-flex-col tr-gap-2">
								{alt_text_suggestions.map((s, i) => (
									<div
										key={i}
										className="tr-p-2.5 tr-rounded-lg tr-bg-success-bg tr-border tr-border-success"
									>
										<p className="tr-text-xs tr-text-secondary tr-m-0 tr-leading-relaxed tr-font-mono">
											<FormattedText>{s}</FormattedText>
										</p>
									</div>
								))}
							</div>
						</div>
					)}
					{infographic_opportunities?.length > 0 && (
						<div>
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-mb-2 tr-m-0">
								{__('Infographic Opportunities', 'thinkrank')}
							</p>
							<ul className="tr-m-0 tr-pl-4 tr-flex tr-flex-col tr-gap-1">
								{infographic_opportunities.map((o, i) => (
									<li
										key={i}
										className="tr-text-sm tr-text-secondary tr-leading-relaxed"
									>
										<FormattedText>{o}</FormattedText>
									</li>
								))}
							</ul>
						</div>
					)}
				</div>
			</CollapsibleSection>
		);
	};

	// ── Competitor Analysis ───────────────────────────────────────────────────

	const renderCompetitorAnalysis = () => {
		const urls = briefData.generation_params?.competitor_urls?.filter((u) =>
			u?.trim()
		);
		if (!urls?.length) return null;

		const insights = [
			{
				icon: '📊',
				title: __('Content Analysis', 'thinkrank'),
				desc: __('Word count, readability & structure', 'thinkrank'),
			},
			{
				icon: '🎯',
				title: __('SEO Evaluation', 'thinkrank'),
				desc: __('Title tags, meta & schema markup', 'thinkrank'),
			},
			{
				icon: '🔗',
				title: __('Link Analysis', 'thinkrank'),
				desc: __('Internal & external linking strategies', 'thinkrank'),
			},
			{
				icon: '🖼️',
				title: __('Media Usage', 'thinkrank'),
				desc: __('Image optimization & alt text', 'thinkrank'),
			},
		];

		return (
			<CollapsibleSection
				title={__('Competitor Analysis', 'thinkrank')}
				count={urls.length}
				defaultOpen={false}
				icon={iconCompetitor}
			>
				<div className="tr-flex tr-flex-col tr-gap-4">
					<div className="tr-flex tr-flex-col tr-gap-2">
						{urls.map((url, i) => (
							<div
								key={i}
								className="tr-flex tr-items-center tr-justify-between tr-p-3 tr-rounded-lg tr-bg-white tr-border tr-border-secondary"
							>
								<div className="tr-flex-1 tr-min-w-0">
									<p className="tr-text-xs tr-font-medium tr-text-tertiary tr-m-0 tr-mb-0.5">
										{__('Competitor', 'thinkrank')} {i + 1}
									</p>
									<a
										href={url}
										target="_blank"
										rel="noopener noreferrer"
										className="tr-text-sm tr-text-brand hover:tr-text-brand tr-truncate tr-block"
									>
										{url.length > 60
											? url.substring(0, 60) + '…'
											: url}
									</a>
								</div>
								<span className="tr-inline-flex tr-items-center tr-gap-1 tr-px-2 tr-py-0.5 tr-rounded-full tr-text-xs tr-font-medium tr-bg-success-bg tr-text-success tr-shrink-0 tr-ml-3">
									✓ {__('Analyzed', 'thinkrank')}
								</span>
							</div>
						))}
					</div>

					<div className="tr-grid tr-grid-cols-2 tr-gap-3">
						{insights.map(({ icon, title, desc }) => (
							<div
								key={title}
								className="tr-p-3 tr-rounded-lg tr-bg-white tr-border tr-border-secondary tr-flex tr-items-start tr-gap-2"
							>
								<span className="tr-text-base tr-shrink-0">
									{icon}
								</span>
								<div>
									<p className="tr-text-xs tr-font-semibold tr-text-secondary tr-m-0 tr-mb-0.5">
										{title}
									</p>
									<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-leading-snug">
										{desc}
									</p>
								</div>
							</div>
						))}
					</div>

					<div className="tr-p-3 tr-rounded-lg tr-bg-brand-bg tr-border tr-border-brand">
						<p className="tr-text-xs tr-text-brand tr-m-0 tr-leading-relaxed">
							<strong>{__('Note:', 'thinkrank')}</strong>{' '}
							{__(
								'This analysis shaped the content gaps and recommendations below. The AI identified opportunities to outperform these competitors.',
								'thinkrank'
							)}
						</p>
					</div>
				</div>
			</CollapsibleSection>
		);
	};

	// ── Competitor Gaps ───────────────────────────────────────────────────────

	const renderCompetitorGaps = () => {
		if (!briefData.competitor_gaps?.length) return null;

		return (
			<CollapsibleSection
				title={__('Content Gaps & Opportunities', 'thinkrank')}
				count={briefData.competitor_gaps.length}
				defaultOpen={false}
				icon={iconGaps}
			>
				<div className="tr-flex tr-flex-col tr-gap-3">
					<p className="tr-text-sm tr-text-tertiary tr-m-0 tr-leading-relaxed">
						{__(
							'Opportunities to outperform competitors based on analysis:',
							'thinkrank'
						)}
					</p>

					{briefData.competitor_gaps.map((gap, i) => (
						<div
							key={i}
							className="tr-p-3 tr-rounded-lg tr-border tr-border-warning tr-bg-warning-bg tr-flex tr-items-start tr-gap-3"
						>
							<span className="tr-inline-flex tr-items-center tr-px-2 tr-py-0.5 tr-rounded tr-text-xs tr-font-bold tr-bg-warning tr-text-white tr-shrink-0">
								{__('GAP', 'thinkrank')}
							</span>
							<div>
								<p className="tr-text-sm tr-text-secondary tr-m-0 tr-leading-relaxed tr-mb-1">
									<FormattedText>{gap}</FormattedText>
								</p>
								<p className="tr-text-xs tr-text-warning tr-m-0 tr-font-medium">
									→{' '}
									{__(
										'Include this in your content strategy',
										'thinkrank'
									)}
								</p>
							</div>
						</div>
					))}

					<div className="tr-p-3 tr-rounded-lg tr-bg-success-bg tr-border tr-border-success">
						<p className="tr-text-xs tr-font-semibold tr-text-success tr-mb-2 tr-m-0">
							🚀 {__('Your Competitive Advantage', 'thinkrank')}
						</p>
						<div className="tr-flex tr-flex-col tr-gap-1">
							{[
								__(
									'Address gaps competitors miss',
									'thinkrank'
								),
								__(
									'Provide more comprehensive coverage',
									'thinkrank'
								),
								__(
									'Better user experience and engagement',
									'thinkrank'
								),
								__(
									'Higher search engine rankings potential',
									'thinkrank'
								),
							].map((pt, i) => (
								<p
									key={i}
									className="tr-text-xs tr-text-success tr-m-0"
								>
									✓ {pt}
								</p>
							))}
						</div>
					</div>
				</div>
			</CollapsibleSection>
		);
	};

	// ── Call-to-Actions ───────────────────────────────────────────────────────

	const renderCallToActions = () => {
		if (!briefData.call_to_actions?.length) return null;

		return (
			<CollapsibleSection
				title={__('Call-to-Action Suggestions', 'thinkrank')}
				count={briefData.call_to_actions.length}
				defaultOpen={false}
				icon={iconCTA}
			>
				<div className="tr-flex tr-flex-col tr-gap-2">
					{briefData.call_to_actions.map((cta, i) => (
						<div
							key={i}
							className="tr-flex tr-items-center tr-gap-3 tr-p-3 tr-rounded-lg tr-bg-white tr-border tr-border-secondary"
						>
							<span className="tr-text-xs tr-font-bold tr-text-tertiary tr-w-5 tr-shrink-0">
								{i + 1}
							</span>
							<span className="tr-flex-1 tr-text-sm tr-text-secondary tr-leading-snug">
								<FormattedText>{cta}</FormattedText>
							</span>
							<button
								type="button"
								onClick={() => copyToClipboard(cta)}
								title={__('Copy CTA', 'thinkrank')}
								className="tr-shrink-0 tr-flex tr-items-center tr-justify-center tr-w-7 tr-h-7 tr-rounded tr-text-tertiary hover:tr-text-brand hover:tr-bg-brand-bg tr-transition-colors"
							>
								<IconCopy />
							</button>
						</div>
					))}
				</div>
			</CollapsibleSection>
		);
	};

	// ── Raw Response ──────────────────────────────────────────────────────────

	const renderRawResponse = () => {
		if (!briefData.raw_response) return null;

		return (
			<CollapsibleSection
				title={__('AI Raw Response', 'thinkrank')}
				defaultOpen={false}
				icon={iconCode}
			>
				<div>
					<pre className="tr-bg-ink-1 tr-text-surface tr-p-4 tr-rounded-lg tr-text-xs tr-overflow-x-auto tr-whitespace-pre-wrap tr-leading-relaxed">
						{briefData.raw_response}
					</pre>
					<div className="tr-mt-3 tr-flex tr-justify-end">
						<Button
							variant="secondary"
							size="sm"
							onClick={() =>
								copyToClipboard(briefData.raw_response)
							}
						>
							<IconCopy className="tr-w-3.5 tr-h-3.5 tr-mr-1.5" />
							{__('Copy', 'thinkrank')}
						</Button>
					</div>
				</div>
			</CollapsibleSection>
		);
	};

	// ── Full Article Draft ────────────────────────────────────────────────────

	const renderArticle = () => {
		if (!briefData.content_body) return null;

		return (
			<CollapsibleSection
				title={__('Full Article Content', 'thinkrank')}
				defaultOpen={true}
				icon={iconOutline}
				headerAction={
					<Button
						variant="secondary"
						size="sm"
						onClick={() => copyToClipboard(briefData.content_body)}
					>
						<IconCopy className="tr-w-3.5 tr-h-3.5 tr-mr-1.5" />
						{__('Copy HTML', 'thinkrank')}
					</Button>
				}
			>
				{/*
				   content_body is the drafted article and is deliberately
				   rendered as markup. It is AI-generated and therefore
				   untrusted, so it is passed through wp_kses_post() server-side
				   in Content_Brief_Generator::sanitize_brief_output() — on the
				   read path as well as generation, so stored briefs are covered
				   too (#365). Do not render provider HTML that has not been
				   through that boundary.
				*/}
				<div
					className="tr-content-brief-article"
					// eslint-disable-next-line react/no-danger
					dangerouslySetInnerHTML={{
						__html: briefData.content_body,
					}}
				/>
			</CollapsibleSection>
		);
	};

	// ── Render ────────────────────────────────────────────────────────────────

	// Build the section list, dropping any section with no data so the nav only
	// lists what the brief actually contains.
	const sections = [
		{
			id: 'overview',
			label: __('Overview', 'thinkrank'),
			node: renderOverview(),
		},
		{
			id: 'article',
			label: __('Article', 'thinkrank'),
			node: renderArticle(),
		},
		{
			id: 'titles',
			label: __('Titles', 'thinkrank'),
			node: renderTitleSuggestions(),
		},
		{
			id: 'outline',
			label: __('Outline', 'thinkrank'),
			node: renderContentOutline(),
		},
		{
			id: 'seo',
			label: __('SEO', 'thinkrank'),
			node: renderSEORecommendations(),
		},
		{
			id: 'social',
			label: __('Social', 'thinkrank'),
			node: renderSocialMedia(),
		},
		{
			id: 'schema',
			label: __('Schema', 'thinkrank'),
			node: renderSchemaMarkup(),
		},
		{
			id: 'visual',
			label: __('Visuals', 'thinkrank'),
			node: renderVisualContent(),
		},
		{
			id: 'competitors',
			label: __('Competitors', 'thinkrank'),
			node: renderCompetitorAnalysis(),
		},
		{
			id: 'gaps',
			label: __('Gaps', 'thinkrank'),
			node: renderCompetitorGaps(),
		},
		{
			id: 'cta',
			label: __('CTAs', 'thinkrank'),
			node: renderCallToActions(),
		},
		{ id: 'raw', label: __('Raw', 'thinkrank'), node: renderRawResponse() },
	].filter((s) => s.node);

	if (sections.length === 0) return null;

	const current = sections.find((s) => s.id === activeSection) || sections[0];

	return (
		<div className="tr-flex tr-flex-col md:tr-flex-row tr-gap-5 tr-items-start">
			{/* Section navigation */}
			<nav
				className="tr-flex md:tr-flex-col tr-gap-1 tr-w-full md:tr-w-52 tr-shrink-0 tr-overflow-x-auto md:tr-overflow-visible md:tr-sticky md:tr-top-4"
				aria-label={__('Content brief sections', 'thinkrank')}
			>
				{sections.map((s) => {
					const isActive = current.id === s.id;
					return (
						<button
							key={s.id}
							type="button"
							onClick={() => setActiveSection(s.id)}
							aria-current={isActive ? 'page' : undefined}
							className={`tr-flex tr-items-center tr-gap-2 tr-whitespace-nowrap tr-px-3 tr-py-2 tr-rounded-lg tr-text-sm tr-font-medium tr-text-left tr-transition-colors md:tr-w-full ${
								isActive
									? 'tr-bg-brand-bg tr-text-brand'
									: 'tr-text-secondary hover:tr-text-primary'
							}`}
						>
							<span
								className="tr-w-1.5 tr-h-1.5 tr-rounded-full tr-shrink-0"
								style={{
									backgroundColor: isActive
										? '#4451FF'
										: '#CBD5E1',
								}}
							/>
							{s.label}
						</button>
					);
				})}
			</nav>

			{/* Active section */}
			<div className="tr-flex-1 tr-min-w-0 tr-w-full">{current.node}</div>
		</div>
	);
};

export default ContentStructure;
