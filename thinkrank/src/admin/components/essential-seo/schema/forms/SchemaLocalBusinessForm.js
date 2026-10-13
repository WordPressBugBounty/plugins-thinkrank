/**
 * Schema Local Business Form Component
 *
 * Redesigned — shows data sources and status; no direct data entry needed
 * as LocalBusiness schema is auto-generated from Business Info & Organization tabs.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Card, Button } from '../../../ui';

const IconInfo = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<circle cx="12" cy="12" r="10" />
		<line x1="12" y1="16" x2="12" y2="12" />
		<line x1="12" y1="8" x2="12.01" y2="8" />
	</svg>
);

const IconCheck = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<polyline points="20 6 9 17 4 12" />
	</svg>
);

const IconX = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<line x1="18" y1="6" x2="6" y2="18" />
		<line x1="6" y1="6" x2="18" y2="18" />
	</svg>
);

const IconArrow = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<line x1="5" y1="12" x2="19" y2="12" />
		<polyline points="12 5 19 12 12 19" />
	</svg>
);

const IconMapPin = () => (
	<svg
		width="18"
		height="18"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.8"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
		<circle cx="12" cy="10" r="3" />
	</svg>
);

const IconBuilding = () => (
	<svg
		width="18"
		height="18"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.8"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<rect x="2" y="7" width="20" height="14" rx="2" />
		<path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" />
	</svg>
);

const IconImage = () => (
	<svg
		width="18"
		height="18"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.8"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<rect x="3" y="3" width="18" height="18" rx="2" />
		<circle cx="8.5" cy="8.5" r="1.5" />
		<polyline points="21 15 16 10 5 21" />
	</svg>
);

const DataSourceCard = ({
	icon,
	title,
	description,
	items,
	buttonLabel,
	onNavigate,
}) => (
	<div className="tr-border tr-border-secondary tr-rounded-lg tr-overflow-hidden">
		<div className="tr-px-4 tr-py-3 tr-bg-white tr-border-b tr-border-secondary tr-flex tr-items-center tr-justify-between">
			<div className="tr-flex tr-items-center tr-gap-2">
				<span className="tr-text-brand">{icon}</span>
				<span className="tr-text-sm tr-font-semibold tr-text-primary">
					{title}
				</span>
			</div>
			<button
				onClick={onNavigate}
				className="tr-flex tr-items-center tr-gap-1 tr-text-xs tr-font-medium tr-text-brand tr-bg-brand-bg tr-border tr-border-brand tr-rounded tr-px-2 tr-py-1 hover:tr-bg-brand-bg tr-transition-colors tr-duration-150 tr-cursor-pointer"
			>
				{buttonLabel}
				<IconArrow />
			</button>
		</div>
		<div className="tr-px-4 tr-py-3">
			<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mb-2">
				{description}
			</p>
			<ul className="tr-m-0 tr-pl-0 tr-list-none tr-flex tr-flex-col tr-gap-1">
				{items.map((item, i) => (
					<li
						key={i}
						className="tr-flex tr-items-start tr-gap-2 tr-text-xs tr-text-secondary"
					>
						<span className="tr-text-tertiary tr-mt-0.5 tr-shrink-0">
							—
						</span>
						{item}
					</li>
				))}
			</ul>
		</div>
	</div>
);

const SchemaLocalBusinessForm = ({
	settings,
	onSettingChange,
	isLoading,
	onNavigate,
}) => {
	const socialProfiles = [
		settings.organization_social_facebook,
		settings.organization_social_twitter,
		settings.organization_social_linkedin,
		settings.organization_social_instagram,
		settings.organization_social_youtube,
	].filter((p) => p && p.trim());

	const isEnabled = !!settings.enabled;

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{/* Info banner */}
			<div className="tr-flex tr-items-start tr-gap-3 tr-bg-brand-bg tr-border tr-border-brand/30 tr-rounded-lg tr-p-4">
				<span className="tr-text-brand tr-shrink-0 tr-mt-0.5">
					<IconInfo />
				</span>
				<div>
					<p className="tr-text-sm tr-font-semibold tr-text-brand tr-m-0 tr-mb-1">
						{__(
							'Auto-generated from your existing data',
							'thinkrank'
						)}
					</p>
					<p className="tr-text-xs tr-text-brand/80 tr-m-0">
						{__(
							'LocalBusiness schema is built automatically from Business Info, Organization, and Site Identity settings no extra configuration needed here.',
							'thinkrank'
						)}
					</p>
				</div>
			</div>

			{/* Data sources */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Data Sources', 'thinkrank')}
					</h3>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Schema is generated from these sections click to manage each one',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-3">
					<DataSourceCard
						icon={<IconMapPin />}
						title={__('Business Info', 'thinkrank')}
						description={__(
							'Primary source for address, contact, and location data',
							'thinkrank'
						)}
						items={[
							__(
								'Business name, type, and description',
								'thinkrank'
							),
							__(
								'Full address street, city, state, postal code, country',
								'thinkrank'
							),
							__('Phone number and email address', 'thinkrank'),
							__('Geographic coordinates (lat/lng)', 'thinkrank'),
							__('Price range and business hours', 'thinkrank'),
						]}
						buttonLabel={__('Manage', 'thinkrank')}
						onNavigate={() =>
							onNavigate &&
							onNavigate('site-identity', 'local-seo')
						}
					/>
					<DataSourceCard
						icon={<IconBuilding />}
						title={__('Organization', 'thinkrank')}
						description={__(
							'Branding and social presence data',
							'thinkrank'
						)}
						items={[
							__(
								'Organization logo and branding assets',
								'thinkrank'
							),
							__(
								'Social media profiles (sameAs property)',
								'thinkrank'
							),
							__('Contact point information', 'thinkrank'),
						]}
						buttonLabel={__('Manage', 'thinkrank')}
						onNavigate={() =>
							onNavigate && onNavigate('schema', 'organization')
						}
					/>
					<DataSourceCard
						icon={<IconImage />}
						title={__('Hero & Branding', 'thinkrank')}
						description={__(
							'Site logo used in schema markup',
							'thinkrank'
						)}
						items={[
							__(
								'Site logo for schema and structured data',
								'thinkrank'
							),
							__('Favicon and Apple touch icon', 'thinkrank'),
						]}
						buttonLabel={__('Manage', 'thinkrank')}
						onNavigate={() =>
							onNavigate &&
							onNavigate('site-identity', 'hero-section')
						}
					/>
				</div>
			</Card>

			{/* Status summary */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Schema Status', 'thinkrank')}
					</h3>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-3">
					{/* Generation status */}
					<div
						className={`tr-flex tr-items-center tr-gap-3 tr-rounded-lg tr-border tr-p-4 ${isEnabled ? 'tr-bg-success-bg tr-border-success' : 'tr-bg-danger-bg tr-border-danger'}`}
					>
						<span
							className={`tr-flex tr-items-center tr-justify-center tr-w-8 tr-h-8 tr-rounded-full tr-shrink-0 ${isEnabled ? 'tr-bg-success-bg tr-text-success' : 'tr-bg-danger-bg tr-text-danger'}`}
						>
							{isEnabled ? <IconCheck /> : <IconX />}
						</span>
						<div>
							<p
								className={`tr-text-sm tr-font-semibold tr-m-0 tr-mb-0.5 ${isEnabled ? 'tr-text-success' : 'tr-text-danger'}`}
							>
								{isEnabled
									? __(
											'Schema Generation Active',
											'thinkrank'
										)
									: __(
											'Schema Generation Disabled',
											'thinkrank'
										)}
							</p>
							<p
								className={`tr-text-xs tr-m-0 ${isEnabled ? 'tr-text-success' : 'tr-text-danger'}`}
							>
								{isEnabled
									? __(
											'LocalBusiness schema is being output automatically on your site.',
											'thinkrank'
										)
									: __(
											'Enable schema generation in the main Schema Manager settings to activate output.',
											'thinkrank'
										)}
							</p>
						</div>
					</div>

					{/* Logo + Social chips */}
					<div className="tr-grid tr-grid-cols-2 tr-gap-3">
						<div className="tr-border tr-border-secondary tr-rounded-lg tr-p-3">
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-m-0 tr-mb-2">
								{__('Logo', 'thinkrank')}
							</p>
							{settings.logo_url ? (
								<span className="tr-inline-flex tr-items-center tr-gap-1 tr-text-xs tr-font-medium tr-text-success tr-bg-success-bg tr-border tr-border-success tr-rounded tr-px-2 tr-py-1">
									<IconCheck />{' '}
									{__('Configured', 'thinkrank')}
								</span>
							) : (
								<span className="tr-inline-flex tr-items-center tr-gap-1 tr-text-xs tr-font-medium tr-text-warning tr-bg-warning-bg tr-border tr-border-warning tr-rounded tr-px-2 tr-py-1">
									<IconInfo /> {__('Not set', 'thinkrank')}
								</span>
							)}
						</div>
						<div className="tr-border tr-border-secondary tr-rounded-lg tr-p-3">
							<p className="tr-text-xs tr-font-semibold tr-text-tertiary tr-uppercase tr-tracking-wide tr-m-0 tr-mb-2">
								{__('Social Profiles', 'thinkrank')}
							</p>
							{socialProfiles.length > 0 ? (
								<span className="tr-inline-flex tr-items-center tr-gap-1 tr-text-xs tr-font-medium tr-text-success tr-bg-success-bg tr-border tr-border-success tr-rounded tr-px-2 tr-py-1">
									<IconCheck /> {socialProfiles.length}{' '}
									{__('connected', 'thinkrank')}
								</span>
							) : (
								<span className="tr-inline-flex tr-items-center tr-gap-1 tr-text-xs tr-font-medium tr-text-warning tr-bg-warning-bg tr-border tr-border-warning tr-rounded tr-px-2 tr-py-1">
									<IconInfo /> {__('None added', 'thinkrank')}
								</span>
							)}
						</div>
					</div>
				</div>
			</Card>
		</div>
	);
};

export default SchemaLocalBusinessForm;
