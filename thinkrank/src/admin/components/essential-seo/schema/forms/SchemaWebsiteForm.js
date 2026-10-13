/**
 * Schema Website Form Component
 *
 * Redesigned — uses plugin UI components and Tailwind classes.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Card, Input, Textarea, Toggle } from '../../../ui';
import SchemaDisabledNotice from '../SchemaDisabledNotice';

const IconGlobe = () => (
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
		<circle cx="12" cy="12" r="10" />
		<line x1="2" y1="12" x2="22" y2="12" />
		<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
	</svg>
);

const IconSearch = () => (
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
		<circle cx="11" cy="11" r="8" />
		<line x1="21" y1="21" x2="16.65" y2="16.65" />
	</svg>
);

const IconInfo = () => (
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
		<circle cx="12" cy="12" r="10" />
		<line x1="12" y1="16" x2="12" y2="12" />
		<line x1="12" y1="8" x2="12.01" y2="8" />
	</svg>
);

const IconCheck = () => (
	<svg
		width="14"
		height="14"
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

const SchemaWebsiteForm = ({
	settings,
	onSettingChange,
	isLoading,
	onNavigate,
}) => {
	const disabled = !settings.enabled;
	const searchEnabled = settings.website_enable_search ?? true;

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{disabled && <SchemaDisabledNotice onNavigate={onNavigate} />}
			{/* ── Website Identity ──────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconGlobe />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Website Identity', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Core website details for schema markup',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Input
						label={__('Website Name', 'thinkrank')}
						value={settings.website_name || ''}
						onChange={(e) =>
							onSettingChange('website_name', e.target.value)
						}
						description={__(
							'Name of your website defaults to site title if empty',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<Input
						label={__('Website URL', 'thinkrank')}
						type="url"
						value={settings.website_url || ''}
						onChange={(e) =>
							onSettingChange('website_url', e.target.value)
						}
						description={__(
							'Main URL of your website defaults to home URL if empty',
							'thinkrank'
						)}
						placeholder="https://example.com"
						disabled={disabled}
					/>
					<Textarea
						label={__('Website Description', 'thinkrank')}
						value={settings.website_description || ''}
						onChange={(e) =>
							onSettingChange(
								'website_description',
								e.target.value
							)
						}
						description={__(
							'Brief description defaults to site tagline if empty',
							'thinkrank'
						)}
						rows={3}
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── Search Configuration ──────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconSearch />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Search Configuration', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Sitelinks search box in Google search results',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Toggle
						id="website-enable-search"
						label={__('Enable Sitelinks Search Box', 'thinkrank')}
						description={__(
							'Show a search box directly in Google search results for your site',
							'thinkrank'
						)}
						checked={searchEnabled}
						onChange={(value) =>
							onSettingChange('website_enable_search', value)
						}
						disabled={disabled}
					/>
					{searchEnabled && (
						<Input
							label={__('Search URL Template', 'thinkrank')}
							value={settings.website_search_url || ''}
							onChange={(e) =>
								onSettingChange(
									'website_search_url',
									e.target.value
								)
							}
							description={__(
								'Use {search_term_string} as placeholder leave empty to use default WordPress search',
								'thinkrank'
							)}
							placeholder="https://example.com/?s={search_term_string}"
							disabled={disabled}
						/>
					)}
				</div>
			</Card>

			{/* ── Auto-managed Data ─────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconInfo />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Auto-managed Data', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'These properties are pulled from other settings automatically',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-3">
					<div className="tr-flex tr-items-start tr-gap-3 tr-border tr-border-secondary tr-rounded-lg tr-p-3.5">
						<span
							className={`tr-flex tr-items-center tr-justify-center tr-w-7 tr-h-7 tr-rounded-full tr-shrink-0 ${settings.logo_url ? 'tr-bg-success-bg tr-text-success' : 'tr-bg-white tr-text-tertiary'}`}
						>
							<IconCheck />
						</span>
						<div>
							<p className="tr-text-sm tr-font-medium tr-text-secondary tr-m-0">
								{__('Site Logo', 'thinkrank')}
							</p>
							<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
								{settings.logo_url
									? __(
											'Logo configured, sourced from Site Identity › Site Assets',
											'thinkrank'
										)
									: __(
											'No logo set configure in Site Identity › Site Assets',
											'thinkrank'
										)}
							</p>
						</div>
					</div>
					<div className="tr-flex tr-items-start tr-gap-3 tr-border tr-border-secondary tr-rounded-lg tr-p-3.5">
						<span className="tr-flex tr-items-center tr-justify-center tr-w-7 tr-h-7 tr-rounded-full tr-bg-white tr-text-tertiary tr-shrink-0">
							<IconInfo />
						</span>
						<div>
							<p className="tr-text-sm tr-font-medium tr-text-secondary tr-m-0">
								{__('Social Profiles (sameAs)', 'thinkrank')}
							</p>
							<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
								{__(
									'Configured in Schema Manager › Organization automatically included in Website schema',
									'thinkrank'
								)}
							</p>
						</div>
					</div>
				</div>
			</Card>
		</div>
	);
};

export default SchemaWebsiteForm;
