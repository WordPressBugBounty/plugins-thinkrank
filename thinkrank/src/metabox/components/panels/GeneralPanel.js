/**
 * General panel — the core editing surface.
 *
 * SEO Title, Meta Description, Focus Keywords, and the live Google (SERP)
 * preview. Values flow up through `onFieldChange` into the shared formData; the
 * actual form submission happens via hidden inputs rendered by MetaboxApp.
 */

import { __ } from '@wordpress/i18n';
import Input, { Textarea } from '../../../admin/components/ui/Input';
import CharacterCounter from '../../../admin/components/ui/CharacterCounter';
import PanelCard from '../ui/PanelCard';
import Field from '../ui/Field';
import { IconDocument } from '../ui/icons';
import FocusKeywordsInput from '../FocusKeywordsInput';
import KeywordUsageNotice from '../KeywordUsageNotice';
import SerpPreview from '../SerpPreview';
import VariableTags from '../ui/VariableTags';
import { resolvePattern } from '../../utils/resolvePattern';
import CustomFieldStatus from '../ui/CustomFieldStatus';

// Variable tags offered per field — mirrors the Bulk SEO Optimization UI.
const TITLE_VARIABLES = [
	{ label: __('+ Title', 'thinkrank'), value: '%title%' },
	{ label: __('+ Site Title', 'thinkrank'), value: '%sitename%' },
	{ label: __('+ Separator', 'thinkrank'), value: '%sep%' },
];

const DESCRIPTION_VARIABLES = [
	{ label: __('+ Excerpt', 'thinkrank'), value: '%excerpt%' },
	{ label: __('+ Site Title', 'thinkrank'), value: '%sitename%' },
	{ label: __('+ Title', 'thinkrank'), value: '%title%' },
	{ label: __('+ Separator', 'thinkrank'), value: '%sep%' },
];

/**
 * Tokens that only resolve on a WooCommerce product, offered only there.
 *
 * They resolve to an empty string on any other post type, so showing them
 * everywhere would offer a chip that silently inserts nothing (#715).
 */
const PRODUCT_VARIABLES = [
	{ label: __('+ Price', 'thinkrank'), value: '%price%' },
	{ label: __('+ Sale price', 'thinkrank'), value: '%sale_price%' },
	{ label: __('+ SKU', 'thinkrank'), value: '%sku%' },
	{ label: __('+ Stock status', 'thinkrank'), value: '%stock_status%' },
	{
		label: __('+ Short description', 'thinkrank'),
		value: '%short_description%',
	},
	{ label: __('+ Brand', 'thinkrank'), value: '%brand%' },
];

/**
 * A custom field (post meta) value, on any post type. `field-name` is
 * replaced with the key (#1277).
 */
const CUSTOM_FIELD_VARIABLE = {
	label: __('+ Custom field', 'thinkrank'),
	value: '%customfield(field-name)%',
};

/**
 * The variable chips for a field, with the product tokens appended on a
 * product and the custom field token last.
 *
 * @param {Array} base The tokens every post type offers.
 * @return {Array} The chips to render.
 */
const variablesFor = (base) => [
	...base,
	...(window.thinkrankMetabox?.postType === 'product'
		? PRODUCT_VARIABLES
		: []),
	CUSTOM_FIELD_VARIABLE,
];

