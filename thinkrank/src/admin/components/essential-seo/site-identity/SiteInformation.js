/**
 * Site Information Component
 *
 * Extracted from SiteIdentityTab.js — handles basic site information form fields.
 *
 * @package
 * @since 1.0.0
 */

import { __, sprintf } from '@wordpress/i18n';
import classnames from 'classnames';
import { Card, Input, Textarea, Container } from '../../ui';
import SectionHeader from '../../common/SectionHeader';
import FormField from '../../common/FormField';

/**
 * alternate_name is stored as a string or a list of them: schema.org allows
 * `alternateName` to carry several, and the MCP ability accepts either shape.
 * The field shows them comma-separated so one text input can edit both, and
 * saves a list back only when there is more than one name — otherwise a site
 * with a single alternate name would start storing a one-element array for no
 * reason (#692).
 *
 * @param {string|string[]} value Stored alternate_name.
 * @return {string} Comma-separated names for the input.
 */
const formatAlternateName = (value) =>
	Array.isArray(value) ? value.join(', ') : value || '';

/**
 * @param {string} input Comma-separated names from the input.
 * @return {string|string[]} One name as a string, several as a list, '' when empty.
 */
const parseAlternateName = (input) => {
	const names = String(input)
		.split(',')
		.map((name) => name.trim())
		.filter(Boolean);

	if (names.length === 0) {
		return '';
	}

	return names.length === 1 ? names[0] : names;
};

const META_MIN = 120;
const META_MAX = 160;

const getLengthStatus = (len) => {
	if (len >= META_MIN && len <= META_MAX) {
		return {
			label: __('Good length', 'thinkrank'),
			tone: 'tr-text-success',
		};
	}
	if (len < META_MIN) {
		return {
			label: __('Too short', 'thinkrank'),
			tone: 'tr-text-warning',
		};
	}
	return {
		label: __('Too long', 'thinkrank'),
		tone: 'tr-text-warning',
	};
};

const LengthStatus = ({ length }) => {
	const status = getLengthStatus(length);
	return (
		<span
			className={classnames('tr-text-xs tr-font-medium', status.tone)}
			aria-live="polite"
		>
			{status.label} ({length}/{META_MAX})
		</span>
	);
};

const SiteInformation = ({ settings, handleSettingChange }) => {
	const alternateNameValue = formatAlternateName(settings.alternate_name);
	const disabled = !settings.enabled;
	const descLen = settings.site_description?.length || 0;
	const metaLen = settings.default_meta_description?.length || 0;
	const homeMetaLen = settings.homepage_description?.length || 0;

	return (
		<Container direction="column" gap={4}>
			<Card padding={false}>
				<SectionHeader
					title={__('Site Information', 'thinkrank')}
					description={__(
						'The core identity fields used as defaults across your site.',
						'thinkrank'
					)}
				/>
				<Container
					direction="column"
					gap={6}
					className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
				>
					<FormField
						id="tr-si-name"
						label={__('Site Name', 'thinkrank')}
						description={
							/* translators: %sitename% is a literal template variable token, not a printf placeholder. */
							__(
								'The name of your website. Used wherever %sitename% appears.',
								'thinkrank'
							)
						}
						disabled={disabled}
					>
						<Input
							id="tr-si-name"
							value={settings.site_name}
							onChange={(e) =>
								handleSettingChange('site_name', e.target.value)
							}
							placeholder={__(
								'e.g., Acme Coffee Roasters',
								'thinkrank'
							)}
							disabled={disabled}
						/>
					</FormField>

					<FormField
						id="tr-si-description"
						label={__('Site Description', 'thinkrank')}
						description={__(
							'A brief description of your website. Aim for 120–160 characters.',
							'thinkrank'
						)}
						counter={<LengthStatus length={descLen} />}
						disabled={disabled}
						className="tr-pt-6"
					>
						<Input
							id="tr-si-description"
							value={settings.site_description}
							onChange={(e) =>
								handleSettingChange(
									'site_description',
									e.target.value
								)
							}
							placeholder={__(
								'e.g., Small-batch specialty coffee roasted in Brooklyn.',
								'thinkrank'
							)}
							disabled={disabled}
						/>
					</FormField>

					<FormField
						id="tr-si-alternate-name"
						label={__('Alternate Name', 'thinkrank')}
						description={__(
							'Another name your site is known by, published as schema alternateName. Separate several with commas.',
							'thinkrank'
						)}
						disabled={disabled}
						className="tr-pt-6"
					>
						<Input
							id="tr-si-alternate-name"
							value={alternateNameValue}
							onChange={(e) =>
								handleSettingChange(
									'alternate_name',
									parseAlternateName(e.target.value)
								)
							}
							placeholder={__(
								'e.g., TR, ThinkRank SEO',
								'thinkrank'
							)}
							disabled={disabled}
						/>
					</FormField>

					<FormField
						id="tr-si-tagline"
						label={__('Tagline', 'thinkrank')}
						description={__(
							'Your site tagline or slogan.',
							'thinkrank'
						)}
						disabled={disabled}
						className="tr-pt-6"
					>
						<Input
							id="tr-si-tagline"
							value={settings.tagline}
							onChange={(e) =>
								handleSettingChange('tagline', e.target.value)
							}
							placeholder={__(
								'e.g., Coffee, crafted with care.',
								'thinkrank'
							)}
							disabled={disabled}
						/>
					</FormField>

					<FormField
						id="tr-si-meta-description"
						label={__('Default Meta Description', 'thinkrank')}
						description={__(
							'Used for pages that do not set their own meta description. Aim for 120–160 characters.',
							'thinkrank'
						)}
						counter={<LengthStatus length={metaLen} />}
						disabled={disabled}
						className="tr-pt-6"
					>
						<Textarea
							id="tr-si-meta-description"
							value={settings.default_meta_description}
							onChange={(e) =>
								handleSettingChange(
									'default_meta_description',
									e.target.value
								)
							}
							placeholder={__(
								'e.g., Discover small-batch specialty coffee, freshly roasted and shipped from Brooklyn.',
								'thinkrank'
							)}
							rows={3}
							disabled={disabled}
						/>
					</FormField>

					<FormField
						id="tr-si-homepage-description"
						label={__('Homepage Meta Description', 'thinkrank')}
						description={sprintf(
							/* translators: %s: the list of available variable tags. */
							__(
								'Used on the homepage when it shows your latest posts. A static front page uses that page’s own description. Available tags: %s.',
								'thinkrank'
							),
							'%site_title%, %site_description%, %sep%'
						)}
						counter={<LengthStatus length={homeMetaLen} />}
						disabled={disabled}
						className="tr-pt-6"
					>
						<Textarea
							id="tr-si-homepage-description"
							value={settings.homepage_description}
							onChange={(e) =>
								handleSettingChange(
									'homepage_description',
									e.target.value
								)
							}
							placeholder={__(
								'Leave empty to use the default meta description.',
								'thinkrank'
							)}
							rows={3}
							disabled={disabled}
						/>
					</FormField>
				</Container>
			</Card>
		</Container>
	);
};

export default SiteInformation;
