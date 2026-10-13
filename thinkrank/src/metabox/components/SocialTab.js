/**
 * Social Tab Component for Metabox
 *
 * Edits per-post Open Graph (Facebook) and Twitter Card metadata, and renders
 * a live preview that reflects entered values with sensible fallbacks
 * (SEO title / meta description / featured image).
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import Badge from '../../admin/components/ui/Badge';
import MediaPicker from '../../admin/components/common/MediaPicker';
import VariableTags from './ui/VariableTags';
import { resolvePattern } from '../utils/resolvePattern';

const PLATFORMS = [
	{ label: __('Facebook', 'thinkrank'), value: 'facebook' },
	{ label: __('Twitter', 'thinkrank'), value: 'twitter' },
	{ label: __('LinkedIn', 'thinkrank'), value: 'linkedin' },
	{ label: __('Pinterest', 'thinkrank'), value: 'pinterest' },
];
const TWITTER_PLATFORMS = ['twitter'];

// Variable tags offered for social title / description fields.
const SOCIAL_TITLE_VARIABLES = [
	{ label: __('+ Title', 'thinkrank'), value: '%title%' },
	{ label: __('+ Site Title', 'thinkrank'), value: '%sitename%' },
	{ label: __('+ Separator', 'thinkrank'), value: '%sep%' },
];
const SOCIAL_DESCRIPTION_VARIABLES = [
	{ label: __('+ Excerpt', 'thinkrank'), value: '%excerpt%' },
	{ label: __('+ Site Title', 'thinkrank'), value: '%sitename%' },
	{ label: __('+ Title', 'thinkrank'), value: '%title%' },
	{ label: __('+ Separator', 'thinkrank'), value: '%sep%' },
];

const fieldClass =
	'tr-w-full tr-rounded tr-border tr-border-primary tr-bg-white tr-text-sm tr-text-primary tr-px-3 focus:tr-outline-none focus:tr-ring-2 focus:tr-ring-brand focus:tr-border-brand tr-transition-colors';

const isValidImageUrl = (url) => {
	if (!url || typeof url !== 'string') return false;
	try {
		const urlObj = new URL(url);
		return ['http:', 'https:'].includes(urlObj.protocol);
	} catch {
		return false;
	}
};

// Display domain (no protocol / www) for the preview card.
const getDomain = (url) => {
	if (!url) return '';
	try {
		return new URL(url).hostname.replace(/^www\./, '');
	} catch {
		return url;
	}
};

const SocialTab = ({
	postId,
	postTitle = '',
	formData = {},
	onChange,
	showNotice,
	patternPreviews = {},
	patternVariables = {},
}) => {
	const [selectedPlatform, setSelectedPlatform] = useState('facebook');
	const [socialPreview, setSocialPreview] = useState(null);
	const [isGenerating, setIsGenerating] = useState(false);
	const [validationErrors, setValidationErrors] = useState([]);

	const metaboxData = window.thinkrankMetabox || {};
	const featuredImageUrl = metaboxData.featuredImageUrl || '';
	const postPermalink = metaboxData.postPermalink || '';
	const siteName = metaboxData.siteName || '';

	const ogTitle = formData.thinkrank_og_title || '';
	const ogDescription = formData.thinkrank_og_description || '';
	const ogImage = formData.thinkrank_og_image || '';
	const twitterTitle = formData.thinkrank_twitter_title || '';
	const twitterDescription = formData.thinkrank_twitter_description || '';
	const twitterImage = formData.thinkrank_twitter_image || '';

	// When the SEO fields are empty too, fall back to the resolved Global/Bulk
	// SEO pattern preview so the social placeholders + preview mirror output.
	const fallbackTitle =
		formData.thinkrank_seo_title || patternPreviews.og_title || postTitle;
	const fallbackDescription =
		formData.thinkrank_meta_description ||
		patternPreviews.og_description ||
		'';

	const updateField = (key, value) => onChange && onChange(key, value);

	// Append a %tag% to a social field, keeping a space between tokens.
	const insertSocialVar = (key, current, token) => {
		const next =
			current && !current.endsWith(' ')
				? `${current} ${token}`
				: `${current}${token}`;
		updateField(key, next);
	};

	/**
	 * Resolve effective social data per platform with fallback chain.
	 * Twitter falls back to OG values, then SEO Analysis, then post defaults.
	 * @param platform
	 */
	const resolveSocialData = (platform) => {
		const isTwitter = TWITTER_PLATFORMS.includes(platform);

		// Resolve any variable tags so the preview reflects the rendered output.
		const title = resolvePattern(
			(isTwitter && twitterTitle) ||
				ogTitle ||
				fallbackTitle ||
				__('Your Post Title', 'thinkrank'),
			patternVariables
		);

		const description = resolvePattern(
			(isTwitter && twitterDescription) ||
				ogDescription ||
				fallbackDescription ||
				__(
					'Add a meta description to improve how your post appears when shared on social media.',
					'thinkrank'
				),
			patternVariables
		);

		const image =
			(isTwitter && twitterImage) || ogImage || featuredImageUrl || '';

		return {
			title,
			description,
			url: postPermalink,
			image,
			type: 'article',
			site_name: siteName,
		};
	};

	const generatePreview = async (isCancelled = () => false) => {
		try {
			setIsGenerating(true);
			setValidationErrors([]);

			const socialData = resolveSocialData(selectedPlatform);

			const errors = [];
			if (socialData.image && !isValidImageUrl(socialData.image)) {
				errors.push(__('Invalid image URL format', 'thinkrank'));
			}
			if (errors.length) {
				setValidationErrors(errors);
				showNotice(
					__(
						'Please fix validation errors before generating preview',
						'thinkrank'
					),
					'error'
				);
				return;
			}

			const response = await apiFetch({
				path: '/thinkrank/v1/social-media/preview',
				method: 'POST',
				data: { data: socialData, platform: selectedPlatform },
			});

			if (isCancelled()) {
				return;
			}

			if (response.success) {
				setSocialPreview({
					...response.data,
					platform: selectedPlatform,
				});
			} else {
				setSocialPreview({
					title: socialData.title,
					description: socialData.description,
					image: socialData.image,
					preview_url: socialData.url,
					platform: selectedPlatform,
					valid: true,
					warnings: response.message ? [response.message] : [],
				});
			}
		} catch (error) {
			if (isCancelled()) {
				return;
			}

			const socialData = resolveSocialData(selectedPlatform);
			setSocialPreview({
				title: socialData.title,
				description: socialData.description,
				image: socialData.image,
				preview_url: socialData.url,
				platform: selectedPlatform,
				valid: true,
				warnings: [
					__(
						'Preview generated locally - API unavailable',
						'thinkrank'
					),
				],
			});
		} finally {
			if (!isCancelled()) {
				setIsGenerating(false);
			}
		}
	};

	// Auto-refresh preview when source data or platform changes (debounced).
	//
	// The debounce bounds how often a request starts; it does nothing about how
	// long one takes. Continued typing produced overlapping in-flight requests
	// with no cancellation, so a slow earlier response could resolve last and
	// repaint the card with a stale title and description (#404). The effect's
	// cleanup marks its own request abandoned, and generatePreview() checks
	// that before every setState.
	useEffect(() => {
		if (!postId) return undefined;

		let cancelled = false;
		const timer = setTimeout(() => generatePreview(() => cancelled), 400);

		return () => {
			cancelled = true;
			clearTimeout(timer);
		};
	}, [
		selectedPlatform,
		ogTitle,
		ogDescription,
		ogImage,
		twitterTitle,
		twitterDescription,
		twitterImage,
		formData.thinkrank_seo_title,
		formData.thinkrank_meta_description,
	]);

	const renderEditor = (variant) => {
		const isTwitter = variant === 'twitter';
		// renderEditor() is called once per variant and both stay mounted, so
		// the ids have to carry the variant or the second group's labels point
		// at the first group's fields.
		const fieldId = (field) => `tr-social-${variant}-${field}`;
		const titleValue = isTwitter ? twitterTitle : ogTitle;
		const descValue = isTwitter ? twitterDescription : ogDescription;
		const imageValue = isTwitter ? twitterImage : ogImage;
		const titleKey = isTwitter
			? 'thinkrank_twitter_title'
			: 'thinkrank_og_title';
		const descKey = isTwitter
			? 'thinkrank_twitter_description'
			: 'thinkrank_og_description';
		const imageKey = isTwitter
			? 'thinkrank_twitter_image'
			: 'thinkrank_og_image';
		const placeholderTitle = isTwitter
			? ogTitle ||
				fallbackTitle ||
				__('Inherits Open Graph or SEO title', 'thinkrank')
			: fallbackTitle || __('Inherits SEO / post title', 'thinkrank');
		const placeholderDesc = isTwitter
			? ogDescription ||
				fallbackDescription ||
				__('Inherits Open Graph or meta description', 'thinkrank')
			: fallbackDescription ||
				__('Inherits meta description', 'thinkrank');
		const placeholderImage = isTwitter
			? ogImage ||
				featuredImageUrl ||
				__('Inherits Open Graph image or featured image', 'thinkrank')
			: featuredImageUrl || __('Inherits featured image', 'thinkrank');

		return (
			<div className="tr-flex tr-flex-col tr-gap-3">
				<div>
					<label
						className="tr-text-sm tr-font-medium tr-text-secondary tr-block tr-mb-1"
						htmlFor={fieldId('title')}
					>
						{__('Title', 'thinkrank')}
					</label>
					<input
						id={fieldId('title')}
						type="text"
						value={titleValue}
						onChange={(e) => updateField(titleKey, e.target.value)}
						placeholder={placeholderTitle}
						className={`${fieldClass} tr-h-9`}
					/>
					<VariableTags
						variables={SOCIAL_TITLE_VARIABLES}
						onInsert={(token) =>
							insertSocialVar(titleKey, titleValue, token)
						}
						value={titleValue}
						vars={patternVariables}
						fallback={fallbackTitle}
					/>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-1">
						{isTwitter
							? __(
									'Recommended: up to 70 characters.',
									'thinkrank'
								)
							: __(
									'Recommended: up to 60 characters.',
									'thinkrank'
								)}
					</p>
				</div>

				<div>
					<label
						className="tr-text-sm tr-font-medium tr-text-secondary tr-block tr-mb-1"
						htmlFor={fieldId('description')}
					>
						{__('Description', 'thinkrank')}
					</label>
					<textarea
						id={fieldId('description')}
						rows={3}
						value={descValue}
						onChange={(e) => updateField(descKey, e.target.value)}
						placeholder={placeholderDesc}
						className={`${fieldClass} tr-py-2 tr-resize-y`}
					/>
					<VariableTags
						variables={SOCIAL_DESCRIPTION_VARIABLES}
						onInsert={(token) =>
							insertSocialVar(descKey, descValue, token)
						}
						value={descValue}
						vars={patternVariables}
						fallback={fallbackDescription}
					/>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-1">
						{__('Recommended: up to 200 characters.', 'thinkrank')}
					</p>
				</div>

				<div>
					<span className="tr-text-sm tr-font-medium tr-text-secondary tr-block tr-mb-1">
						{__('Image', 'thinkrank')}
					</span>

					{/* Same drop zone / preview row the admin screens use, so
					    the two places look and behave alike. */}
					<MediaPicker
						value={imageValue}
						onChange={(url) => updateField(imageKey, url)}
						allowedTypes={['image']}
					/>

					{!isTwitter && featuredImageUrl && !imageValue && (
						<button
							type="button"
							onClick={() =>
								updateField(imageKey, featuredImageUrl)
							}
							className="tr-text-xs tr-font-medium tr-text-brand tr-bg-transparent tr-border-0 tr-cursor-pointer tr-p-0 tr-mt-1.5"
						>
							{__('Use featured image', 'thinkrank')}
						</button>
					)}

					{/* The picker only reaches the media library, so keep a
					    way to point at an image hosted elsewhere. */}
					<label
						className="tr-text-xs tr-font-medium tr-text-tertiary tr-block tr-mt-2.5 tr-mb-1"
						htmlFor={fieldId('image')}
					>
						{__('Or paste an image URL', 'thinkrank')}
					</label>
					<input
						id={fieldId('image')}
						type="url"
						value={imageValue}
						onChange={(e) => updateField(imageKey, e.target.value)}
						placeholder={placeholderImage}
						className={`${fieldClass} tr-h-9`}
					/>
				</div>
			</div>
		);
	};

	const platformName =
		selectedPlatform.charAt(0).toUpperCase() + selectedPlatform.slice(1);

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{/* Open Graph + Twitter — side by side, equal height */}
			<div className="tr-flex tr-flex-col md:tr-flex-row tr-gap-4 md:tr-items-stretch">
				{/* Open Graph editor */}
				<div className="tr-flex-1 tr-min-w-0 tr-bg-white tr-rounded-lg tr-border tr-border-secondary">
					<div className="tr-px-4 tr-py-3 tr-border-b tr-border-secondary">
						<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__(
								'Open Graph (Facebook, LinkedIn, Pinterest)',
								'thinkrank'
							)}
						</h4>
						<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-1">
							{__(
								'Empty fields fall back to the SEO title, meta description, and featured image.',
								'thinkrank'
							)}
						</p>
					</div>
					<div className="tr-p-4">{renderEditor('og')}</div>
				</div>

				{/* Twitter editor */}
				<div className="tr-flex-1 tr-min-w-0 tr-bg-white tr-rounded-lg tr-border tr-border-secondary">
					<div className="tr-px-4 tr-py-3 tr-border-b tr-border-secondary">
						<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Twitter Card', 'thinkrank')}
						</h4>
						<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-1">
							{__(
								'Empty fields fall back to Open Graph values.',
								'thinkrank'
							)}
						</p>
					</div>
					<div className="tr-p-4">{renderEditor('twitter')}</div>
				</div>
			</div>

			{/* Preview */}
			<div className="tr-bg-white tr-rounded-lg tr-border tr-border-secondary">
				<div className="tr-px-4 tr-py-3 tr-border-b tr-border-secondary tr-flex tr-items-center tr-justify-between tr-gap-3 tr-flex-wrap">
					<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Preview', 'thinkrank')}
					</h4>
					<div className="tr-flex tr-items-center tr-gap-2">
						<select
							value={selectedPlatform}
							onChange={(e) =>
								setSelectedPlatform(e.target.value)
							}
							className="tr-h-8 tr-rounded tr-border tr-border-primary tr-bg-white tr-text-xs tr-px-2 focus:tr-outline-none focus:tr-ring-2 focus:tr-ring-brand"
						>
							{PLATFORMS.map((p) => (
								<option key={p.value} value={p.value}>
									{p.label}
								</option>
							))}
						</select>
						<button
							type="button"
							onClick={() => generatePreview()}
							disabled={isGenerating}
							className="tr-inline-flex tr-items-center tr-gap-1 tr-h-8 tr-px-3 tr-rounded tr-text-xs tr-font-medium tr-text-white tr-bg-brand hover:tr-bg-brand disabled:tr-opacity-50 tr-transition-colors tr-border-0 tr-cursor-pointer"
						>
							{isGenerating && (
								<span className="tr-w-3 tr-h-3 tr-border-2 tr-border-white tr-border-t-transparent tr-rounded-full tr-animate-spin" />
							)}
							{isGenerating
								? __('Generating…', 'thinkrank')
								: __('Refresh', 'thinkrank')}
						</button>
					</div>
				</div>

				<div className="tr-p-4">
					{validationErrors.length > 0 && (
						<div className="tr-bg-danger-bg tr-text-danger tr-rounded tr-p-3 tr-text-xs tr-mb-3">
							{validationErrors.map((err, i) => (
								<p key={i} className="tr-m-0">
									{err}
								</p>
							))}
						</div>
					)}

					{!socialPreview ? (
						<div className="tr-flex tr-flex-col tr-items-center tr-justify-center tr-text-center tr-py-10 tr-px-4 tr-rounded-xl tr-border tr-border-dashed tr-border-secondary tr-bg-[#F6F7FB]">
							<span className="tr-text-2xl tr-mb-2">🔗</span>
							<p className="tr-text-sm tr-font-medium tr-text-secondary tr-m-0">
								{__('Social preview', 'thinkrank')}
							</p>
							<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-1 tr-max-w-[260px]">
								{__(
									'Click "Refresh" to see how your post will appear when shared.',
									'thinkrank'
								)}
							</p>
						</div>
					) : (
						<div className="tr-max-w-[480px] tr-mx-auto tr-rounded-xl tr-border tr-border-secondary tr-overflow-hidden tr-bg-white tr-shadow-sm">
							{/* Header: platform + validity */}
							<div className="tr-flex tr-items-center tr-justify-between tr-gap-2 tr-px-3 tr-py-2 tr-border-b tr-border-secondary">
								<span className="tr-text-xs tr-font-semibold tr-text-secondary">
									{platformName} {__('Preview', 'thinkrank')}
								</span>
								{socialPreview.valid ? (
									<Badge variant="good">
										✓ {__('Valid', 'thinkrank')}
									</Badge>
								) : (
									<Badge variant="warning">
										⚠ {__('Issues', 'thinkrank')}
									</Badge>
								)}
							</div>

							{/* Image (or placeholder) */}
							{socialPreview.image ? (
								<div className="tr-aspect-video tr-bg-[#F6F7FB] tr-overflow-hidden">
									<img
										src={socialPreview.image}
										alt={socialPreview.title}
										className="tr-w-full tr-h-full tr-object-cover"
										onError={(e) => {
											e.target.style.display = 'none';
										}}
									/>
								</div>
							) : (
								<div className="tr-aspect-video tr-flex tr-flex-col tr-items-center tr-justify-center tr-gap-1 tr-bg-[#F6F7FB] tr-text-tertiary">
									<span className="tr-text-2xl">🖼️</span>
									<span className="tr-text-[11px]">
										{__('No share image set', 'thinkrank')}
									</span>
								</div>
							)}

							{/* Content (link-card footer) */}
							<div className="tr-bg-[#F2F3F5] tr-px-3 tr-py-2.5">
								<p className="tr-text-[11px] tr-uppercase tr-tracking-wide tr-text-tertiary tr-m-0 tr-truncate">
									{getDomain(
										socialPreview.preview_url ||
											postPermalink
									)}
								</p>
								<p className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0 tr-mt-0.5 tr-leading-snug">
									{socialPreview.title}
								</p>
								<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5 tr-leading-relaxed">
									{socialPreview.description}
								</p>
							</div>

							{/* Warnings */}
							{socialPreview.warnings?.length > 0 && (
								<div className="tr-flex tr-flex-col tr-gap-1 tr-px-3 tr-py-2.5 tr-bg-warning-bg tr-border-t tr-border-secondary">
									{socialPreview.warnings.map((w, i) => (
										<p
											key={i}
											className="tr-text-xs tr-text-warning tr-m-0 tr-flex tr-items-start tr-gap-1"
										>
											<span aria-hidden="true">⚠</span>{' '}
											{w}
										</p>
									))}
								</div>
							)}
						</div>
					)}
				</div>
			</div>
		</div>
	);
};

export default SocialTab;