const GeneralPanel = ({
	formData,
	onFieldChange,
	onFocusKeywordsChange,
	onFocusKeywordDraftChange,
	focusKeywordsMax,
	postTitle,
	homeUrl,
	patternPreviews = {},
	patternVariables = {},
	customFieldStatus = 'idle',
	customFieldError = '',
	onRetryCustomFields,
}) => {
	// Append a %tag% to a field, keeping a space between tokens.
	const insertVariable = (field, token) => {
		const current = formData[field] || '';
		const next =
			current && !current.endsWith(' ')
				? `${current} ${token}`
				: `${current}${token}`;
		onFieldChange(field, next);
	};

	// Effective (resolved) values for the SERP preview: resolve any tags the
	// user typed, else fall back to the inherited pattern preview.
	const resolvedTitle = formData.thinkrank_seo_title
		? resolvePattern(formData.thinkrank_seo_title, patternVariables)
		: patternPreviews.seo_title || '';
	const resolvedDescription = formData.thinkrank_meta_description
		? resolvePattern(formData.thinkrank_meta_description, patternVariables)
		: patternPreviews.meta_description || '';

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			<PanelCard
				icon={<IconDocument />}
				title={__('General', 'thinkrank')}
				subtitle={__(
					'How this content appears in search results.',
					'thinkrank'
				)}
				bodyClassName="tr-flex tr-flex-col tr-gap-5"
			>
				<CustomFieldStatus
					status={customFieldStatus}
					error={customFieldError}
					onRetry={onRetryCustomFields}
				/>
				<Field
					label={__('SEO Title', 'thinkrank')}
					htmlFor="thinkrank_seo_title"
					counter={
						<CharacterCounter value={resolvedTitle} max={60} />
					}
					help={__(
						'Keep it under 60 characters for best results.',
						'thinkrank'
					)}
				>
					<Input
						id="thinkrank_seo_title"
						value={formData.thinkrank_seo_title}
						onChange={(e) =>
							onFieldChange('thinkrank_seo_title', e.target.value)
						}
						maxLength="80"
						placeholder={
							patternPreviews.seo_title ||
							__(
								'Enter SEO title or generate with AI',
								'thinkrank'
							)
						}
					/>
					<VariableTags
						variables={variablesFor(TITLE_VARIABLES)}
						onInsert={(token) =>
							insertVariable('thinkrank_seo_title', token)
						}
						value={formData.thinkrank_seo_title}
						vars={patternVariables}
						fallback={patternPreviews.seo_title}
					/>
				</Field>

				<Field
					label={__('Meta Description', 'thinkrank')}
					htmlFor="thinkrank_meta_description"
					counter={
						<CharacterCounter
							value={resolvedDescription}
							max={160}
						/>
					}
				>
					<Textarea
						id="thinkrank_meta_description"
						rows="3"
						maxLength="160"
						value={formData.thinkrank_meta_description}
						onChange={(e) =>
							onFieldChange(
								'thinkrank_meta_description',
								e.target.value
							)
						}
						placeholder={
							patternPreviews.meta_description ||
							__(
								'Enter meta description or generate with AI',
								'thinkrank'
							)
						}
					/>
					<VariableTags
						variables={variablesFor(DESCRIPTION_VARIABLES)}
						onInsert={(token) =>
							insertVariable('thinkrank_meta_description', token)
						}
						value={formData.thinkrank_meta_description}
						vars={patternVariables}
						fallback={patternPreviews.meta_description}
					/>
				</Field>

				<Field
					label={__('Permalink', 'thinkrank')}
					htmlFor="thinkrank_post_slug"
					counter={
						<CharacterCounter
							value={formData.thinkrank_post_slug || ''}
							max={75}
						/>
					}
					help={__(
						'The URL slug for this content. Leave blank to keep the auto-generated slug.',
						'thinkrank'
					)}
				>
					<Input
						id="thinkrank_post_slug"
						value={formData.thinkrank_post_slug || ''}
						onChange={(e) =>
							onFieldChange('thinkrank_post_slug', e.target.value)
						}
						placeholder={
							postTitle
								? postTitle
										.toLowerCase()
										.replace(/[^a-z0-9]+/g, '-')
										.replace(/^-|-$/g, '')
								: __('your-post-slug', 'thinkrank')
						}
					/>
				</Field>

				<FocusKeywordsInput
					keywords={formData.thinkrank_focus_keywords}
					onChange={onFocusKeywordsChange}
					onDraftChange={onFocusKeywordDraftChange}
					max={focusKeywordsMax}
					primaryKeyword={formData.thinkrank_focus_keyword}
				/>
				<KeywordUsageNotice
					keywords={formData.thinkrank_focus_keywords}
				/>
			</PanelCard>

			<SerpPreview
				title={resolvedTitle}
				description={resolvedDescription}
				postTitle={postTitle}
				homeUrl={homeUrl}
				slug={formData.thinkrank_post_slug}
			/>
		</div>
	);
};

export default GeneralPanel;
