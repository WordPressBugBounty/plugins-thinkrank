/**
 * AI Metadata Generator Component
 *
 * Interface for generating SEO metadata using AI
 *
 * @package
 * @since 1.0.0
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import apiFetch from '@wordpress/api-fetch';
import { Alert, Select, Button, Input, Textarea } from '../ui';
import linkifyMessage from '../../../shared/utils/linkifyMessage';

// Same limits the SERP preview meters use.
const TITLE_MAX = 60;
const DESC_MAX = 160;

/**
 * Metadata Generator Component
 * @param root0
 * @param root0.onMetadataGenerated
 * @param root0.initialContent
 */
const MetadataGenerator = ({ onMetadataGenerated, initialContent = '' }) => {
	const [content, setContent] = useState(initialContent);
	const [targetKeyword, setTargetKeyword] = useState('');
	const [contentType, setContentType] = useState('blog_post');
	const [tone, setTone] = useState('professional');
	const [isGenerating, setIsGenerating] = useState(false);
	const [generatedMetadata, setGeneratedMetadata] = useState(null);
	const [error, setError] = useState(null);
	const [variations, setVariations] = useState([]);

	const aiConfigured = Boolean(window.thinkrankAdmin?.aiConfigured);

	const contentTypeOptions = [
		{ label: __('Blog Post', 'thinkrank'), value: 'blog_post' },
		{ label: __('Product Page', 'thinkrank'), value: 'product' },
		{ label: __('Landing Page', 'thinkrank'), value: 'landing_page' },
		{ label: __('About Page', 'thinkrank'), value: 'about_page' },
		{ label: __('Service Page', 'thinkrank'), value: 'service_page' },
	];

	const toneOptions = [
		{ label: __('Professional', 'thinkrank'), value: 'professional' },
		{ label: __('Friendly', 'thinkrank'), value: 'friendly' },
		{ label: __('Authoritative', 'thinkrank'), value: 'authoritative' },
		{ label: __('Conversational', 'thinkrank'), value: 'conversational' },
		{ label: __('Technical', 'thinkrank'), value: 'technical' },
	];

	const handleGenerate = async () => {
		if (!content.trim()) {
			setError(__('Please enter some content to analyze.', 'thinkrank'));
			return;
		}

		setIsGenerating(true);
		setError(null);

		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/ai/generate-metadata',
				method: 'POST',
				data: {
					content: content.trim(),
					target_keyword: targetKeyword,
					content_type: contentType,
					tone,
				},
			});

			if (response.success) {
				setGeneratedMetadata(response.data);
				if (onMetadataGenerated) {
					onMetadataGenerated(response.data);
				}
			} else {
				setError(
					response.message ||
						__('Failed to generate metadata', 'thinkrank')
				);
			}
		} catch (err) {
			setError(
				err.message ||
					__(
						'An error occurred while generating metadata',
						'thinkrank'
					)
			);
		} finally {
			setIsGenerating(false);
		}
	};

	const handleGenerateVariations = async () => {
		if (!generatedMetadata) {
			await handleGenerate();
			return;
		}

		setIsGenerating(true);
		setError(null);

		try {
			// Generate 3 variations
			const variationPromises = [];
			for (let i = 0; i < 3; i++) {
				variationPromises.push(
					apiFetch({
						path: '/thinkrank/v1/ai/generate-metadata',
						method: 'POST',
						data: {
							content: content.trim(),
							target_keyword: targetKeyword,
							content_type: contentType,
							tone,
							variation: i + 1,
						},
					})
				);
			}

			const responses = await Promise.all(variationPromises);
			const successfulVariations = responses
				.filter((response) => response.success)
				.map((response) => response.data);

			setVariations(successfulVariations);
		} catch (err) {
			setError(
				err.message || __('Failed to generate variations', 'thinkrank')
			);
		} finally {
			setIsGenerating(false);
		}
	};

	const selectVariation = (variation) => {
		setGeneratedMetadata(variation);
		if (onMetadataGenerated) {
			onMetadataGenerated(variation);
		}
	};

	return (
		<div className="tr-bg-white tr-rounded-xl tr-shadow-sm tr-overflow-hidden">
			{/* Header */}
			<div className="tr-relative tr-overflow-hidden tr-bg-[linear-gradient(90deg,#4c4cff_58%,#3535b0_118%)] tr-px-6 tr-py-5 tr-flex tr-items-center tr-gap-3.5">
				{/* Decorative glow accents */}
				<span
					aria-hidden="true"
					className="tr-pointer-events-none tr-absolute tr-top-[-2.5rem] tr-right-[-2rem] tr-w-40 tr-h-40 tr-rounded-full tr-bg-white/10 tr-blur-2xl"
				/>
				<span
					aria-hidden="true"
					className="tr-pointer-events-none tr-absolute tr-bottom-[-3rem] tr-left-[6rem] tr-w-32 tr-h-32 tr-rounded-full tr-bg-white/5 tr-blur-2xl"
				/>
				<span className="tr-relative tr-flex tr-items-center tr-justify-center tr-w-11 tr-h-11 tr-rounded-xl tr-bg-white/15 tr-ring-1 tr-ring-white/25 tr-text-white tr-shrink-0 tr-shadow-sm">
					<svg
						className="tr-w-5 tr-h-5"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						strokeWidth="1.8"
						strokeLinecap="round"
						strokeLinejoin="round"
						aria-hidden="true"
					>
						<path d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
						<path d="M6 6h.008v.008H6V6z" />
					</svg>
				</span>
				<div className="tr-relative">
					<h3 className="tr-text-white tr-font-semibold tr-text-base tr-m-0 tr-leading-tight">
						{__('AI Metadata Generator', 'thinkrank')}
					</h3>
					<p className="tr-text-white/70 tr-text-xs tr-m-0 tr-mt-0.5">
						{__(
							'Generate SEO-ready titles, descriptions, and keywords from your content',
							'thinkrank'
						)}
					</p>
				</div>
			</div>

			{/* Form body */}
			<div className="tr-p-6 tr-flex tr-flex-col tr-gap-6">
				{error && (
					<Alert variant="error" onDismiss={() => setError(null)}>
						{linkifyMessage(error)}
					</Alert>
				)}

				<Textarea
					label={__('Content to Analyze', 'thinkrank')}
					value={content}
					onChange={(e) => setContent(e.target.value)}
					rows={6}
					placeholder={__(
						'Enter your blog post, page content, or product description…',
						'thinkrank'
					)}
					help={__(
						'Paste your content here for AI analysis and metadata generation.',
						'thinkrank'
					)}
				/>

				<div className="tr-grid tr-grid-cols-1 md:tr-grid-cols-3 tr-gap-4">
					<Input
						label={__('Target Keyword', 'thinkrank')}
						value={targetKeyword}
						onChange={(e) => setTargetKeyword(e.target.value)}
						placeholder={__('e.g., "WordPress SEO"', 'thinkrank')}
						help={__(
							'Main keyword you want to optimize for',
							'thinkrank'
						)}
					/>
					<Select
						label={__('Content Type', 'thinkrank')}
						value={contentType}
						options={contentTypeOptions}
						onChange={(e) => setContentType(e.target.value)}
					/>
					<Select
						label={__('Tone', 'thinkrank')}
						value={tone}
						options={toneOptions}
						onChange={(e) => setTone(e.target.value)}
					/>
				</div>
			</div>

			{/* Footer with generate buttons */}
			<div className="tr-px-6 tr-py-4 tr-bg-white tr-border-t tr-border-secondary tr-flex tr-flex-col sm:tr-flex-row tr-gap-3">
				<Button
					variant="primary"
					size="lg"
					className="tr-flex-1 tr-justify-center tr-bg-brand tr-border-0 hover:tr-bg-[#3641D9]"
					onClick={handleGenerate}
					loading={isGenerating}
					disabled={isGenerating || !content.trim() || !aiConfigured}
					title={
						!aiConfigured
							? __(
									'Configure an AI provider in Settings to enable AI generation',
									'thinkrank'
								)
							: undefined
					}
				>
					{!isGenerating && (
						<svg
							aria-hidden="true"
							width="14"
							height="17"
							viewBox="0 0 15 18"
							fill="none"
							xmlns="http://www.w3.org/2000/svg"
							className="tr-mr-1.5 tr-text-white"
						>
							<path
								d="M5.58072 3.39707L6.0826 4.79082C6.35798 5.55489 6.79852 6.2488 7.37282 6.8231C7.94712 7.3974 8.64103 7.83794 9.4051 8.11332L10.7988 8.6152C10.8264 8.62529 10.8501 8.64359 10.8669 8.66762C10.8837 8.69165 10.8927 8.72026 10.8927 8.74957C10.8927 8.77888 10.8837 8.80749 10.8669 8.83152C10.8501 8.85556 10.8264 8.87385 10.7988 8.88395L9.4051 9.38582C8.64103 9.6612 7.94712 10.1017 7.37282 10.676C6.79852 11.2503 6.35798 11.9443 6.0826 12.7083L5.58072 14.1021C5.57063 14.1296 5.55233 14.1534 5.5283 14.1701C5.50427 14.1869 5.47566 14.1959 5.44635 14.1959C5.41704 14.1959 5.38843 14.1869 5.3644 14.1701C5.34036 14.1534 5.32207 14.1296 5.31197 14.1021L4.8101 12.7083C4.53472 11.9443 4.09418 11.2503 3.51988 10.676C2.94558 10.1017 2.25167 9.6612 1.4876 9.38582L0.093849 8.88395C0.0663279 8.87385 0.0425693 8.85556 0.025785 8.83152C0.00900062 8.80749 0 8.77888 0 8.74957C0 8.72026 0.00900062 8.69165 0.025785 8.66762C0.0425693 8.64359 0.0663279 8.62529 0.093849 8.6152L1.4876 8.11332C2.25167 7.83794 2.94558 7.3974 3.51988 6.8231C4.09418 6.2488 4.53472 5.55489 4.8101 4.79082L5.31197 3.39707C5.32179 3.36927 5.33998 3.3452 5.36405 3.32817C5.38811 3.31115 5.41687 3.302 5.44635 3.302C5.47583 3.302 5.50459 3.31115 5.52865 3.32817C5.55272 3.3452 5.57091 3.36927 5.58072 3.39707ZM11.8545 0.0476961L12.1088 0.753321C12.2484 1.14018 12.4715 1.49151 12.7624 1.78232C13.0532 2.07313 13.4045 2.29625 13.7913 2.43582L14.497 2.6902C14.511 2.69529 14.523 2.70455 14.5316 2.71674C14.5401 2.72893 14.5447 2.74344 14.5447 2.75832C14.5447 2.7732 14.5401 2.78772 14.5316 2.7999C14.523 2.81209 14.511 2.82136 14.497 2.82645L13.7913 3.08082C13.4045 3.22039 13.0532 3.44352 12.7624 3.73432C12.4715 4.02513 12.2484 4.37646 12.1088 4.76332L11.8545 5.46895C11.8494 5.48293 11.8401 5.495 11.8279 5.50353C11.8157 5.51207 11.8012 5.51664 11.7863 5.51664C11.7715 5.51664 11.757 5.51207 11.7448 5.50353C11.7326 5.495 11.7233 5.48293 11.7182 5.46895L11.4639 4.76332C11.3243 4.37646 11.1012 4.02513 10.8103 3.73432C10.5195 3.44352 10.1682 3.22039 9.78135 3.08082L9.07572 2.82645C9.06174 2.82136 9.04967 2.81209 9.04114 2.7999C9.03261 2.78772 9.02803 2.7732 9.02803 2.75832C9.02803 2.74344 9.03261 2.72893 9.04114 2.71674C9.04967 2.70455 9.06174 2.69529 9.07572 2.6902L9.78135 2.43582C10.1682 2.29625 10.5195 2.07313 10.8103 1.78232C11.1012 1.49151 11.3243 1.14018 11.4639 0.753321L11.7182 0.0476961C11.7233 0.0337168 11.7326 0.0216418 11.7448 0.0131093C11.757 0.00457683 11.7715 0 11.7863 0C11.8012 0 11.8157 0.00457683 11.8279 0.0131093C11.8401 0.0216418 11.8494 0.0337168 11.8545 0.0476961ZM11.8545 12.0308L12.1088 12.7364C12.2484 13.1233 12.4715 13.4746 12.7624 13.7654C13.0532 14.0563 13.4045 14.2794 13.7913 14.4189L14.497 14.6733C14.511 14.6784 14.523 14.6877 14.5316 14.6999C14.5401 14.7121 14.5447 14.7266 14.5447 14.7414C14.5447 14.7563 14.5401 14.7708 14.5316 14.783C14.523 14.7952 14.511 14.8045 14.497 14.8096L13.7913 15.0639C13.4045 15.2035 13.0532 15.4266 12.7624 15.7174C12.4715 16.0083 12.2484 16.3596 12.1088 16.7464L11.8545 17.4521C11.8494 17.4661 11.8401 17.4781 11.8279 17.4867C11.8157 17.4952 11.8012 17.4998 11.7863 17.4998C11.7715 17.4998 11.757 17.4952 11.7448 17.4867C11.7326 17.4781 11.7233 17.4661 11.7182 17.4521L11.4639 16.7464C11.3243 16.3596 11.1012 16.0083 10.8103 15.7174C10.5195 15.4266 10.1682 15.2035 9.78135 15.0639L9.07572 14.8096C9.06174 14.8045 9.04967 14.7952 9.04114 14.783C9.03261 14.7708 9.02803 14.7563 9.02803 14.7414C9.02803 14.7266 9.03261 14.7121 9.04114 14.6999C9.04967 14.6877 9.06174 14.6784 9.07572 14.6733L9.78135 14.4189C10.1682 14.2794 10.5195 14.0563 10.8103 13.7654C11.1012 13.4746 11.3243 13.1233 11.4639 12.7364L11.7182 12.0308C11.7413 11.9671 11.832 11.9671 11.8545 12.0308Z"
								fill="currentColor"
							/>
						</svg>
					)}
					{isGenerating
						? __('Generating…', 'thinkrank')
						: __('Generate SEO Metadata', 'thinkrank')}
				</Button>
				{generatedMetadata && (
					<Button
						variant="secondary"
						size="lg"
						className="tr-justify-center"
						onClick={handleGenerateVariations}
						disabled={isGenerating || !aiConfigured}
					>
						{__('Generate Variations', 'thinkrank')}
					</Button>
				)}
			</div>

			{variations.length > 0 && (
				<div className="tr-px-6 tr-pb-6">
					<h4 className="tr-text-base tr-font-semibold tr-text-primary tr-mb-4 tr-mt-0">
						{__('Alternative Variations', 'thinkrank')}
					</h4>
					<div className="tr-flex tr-flex-col tr-gap-4">
						{variations.map((variation, index) => (
							<div
								key={index}
								className="tr-border-l-4 tr-border-brand tr-bg-white tr-rounded-lg"
							>
								<MetadataPreview
									metadata={variation}
									isVariation={true}
									onSelect={() => selectVariation(variation)}
								/>
							</div>
						))}
					</div>
				</div>
			)}
		</div>
	);
};

