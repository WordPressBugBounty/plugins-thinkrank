/**
 * Breadcrumb Preview Component
 *
 * Live preview of breadcrumb navigation with different settings
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Card } from '../ui';

const PAGE_LABELS = {
	blog_post: __('Blog Post', 'thinkrank'),
	page: __('Page', 'thinkrank'),
	category: __('Category', 'thinkrank'),
	product: __('Product', 'thinkrank'),
};

const getSampleBreadcrumbs = (homeText) => ({
	blog_post: [
		{ text: homeText, url: '/', current: false },
		{ text: 'Blog', url: '/blog/', current: false },
		{ text: 'Technology', url: '/category/technology/', current: false },
		{ text: 'Sample Blog Post Title', url: '', current: true },
	],
	page: [
		{ text: homeText, url: '/', current: false },
		{ text: 'About', url: '/about/', current: false },
		{ text: 'Our Team', url: '', current: true },
	],
	category: [
		{ text: homeText, url: '/', current: false },
		{ text: 'Blog', url: '/blog/', current: false },
		{ text: 'Technology', url: '', current: true },
	],
	product: [
		{ text: homeText, url: '/', current: false },
		{ text: 'Shop', url: '/shop/', current: false },
		{ text: 'Electronics', url: '/category/electronics/', current: false },
		{ text: 'Smartphones', url: '/category/smartphones/', current: false },
		{ text: 'iPhone 15 Pro', url: '', current: true },
	],
});

const HomeIcon = () => (
	<svg
		width="12"
		height="12"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
		<polyline points="9 22 9 12 15 12 15 22" />
	</svg>
);

const BreadcrumbTrail = ({ breadcrumbs, separator, prefix, showCurrent }) => {
	const filtered = showCurrent
		? breadcrumbs
		: breadcrumbs.filter((c) => !c.current);

	return (
		<div className="tr-flex tr-items-center tr-flex-wrap tr-gap-0.5 tr-text-sm">
			{prefix && (
				<span className="tr-text-tertiary tr-mr-1 tr-text-xs">
					{prefix}
				</span>
			)}
			{filtered.map((crumb, i) => (
				<span key={i} className="tr-flex tr-items-center tr-gap-0.5">
					{crumb.current ? (
						<span className="tr-text-tertiary tr-font-normal">
							{crumb.text}
						</span>
					) : (
						<span
							className="tr-font-medium tr-cursor-default"
							style={{ color: '#1a0dab' }}
						>
							{i === 0 && crumb.url === '/' ? (
								<span className="tr-flex tr-items-center tr-gap-1">
									<HomeIcon />
									{crumb.text}
								</span>
							) : (
								crumb.text
							)}
						</span>
					)}
					{i < filtered.length - 1 && (
						<span className="tr-text-tertiary tr-mx-1 tr-select-none tr-font-normal">
							{separator}
						</span>
					)}
				</span>
			))}
		</div>
	);
};

const BreadcrumbCard = ({ pageType, breadcrumbs, settings }) => {
	const separator = settings.breadcrumb_separator || '›';
	const prefix = settings.breadcrumb_prefix || '';
	const showCurrent = settings.show_current_page !== false;
	const depth = (
		showCurrent ? breadcrumbs : breadcrumbs.filter((c) => !c.current)
	).length;

	return (
		<div className="tr-border tr-border-secondary tr-rounded-lg tr-overflow-hidden">
			{/* Label bar */}
			<div className="tr-px-4 tr-py-2 tr-bg-white tr-border-b tr-border-secondary tr-flex tr-items-center tr-justify-between">
				<span className="tr-text-xs tr-font-medium tr-text-secondary tr-bg-white tr-border tr-border-secondary tr-px-2 tr-py-0.5 tr-rounded-full">
					{PAGE_LABELS[pageType] || pageType}
				</span>
				<span className="tr-text-xs tr-text-tertiary">
					{depth}{' '}
					{depth === 1
						? __('level', 'thinkrank')
						: __('levels', 'thinkrank')}
				</span>
			</div>

			{/* Breadcrumb preview area */}
			<div className="tr-px-4 tr-py-3 tr-bg-white">
				<BreadcrumbTrail
					breadcrumbs={breadcrumbs}
					separator={separator}
					prefix={prefix}
					showCurrent={showCurrent}
				/>
			</div>

			{/* Schema.org structured data hint */}
			<div className="tr-px-4 tr-py-1.5 tr-bg-white tr-border-t tr-border-secondary">
				<span className="tr-text-xs tr-text-tertiary">
					schema.org/BreadcrumbList · {depth}{' '}
					{__('items', 'thinkrank')}
				</span>
			</div>
		</div>
	);
};

