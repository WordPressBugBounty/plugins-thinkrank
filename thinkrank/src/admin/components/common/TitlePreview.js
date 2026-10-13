/**
 * Title Preview Component
 *
 * Live SERP-style preview of title formats with real-time updates
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Card } from '../ui';

const getSeparatorChar = (type) =>
	({
		pipe: '|',
		dash: '-',
		bullet: '•',
		colon: ':',
		greater: '>',
		tilde: '~',
	})[type] || '|';

const replaceVariables = (template, siteInfo) => {
	const map = {
		'%site_title%': siteInfo.site_name || 'Your Site Name',
		'%site_name%': siteInfo.site_name || 'Your Site Name',
		'%site_description%':
			siteInfo.site_description || 'Your site description',
		'%tagline%': siteInfo.tagline || 'Your site tagline',
		'%post_title%': 'Sample Blog Post Title',
		'%page_title%': 'Sample Page Title',
		'%category_title%': 'Sample Category',
		'%tag_title%': 'Sample Tag',
		'%author_name%': 'John Doe',
		'%search_term%': 'sample search',
		'%archive_title%': 'Sample Archive',
	};
	return Object.entries(map).reduce(
		(str, [key, val]) =>
			str.replace(
				new RegExp(key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'),
				val
			),
		template
	);
};

const PAGE_LABELS = {
	homepage_title: __('Homepage', 'thinkrank'),
	post_title: __('Blog Post', 'thinkrank'),
	page_title: __('Page', 'thinkrank'),
	category_title: __('Category', 'thinkrank'),
	tag_title: __('Tag', 'thinkrank'),
	author_title: __('Author', 'thinkrank'),
	search_title: __('Search Results', 'thinkrank'),
	archive_title: __('Archive', 'thinkrank'),
};

const PAGE_URLS = {
	homepage_title: 'yoursite.com',
	post_title: 'yoursite.com › blog › sample-post',
	page_title: 'yoursite.com › sample-page',
	category_title: 'yoursite.com › category › sample',
	tag_title: 'yoursite.com › tag › sample',
	author_title: 'yoursite.com › author › john-doe',
	search_title: 'yoursite.com › ?s=sample+search',
	archive_title: 'yoursite.com › archive',
};

const getTitleStatus = (len) => {
	if (len === 0) return { status: 'empty', msg: '' };
	if (len < 30) return { status: 'short', msg: __('Too short', 'thinkrank') };
	if (len > 60)
		return {
			status: 'long',
			msg: __('Too long, may be truncated', 'thinkrank'),
		};
	return { status: 'good', msg: __('Good length', 'thinkrank') };
};

const SerpFavicon = () => (
	<div className="tr-flex tr-items-center tr-justify-center tr-w-4 tr-h-4 tr-rounded-full tr-bg-line-2 tr-shrink-0">
		<svg
			width="8"
			height="8"
			viewBox="0 0 24 24"
			fill="none"
			stroke="#94a3b8"
			strokeWidth="2.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<circle cx="12" cy="12" r="10" />
			<line x1="2" y1="12" x2="22" y2="12" />
			<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
		</svg>
	</div>
);

const TitlePreviewCard = ({ pageType, preview, label, url }) => {
	const len = preview.length;
	const { status, msg } = getTitleStatus(len);
	const barPct = Math.min((len / 60) * 100, 100);

	const barColor =
		status === 'good'
			? 'tr-bg-success'
			: status === 'empty'
				? 'tr-bg-line-2'
				: 'tr-bg-warning';
	const textColor =
		status === 'good'
			? 'tr-text-success'
			: status === 'empty'
				? 'tr-text-tertiary'
				: 'tr-text-warning';
	const labelBg =
		status === 'good'
			? 'tr-bg-success-bg tr-text-success tr-border-success'
			: status === 'empty'
				? 'tr-bg-white tr-text-tertiary tr-border-secondary'
				: 'tr-bg-warning-bg tr-text-warning tr-border-warning';

	return (
		<div className="tr-border tr-border-secondary tr-rounded-lg tr-overflow-hidden">
			{/* Card label bar */}
			<div className="tr-px-4 tr-py-2 tr-bg-white tr-border-b tr-border-secondary tr-flex tr-items-center tr-justify-between">
				<span
					className={`tr-text-xs tr-font-medium tr-px-2 tr-py-0.5 tr-rounded-full tr-border ${labelBg}`}
				>
					{label}
				</span>
				{len > 0 && (
					<span className={`tr-text-xs tr-font-medium ${textColor}`}>
						{len}/60
					</span>
				)}
			</div>

			{/* SERP mock */}
			<div className="tr-p-4 tr-bg-white">
				{/* URL breadcrumb */}
				<div className="tr-flex tr-items-center tr-gap-1.5 tr-mb-1">
					<SerpFavicon />
					<span className="tr-text-xs tr-text-tertiary tr-truncate">
						{url}
					</span>
				</div>

				{/* Title — mimics Google blue link */}
				<div
					className="tr-text-base tr-font-normal tr-leading-snug tr-truncate"
					style={{ color: preview ? '#1a0dab' : '#94a3b8' }}
					title={preview || undefined}
				>
					{preview || __('No title format set', 'thinkrank')}
				</div>

				{/* Description placeholder */}
				<div className="tr-mt-1 tr-text-xs tr-text-tertiary tr-leading-relaxed">
					{__(
						'Your meta description will appear here in search results…',
						'thinkrank'
					)}
				</div>
			</div>

			{/* Character count bar */}
			{len > 0 && (
				<div className="tr-px-4 tr-py-2 tr-bg-white tr-border-t tr-border-secondary tr-flex tr-items-center tr-gap-3">
					<div className="tr-flex-1 tr-bg-line-2 tr-rounded-full tr-h-1.5">
						<div
							className={`tr-h-1.5 tr-rounded-full tr-transition-all ${barColor}`}
							style={{ width: `${barPct}%` }}
						/>
					</div>
					{msg && (
						<span className={`tr-text-xs tr-shrink-0 ${textColor}`}>
							{msg}
						</span>
					)}
				</div>
			)}
		</div>
	);
};