/**
 * Copy-to-clipboard button for a single metadata field.
 * @param root0
 * @param root0.value
 * @param root0.label
 */
const CopyButton = ({ value, label }) => {
	const [copied, setCopied] = useState(false);

	const handleCopy = async () => {
		if (!value) {
			return;
		}
		try {
			await navigator.clipboard.writeText(value);
			setCopied(true);
			setTimeout(() => setCopied(false), 1500);
		} catch (e) {
			// Clipboard unavailable (e.g. insecure context) — silently ignore.
		}
	};

	return (
		<button
			type="button"
			onClick={handleCopy}
			disabled={!value}
			aria-label={label}
			className="tr-inline-flex tr-items-center tr-gap-1 tr-text-xs tr-font-semibold tr-bg-transparent tr-border-0 tr-p-0 tr-cursor-pointer tr-transition-colors disabled:tr-opacity-40 disabled:tr-cursor-not-allowed tr-text-brand hover:tr-text-[#3641D9]"
		>
			{copied ? (
				<>
					<svg
						className="tr-w-3.5 tr-h-3.5"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						strokeWidth="2.2"
						strokeLinecap="round"
						strokeLinejoin="round"
						aria-hidden="true"
					>
						<path d="M20 6L9 17l-5-5" />
					</svg>
					{__('Copied', 'thinkrank')}
				</>
			) : (
				<>
					<svg
						className="tr-w-3.5 tr-h-3.5"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						strokeWidth="1.8"
						strokeLinecap="round"
						strokeLinejoin="round"
						aria-hidden="true"
					>
						<rect x="9" y="9" width="11" height="11" rx="2" />
						<path d="M5 15V5a2 2 0 0 1 2-2h10" />
					</svg>
					{__('Copy', 'thinkrank')}
				</>
			)}
		</button>
	);
};

