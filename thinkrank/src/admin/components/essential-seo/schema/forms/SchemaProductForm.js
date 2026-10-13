/**
 * Schema Product Form Component
 *
 * Redesigned — uses plugin UI components and Tailwind classes.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Card, Input, Textarea, Select } from '../../../ui';
import MediaPicker from '../../../common/MediaPicker';

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

const IconBarcode = () => (
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
		<path d="M3 5v14" />
		<path d="M8 5v14" />
		<path d="M12 5v14" />
		<path d="M17 5v14" />
		<path d="M21 5v14" />
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

const CURRENCY_OPTIONS = [
	{ value: 'USD', label: 'USD ($)' },
	{ value: 'EUR', label: 'EUR (€)' },
	{ value: 'GBP', label: 'GBP (£)' },
	{ value: 'CAD', label: 'CAD (C$)' },
	{ value: 'AUD', label: 'AUD (A$)' },
	{ value: 'JPY', label: 'JPY (¥)' },
	{ value: 'CHF', label: 'CHF' },
	{ value: 'SEK', label: 'SEK' },
	{ value: 'NOK', label: 'NOK' },
	{ value: 'DKK', label: 'DKK' },
];

const AVAILABILITY_OPTIONS = [
	{ value: 'InStock', label: __('In Stock', 'thinkrank') },
	{ value: 'OutOfStock', label: __('Out of Stock', 'thinkrank') },
	{ value: 'PreOrder', label: __('Pre-Order', 'thinkrank') },
	{ value: 'BackOrder', label: __('Back Order', 'thinkrank') },
	{ value: 'Discontinued', label: __('Discontinued', 'thinkrank') },
];

const SchemaProductForm = ({ settings, onSettingChange, isLoading }) => {
	const disabled = !settings.enabled;

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{/* ── Product Details ───────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Product Details', 'thinkrank')}
					</h3>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Core product information for schema markup',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Input
						label={__('Product Name', 'thinkrank')}
						value={settings.product_name || ''}
						onChange={(e) =>
							onSettingChange('product_name', e.target.value)
						}
						description={__(
							'Full name of the product',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<MediaPicker
						label={__('Product Image', 'thinkrank')}
						value={settings.product_image}
						onChange={(value) =>
							onSettingChange('product_image', value)
						}
						help={__(
							'Main product image (recommended: 1200×1200 px)',
							'thinkrank'
						)}
						allowedTypes={['image']}
						disabled={disabled}
					/>
					<Textarea
						label={__('Description', 'thinkrank')}
						value={settings.product_description || ''}
						onChange={(e) =>
							onSettingChange(
								'product_description',
								e.target.value
							)
						}
						description={__(
							'Detailed product description for search engines',
							'thinkrank'
						)}
						rows={3}
						disabled={disabled}
					/>
					<Input
						label={__('Product URL', 'thinkrank')}
						type="url"
						value={settings.product_url || ''}
						onChange={(e) =>
							onSettingChange('product_url', e.target.value)
						}
						description={__(
							'Direct link to the product page',
							'thinkrank'
						)}
						placeholder="https://example.com/products/product-name"
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── Pricing & Availability ────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconTag />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Pricing & Availability', 'thinkrank')}
						</h3>
					</div>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Price', 'thinkrank')}
							type="number"
							step="0.01"
							min="0"
							value={settings.product_price || ''}
							onChange={(e) =>
								onSettingChange('product_price', e.target.value)
							}
							description={__('e.g., 29.99', 'thinkrank')}
							disabled={disabled}
						/>
						<Select
							label={__('Currency', 'thinkrank')}
							value={settings.product_currency || 'USD'}
							options={CURRENCY_OPTIONS}
							onChange={(e) =>
								onSettingChange(
									'product_currency',
									e.target.value
								)
							}
							disabled={disabled}
						/>
					</div>
					<Select
						label={__('Availability', 'thinkrank')}
						value={settings.product_availability || 'InStock'}
						options={AVAILABILITY_OPTIONS}
						onChange={(e) =>
							onSettingChange(
								'product_availability',
								e.target.value
							)
						}
						description={__(
							'Current product availability status',
							'thinkrank'
						)}
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── Product Identity ──────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconBarcode />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Product Identity', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Brand identifiers and search keywords',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Brand', 'thinkrank')}
							value={settings.product_brand || ''}
							onChange={(e) =>
								onSettingChange('product_brand', e.target.value)
							}
							description={__(
								'Product brand or manufacturer',
								'thinkrank'
							)}
							disabled={disabled}
						/>
						<Input
							label={__('SKU / Model', 'thinkrank')}
							value={settings.product_sku || ''}
							onChange={(e) =>
								onSettingChange('product_sku', e.target.value)
							}
							description={__(
								'Product SKU or model number',
								'thinkrank'
							)}
							disabled={disabled}
						/>
					</div>
					<Input
						label={__('GTIN / Barcode', 'thinkrank')}
						value={settings.product_gtin || ''}
						onChange={(e) =>
							onSettingChange('product_gtin', e.target.value)
						}
						description={__(
							'Global Trade Item Number: UPC, EAN, ISBN, etc.',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<Input
						label={__('Keywords', 'thinkrank')}
						value={settings.product_keywords || ''}
						onChange={(e) =>
							onSettingChange('product_keywords', e.target.value)
						}
						description={__(
							'Comma-separated keywords relevant to the product',
							'thinkrank'
						)}
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── Ratings & Reviews ─────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconStar />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Ratings & Reviews', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Optional. Enhances rich snippets in search results',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Rating Value', 'thinkrank')}
							type="number"
							step="0.1"
							min="1"
							max="5"
							value={settings.product_rating_value || ''}
							onChange={(e) =>
								onSettingChange(
									'product_rating_value',
									e.target.value
								)
							}
							description={__(
								'Average rating on a 1–5 scale',
								'thinkrank'
							)}
							disabled={disabled}
						/>
						<Input
							label={__('Rating Count', 'thinkrank')}
							type="number"
							min="1"
							value={settings.product_rating_count || ''}
							onChange={(e) =>
								onSettingChange(
									'product_rating_count',
									e.target.value
								)
							}
							description={__(
								'Total number of ratings',
								'thinkrank'
							)}
							disabled={disabled}
						/>
					</div>
					<Textarea
						label={__('Customer Review', 'thinkrank')}
						value={settings.product_review || ''}
						onChange={(e) =>
							onSettingChange('product_review', e.target.value)
						}
						description={__(
							'Sample customer review text to enhance rich snippets',
							'thinkrank'
						)}
						rows={2}
						disabled={disabled}
					/>
				</div>
			</Card>
		</div>
	);
};

export default SchemaProductForm;
