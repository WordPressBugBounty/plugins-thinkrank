/**
 * Title Formats Component
 *
 * Extracted from SiteIdentityTab.js — handles title format templates and settings.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Card, Input, Select, Container, CharacterCounter } from '../../ui';
import SectionHeader from '../../common/SectionHeader';
import FormField from '../../common/FormField';
import TitlePreview from '../../common/TitlePreview';

const TITLE_LENGTH = 60;

const TITLE_SEPARATOR_OPTIONS = [
	{ label: '| (Pipe)', value: 'pipe' },
	{ label: '- (Dash)', value: 'dash' },
	{ label: '• (Bullet)', value: 'bullet' },
	{ label: ': (Colon)', value: 'colon' },
	{ label: '> (Greater Than)', value: 'greater' },
	{ label: '~ (Tilde)', value: 'tilde' },
];

const TITLE_FIELDS = [
	{
		key: 'homepage_title',
		id: 'tr-tf-homepage',
		label: __('Homepage Title', 'thinkrank'),
		description: __('Title format for the homepage.', 'thinkrank'),
		placeholder: '%site_title% %sep% %tagline%',
	},
	{
		key: 'post_title',
		id: 'tr-tf-post',
		label: __('Post Title', 'thinkrank'),
		description: __('Title format for blog posts.', 'thinkrank'),
		placeholder: '%post_title% %sep% %site_title%',
	},
	{
		key: 'page_title',
		id: 'tr-tf-page',
		label: __('Page Title', 'thinkrank'),
		description: __('Title format for pages.', 'thinkrank'),
		placeholder: '%page_title% %sep% %site_title%',
	},
	{
		key: 'category_title',
		id: 'tr-tf-category',
		label: __('Category Title', 'thinkrank'),
		description: __('Title format for category pages.', 'thinkrank'),
		placeholder: '%category_title% %sep% %site_title%',
	},
	{
		key: 'tag_title',
		id: 'tr-tf-tag',
		label: __('Tag Title', 'thinkrank'),
		description: __('Title format for tag archive pages.', 'thinkrank'),
		placeholder: '%tag_title% %sep% %site_title%',
	},
	{
		key: 'search_title',
		id: 'tr-tf-search',
		label: __('Search Title', 'thinkrank'),
		description: __('Title format for search results.', 'thinkrank'),
		placeholder: '%search_term% %sep% %site_title%',
	},
	{
		key: 'archive_title',
		id: 'tr-tf-archive',
		label: __('Archive Title', 'thinkrank'),
		description: __(
			'Title format for date and other archive pages.',
			'thinkrank'
		),
		placeholder: '%archive_title% %sep% %site_title%',
	},
];

// Clickable variable chips shown under each title field. The context-specific
// token comes first, then the shared Site Title / Separator. Values are the
// exact tokens the front-end renderer resolves (see get_title_placeholders()).
const COMMON_VARS = [
	{ label: __('+ Site Title', 'thinkrank'), value: '%site_title%' },
	{ label: __('+ Separator', 'thinkrank'), value: '%sep%' },
];

const FIELD_VARIABLES = {
	homepage_title: [
		{ label: __('+ Site Title', 'thinkrank'), value: '%site_title%' },
		{ label: __('+ Tagline', 'thinkrank'), value: '%tagline%' },
		{
			label: __('+ Site Description', 'thinkrank'),
			value: '%site_description%',
		},
		{ label: __('+ Separator', 'thinkrank'), value: '%sep%' },
	],
	post_title: [
		{ label: __('+ Post Title', 'thinkrank'), value: '%post_title%' },
		...COMMON_VARS,
	],
	page_title: [
		{ label: __('+ Page Title', 'thinkrank'), value: '%page_title%' },
		...COMMON_VARS,
	],
	category_title: [
		{ label: __('+ Category', 'thinkrank'), value: '%category_title%' },
		...COMMON_VARS,
	],
	tag_title: [
		{ label: __('+ Tag', 'thinkrank'), value: '%tag_title%' },
		...COMMON_VARS,
	],
	search_title: [
		{ label: __('+ Search Term', 'thinkrank'), value: '%search_term%' },
		...COMMON_VARS,
	],
	archive_title: [
		{ label: __('+ Archive Title', 'thinkrank'), value: '%archive_title%' },
		...COMMON_VARS,
	],
};

const TitleFormats = ({ settings, handleSettingChange }) => {
	const disabled = !settings.enabled;

	// Append a variable token to a field, inserting a space when needed.
	const insertVariable = (key, value) => {
		let current = settings[key] || '';
		if (current.length > 0 && !current.endsWith(' ')) {
			current += ' ';
		}
		handleSettingChange(key, current + value);
	};

	const renderVariableHints = (fieldKey) => {
		const vars = FIELD_VARIABLES[fieldKey];
		if (!vars) {
			return null;
		}
		return (
			<div className="tr-mt-1 tr-text-xs tr-text-tertiary">
				{__('Use variables:', 'thinkrank')}{' '}
				{vars.map((variable) => (
					<button
						type="button"
						key={variable.value}
						onClick={() => insertVariable(fieldKey, variable.value)}
						className="thinkrank-variable-tag"
						title={__('Click to insert', 'thinkrank')}
						disabled={disabled}
					>
						{variable.label}
					</button>
				))}
			</div>
		);
	};

	return (
		<Container direction="column" gap={4}>
			<Card padding={false}>
				{/* translators: %site_title%, %post_title%, %page_title% are literal template variable tokens (not printf placeholders). */}
				<SectionHeader
					title={__('Title Format Templates', 'thinkrank')}
					description={
						/* translators: %site_title%, %post_title%, %page_title% are literal template variable tokens (not printf placeholders). */
						__(
							'Configure how page titles are formatted. Use variables like %site_title%, %post_title%, %page_title%, etc.',
							'thinkrank'
						)
					}
				/>
				<Container
					direction="column"
					gap={6}
					className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
				>
					<FormField
						id="tr-tf-separator"
						label={__('Title Separator', 'thinkrank')}
						description={__(
							'Character used to separate title elements.',
							'thinkrank'
						)}
						disabled={disabled}
					>
						<Select
							id="tr-tf-separator"
							value={settings.title_separator}
							options={TITLE_SEPARATOR_OPTIONS}
							onChange={(e) =>
								handleSettingChange(
									'title_separator',
									e.target.value
								)
							}
							disabled={disabled}
						/>
					</FormField>

					{TITLE_FIELDS.map((field, idx) => (
						<FormField
							key={field.key}
							id={field.id}
							label={field.label}
							description={field.description}
							counter={
								<CharacterCounter
									value={settings[field.key] || ''}
									max={TITLE_LENGTH}
								/>
							}
							disabled={disabled}
							className={idx === 0 ? 'tr-pt-6' : 'tr-pt-6'}
						>
							<Input
								id={field.id}
								value={settings[field.key] || ''}
								onChange={(e) =>
									handleSettingChange(
										field.key,
										e.target.value
									)
								}
								placeholder={field.placeholder}
								disabled={disabled}
							/>
							{renderVariableHints(field.key)}
						</FormField>
					))}
				</Container>
			</Card>

			<TitlePreview
				titleFormats={{
					homepage_title: settings.homepage_title,
					post_title: settings.post_title,
					page_title: settings.page_title,
					category_title: settings.category_title,
					tag_title: settings.tag_title,
					search_title: settings.search_title,
					archive_title: settings.archive_title,
				}}
				separator={settings.title_separator}
				siteInfo={{
					site_name: settings.site_name,
					site_description: settings.site_description,
					tagline: settings.tagline,
				}}
				disabled={disabled}
			/>
		</Container>
	);
};

export default TitleFormats;