/**
 * Locked (upsell) state for the Apply-to-post control shown to free users.
 */
const ApplyToPostLocked = () => (
	<div className="tr-mt-5 tr-pt-5 tr-border-t tr-border-secondary">
		<div className="tr-flex tr-items-center tr-gap-2 tr-mb-2">
			<span className="tr-block tr-text-sm tr-font-medium tr-text-primary">
				{__('Apply to a post', 'thinkrank')}
			</span>
			<span
				className="tr-inline-flex tr-items-center tr-gap-1 tr-px-2 tr-py-0.5 tr-rounded-full tr-text-[11px] tr-font-semibold tr-uppercase tr-tracking-wide"
				style={{
					color: '#9a3412',
					background: '#ffedd5',
					border: '1px solid #fed7aa',
				}}
			>
				<span aria-hidden="true">✦</span>
				{__('Pro', 'thinkrank')}
			</span>
		</div>
		<div className="tr-flex tr-flex-col sm:tr-flex-row tr-gap-2">
			<input
				type="text"
				disabled
				placeholder={__('Search for a post or page…', 'thinkrank')}
				className="tr-w-full sm:tr-flex-1 tr-rounded-lg tr-border-[1.5px] tr-border-slate-300 tr-px-3 tr-py-2 tr-text-sm tr-bg-[#F7F8FB] tr-text-tertiary tr-cursor-not-allowed"
			/>
			<a
				href="https://thinkrank.ai/#pricing"
				target="_blank"
				rel="noopener noreferrer"
				className="tr-inline-flex tr-items-center tr-justify-center tr-gap-2 tr-h-10 tr-px-4 tr-rounded-md tr-border tr-border-transparent tr-text-sm tr-font-medium tr-no-underline tr-bg-brand tr-text-white hover:tr-bg-[#3641D9] tr-transition-colors tr-shrink-0"
			>
				{__('Upgrade to apply', 'thinkrank')}
			</a>
		</div>
		<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-2">
			{__(
				'Push the generated title, description, and focus keyword straight to any post with ThinkRank Pro.',
				'thinkrank'
			)}
		</p>
	</div>
);

