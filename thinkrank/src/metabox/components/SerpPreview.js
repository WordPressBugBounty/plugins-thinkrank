import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import classnames from 'classnames';

const SerpPreview = ({ title, description, postTitle, homeUrl, slug }) => {
	const [viewMode, setViewMode] = useState('desktop');

	const displayTitle =
		title || postTitle || __('Your SEO Title', 'thinkrank');
	const displayDescription =
		description ||
		__(
			"Please provide a meta description. If you don't, Google will try to find a relevant part of your post to show in search results.",
			'thinkrank'
		);

	const siteName = window.thinkrankMetabox?.siteName || 'Your Site';
	const domain = homeUrl
		? new URL(homeUrl).hostname
		: window.location.hostname;
	const faviconUrl =
		window.thinkrankMetabox?.faviconUrl || `${homeUrl}/favicon.ico`;
	const featuredImageUrl = window.thinkrankMetabox?.featuredImageUrl;
	const currentDate = new Date().toLocaleDateString('en-US', {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
	});
	// Prefer the actual/edited slug; fall back to a title-derived preview.
	const derivedSlug = postTitle
		? postTitle
				.toLowerCase()
				.replace(/[^a-z0-9]+/g, '-')
				.replace(/^-|-$/g, '')
		: 'your-post-slug';
	const postSlug = slug && slug.trim() ? slug.trim() : derivedSlug;
	const postUrl = `${domain} › ${postSlug}`;

	return (
		<div className="tr-bg-white tr-rounded-lg tr-border tr-border-secondary">
			<div className="tr-px-4 tr-py-3 tr-border-b tr-border-secondary tr-flex tr-items-center tr-justify-between">
				<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
					{__('Search Engine Preview', 'thinkrank')}
				</h4>
				<div className="tr-flex tr-gap-1">
					{['desktop', 'mobile'].map((mode) => (
						<button
							key={mode}
							type="button"
							onClick={() => setViewMode(mode)}
							className={classnames(
								'tr-px-2.5 tr-py-1 tr-rounded tr-text-xs tr-font-medium tr-border-0 tr-cursor-pointer tr-transition-colors',
								viewMode === mode
									? 'tr-bg-brand tr-text-white'
									: 'tr-bg-white tr-text-tertiary hover:tr-bg-line-2'
							)}
						>
							{mode.charAt(0).toUpperCase() + mode.slice(1)}
						</button>
					))}
				</div>
			</div>

			<div className="tr-p-4">
				<div
					className={classnames(
						'tr-bg-white tr-rounded tr-border tr-border-secondary tr-p-4',
						viewMode === 'mobile' && 'tr-max-w-xs'
					)}
				>
					{/* Site info row */}
					<div className="tr-flex tr-items-center tr-gap-2 tr-mb-1">
						<div className="tr-w-5 tr-h-5 tr-rounded-full tr-bg-line-2 tr-overflow-hidden tr-shrink-0">
							<img
								src={faviconUrl}
								alt={siteName}
								className="tr-w-full tr-h-full tr-object-cover"
								onError={(e) => {
									e.target.style.display = 'none';
								}}
							/>
						</div>
						<div>
							<p className="tr-text-xs tr-text-secondary tr-font-medium tr-m-0 tr-leading-none">
								{siteName}
							</p>
							<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5 tr-leading-none">
								{postUrl}
							</p>
						</div>
						<span className="tr-ml-auto tr-text-tertiary tr-text-sm">
							⋮
						</span>
					</div>

					{/* Title */}
					<p className="tr-text-lg tr-text-blue-700 tr-font-medium tr-m-0 tr-mb-1 tr-leading-snug hover:tr-underline tr-cursor-pointer">
						{displayTitle}
					</p>

					{/* Description row */}
					<div
						className={classnames(
							'tr-flex tr-gap-3',
							viewMode === 'mobile' && 'tr-items-start'
						)}
					>
						<p className="tr-text-xs tr-text-secondary tr-m-0 tr-flex-1 tr-leading-relaxed">
							<span className="tr-text-tertiary">
								{currentDate}{' '}
							</span>
							{displayDescription}
						</p>
						{viewMode === 'mobile' && (
							<div className="tr-w-16 tr-h-16 tr-rounded tr-bg-white tr-shrink-0 tr-overflow-hidden tr-flex tr-items-center tr-justify-center">
								{featuredImageUrl ? (
									<img
										src={featuredImageUrl}
										alt={displayTitle}
										className="tr-w-full tr-h-full tr-object-cover"
									/>
								) : (
									<span className="tr-text-xl">🖼️</span>
								)}
							</div>
						)}
					</div>
				</div>
			</div>
		</div>
	);
};

export default SerpPreview;
