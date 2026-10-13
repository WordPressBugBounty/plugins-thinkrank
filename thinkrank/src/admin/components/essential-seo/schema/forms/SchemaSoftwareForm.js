/**
 * Schema Software Application Form Component
 *
 * Redesigned — uses plugin UI components and Tailwind classes.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Card, Input, Textarea, Select } from '../../../ui';

const IconCode = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<polyline points="16 18 22 12 16 6" />
		<polyline points="8 6 2 12 8 18" />
	</svg>
);

const IconMonitor = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<rect x="2" y="3" width="20" height="14" rx="2" />
		<line x1="8" y1="21" x2="16" y2="21" />
		<line x1="12" y1="17" x2="12" y2="21" />
	</svg>
);

const IconTag = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" />
		<line x1="7" y1="7" x2="7.01" y2="7" />
	</svg>
);

const IconStar = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
	</svg>
);

const CREATOR_TYPE_OPTIONS = [
	{ value: 'Person', label: __('Person', 'thinkrank') },
	{ value: 'Organization', label: __('Organization', 'thinkrank') },
];

const LICENSE_OPTIONS = [
	{ value: '', label: __('Select License', 'thinkrank') },
	{ value: 'Free', label: __('Free', 'thinkrank') },
	{ value: 'Freemium', label: __('Freemium', 'thinkrank') },
	{ value: 'Paid', label: __('Paid', 'thinkrank') },
	{ value: 'Subscription', label: __('Subscription', 'thinkrank') },
	{ value: 'Open Source', label: __('Open Source', 'thinkrank') },
	{ value: 'Trial', label: __('Free Trial', 'thinkrank') },
];

const CATEGORY_OPTIONS = [
	{ value: '', label: __('Select Category', 'thinkrank') },
	{
		value: 'BusinessApplication',
		label: __('Business & Productivity', 'thinkrank'),
	},
	{
		value: 'GameApplication',
		label: __('Games & Entertainment', 'thinkrank'),
	},
	{
		value: 'MultimediaApplication',
		label: __('Multimedia & Graphics', 'thinkrank'),
	},
	{ value: 'MobileApplication', label: __('Mobile App', 'thinkrank') },
	{ value: 'WebApplication', label: __('Web Application', 'thinkrank') },
	{
		value: 'DesktopEnhancementApplication',
		label: __('Desktop Enhancement', 'thinkrank'),
	},
	{ value: 'EducationalApplication', label: __('Educational', 'thinkrank') },
	{
		value: 'DeveloperApplication',
		label: __('Developer Tools', 'thinkrank'),
	},
	{
		value: 'SecurityApplication',
		label: __('Security & Privacy', 'thinkrank'),
	},
	{
		value: 'UtilitiesApplication',
		label: __('Utilities & System', 'thinkrank'),
	},
];

const AVAILABILITY_OPTIONS = [
	{ value: 'InStock', label: __('Available', 'thinkrank') },
	{ value: 'OutOfStock', label: __('Not Available', 'thinkrank') },
	{ value: 'PreOrder', label: __('Pre-Order', 'thinkrank') },
	{ value: 'ComingSoon', label: __('Coming Soon', 'thinkrank') },
];

const CURRENCY_OPTIONS = [
	{ value: 'USD', label: 'USD ($)' },
	{ value: 'EUR', label: 'EUR (€)' },
	{ value: 'GBP', label: 'GBP (£)' },
	{ value: 'CAD', label: 'CAD (C$)' },
	{ value: 'AUD', label: 'AUD (A$)' },
];

const toLines = (arr) => (Array.isArray(arr) ? arr.join('\n') : arr || '');
const fromLines = (str) =>
	str
		.split('\n')
		.map((s) => s.trim())
		.filter(Boolean);

const SchemaSoftwareForm = ({ settings, onSettingChange, isLoading }) => {
	const disabled = !settings.enabled;

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{/* ── Basic Information ─────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconCode />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Basic Information', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Core application details for schema markup',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Input
						label={__('Application Name', 'thinkrank')}
						value={settings.software_name || ''}
						onChange={(e) =>
							onSettingChange('software_name', e.target.value)
						}
						description={__(
							'Full name of the software application',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<Input
						label={__('Tagline / Alternate Name', 'thinkrank')}
						value={settings.software_alternate_name || ''}
						onChange={(e) =>
							onSettingChange(
								'software_alternate_name',
								e.target.value
							)
						}
						description={__(
							'e.g., "Design Made Simple" or "Code Without Limits"',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Version', 'thinkrank')}
							value={settings.software_version || ''}
							onChange={(e) =>
								onSettingChange(
									'software_version',
									e.target.value
								)
							}
							description={__('e.g., 2.1.0 or v1.5', 'thinkrank')}
							placeholder="1.0.0"
							disabled={disabled}
						/>
						<Input
							label={__('Website URL', 'thinkrank')}
							type="url"
							value={settings.software_url || ''}
							onChange={(e) =>
								onSettingChange('software_url', e.target.value)
							}
							description={__(
								'Official application website',
								'thinkrank'
							)}
							placeholder="https://example.com"
							disabled={disabled}
						/>
					</div>
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Creator / Developer', 'thinkrank')}
							value={settings.software_creator || ''}
							onChange={(e) =>
								onSettingChange(
									'software_creator',
									e.target.value
								)
							}
							description={__(
								'Name of the person or organization',
								'thinkrank'
							)}
							disabled={disabled}
						/>
						<Select
							label={__('Creator Type', 'thinkrank')}
							value={settings.software_creator_type || 'Person'}
							options={CREATOR_TYPE_OPTIONS}
							onChange={(e) =>
								onSettingChange(
									'software_creator_type',
									e.target.value
								)
							}
							disabled={disabled}
						/>
					</div>
					<Textarea
						label={__('Description', 'thinkrank')}
						value={settings.software_description || ''}
						onChange={(e) =>
							onSettingChange(
								'software_description',
								e.target.value
							)
						}
						description={__(
							'Detailed description of features and functionality',
							'thinkrank'
						)}
						rows={3}
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── Technical Specifications ──────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconMonitor />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Technical Specifications', 'thinkrank')}
						</h3>
					</div>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Textarea
						label={__('Operating Systems', 'thinkrank')}
						value={toLines(settings.software_operating_systems)}
						onChange={(e) =>
							onSettingChange(
								'software_operating_systems',
								fromLines(e.target.value)
							)
						}
						description={__(
							'Supported operating systems, one per line (e.g., Windows, macOS, Linux)',
							'thinkrank'
						)}
						placeholder={'Windows\nmacOS\nLinux'}
						rows={4}
						disabled={disabled}
					/>
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('File Size', 'thinkrank')}
							value={settings.software_file_size || ''}
							onChange={(e) =>
								onSettingChange(
									'software_file_size',
									e.target.value
								)
							}
							description={__(
								'Download size, e.g., 25 MB, 1.2 GB',
								'thinkrank'
							)}
							disabled={disabled}
						/>
						<Select
							label={__('License Type', 'thinkrank')}
							value={settings.software_license || ''}
							options={LICENSE_OPTIONS}
							onChange={(e) =>
								onSettingChange(
									'software_license',
									e.target.value
								)
							}
							disabled={disabled}
						/>
					</div>
				</div>
			</Card>

			{/* ── Category & Features ───────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconTag />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Category & Features', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Application classification and key capabilities',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Select
						label={__('Application Category', 'thinkrank')}
						value={settings.software_category || ''}
						options={CATEGORY_OPTIONS}
						onChange={(e) =>
							onSettingChange('software_category', e.target.value)
						}
						description={__(
							'Primary category that best describes your application',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<Textarea
						label={__('Key Features', 'thinkrank')}
						value={toLines(settings.software_features)}
						onChange={(e) =>
							onSettingChange(
								'software_features',
								fromLines(e.target.value)
							)
						}
						description={__(
							'List key features, one per line (e.g., User-friendly interface / Cloud synchronization)',
							'thinkrank'
						)}
						rows={5}
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── Pricing & Availability ────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Pricing & Availability', 'thinkrank')}
					</h3>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Price', 'thinkrank')}
							type="number"
							step="0.01"
							min="0"
							value={settings.software_price || ''}
							onChange={(e) =>
								onSettingChange(
									'software_price',
									e.target.value
								)
							}
							description={__('Use 0 for free apps', 'thinkrank')}
							disabled={disabled}
						/>
						<Select
							label={__('Currency', 'thinkrank')}
							value={settings.software_currency || 'USD'}
							options={CURRENCY_OPTIONS}
							onChange={(e) =>
								onSettingChange(
									'software_currency',
									e.target.value
								)
							}
							disabled={disabled}
						/>
					</div>
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Price Valid Until', 'thinkrank')}
							type="date"
							value={settings.software_price_valid_until || ''}
							onChange={(e) =>
								onSettingChange(
									'software_price_valid_until',
									e.target.value
								)
							}
							description={__(
								'When this price expires (optional)',
								'thinkrank'
							)}
							disabled={disabled}
						/>
						<Select
							label={__('Availability', 'thinkrank')}
							value={settings.software_availability || 'InStock'}
							options={AVAILABILITY_OPTIONS}
							onChange={(e) =>
								onSettingChange(
									'software_availability',
									e.target.value
								)
							}
							disabled={disabled}
						/>
					</div>
					<Textarea
						label={__('Pricing Description', 'thinkrank')}
						value={settings.software_pricing_description || ''}
						onChange={(e) =>
							onSettingChange(
								'software_pricing_description',
								e.target.value
							)
						}
						description={__(
							'Describe pricing model, e.g., "Free version available with premium features"',
							'thinkrank'
						)}
						rows={2}
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── User Ratings ──────────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconStar />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('User Ratings', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Optional. Leave empty if you have no ratings data',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Average Rating', 'thinkrank')}
							type="number"
							step="0.1"
							min="1"
							max="5"
							value={settings.software_rating_value || ''}
							onChange={(e) =>
								onSettingChange(
									'software_rating_value',
									e.target.value
								)
							}
							description={__(
								'Rating on a 1–5 scale',
								'thinkrank'
							)}
							disabled={disabled}
						/>
						<Input
							label={__('Total Reviews', 'thinkrank')}
							type="number"
							min="0"
							value={settings.software_rating_count || ''}
							onChange={(e) =>
								onSettingChange(
									'software_rating_count',
									e.target.value
								)
							}
							description={__(
								'Number of reviews / ratings',
								'thinkrank'
							)}
							disabled={disabled}
						/>
					</div>
				</div>
			</Card>
		</div>
	);
};

export default SchemaSoftwareForm;
