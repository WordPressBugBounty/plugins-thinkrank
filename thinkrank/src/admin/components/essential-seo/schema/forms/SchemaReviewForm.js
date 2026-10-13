/**
 * Schema Review Form Component
 *
 * Standalone Review schema — the reviewed item, a rating, the reviewer, and an
 * optional review body. Mirrors the design language of the other schema forms.
 *
 * @package
 * @since 1.13.0
 */

import { __ } from '@wordpress/i18n';
import { Card, Input, Textarea, Select } from '../../../ui';

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

const ITEM_TYPE_OPTIONS = [
	{ value: 'Thing', label: __('Thing (generic)', 'thinkrank') },
	{ value: 'Product', label: __('Product', 'thinkrank') },
	{ value: 'Book', label: __('Book', 'thinkrank') },
	{ value: 'Movie', label: __('Movie', 'thinkrank') },
	{ value: 'Course', label: __('Course', 'thinkrank') },
	{
		value: 'SoftwareApplication',
		label: __('Software Application', 'thinkrank'),
	},
	{ value: 'LocalBusiness', label: __('Local Business', 'thinkrank') },
	{ value: 'Organization', label: __('Organization', 'thinkrank') },
];

const SchemaReviewForm = ({ settings, onSettingChange, isLoading }) => {
	const disabled = !settings.enabled;

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{/* ── Reviewed Item ─────────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Reviewed Item', 'thinkrank')}
					</h3>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__('The thing this review is about', 'thinkrank')}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Input
						label={__('Item Name', 'thinkrank')}
						value={settings.review_item_name || ''}
						onChange={(e) =>
							onSettingChange('review_item_name', e.target.value)
						}
						description={__(
							'Name of the reviewed item (defaults to the post title)',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<Select
						label={__('Item Type', 'thinkrank')}
						value={settings.review_item_type || 'Thing'}
						options={ITEM_TYPE_OPTIONS}
						onChange={(e) =>
							onSettingChange('review_item_type', e.target.value)
						}
						description={__(
							'Schema.org type of the reviewed item',
							'thinkrank'
						)}
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── Rating ────────────────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconStar />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Rating', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__('The score given in this review', 'thinkrank')}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<div className="tr-grid tr-grid-cols-3 tr-gap-4">
						<Input
							label={__('Rating Value', 'thinkrank')}
							type="number"
							step="0.1"
							min="0"
							value={settings.review_rating_value || ''}
							onChange={(e) =>
								onSettingChange(
									'review_rating_value',
									e.target.value
								)
							}
							description={__('e.g., 4.5', 'thinkrank')}
							disabled={disabled}
						/>
						<Input
							label={__('Best Rating', 'thinkrank')}
							type="number"
							min="1"
							value={settings.review_best_rating || ''}
							onChange={(e) =>
								onSettingChange(
									'review_best_rating',
									e.target.value
								)
							}
							description={__('Default 5', 'thinkrank')}
							disabled={disabled}
						/>
						<Input
							label={__('Worst Rating', 'thinkrank')}
							type="number"
							min="0"
							value={settings.review_worst_rating || ''}
							onChange={(e) =>
								onSettingChange(
									'review_worst_rating',
									e.target.value
								)
							}
							description={__('Default 1', 'thinkrank')}
							disabled={disabled}
						/>
					</div>
				</div>
			</Card>

			{/* ── Reviewer ──────────────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Reviewer', 'thinkrank')}
					</h3>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__('Who wrote the review', 'thinkrank')}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Input
						label={__('Author', 'thinkrank')}
						value={settings.review_author || ''}
						onChange={(e) =>
							onSettingChange('review_author', e.target.value)
						}
						description={__(
							'Name of the reviewer (defaults to the post author)',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<Textarea
						label={__('Review Body', 'thinkrank')}
						value={settings.review_body || ''}
						onChange={(e) =>
							onSettingChange('review_body', e.target.value)
						}
						description={__('The review text', 'thinkrank')}
						rows={3}
						disabled={disabled}
					/>
				</div>
			</Card>
		</div>
	);
};

export default SchemaReviewForm;
