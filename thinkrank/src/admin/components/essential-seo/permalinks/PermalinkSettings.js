/**
 * Permalink settings: removing the product, product category and category
 * bases from URLs (#1476).
 *
 * One component for both screens that show these switches: the WooCommerce
 * tab shows the three product ones, Crawling & AI Indexing → Permalinks the
 * category one. Both read and write `/thinkrank/v1/permalinks/settings`, so a
 * switch flipped on one screen is current on the other. Each posts only the
 * switch that changed: the route authorizes every switch against its section,
 * and a stored switch tells a migration the admin decided it (#1533).
 *
 * Every switch changes public URLs, so each row shows the URL before and
 * after with the site's real bases, and the card says plainly that the old
 * URLs are redirected rather than lost.
 *
 * @package
 * @since 2.21.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { link } from '@wordpress/icons';

import { Alert, Card, SettingsLoadError, SkeletonCard } from '../../ui';
import { ProductSeoCardHeader, ToggleRow } from '../woocommerce/ProductSeoCard';
import notify from '../../../utils/notify';
import useAutosaveSettings from '../../../hooks/useAutosaveSettings';
import logger from '../../../../shared/utils/logger';

const API_PATH = '/thinkrank/v1/permalinks/settings';

export const DEFAULT_SETTINGS = {
	remove_category_base: false,
	remove_product_base: false,
	remove_product_category_base: false,
	remove_product_category_parents: false,
};

/**
 * The example URLs a switch turns one into the other, with the site's bases.
 *
 * @param {string} key     Setting key.
 * @param {Object} context Bases from the server.
 * @return {{before: string, after: string}} Paths.
 */
export const examplePaths = (key, context = {}) => {
	const productBase = context.product_base || 'product';
	const categoryBase = context.product_category_base || 'product-category';
	const postCategoryBase = context.category_base || 'category';

	switch (key) {
		case 'remove_product_base':
			return {
				before: `/${productBase}/blue-shirt/`,
				after: '/blue-shirt/',
			};
		case 'remove_product_category_base':
			return {
				before: `/${categoryBase}/clothing/shirts/`,
				after: '/clothing/shirts/',
			};
		case 'remove_product_category_parents':
			return {
				before: `/${categoryBase}/clothing/shirts/`,
				after: `/${categoryBase}/shirts/`,
			};
		case 'remove_category_base':
		default:
			return {
				before: `/${postCategoryBase}/news/`,
				after: `/${context.category_blog_prefix || ''}news/`,
			};
	}
};

const ROWS = {
	woocommerce: [
		{
			key: 'remove_product_base',
			label: __('Remove product base', 'thinkrank'),
			description: __(
				'Product pages are published at the site root. A base containing %product_cat% keeps the category path.',
				'thinkrank'
			),
		},
		{
			key: 'remove_product_category_base',
			label: __('Remove product category base', 'thinkrank'),
			description: __(
				'Product category pages are published without their prefix.',
				'thinkrank'
			),
		},
		{
			key: 'remove_product_category_parents',
			label: __('Remove parent categories', 'thinkrank'),
			description: __(
				'A subcategory is published under its own slug, without its parents.',
				'thinkrank'
			),
		},
	],
	categories: [
		{
			key: 'remove_category_base',
			label: __('Remove category base', 'thinkrank'),
			description: __(
				'Post category pages are published without their prefix.',
				'thinkrank'
			),
		},
	],
};

/**
 * A path pair, before and after.
 *
 * @param {Object} props        Props.
 * @param {string} props.before Path with the base.
 * @param {string} props.after  Path without it.
 * @return {JSX.Element} The example line.
 */
const ExamplePaths = ({ before, after }) => {
	const pathStyle = {
		background: 'var(--tr-bg-subtle, #f3f4f6)',
		borderRadius: '4px',
		padding: '2px 6px',
	};

	return (
		<p className="tr-m-0 tr-mt-2 tr-flex tr-flex-wrap tr-items-center tr-gap-2 tr-text-xs tr-text-tertiary">
			<code style={pathStyle}>{before}</code>
			<span aria-hidden="true">→</span>
			<code style={pathStyle}>{after}</code>
		</p>
	);
};

/**
 * The permalink switches for one screen.
 *
 * @param {Object}                     props       Props.
 * @param {'woocommerce'|'categories'} props.scope Which switches to show.
 * @return {JSX.Element|null} The card.
 */
