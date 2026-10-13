/**
 * SERP Preview Component
 *
 * Shows how metadata will appear in search engine results
 *
 * @package
 * @since 1.0.0
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const TITLE_MAX = 60;
const DESC_MAX = 160;

const lengthStatus = (length, max) => {
	if (length === 0)
		return { tone: 'tertiary', label: __('Empty', 'thinkrank') };
	if (length > max)
		return { tone: 'danger', label: __('Too long', 'thinkrank') };
	if (length < max * 0.5)
		return { tone: 'warning', label: __('Too short', 'thinkrank') };
	return { tone: 'success', label: __('Good', 'thinkrank') };
};

const LengthMeter = ({ label, length, max }) => {
	const status = lengthStatus(length, max);
	const pct = Math.min((length / max) * 100, 110);
	const barColor = {
		success: 'tr-bg-success',
		warning: 'tr-bg-warning',
		danger: 'tr-bg-danger',
		tertiary: 'tr-bg-line-2',
	}[status.tone];
	const textColor = {
		success: 'tr-text-success',
		warning: 'tr-text-warning',
		danger: 'tr-text-danger',
		tertiary: 'tr-text-tertiary',
	}[status.tone];

	return (
		<div>
			<div className="tr-flex tr-items-center tr-justify-between tr-mb-1.5">
				<span className="tr-text-xs tr-font-medium tr-text-secondary">
					{label}
				</span>
				<span className={`tr-text-xs tr-font-semibold ${textColor}`}>
					{length}/{max} · {status.label}
				</span>
			</div>
			<div className="tr-h-1.5 tr-w-full tr-rounded-full tr-bg-line-2 tr-overflow-hidden">
				<div
					className={`tr-h-full tr-rounded-full tr-transition-all tr-duration-200 ${barColor}`}
					style={{ width: `${pct}%` }}
				/>
			</div>
		</div>
	);
};

/**
 * SERP Preview Component
 * @param root0
 * @param root0.title
 * @param root0.description
 * @param root0.url
 * @param root0.siteName
 * @param root0.favicon
 * @param root0.className
 */
const SerpPreview = ({
	title = '',
	description = '',
	url = '',
	siteName = '',
	favicon = null,
	className = '',
}) => {
	// Get current site info if not provided
	const currentUrl = url || window.location.origin;
	const currentSiteName =
		siteName || window.thinkrankAdmin?.siteName || 'Your Website';

	// Format URL for display
	const displayUrl = currentUrl
		.replace(/^https?:\/\//, '')
		.replace(/\/$/, '');
	const initial = currentSiteName.trim().charAt(0).toUpperCase() || 'W';

	// Truncate title and description to Google's limits
	const truncatedTitle =
		title.length > TITLE_MAX
			? title.substring(0, TITLE_MAX - 3) + '…'
			: title;
	const truncatedDescription =
		description.length > DESC_MAX
			? description.substring(0, DESC_MAX - 3) + '…'
			: description;
	const formattedDate = new Date().toLocaleDateString('en-US', {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
	});

	return (
		<div
			className={`tr-bg-white tr-rounded-xl tr-shadow-sm tr-overflow-hidden ${className}`}
		>
			{/* Header */}
			<div className="tr-px-6 tr-py-4 tr-border-b tr-border-secondary tr-flex tr-items-center tr-gap-3">
				<span className="tr-flex tr-items-center tr-justify-center tr-w-9 tr-h-9 tr-rounded-lg tr-bg-brand-bg tr-text-brand tr-shrink-0">
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
						<circle cx="11" cy="11" r="7" />
						<path d="M21 21l-4.3-4.3" />
					</svg>
				</span>
				<div className="tr-min-w-0">
					<h4 className="tr-text-base tr-font-semibold tr-text-primary tr-m-0">
						{__('Search Engine Preview', 'thinkrank')}
					</h4>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'How your page will appear on Google search results',
							'thinkrank'
						)}
					</p>
				</div>
			</div>

			{/* Mock SERP result */}
			<div className="tr-p-6">
				<div className="tr-bg-white tr-border tr-border-secondary tr-rounded-xl tr-p-5 tr-shadow-sm hover:tr-shadow-md tr-transition-shadow tr-duration-200">
					{/* Favicon + site name + URL breadcrumb */}
					<div className="tr-flex tr-items-center tr-gap-2 tr-mb-2">
						{favicon ? (
							<img
								src={favicon}
								alt=""
								width="22"
								height="22"
								className="tr-w-[22px] tr-h-[22px] tr-rounded-full tr-shrink-0 tr-bg-line-2 tr-p-0.5"
							/>
						) : (
							<span
								className="tr-flex tr-items-center tr-justify-center tr-w-[22px] tr-h-[22px] tr-rounded-full tr-shrink-0 tr-bg-brand tr-text-white tr-text-[10px] tr-font-semibold"
								aria-hidden="true"
							>
								{initial}
							</span>
						)}
						<div className="tr-flex tr-flex-col tr-leading-tight tr-min-w-0">
							<span className="tr-text-xs tr-font-medium tr-text-primary tr-truncate">
								{currentSiteName}
							</span>
							<span className="tr-text-[11px] tr-text-tertiary tr-truncate">
								{displayUrl}
							</span>
						</div>
					</div>

					{/* Title */}
					{/* A rendering of what Google will show, not a link: it must
					    never navigate, so it is not an anchor. */}
					<span
						className="tr-block tr-text-lg tr-leading-snug tr-font-normal hover:tr-underline tr-no-underline tr-mb-1"
						style={{ color: '#1A0DAB' }}
					>
						{truncatedTitle ||
							__('Your SEO Title Will Appear Here', 'thinkrank')}
					</span>

					{/* Description */}
					<p className="tr-text-sm tr-text-secondary tr-leading-relaxed tr-m-0">
						<span className="tr-text-tertiary tr-mr-1">
							{formattedDate}
						</span>
						{truncatedDescription ||
							__(
								'Your meta description will appear here. Make it compelling to encourage clicks from search results.',
								'thinkrank'
							)}
					</p>
				</div>

				{/* Validation meters — one per row, each full width. */}
				<div className="tr-mt-5 tr-p-4 tr-rounded-xl tr-bg-[#F7F8FB] tr-border tr-border-secondary tr-grid tr-grid-cols-1 tr-gap-4">
					<LengthMeter
						label={__('Title Length', 'thinkrank')}
						length={title.length}
						max={TITLE_MAX}
					/>
					<LengthMeter
						label={__('Description Length', 'thinkrank')}
						length={description.length}
						max={DESC_MAX}
					/>
				</div>
			</div>
		</div>
	);
};

