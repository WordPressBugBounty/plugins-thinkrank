/**
 * Step 3 — Site Identity & Sitemap (functional).
 *
 * Saved on continue via site-identity/settings + sitemap/settings.
 *
 * @package
 * @since 1.0.0
 */

import { TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import MediaPicker from '../../components/common/MediaPicker';

function Pill({ active, onClick, children, radio = false }) {
	return (
		<button
			type="button"
			className={`tr-wizard__pill${active ? ' is-selected' : ''}`}
			aria-pressed={active}
			onClick={onClick}
		>
			{radio && (
				<span className="tr-wizard__pill-radio" aria-hidden="true" />
			)}
			{children}
		</button>
	);
}

const IDENTITY_TYPES = [
	{ value: 'blog', label: __('Blog', 'thinkrank') },
	{ value: 'business', label: __('Business', 'thinkrank') },
	{ value: 'portfolio', label: __('Portfolio', 'thinkrank') },
	{ value: 'other', label: __('Other', 'thinkrank') },
];

export default function Step3SiteSetup({ siteData, onSiteDataChange }) {
	return (
		<div className="tr-wizard__stack">
			{/* Menu detail (#730) */}
			<div className="tr-wizard__group">
				<div className="tr-wizard__group-head">
					<span className="tr-wizard__group-title">
						{__('Menu', 'thinkrank')}
					</span>
					<span className="tr-wizard__group-sub">
						{__(
							'How much SEO detail do you want to see? You can switch at any time from the Essential SEO screen.',
							'thinkrank'
						)}
					</span>
				</div>
				<div className="tr-wizard__pills">
					<Pill
						radio
						active={'advanced' !== siteData.ui_mode}
						onClick={() => onSiteDataChange({ ui_mode: 'simple' })}
					>
						{__('Basic: the essentials', 'thinkrank')}
					</Pill>
					<Pill
						radio
						active={'advanced' === siteData.ui_mode}
						onClick={() =>
							onSiteDataChange({ ui_mode: 'advanced' })
						}
					>
						{__('Advanced: every setting', 'thinkrank')}
					</Pill>
				</div>
			</div>

			{/* Identity type */}
			<div className="tr-wizard__group">
				<div className="tr-wizard__group-head">
					<span className="tr-wizard__group-title">
						{__('Identity', 'thinkrank')}
					</span>
					<span className="tr-wizard__group-sub">
						{__(
							'Which option best describes your site?',
							'thinkrank'
						)}
					</span>
				</div>
				<div className="tr-wizard__pills">
					{IDENTITY_TYPES.map((opt) => (
						<Pill
							key={opt.value}
							radio
							active={siteData.identity_type === opt.value}
							onClick={() =>
								onSiteDataChange({ identity_type: opt.value })
							}
						>
							{opt.label}
						</Pill>
					))}
				</div>
			</div>

			{/* Represents */}
			<div className="tr-wizard__group">
				<div className="tr-wizard__group-head">
					<span className="tr-wizard__group-title">
						{__('This site represents', 'thinkrank')}
					</span>
				</div>
				<div className="tr-wizard__pills">
					<Pill
						radio
						active={siteData.represents === 'person'}
						onClick={() =>
							onSiteDataChange({ represents: 'person' })
						}
					>
						{__('Person', 'thinkrank')}
					</Pill>
					<Pill
						radio
						active={siteData.represents === 'organization'}
						onClick={() =>
							onSiteDataChange({ represents: 'organization' })
						}
					>
						{__('Organization', 'thinkrank')}
					</Pill>
				</div>
			</div>

			{/* Names */}
			<div className="tr-wizard__cols">
				<TextControl
					label={__('Website name', 'thinkrank')}
					value={siteData.site_name}
					onChange={(value) => onSiteDataChange({ site_name: value })}
					placeholder={__('ABC Company', 'thinkrank')}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<TextControl
					label={__('Alternate Name (Optional)', 'thinkrank')}
					value={siteData.alternate_name}
					onChange={(value) =>
						onSiteDataChange({ alternate_name: value })
					}
					placeholder={__('DEF Company', 'thinkrank')}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</div>

			{/* Media — paired in the same two-column grid as the names above. */}
			<div className="tr-wizard__cols">
				<MediaPicker
					label={__('Default social image', 'thinkrank')}
					help={__('Recommended: 1200×630px', 'thinkrank')}
					value={siteData.default_social_image}
					onChange={(url) =>
						onSiteDataChange({ default_social_image: url })
					}
				/>
				<MediaPicker
					label={__('Logo', 'thinkrank')}
					help={__('SVG or PNG, min 112×112px', 'thinkrank')}
					value={siteData.logo_url}
					onChange={(url) => onSiteDataChange({ logo_url: url })}
				/>
			</div>

			{/* Sitemap */}
			<div className="tr-wizard__section-label">
				{__('Site Map', 'thinkrank')}
			</div>

			<div className="tr-wizard__row-card">
				<div className="tr-wizard__row-text">
					<span className="tr-wizard__row-title">
						{__('Enable XML sitemap', 'thinkrank')}
					</span>
					<span className="tr-wizard__row-detail">
						{__(
							'Helps search engines discover your pages faster.',
							'thinkrank'
						)}
					</span>
				</div>
				<ToggleControl
					checked={!!siteData.sitemap_enabled}
					onChange={(value) =>
						onSiteDataChange({ sitemap_enabled: value })
					}
					__nextHasNoMarginBottom
				/>
			</div>

			<div className="tr-wizard__row-card">
				<div className="tr-wizard__row-text">
					<span className="tr-wizard__row-title">
						{__('Include images', 'thinkrank')}
					</span>
					<span className="tr-wizard__row-detail">
						{__(
							'Improves image indexing in Google Search.',
							'thinkrank'
						)}
					</span>
				</div>
				<ToggleControl
					checked={!!siteData.sitemap_include_images}
					onChange={(value) =>
						onSiteDataChange({ sitemap_include_images: value })
					}
					__nextHasNoMarginBottom
				/>
			</div>

			<div className="tr-wizard__group">
				<div className="tr-wizard__group-head">
					<span className="tr-wizard__group-title">
						{__('Include in sitemap', 'thinkrank')}
					</span>
				</div>
				<div className="tr-wizard__pills">
					<Pill
						active={!!siteData.include_posts}
						onClick={() =>
							onSiteDataChange({
								include_posts: !siteData.include_posts,
							})
						}
					>
						{__('Posts', 'thinkrank')}
					</Pill>
					<Pill
						active={!!siteData.include_pages}
						onClick={() =>
							onSiteDataChange({
								include_pages: !siteData.include_pages,
							})
						}
					>
						{__('Pages', 'thinkrank')}
					</Pill>
					<Pill
						active={!!siteData.include_taxonomies}
						onClick={() =>
							onSiteDataChange({
								include_taxonomies:
									!siteData.include_taxonomies,
							})
						}
					>
						{__('Taxonomies', 'thinkrank')}
					</Pill>
				</div>
			</div>
		</div>
	);
}