/**
 * Apply-to-post slot. ThinkRank Pro injects the working control via the
 * `thinkrank.metadata.applyTab` filter; free users see the upsell.
 * @param root0
 * @param root0.metadata
 */
const ApplyToPostSlot = ({ metadata }) => {
	const injected = applyFilters('thinkrank.metadata.applyTab', null, {
		metadata,
	});
	if (injected) {
		return injected;
	}
	// Pro active but nothing injected (e.g. old Pro build) — render nothing.
	if (window.thinkrankAdmin?.isPro) {
		return null;
	}
	return <ApplyToPostLocked />;
};

/**
 * Map a field length to a status pill (label + color classes).
 * @param {number} length Current length.
 * @param {number} max    Recommended maximum.
 * @return {{label: string, text: string, bg: string}} Pill descriptor.
 */
const lengthTag = (length, max) => {
	if (length === 0) {
		return {
			label: __('Empty', 'thinkrank'),
			text: 'tr-text-tertiary',
			bg: 'tr-bg-[#f2f4f7]',
		};
	}
	if (length > max) {
		return {
			label: __('Too long', 'thinkrank'),
			text: 'tr-text-danger',
			bg: 'tr-bg-[#fef3f2]',
		};
	}
	if (length < max * 0.5) {
		return {
			label: __('Too short', 'thinkrank'),
			text: 'tr-text-warning',
			bg: 'tr-bg-[#fffaeb]',
		};
	}
	return {
		label: __('Good', 'thinkrank'),
		text: 'tr-text-success',
		bg: 'tr-bg-[#ecfdf3]',
	};
};