/**
 * Mobile SERP Preview Component
 * @param root0
 * @param root0.title
 * @param root0.description
 * @param root0.url
 * @param root0.siteName
 */
const MobileSerpPreview = ({
	title = '',
	description = '',
	url = '',
	siteName = '',
}) => {
	const currentUrl = url || window.location.origin;
	const currentSiteName =
		siteName || window.thinkrankAdmin?.siteName || 'Your Website';
	const displayUrl = currentUrl
		.replace(/^https?:\/\//, '')
		.replace(/\/$/, '');

	// Mobile has slightly different limits
	const truncatedTitle =
		title.length > 78 ? title.substring(0, 75) + '...' : title;
	const truncatedDescription =
		description.length > 120
			? description.substring(0, 117) + '...'
			: description;

	return (
		<div className="tr-bg-white tr-rounded-xl tr-shadow-sm tr-overflow-hidden">
			<div className="tr-px-6 tr-py-4 tr-border-b tr-border-secondary">
				<h4 className="tr-text-base tr-font-semibold tr-text-primary tr-m-0">
					{__('Mobile Search Preview', 'thinkrank')}
				</h4>
			</div>
			<div className="tr-p-6">
				<div className="tr-bg-white tr-border tr-border-secondary tr-rounded-lg tr-p-4 tr-max-w-sm">
					<div className="tr-flex tr-flex-col tr-leading-tight tr-mb-2">
						<span className="tr-text-xs tr-font-medium tr-text-primary tr-truncate">
							{currentSiteName}
						</span>
						<span className="tr-text-[11px] tr-text-tertiary tr-truncate">
							{displayUrl}
						</span>
					</div>
					{/* A rendering of what Google will show, not a link: it must
					    never navigate, so it is not an anchor. */}
					<span
						className="tr-block tr-text-base tr-leading-snug tr-font-normal hover:tr-underline tr-no-underline tr-mb-1"
						style={{ color: '#1A0DAB' }}
					>
						{truncatedTitle || __('Your SEO Title', 'thinkrank')}
					</span>
					<p className="tr-text-sm tr-text-secondary tr-leading-relaxed tr-m-0">
						{truncatedDescription ||
							__(
								'Your meta description for mobile…',
								'thinkrank'
							)}
					</p>
				</div>
			</div>
		</div>
	);
};

/**
 * Combined SERP Preview with tabs
 * @param props
 */
const CombinedSerpPreview = (props) => {
	const [activeTab, setActiveTab] = useState('desktop');

	const tabClass = (isActive) =>
		`tr-px-4 tr-py-2 tr-text-sm tr-font-medium tr-border-b-2 tr-transition-colors ${
			isActive
				? 'tr-border-brand tr-text-brand'
				: 'tr-border-transparent tr-text-tertiary hover:tr-text-secondary'
		}`;

	return (
		<div>
			<div className="tr-flex tr-border-b tr-border-secondary tr-mb-4">
				<button
					className={tabClass(activeTab === 'desktop')}
					onClick={() => setActiveTab('desktop')}
				>
					{__('Desktop', 'thinkrank')}
				</button>
				<button
					className={tabClass(activeTab === 'mobile')}
					onClick={() => setActiveTab('mobile')}
				>
					{__('Mobile', 'thinkrank')}
				</button>
			</div>

			{activeTab === 'desktop' ? (
				<SerpPreview {...props} />
			) : (
				<MobileSerpPreview {...props} />
			)}
		</div>
	);
};

export default SerpPreview;
export { MobileSerpPreview, CombinedSerpPreview };
