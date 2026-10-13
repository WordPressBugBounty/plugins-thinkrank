/**
 * Hero Section Component
 *
 * Handles hero section optimization settings for Site Identity
 * Provides comprehensive hero content management and AI optimization
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Card, Input, Textarea, Container } from '../../ui';
import SectionHeader from '../../common/SectionHeader';
import FormField from '../../common/FormField';
import MediaPicker from '../../common/MediaPicker';

/**
 * Hero Section Component
 * @param root0
 * @param root0.settings
 * @param root0.handleSettingChange
 */
const HeroSection = ({ settings, handleSettingChange }) => {
	const disabled = !settings.enabled;

	return (
		<Container direction="column" gap={4}>
			<Card padding={false}>
				<SectionHeader
					title={__('Hero Section Optimization', 'thinkrank')}
					description={__(
						'Configure the headline, supporting copy, and call-to-action for your hero.',
						'thinkrank'
					)}
				/>
				<Container
					direction="column"
					gap={6}
					className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
				>
					<FormField
						id="tr-hero-title"
						label={__('Hero Title', 'thinkrank')}
						description={__(
							'Main headline for your homepage hero section',
							'thinkrank'
						)}
						disabled={disabled}
					>
						<Input
							id="tr-hero-title"
							value={settings.hero_title || ''}
							onChange={(e) =>
								handleSettingChange(
									'hero_title',
									e.target.value
								)
							}
							placeholder={__(
								'e.g., Grow your traffic with smarter SEO',
								'thinkrank'
							)}
							disabled={disabled}
						/>
					</FormField>

					<FormField
						id="tr-hero-subtitle"
						label={__('Hero Subtitle', 'thinkrank')}
						description={__(
							'Supporting text for your hero section',
							'thinkrank'
						)}
						disabled={disabled}
						className="tr-pt-6"
					>
						<Textarea
							id="tr-hero-subtitle"
							value={settings.hero_subtitle || ''}
							onChange={(e) =>
								handleSettingChange(
									'hero_subtitle',
									e.target.value
								)
							}
							placeholder={__(
								'e.g., Practical tools and insights to help your content rank.',
								'thinkrank'
							)}
							rows={2}
							disabled={disabled}
						/>
					</FormField>

					<FormField
						id="tr-hero-cta-text"
						label={__('Call-to-Action Text', 'thinkrank')}
						description={__(
							'Text for your primary CTA button',
							'thinkrank'
						)}
						disabled={disabled}
						className="tr-pt-6"
					>
						<Input
							id="tr-hero-cta-text"
							value={settings.hero_cta_text || ''}
							onChange={(e) =>
								handleSettingChange(
									'hero_cta_text',
									e.target.value
								)
							}
							placeholder={__(
								'e.g., Get started free',
								'thinkrank'
							)}
							disabled={disabled}
						/>
					</FormField>

					<FormField
						id="tr-hero-cta-url"
						label={__('Call-to-Action URL', 'thinkrank')}
						description={__(
							'Enter a valid URL for your CTA button (e.g., /contact, /get-started, https://example.com)',
							'thinkrank'
						)}
						disabled={disabled}
						className="tr-pt-6"
					>
						<Input
							id="tr-hero-cta-url"
							value={settings.hero_cta_url || ''}
							onChange={(e) =>
								handleSettingChange(
									'hero_cta_url',
									e.target.value
								)
							}
							placeholder={__(
								'e.g., /contact or /get-started',
								'thinkrank'
							)}
							disabled={disabled}
						/>
					</FormField>

					<div className="tr-pt-6">
						<MediaPicker
							label={__('Hero Background Image', 'thinkrank')}
							value={settings.hero_background_image}
							onChange={(value) =>
								handleSettingChange(
									'hero_background_image',
									value
								)
							}
							help={__(
								'Recommended: 1920x1080px or larger',
								'thinkrank'
							)}
							allowedTypes={['image']}
							disabled={disabled}
						/>
					</div>
				</Container>
			</Card>

			<Card padding={false}>
				<SectionHeader
					title={__('Site Assets', 'thinkrank')}
					description={__(
						"Upload and manage your site's visual assets for branding and SEO optimization.",
						'thinkrank'
					)}
				/>
				{/* Two columns: the compact pickers are short enough to pair
				    up, which keeps the whole card on one screen. */}
				<div className="tr-px-6 tr-py-6 tr-grid tr-grid-cols-1 md:tr-grid-cols-2 tr-gap-x-6 tr-gap-y-5">
					<MediaPicker
						label={__('Site Logo', 'thinkrank')}
						value={settings.logo_url}
						onChange={(value) =>
							handleSettingChange('logo_url', value)
						}
						help={__('Recommended: 600x60px', 'thinkrank')}
						allowedTypes={['image']}
						disabled={disabled}
					/>

					<MediaPicker
						label={__('Favicon', 'thinkrank')}
						value={settings.favicon_url}
						onChange={(value) =>
							handleSettingChange('favicon_url', value)
						}
						help={__(
							'Recommended: 32x32px, ICO or PNG',
							'thinkrank'
						)}
						allowedTypes={['image']}
						disabled={disabled}
					/>

					<MediaPicker
						label={__('Apple Touch Icon', 'thinkrank')}
						value={settings.apple_touch_icon_url}
						onChange={(value) =>
							handleSettingChange('apple_touch_icon_url', value)
						}
						help={__('Recommended: 180x180px, PNG', 'thinkrank')}
						allowedTypes={['image']}
						disabled={disabled}
					/>
				</div>
			</Card>
		</Container>
	);
};

export default HeroSection;
