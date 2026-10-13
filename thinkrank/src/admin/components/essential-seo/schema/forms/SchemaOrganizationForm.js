/**
 * Schema Organization Form Component
 *
 * Redesigned — uses plugin UI components, Tailwind classes, inline SVG icons.
 *
 * @package
 * @since 1.0.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { Badge, Card, Input, Textarea, Select } from '../../../ui';
import MediaPicker from '../../../common/MediaPicker';
import SchemaDisabledNotice from '../SchemaDisabledNotice';
import {
	getOrganizationTypeOptions,
	getContactTypeOptions,
} from '../../../../config/schema-settings-config';

/* ── Icons ──────────────────────────────────────────────────────────────── */

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

const IconArrow = () => (
	<svg
		width="13"
		height="13"
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

const IconFacebook = () => (
	<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
		<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z" />
	</svg>
);

const IconTwitter = () => (
	<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
		<path d="M22 4s-.7 2.1-2 3.4c1.6 10-9.4 17.3-18 11.6 2.2.1 4.4-.6 6-2C3 15.5.5 9.6 3 5c2.2 2.6 5.6 4.1 9 4-.9-4.2 4-6.6 7-3.8 1.1 0 3-1.2 3-1.2z" />
	</svg>
);

const IconLinkedIn = () => (
	<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
		<path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z" />
		<rect x="2" y="9" width="4" height="12" />
		<circle cx="4" cy="4" r="2" />
	</svg>
);

const IconInstagram = () => (
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
		<rect x="2" y="2" width="20" height="20" rx="5" />
		<path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" />
		<line x1="17.5" y1="6.5" x2="17.51" y2="6.5" />
	</svg>
);

const IconYouTube = () => (
	<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
		<path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46a2.78 2.78 0 0 0-1.95 1.96A29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58A2.78 2.78 0 0 0 3.41 19.6C5.12 20 12 20 12 20s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.95-1.95A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58z" />
		<polygon fill="#fff" points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02" />
	</svg>
);

const IconClock = () => (
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
		<polyline points="12 6 12 12 16 14" />
	</svg>
);

const IconPhone = () => (
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
		<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" />
	</svg>
);

const IconMail = () => (
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
		<rect x="2" y="4" width="20" height="16" rx="2" />
		<polyline points="22,6 12,13 2,6" />
	</svg>
);

/* ── Validation ─────────────────────────────────────────────────────────── */

/**
 * Whether a string is a complete http(s) URL.
 *
 * Social profiles become `sameAs` values in the emitted schema, where a bare
 * handle or a stray sentence is not a valid reference — Google needs a
 * resolvable URL.
 *
 * @param {string} value Candidate URL.
 * @return {boolean} True when the value parses as an http(s) URL with a host.
 */
const isHttpUrl = (value) => {
	try {
		const url = new URL(value.trim());
		return (
			(url.protocol === 'http:' || url.protocol === 'https:') &&
			!!url.hostname &&
			url.hostname.includes('.')
		);
	} catch (e) {
		return false;
	}
};

/**
 * Error for a social profile field, if any. Empty is allowed — the field is
 * optional, and only a filled-in bad value is worth flagging.
 *
 * @param {string} value Field value.
 * @return {string} Error message, or an empty string.
 */
const socialUrlError = (value) => {
	const trimmed = (value || '').trim();
	if (!trimmed || isHttpUrl(trimmed)) {
		return '';
	}
	return __(
		'Enter the full profile URL, starting with https://',
		'thinkrank'
	);
};

/**
 * Error for the contact email field, if any.
 *
 * @param {string} value Field value.
 * @return {string} Error message, or an empty string.
 */
const emailError = (value) => {
	const trimmed = (value || '').trim();
	if (!trimmed || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) {
		return '';
	}
	return __('Enter a valid email address.', 'thinkrank');
};

/**
 * Error for the contact phone field, if any.
 *
 * Deliberately permissive: international numbers vary too much to pattern
 * match, so this only rejects values that cannot be a phone number at all.
 *
 * @param {string} value Field value.
 * @return {string} Error message, or an empty string.
 */
const phoneError = (value) => {
	const trimmed = (value || '').trim();
	if (!trimmed) {
		return '';
	}
	const digits = trimmed.replace(/\D/g, '');
	if (/[a-zA-Z]/.test(trimmed) || digits.length < 7) {
		return __('Enter a valid phone number.', 'thinkrank');
	}
	return '';
};

/* ── Social row ─────────────────────────────────────────────────────────── */

/**
 * Platforms rendered in the Social Media Profiles card.
 *
 * `tint`/`color` are per-brand and data-driven, so they stay inline rather
 * than becoming Tailwind tokens.
 */
const SOCIAL_PLATFORMS = [
	{
		key: 'organization_social_facebook',
		label: __('Facebook', 'thinkrank'),
		Icon: IconFacebook,
		color: '#1877F2',
		tint: '#E7F0FE',
		placeholder: 'https://facebook.com/yourpage',
	},
	{
		key: 'organization_social_twitter',
		label: __('Twitter / X', 'thinkrank'),
		Icon: IconTwitter,
		color: '#1D9BF0',
		tint: '#E8F5FE',
		placeholder: 'https://twitter.com/yourhandle',
	},
	{
		key: 'organization_social_linkedin',
		label: __('LinkedIn', 'thinkrank'),
		Icon: IconLinkedIn,
		color: '#0A66C2',
		tint: '#E7F0F9',
		placeholder: 'https://linkedin.com/company/yourcompany',
	},
	{
		key: 'organization_social_instagram',
		label: __('Instagram', 'thinkrank'),
		Icon: IconInstagram,
		color: '#E4405F',
		tint: '#FDECEF',
		placeholder: 'https://instagram.com/yourhandle',
	},
	{
		key: 'organization_social_youtube',
		label: __('YouTube', 'thinkrank'),
		Icon: IconYouTube,
		color: '#FF0000',
		tint: '#FFEAEA',
		placeholder: 'https://youtube.com/c/yourchannel',
	},
];

const SocialRow = ({
	Icon,
	label,
	color,
	tint,
	value,
	settingKey,
	onSettingChange,
	disabled,
	placeholder,
}) => {
	const error = socialUrlError(value);

	return (
		// items-start, not items-center: the chip must stay put when an error
		// line appears under the input and grows the row.
		<div className="tr-flex tr-items-start tr-gap-3">
			<span
				className="tr-flex tr-items-center tr-justify-center tr-w-8 tr-h-8 tr-mt-1 tr-rounded-lg tr-shrink-0"
				style={{
					backgroundColor: tint,
					color: disabled ? '#A9AEC0' : color,
				}}
			>
				<Icon />
			</span>
			<Input
				id={settingKey}
				className="tr-flex-1"
				type="url"
				// No visible label — the brand icon names the platform. Screen
				// readers still need one, and the placeholder is not a label.
				aria-label={label}
				value={value || ''}
				onChange={(e) => onSettingChange(settingKey, e.target.value)}
				placeholder={placeholder}
				disabled={disabled}
				error={error}
				aria-invalid={error ? 'true' : undefined}
			/>
		</div>
	);
};

/* ── Main component ─────────────────────────────────────────────────────── */

const SchemaOrganizationForm = ({
	settings,
	onSettingChange,
	isLoading,
	onNavigate,
}) => {
	const disabled = !settings.enabled;

	const socialFilledCount = SOCIAL_PLATFORMS.filter((platform) =>
		(settings[platform.key] || '').trim()
	).length;

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{disabled && <SchemaDisabledNotice onNavigate={onNavigate} />}
			{/* ── Identity ─────────────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Organization Identity', 'thinkrank')}
					</h3>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Core details used in Organization schema markup',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Select
						label={__('Organization Type', 'thinkrank')}
						value={settings.organization_type || ''}
						options={getOrganizationTypeOptions()}
						onChange={(e) =>
							onSettingChange('organization_type', e.target.value)
						}
						description={__(
							'Type of organization for schema markup',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<Input
						label={__('Organization Name', 'thinkrank')}
						value={settings.organization_name || ''}
						onChange={(e) =>
							onSettingChange('organization_name', e.target.value)
						}
						description={__(
							'Official name of your organization',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<Input
						label={__('Organization URL', 'thinkrank')}
						value={settings.organization_url || ''}
						onChange={(e) =>
							onSettingChange('organization_url', e.target.value)
						}
						description={__(
							'Official website URL of your organization',
							'thinkrank'
						)}
						placeholder="https://example.com"
						disabled={disabled}
					/>
					<Textarea
						label={__('Organization Description', 'thinkrank')}
						value={settings.organization_description || ''}
						onChange={(e) =>
							onSettingChange(
								'organization_description',
								e.target.value
							)
						}
						description={__(
							'Brief description of your organization',
							'thinkrank'
						)}
						rows={3}
						disabled={disabled}
					/>
					<MediaPicker
						label={__('Organization Logo', 'thinkrank')}
						value={settings.organization_logo}
						onChange={(value) =>
							onSettingChange('organization_logo', value)
						}
						help={__(
							'Logo image used in Organization schema markup',
							'thinkrank'
						)}
						allowedTypes={['image']}
						disabled={disabled}
					/>

					{/* Address managed elsewhere notice */}
					<div className="tr-flex tr-items-start tr-gap-2 tr-bg-brand-bg tr-border tr-border-brand/30 tr-rounded-lg tr-p-3">
						<span className="tr-text-brand tr-shrink-0 tr-mt-0.5">
							<IconInfo />
						</span>
						<div className="tr-flex tr-items-center tr-justify-between tr-flex-1 tr-gap-3">
							<p className="tr-text-xs tr-text-brand tr-m-0">
								{__(
									'Business address, phone, and email are managed in Site Identity › Business Info for consistency across all local SEO features.',
									'thinkrank'
								)}
							</p>
							{/* Only rendered when navigation is actually wired.
							    It used to render unconditionally and no-op if
							    the prop was missing, so a forgotten wire looked
							    exactly like a working button. */}
							{typeof onNavigate === 'function' && (
								<button
									type="button"
									onClick={() =>
										onNavigate('site-identity', 'local-seo')
									}
									className="tr-flex tr-items-center tr-gap-1 tr-text-xs tr-font-medium tr-text-brand tr-bg-white tr-border tr-border-brand tr-rounded tr-px-2 tr-py-1 hover:tr-bg-brand-bg tr-transition-colors tr-duration-150 tr-cursor-pointer tr-whitespace-nowrap tr-shrink-0"
								>
									{__('Go there', 'thinkrank')} <IconArrow />
								</button>
							)}
						</div>
					</div>
				</div>
			</Card>

			{/* ── Social Media ─────────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary tr-flex tr-items-start tr-justify-between tr-gap-3">
					<div>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Social Media Profiles', 'thinkrank')}
						</h3>
						<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
							{__(
								'Used as sameAs properties in Organization schema',
								'thinkrank'
							)}
						</p>
					</div>
					<Badge
						variant={socialFilledCount > 0 ? 'info' : 'neutral'}
						className="tr-shrink-0"
					>
						{sprintf(
							/* translators: 1: profiles filled in, 2: profiles available. */
							__('%1$d of %2$d added', 'thinkrank'),
							socialFilledCount,
							SOCIAL_PLATFORMS.length
						)}
					</Badge>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					{SOCIAL_PLATFORMS.map((platform) => (
						<SocialRow
							key={platform.key}
							Icon={platform.Icon}
							label={platform.label}
							color={platform.color}
							tint={platform.tint}
							value={settings[platform.key]}
							settingKey={platform.key}
							onSettingChange={onSettingChange}
							disabled={disabled}
							placeholder={platform.placeholder}
						/>
					))}
				</div>
			</Card>

			{/* ── Contact Point ─────────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Contact Point', 'thinkrank')}
					</h3>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'ContactPoint schema nested inside Organization',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Select
						label={__('Contact Type', 'thinkrank')}
						value={settings.organization_contact_type || ''}
						options={getContactTypeOptions()}
						onChange={(e) =>
							onSettingChange(
								'organization_contact_type',
								e.target.value
							)
						}
						description={__(
							'Nature of this contact point',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<Input
						label={__('Contact Phone', 'thinkrank')}
						prefix={<IconPhone />}
						type="tel"
						value={settings.organization_contact_phone || ''}
						onChange={(e) =>
							onSettingChange(
								'organization_contact_phone',
								e.target.value
							)
						}
						description={__(
							'Contact phone number for this point',
							'thinkrank'
						)}
						error={phoneError(settings.organization_contact_phone)}
						disabled={disabled}
					/>
					<Input
						label={__('Contact Email', 'thinkrank')}
						prefix={<IconMail />}
						value={settings.organization_contact_email || ''}
						onChange={(e) =>
							onSettingChange(
								'organization_contact_email',
								e.target.value
							)
						}
						description={__('Contact email address', 'thinkrank')}
						error={emailError(settings.organization_contact_email)}
						type="email"
						disabled={disabled}
					/>

					{/* Contact hours — read-only, synced from Local SEO */}
					<div className="tr-border tr-border-secondary tr-rounded-lg tr-overflow-hidden">
						<div className="tr-flex tr-items-center tr-gap-2 tr-px-4 tr-py-2.5 tr-bg-white tr-border-b tr-border-secondary">
							<span className="tr-text-tertiary">
								<IconClock />
							</span>
							<span className="tr-text-xs tr-font-semibold tr-text-secondary">
								{__('Contact Hours', 'thinkrank')}
							</span>
							<span className="tr-ml-auto tr-text-xs tr-text-tertiary tr-italic">
								{__('Auto-synced from Local SEO', 'thinkrank')}
							</span>
						</div>
						<div className="tr-px-4 tr-py-3">
							<p className="tr-text-sm tr-text-secondary tr-m-0">
								{settings.organization_contact_hours ? (
									settings.organization_contact_hours
								) : (
									<span className="tr-text-tertiary tr-italic">
										{__(
											'No hours configured yet set business hours in Site Identity › Business Info.',
											'thinkrank'
										)}
									</span>
								)}
							</p>
						</div>
					</div>
				</div>
			</Card>
		</div>
	);
};

export default SchemaOrganizationForm;