const PermalinkSettings = ({ scope = 'categories' }) => {
	const rows = ROWS[scope] || ROWS.categories;
	const [settings, setSettings] = useState(DEFAULT_SETTINGS);
	const [context, setContext] = useState({});
	const [isLoading, setIsLoading] = useState(true);
	const [loadFailed, setLoadFailed] = useState(false);

	const load = async () => {
		try {
			setIsLoading(true);
			const data = await apiFetch({ path: API_PATH });
			const { context: serverContext, ...stored } = data || {};
			setSettings({ ...DEFAULT_SETTINGS, ...stored });
			setContext(serverContext || {});
			setLoadFailed(false);
		} catch (error) {
			logger.error('Error loading permalink settings:', error);
			setLoadFailed(true);
		} finally {
			setIsLoading(false);
		}
	};

	useEffect(() => {
		load();
	}, []);

	const { update, isSaving } = useAutosaveSettings({
		settings,
		setSettings,
		save: async (next, patch) => {
			// Only the switch flipped. The route refuses a request holding one
			// outside the user's section (#1506), and a stored switch counts as
			// the admin's decision when a migration runs later (#1533).
			const response = await apiFetch({
				path: API_PATH,
				method: 'POST',
				data: Object.fromEntries(
					rows
						.filter(({ key }) => key in patch)
						.map(({ key }) => [key, !!next[key]])
				),
			});

			// A page cache still serves the old pages under the old URLs
			// until it is cleared; say so when ThinkRank could not clear it.
			// That needs doing, so this toast stays until it is dismissed.
			(response?.cache_warnings || []).forEach((warning) =>
				notify.warning(warning, { autoClose: false })
			);
			if (response?.context) {
				setContext(response.context);
			}

			return response?.settings;
		},
		logLabel: 'Permalinks',
	});

	if (isLoading) {
		return <SkeletonCard lines={3} />;
	}

	if (loadFailed) {
		return (
			<SettingsLoadError
				message={__(
					'Could not load your permalink settings. Nothing has been changed, retry before editing.',
					'thinkrank'
				)}
				onRetry={load}
			/>
		);
	}

	if ('woocommerce' === scope && !context.woocommerce_active) {
		return null;
	}

	const plain = !context.pretty_permalinks;
	const categoriesKeepingBase = context.categories_keeping_base || [];
	const productCategoriesKeepingBase =
		'woocommerce' === scope
			? context.product_categories_keeping_base || []
			: [];
	const productsKeepingBase =
		'woocommerce' === scope ? context.products_keeping_base || [] : [];
	const productsKeepBaseUnderStructure =
		'woocommerce' === scope && !!context.products_keep_base_under_structure;

	return (
		<Card padding={false}>
			<ProductSeoCardHeader
				icon={link}
				title={
					'woocommerce' === scope
						? __('Product URLs', 'thinkrank')
						: __('Category URLs', 'thinkrank')
				}
				description={__(
					'These change the public address of every affected page. The previous address answers with a permanent (301) redirect to the new one, and turning a switch off later redirects the other way, so links and rankings are kept.',
					'thinkrank'
				)}
				isSaving={isSaving}
			/>
			<div className="tr-px-6 tr-py-5">
				{(plain ||
					categoriesKeepingBase.length > 0 ||
					productCategoriesKeepingBase.length > 0 ||
					productsKeepingBase.length > 0 ||
					productsKeepBaseUnderStructure) && (
					<div className="tr-mb-4 tr-flex tr-flex-col tr-gap-3">
						{plain && (
							<Alert variant="warning">
								{__(
									'Your site uses plain permalinks (?p=123), which have no base to remove. Choose another structure under Settings → Permalinks to use these switches.',
									'thinkrank'
								)}
							</Alert>
						)}
						{categoriesKeepingBase.length > 0 && (
							<Alert variant="warning">
								{sprintf(
									/* translators: %s: comma-separated category paths. */
									__(
										'These post categories keep their base, because a product category, page or post already uses the same address, or WordPress reserves it, such as for a date archive: %s. Change the slug to give the post category the shorter address.',
										'thinkrank'
									),
									categoriesKeepingBase.join(', ')
								)}
							</Alert>
						)}
						{productCategoriesKeepingBase.length > 0 && (
							<Alert variant="warning">
								{sprintf(
									/* translators: %s: comma-separated category paths. */
									__(
										'These product categories keep their base, because a page or post already uses the same address, or WordPress reserves it, such as for a date archive: %s. Change the slug to give the category the shorter address.',
										'thinkrank'
									),
									productCategoriesKeepingBase.join(', ')
								)}
							</Alert>
						)}
						{productsKeepBaseUnderStructure && (
							<Alert variant="warning">
								{__(
									'Your permalink structure gives products no address of their own at the site root, so every product keeps its base while this switch is on. To remove the base, use a structure such as /%postname%/ under Settings → Permalinks.',
									'thinkrank'
								)}
							</Alert>
						)}
						{productsKeepingBase.length > 0 && (
							<Alert variant="warning">
								{sprintf(
									/* translators: %s: comma-separated product slugs. */
									__(
										'These products keep their base, because a page, post, category or media file already uses the same address, or WordPress reads it as something else, such as a date archive: %s. Change the slug to give the product the shorter address.',
										'thinkrank'
									),
									productsKeepingBase.join(', ')
								)}
							</Alert>
						)}
					</div>
				)}
				<div className="tr-divide-y tr-divide-line-2">
					{rows.map((row) => {
						const { before, after } = examplePaths(
							row.key,
							context
						);

						return (
							<ToggleRow
								key={row.key}
								id={`tr-permalink-${row.key}`}
								checked={!!settings[row.key]}
								disabled={isSaving || plain}
								label={row.label}
								description={row.description}
								onChange={(next) =>
									update(
										{ [row.key]: next },
										next
											? sprintf(
													/* translators: %s: setting name. */
													__(
														'%s is on. Old URLs now redirect to the new ones.',
														'thinkrank'
													),
													row.label
												)
											: sprintf(
													/* translators: %s: setting name. */
													__(
														'%s is off. The shorter URLs now redirect back.',
														'thinkrank'
													),
													row.label
												)
									)
								}
							>
								<ExamplePaths before={before} after={after} />
							</ToggleRow>
						);
					})}
				</div>
			</div>
		</Card>
	);
};

export default PermalinkSettings;
