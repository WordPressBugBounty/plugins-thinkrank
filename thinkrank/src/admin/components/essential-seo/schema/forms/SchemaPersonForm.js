/**
 * Schema Person Form Component
 *
 * Redesigned — uses plugin UI components and Tailwind classes.
 *
 * @package
 * @since 1.0.0
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Card, Input, Textarea } from '../../../ui';
import MediaPicker from '../../../common/MediaPicker';
import SchemaDisabledNotice from '../SchemaDisabledNotice';

const IconUser = () => (
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
		<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
		<circle cx="12" cy="7" r="4" />
	</svg>
);

const IconMail = () => (
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
		<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
		<polyline points="22,6 12,13 2,6" />
	</svg>
);

const IconBriefcase = () => (
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
		<rect x="2" y="7" width="20" height="14" rx="2" />
		<path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" />
	</svg>
);

const IconLink = () => (
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
		<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
		<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
	</svg>
);

const toLines = (arr) => (Array.isArray(arr) ? arr.join('\n') : arr || '');
const fromLines = (str) =>
	str
		.split('\n')
		.map((s) => s.trim())
		.filter(Boolean);

const SchemaPersonForm = ({
	settings,
	onSettingChange,
	isLoading,
	onNavigate,
}) => {
	const disabled = !settings.enabled;

	// The profile URLs textarea keeps its raw text locally while the user types.
	// Splitting and trimming on every keystroke deleted the empty trailing line
	// that pressing Enter creates, so the newline was erased as it was typed and
	// a second URL could never be entered by hand (#469). Normalise on blur.
	const [sameAsText, setSameAsText] = useState(() =>
		toLines(settings.person_same_as)
	);

	// Re-sync when the value changes from outside (settings load, reset).
	useEffect(() => {
		setSameAsText(toLines(settings.person_same_as));
	}, [settings.person_same_as]);

	const commitSameAs = () => {
		const parsed = fromLines(sameAsText);

		if (parsed.join('\n') !== toLines(settings.person_same_as)) {
			onSettingChange('person_same_as', parsed);
		}
	};

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{disabled && <SchemaDisabledNotice onNavigate={onNavigate} />}
			{/* ── Personal Information ──────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconUser />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Personal Information', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Core details for Person schema markup',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Input
						label={__('Full Name', 'thinkrank')}
						value={settings.person_name || ''}
						onChange={(e) =>
							onSettingChange('person_name', e.target.value)
						}
						description={__('Full name of the person', 'thinkrank')}
						disabled={disabled}
					/>
					<MediaPicker
						label={__('Profile Image', 'thinkrank')}
						value={settings.person_image}
						onChange={(value) =>
							onSettingChange('person_image', value)
						}
						help={__(
							'Professional headshot or profile photo',
							'thinkrank'
						)}
						allowedTypes={['image']}
						disabled={disabled}
					/>
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Job Title', 'thinkrank')}
							value={settings.person_job_title || ''}
							onChange={(e) =>
								onSettingChange(
									'person_job_title',
									e.target.value
								)
							}
							description={__(
								'Professional title or position',
								'thinkrank'
							)}
							disabled={disabled}
						/>
						<Input
							label={__('Nationality', 'thinkrank')}
							value={settings.person_nationality || ''}
							onChange={(e) =>
								onSettingChange(
									'person_nationality',
									e.target.value
								)
							}
							description={__(
								'Country of citizenship',
								'thinkrank'
							)}
							disabled={disabled}
						/>
					</div>
					<Textarea
						label={__('Biography', 'thinkrank')}
						value={settings.person_description || ''}
						onChange={(e) =>
							onSettingChange(
								'person_description',
								e.target.value
							)
						}
						description={__(
							'Brief professional biography or description',
							'thinkrank'
						)}
						rows={3}
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── Contact Details ───────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconMail />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Contact Details', 'thinkrank')}
						</h3>
					</div>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Website URL', 'thinkrank')}
							type="url"
							value={settings.person_url || ''}
							onChange={(e) =>
								onSettingChange('person_url', e.target.value)
							}
							description={__(
								'Personal or professional website',
								'thinkrank'
							)}
							placeholder="https://example.com"
							disabled={disabled}
						/>
						<Input
							label={__('Email Address', 'thinkrank')}
							type="email"
							value={settings.person_email || ''}
							onChange={(e) =>
								onSettingChange('person_email', e.target.value)
							}
							description={__(
								'Professional contact email',
								'thinkrank'
							)}
							disabled={disabled}
						/>
					</div>
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Phone Number', 'thinkrank')}
							type="tel"
							value={settings.person_telephone || ''}
							onChange={(e) =>
								onSettingChange(
									'person_telephone',
									e.target.value
								)
							}
							description={__(
								'Professional contact number',
								'thinkrank'
							)}
							disabled={disabled}
						/>
						<Input
							label={__('Birth Date', 'thinkrank')}
							type="date"
							value={settings.person_birth_date || ''}
							onChange={(e) =>
								onSettingChange(
									'person_birth_date',
									e.target.value
								)
							}
							description={__(
								'Date of birth (optional)',
								'thinkrank'
							)}
							disabled={disabled}
						/>
					</div>
				</div>
			</Card>

			{/* ── Professional Details ──────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconBriefcase />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Professional Details', 'thinkrank')}
						</h3>
					</div>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Textarea
						label={__('Address', 'thinkrank')}
						value={settings.person_address || ''}
						onChange={(e) =>
							onSettingChange('person_address', e.target.value)
						}
						description={__(
							'Professional address (optional)',
							'thinkrank'
						)}
						rows={2}
						disabled={disabled}
					/>
					<Input
						label={__('Works For (Organization)', 'thinkrank')}
						value={settings.person_works_for || ''}
						onChange={(e) =>
							onSettingChange('person_works_for', e.target.value)
						}
						description={__(
							'Organization or company name (optional)',
							'thinkrank'
						)}
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── Social Media Profiles ─────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconLink />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Social Media Profiles', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Used as sameAs properties, one URL per line',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5">
					<Textarea
						label={__('Profile URLs', 'thinkrank')}
						value={sameAsText}
						onChange={(e) => setSameAsText(e.target.value)}
						onBlur={commitSameAs}
						description={__(
							'Enter social media profile URLs one per line e.g., LinkedIn, Twitter, Wikipedia',
							'thinkrank'
						)}
						placeholder={
							'https://linkedin.com/in/yourname\nhttps://twitter.com/yourhandle'
						}
						rows={4}
						disabled={disabled}
					/>
				</div>
			</Card>
		</div>
	);
};

export default SchemaPersonForm;