/**
 * Metadata Preview Component
 * @param root0
 * @param root0.metadata
 * @param root0.isVariation
 * @param root0.onSelect
 * @param root0.allowApply
 */
const MetadataPreview = ({
	metadata,
	isVariation = false,
	onSelect = null,
	allowApply = false,
}) => {
	// Count from the rendered strings — the API length fields are only a hint
	// and may be missing on older/cached responses.
	const titleLength = [...(metadata.title || '')].length;
	const descriptionLength = [...(metadata.description || '')].length;
	const titleTag = lengthTag(titleLength, TITLE_MAX);
	const descriptionTag = lengthTag(descriptionLength, DESC_MAX);

	return (
		<div className="tr-bg-white tr-rounded-xl tr-border tr-border-secondary tr-p-5">
			<div className="tr-flex tr-items-center tr-gap-2 tr-mb-4">
				<span className="tr-flex tr-items-center tr-justify-center tr-w-8 tr-h-8 tr-rounded-lg tr-bg-brand-bg tr-text-brand tr-shrink-0">
					<svg
						className="tr-w-[18px] tr-h-[18px]"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						strokeWidth="1.8"
						strokeLinecap="round"
						strokeLinejoin="round"
						aria-hidden="true"
					>
						<path d="M9 12l2 2 4-4" />
						<circle cx="12" cy="12" r="9" />
					</svg>
				</span>
				<h4 className="tr-text-base tr-font-semibold tr-text-primary tr-m-0">
					{__('Generated SEO Metadata', 'thinkrank')}
				</h4>
			</div>

			{/* SEO Title */}
			<div className="tr-mb-3 tr-rounded-lg tr-border tr-border-secondary tr-bg-[#F7F8FB] tr-p-3">
				<div className="tr-flex tr-items-center tr-justify-between tr-mb-1.5">
					<span className="tr-text-[11px] tr-font-semibold tr-uppercase tr-tracking-wide tr-text-tertiary">
						{__('SEO Title', 'thinkrank')}
					</span>
					<CopyButton
						value={metadata.title}
						label={__('Copy SEO title', 'thinkrank')}
					/>
				</div>
				<p className="tr-text-sm tr-font-medium tr-text-primary tr-m-0 tr-mb-2">
					{metadata.title}
				</p>
				<span
					className={`tr-inline-flex tr-items-center tr-px-2 tr-py-0.5 tr-rounded-full tr-text-[11px] tr-font-semibold ${titleTag.text} ${titleTag.bg}`}
				>
					{titleLength}/{TITLE_MAX} · {titleTag.label}
				</span>
			</div>

			{/* Meta Description */}
			<div className="tr-mb-3 tr-rounded-lg tr-border tr-border-secondary tr-bg-[#F7F8FB] tr-p-3">
				<div className="tr-flex tr-items-center tr-justify-between tr-mb-1.5">
					<span className="tr-text-[11px] tr-font-semibold tr-uppercase tr-tracking-wide tr-text-tertiary">
						{__('Meta Description', 'thinkrank')}
					</span>
					<CopyButton
						value={metadata.description}
						label={__('Copy meta description', 'thinkrank')}
					/>
				</div>
				<p className="tr-text-sm tr-text-secondary tr-leading-relaxed tr-m-0 tr-mb-2">
					{metadata.description}
				</p>
				<span
					className={`tr-inline-flex tr-items-center tr-px-2 tr-py-0.5 tr-rounded-full tr-text-[11px] tr-font-semibold ${descriptionTag.text} ${descriptionTag.bg}`}
				>
					{descriptionLength}/{DESC_MAX} · {descriptionTag.label}
				</span>
			</div>

			{metadata.focus_keyword && (
				<div className="tr-mb-3 tr-rounded-lg tr-border tr-border-secondary tr-bg-[#F7F8FB] tr-p-3">
					<div className="tr-flex tr-items-center tr-justify-between tr-mb-1.5">
						<span className="tr-text-[11px] tr-font-semibold tr-uppercase tr-tracking-wide tr-text-tertiary">
							{__('Focus Keyword', 'thinkrank')}
						</span>
						<CopyButton
							value={metadata.focus_keyword}
							label={__('Copy focus keyword', 'thinkrank')}
						/>
					</div>
					<span className="tr-inline-flex tr-items-center tr-px-2.5 tr-py-1 tr-rounded-full tr-bg-brand-bg tr-text-brand tr-text-sm tr-font-medium">
						{metadata.focus_keyword}
					</span>
				</div>
			)}

			{metadata.suggestions && metadata.suggestions.length > 0 && (
				<div className="tr-mt-4 tr-rounded-lg tr-border tr-border-secondary tr-bg-[#F7F8FB] tr-p-3">
					<span className="tr-block tr-text-[11px] tr-font-semibold tr-uppercase tr-tracking-wide tr-text-tertiary tr-mb-2">
						{__('AI Suggestions', 'thinkrank')}
					</span>
					<ul className="tr-list-none tr-m-0 tr-p-0 tr-flex tr-flex-col tr-gap-2">
						{metadata.suggestions.map((suggestion, index) => (
							<li
								key={index}
								className="tr-flex tr-items-start tr-gap-2 tr-text-sm tr-text-secondary"
							>
								<svg
									className="tr-w-4 tr-h-4 tr-mt-0.5 tr-shrink-0 tr-text-brand"
									viewBox="0 0 24 24"
									fill="none"
									stroke="currentColor"
									strokeWidth="1.8"
									strokeLinecap="round"
									strokeLinejoin="round"
									aria-hidden="true"
								>
									<path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9L12 3z" />
								</svg>
								<span>{suggestion}</span>
							</li>
						))}
					</ul>
				</div>
			)}

			{isVariation && onSelect && (
				<div className="tr-mt-4">
					<button
						type="button"
						className="tr-inline-flex tr-items-center tr-justify-center tr-h-9 tr-px-3 tr-rounded-md tr-border tr-border-primary tr-bg-white tr-text-secondary tr-text-sm tr-font-medium tr-cursor-pointer hover:tr-bg-[#F7F8FB] tr-transition-colors"
						onClick={onSelect}
					>
						{__('Use This Variation', 'thinkrank')}
					</button>
				</div>
			)}

			{allowApply && <ApplyToPostSlot metadata={metadata} />}
		</div>
	);
};

export default MetadataGenerator;
export { MetadataPreview };
