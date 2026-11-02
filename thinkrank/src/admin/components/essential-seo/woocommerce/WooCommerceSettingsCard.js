/**
 * WooCommerce Settings Card — the free WooCommerce switches (#1478).
 *
 * Stores moving from Rank Math found three of its free WooCommerce settings
 * had nowhere to go in ThinkRank: which taxonomy names a product's brand,
 * whether shop archives carry a product list in their schema, and whether
 * WooCommerce's generator tag is printed. They live here, above the Pro panel
 * the WooCommerce tab has always shown.
 *
 * Renders nothing unless the server says WooCommerce is active: the tab is in
 * the menu on every site, and these switches mean nothing without a shop.
 * Every control autosaves (hooks/useAutosaveSettings.js).
 *
 * @package
 * @since 2.21.0
 */

import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { store } from '@wordpress/icons';

import { Card, Select, SettingsLoadError, SkeletonCard } from '../../ui';
import { ProductSeoCardHeader, ToggleRow } from './ProductSeoCard';
import useAutosaveSettings from '../../../hooks/useAutosaveSettings';
import logger from '../../../../shared/utils/logger';

export const API_PATH = '/thinkrank/v1/woocommerce/settings';

const BRAND_HELP_ID = 'tr-woo-brand-taxonomy-help';

const DEFAULT_SETTINGS = {
	brand_taxonomy: '',
	shop_archive_schema: false,
	remove_generator: false,
};

/**
 * Options for the brand select.
 *
 * A stored taxonomy the site no longer registers (its brand plugin was
 * deactivated) is still offered, marked, so the select shows what is saved
 * instead of silently reading as Automatic.
 *
 * @param {Array}  taxonomies Taxonomies registered on products, {value, label}.
 * @param {string} current    The stored brand taxonomy.
 * @return {Array} Select options.
 */
export const brandOptions = (taxonomies, current) => {
	const options = [
		{
			value: '',
			label: __('Automatic', 'thinkrank'),
		},
		...(Array.isArray(taxonomies) ? taxonomies : []).map((taxonomy) => ({
			value: taxonomy.value,
			label: sprintf(
				/* translators: 1: taxonomy label, 2: taxonomy name. */
				__('%1$s (%2$s)', 'thinkrank'),
				taxonomy.label,
				taxonomy.value
			),
		})),
	];

	if (current && !options.some((option) => option.value === current)) {
		options.push({
			value: current,
			label: sprintf(
				/* translators: %s: taxonomy name. */
				__('%s (no longer registered, using Automatic)', 'thinkrank'),
				current
			),
		});
	}

	return options;
};

const WooCommerceSettingsCard = () => {
	const [settings, setSettings] = useState(DEFAULT_SETTINGS);
	const [taxonomies, setTaxonomies] = useState([]);
	const [isActive, setIsActive] = useState(false);
	const [isLoading, setIsLoading] = useState(true);
	const [loadFailed, setLoadFailed] = useState(false);

	// Only the newest load may set state: a retry clicked while the first
	// request is still out must not be overwritten by it.
	const loadVersion = useRef(0);

	const load = useCallback(async () => {
		const version = ++loadVersion.current;

		setIsLoading(true);

		try {
			const data = await apiFetch({ path: API_PATH });

			if (version !== loadVersion.current) {
				return;
			}

			setSettings({ ...DEFAULT_SETTINGS, ...(data?.settings || {}) });
			setTaxonomies(data?.brand_taxonomies || []);
			setIsActive(!!data?.woocommerce_active);
			setLoadFailed(false);
		} catch (error) {
			if (version !== loadVersion.current) {
				return;
			}

			// No defaults on failure: every control here saves on change, so a
			// form standing on defaults would write them on the first click.
			logger.error('Error loading WooCommerce settings:', error);
			setLoadFailed(true);
		} finally {
			if (version === loadVersion.current) {
				setIsLoading(false);
			}
		}
	}, []);

	useEffect(() => {
		load();
	}, [load]);

	const { update, isSaving } = useAutosaveSettings({
		settings,
		setSettings,
		save: async (next) => {
			const response = await apiFetch({
				path: API_PATH,
				method: 'POST',
				data: next,
			});

			return response?.settings;
		},
		logLabel: 'WooCommerce',
	});

	if (isLoading) {
		return <SkeletonCard lines={3} />;
	}

	if (loadFailed) {
		return (
			<SettingsLoadError
				message={__(
					'Could not load your WooCommerce settings. Nothing has been changed, retry before editing.',
					'thinkrank'
				)}
				onRetry={load}
			/>
		);
	}

	if (!isActive) {
		return null;
	}

	return (
		<Card padding={false}>
			<ProductSeoCardHeader
				icon={store}
				title={__('Store settings', 'thinkrank')}
				description={__(
					'How your products are described to search engines and social networks.',
					'thinkrank'
				)}
				isSaving={isSaving}
			/>
			<div className="tr-px-6 tr-py-5">
				<div className="tr-pb-5">
					<Select
						className="tr-max-w-lg"
						aria-describedby={BRAND_HELP_ID}
						label={__('Brand taxonomy', 'thinkrank')}
						value={settings.brand_taxonomy || ''}
						options={brandOptions(
							taxonomies,
							settings.brand_taxonomy
						)}
						disabled={isSaving}
						onChange={(event) =>
							update(
								{ brand_taxonomy: event.target.value },
								event.target.value
									? __('Brand taxonomy saved.', 'thinkrank')
									: __(
											'Brands are now found automatically.',
											'thinkrank'
										)
							)
						}
					/>
					{/* Outside the select's width, so it runs the card's full
					    width; Select only wires up a description it renders. */}
					<p
						id={BRAND_HELP_ID}
						className="tr-m-0 tr-mt-1.5 tr-text-xs tr-text-tertiary"
					>
						{__(
							'Where your product brands are kept. Used for the brand in Product schema, the product:brand Open Graph tag and the brand variable in titles. Automatic tries WooCommerce Brands, then Perfect Brands, YITH and BeRocket brands.',
							'thinkrank'
						)}
					</p>
				</div>

				<div className="tr-divide-y tr-divide-line-2 tr-border-t tr-border-line-2 tr-pt-1">
					<ToggleRow
						id="tr-woo-shop-archive-schema"
						checked={!!settings.shop_archive_schema}
						disabled={isSaving}
						label={__(
							'Product list schema on shop archives',
							'thinkrank'
						)}
						description={__(
							'The shop page and product category and tag pages list their products (name, link, image and price) in their structured data.',
							'thinkrank'
						)}
						onChange={(value) =>
							update(
								{ shop_archive_schema: value },
								value
									? __(
											'Shop archives now list their products in schema.',
											'thinkrank'
										)
									: __(
											'Shop archives no longer list their products in schema.',
											'thinkrank'
										)
							)
						}
					/>

					<ToggleRow
						id="tr-woo-remove-generator"
						checked={!!settings.remove_generator}
						disabled={isSaving}
						label={__(
							'Remove WooCommerce generator tag',
							'thinkrank'
						)}
						description={__(
							'Stops WooCommerce printing its name and version in a generator meta tag in your page source.',
							'thinkrank'
						)}
						onChange={(value) =>
							update(
								{ remove_generator: value },
								value
									? __(
											'The WooCommerce generator tag is removed.',
											'thinkrank'
										)
									: __(
											'The WooCommerce generator tag is printed again.',
											'thinkrank'
										)
							)
						}
					/>
				</div>
			</div>
		</Card>
	);
};

export default WooCommerceSettingsCard;