const TitlePreview = ({
	titleFormats,
	separator,
	siteInfo,
	disabled = false,
}) => {
	const [previews, setPreviews] = useState({});

	useEffect(() => {
		const separatorChar = ` ${getSeparatorChar(separator)} `;
		const next = {};
		Object.keys(titleFormats).forEach((pageType) => {
			const tpl = titleFormats[pageType];
			if (!tpl) {
				next[pageType] = '';
				return;
			}
			let out = replaceVariables(tpl, siteInfo);
			out = out.replace(/\s*%sep%\s*/g, separatorChar);
			out = out.replace(/\s*\|\s*/g, separatorChar);
			next[pageType] = out;
		});
		setPreviews(next);
	}, [titleFormats, separator, siteInfo]);

	return (
		<Card padding={false}>
			{/* Header */}
			<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
				<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
					{__('Live Title Preview', 'thinkrank')}
				</h3>
				<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
					{disabled
						? __(
								'Enable site identity to see live previews.',
								'thinkrank'
							)
						: __(
								'How your titles will appear in Google search results',
								'thinkrank'
							)}
				</p>
			</div>

			<div className="tr-p-5 tr-flex tr-flex-col tr-gap-3">
				{disabled ? (
					<p className="tr-text-sm tr-text-tertiary tr-italic tr-m-0">
						{__('Title preview is disabled.', 'thinkrank')}
					</p>
				) : (
					<>
						{Object.keys(previews).map((pageType) => (
							<TitlePreviewCard
								key={pageType}
								pageType={pageType}
								preview={previews[pageType]}
								label={PAGE_LABELS[pageType] || pageType}
								url={PAGE_URLS[pageType] || 'yoursite.com'}
							/>
						))}

						{/* SEO Tips */}
						<div className="tr-bg-brand-bg tr-border tr-border-brand tr-rounded-lg tr-p-4 tr-mt-1">
							<p className="tr-text-xs tr-font-semibold tr-text-brand tr-uppercase tr-tracking-wide tr-m-0 tr-mb-2">
								{__('SEO Tips', 'thinkrank')}
							</p>
							<ul
								className="tr-m-0 tr-pl-4 tr-flex tr-flex-col tr-gap-1"
								style={{ listStyleType: 'disc' }}
							>
								{[
									__(
										'Keep titles between 30–60 characters for optimal SEO',
										'thinkrank'
									),
									__(
										'Include your target keyword near the beginning',
										'thinkrank'
									),
									__(
										'Make titles descriptive and compelling for users',
										'thinkrank'
									),
									__(
										'Use consistent separators across your site',
										'thinkrank'
									),
								].map((tip, i) => (
									<li
										key={i}
										className="tr-text-xs tr-text-brand"
									>
										{tip}
									</li>
								))}
							</ul>
						</div>
					</>
				)}
			</div>
		</Card>
	);
};

export default TitlePreview;