const SettingPill = ({ label, value }) => (
	<div className="tr-flex tr-items-center tr-gap-1.5 tr-bg-white tr-border tr-border-secondary tr-rounded-full tr-px-3 tr-py-1">
		<span className="tr-text-xs tr-text-tertiary">{label}</span>
		<span className="tr-text-xs tr-font-semibold tr-text-secondary">
			{value}
		</span>
	</div>
);

const BreadcrumbPreview = ({ breadcrumbSettings, disabled = false }) => {
	const [previews, setPreviews] = useState({});

	useEffect(() => {
		if (!breadcrumbSettings.breadcrumbs_enabled) {
			setPreviews({});
			return;
		}
		const homeText =
			breadcrumbSettings.breadcrumb_home_text || __('Home', 'thinkrank');
		setPreviews(getSampleBreadcrumbs(homeText));
	}, [breadcrumbSettings]);

	return (
		<Card padding={false}>
			{/* Header */}
			<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
				<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
					{__('Live Breadcrumb Preview', 'thinkrank')}
				</h3>
				<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
					{disabled
						? __(
								'Enable site identity to see live previews.',
								'thinkrank'
							)
						: !breadcrumbSettings.breadcrumbs_enabled
							? __(
									'Enable breadcrumbs above to see the preview.',
									'thinkrank'
								)
							: __(
									'How your breadcrumbs will appear on each page type',
									'thinkrank'
								)}
				</p>
			</div>

			<div className="tr-p-5 tr-flex tr-flex-col tr-gap-3">
				{disabled || !breadcrumbSettings.breadcrumbs_enabled ? (
					<div className="tr-bg-white tr-border tr-border-secondary tr-rounded-lg tr-p-6 tr-text-center">
						<p className="tr-text-sm tr-text-tertiary tr-italic tr-m-0">
							{disabled
								? __(
										'Breadcrumb preview is disabled.',
										'thinkrank'
									)
								: __(
										'Breadcrumbs are currently disabled.',
										'thinkrank'
									)}
						</p>
					</div>
				) : (
					<>
						{Object.keys(previews).map((pageType) => (
							<BreadcrumbCard
								key={pageType}
								pageType={pageType}
								breadcrumbs={previews[pageType]}
								settings={breadcrumbSettings}
							/>
						))}

						{/* Current settings summary */}
						<div className="tr-bg-brand-bg tr-border tr-border-brand tr-rounded-lg tr-p-4 tr-mt-1">
							<p className="tr-text-xs tr-font-semibold tr-text-brand tr-uppercase tr-tracking-wide tr-m-0 tr-mb-3">
								{__('Active Settings', 'thinkrank')}
							</p>
							<div className="tr-flex tr-flex-wrap tr-gap-2">
								<SettingPill
									label={__('Type', 'thinkrank')}
									value={
										breadcrumbSettings.breadcrumb_type ||
										'hierarchical'
									}
								/>
								<SettingPill
									label={__('Separator', 'thinkrank')}
									value={`"${breadcrumbSettings.breadcrumb_separator || '›'}"`}
								/>
								<SettingPill
									label={__('Home', 'thinkrank')}
									value={`"${breadcrumbSettings.breadcrumb_home_text || __('Home', 'thinkrank')}"`}
								/>
								{breadcrumbSettings.breadcrumb_prefix && (
									<SettingPill
										label={__('Prefix', 'thinkrank')}
										value={`"${breadcrumbSettings.breadcrumb_prefix}"`}
									/>
								)}
								<SettingPill
									label={__('Current Page', 'thinkrank')}
									value={
										breadcrumbSettings.show_current_page !==
										false
											? __('Shown', 'thinkrank')
											: __('Hidden', 'thinkrank')
									}
								/>
							</div>
						</div>
					</>
				)}
			</div>
		</Card>
	);
};

export default BreadcrumbPreview;
