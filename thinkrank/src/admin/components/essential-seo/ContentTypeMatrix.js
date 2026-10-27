/**
 * Content Type Matrix
 *
 * Content types as rows, SEO features as columns (#660). Every feature cell is
 * a three-state control: Inherit keeps the site-wide setting named under the
 * column header, On and Off pin the feature for that content type.
 *
 * Rows cover post types, taxonomies, the blog posts index, author and date
 * archives, search results and the 404 page. Sitemap inclusion is a plain
 * switch because it writes the sitemap's own include/exclude flag rather than
 * a tri-state of its own, and it only appears for entities the sitemap
 * generator will actually emit.
 *
 * The screen follows the house settings-screen shape used across Essential SEO:
 * one Card, one SectionHeader, a toolbar under it, then the content. The table
 * is long enough (every post type, every taxonomy, plus archives and special
 * pages) that it also carries a search box, a view filter and a sticky header,
 * so a row can be found without scrolling the whole matrix.
 *
 * Each control autosaves: flipping it IS the save, optimistically applied and
 * rolled back if the request fails.
 *
 * @package
 * @since 2.5.0
 */

import {
	useState,
	useEffect,
	useCallback,
	useMemo,
	Fragment,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';

import {
	Badge,
	Button,
	Card,
	EmptyState,
	Input,
	Select,
	SettingsLoadError,
	SkeletonCard,
	Toggle,
} from '../ui';
import SectionHeader from '../common/SectionHeader';
import notify from '../../utils/notify';
import { restUrl } from '../../utils/restUrl';
import logger from '../../../shared/utils/logger';

const INHERIT = 'inherit';

const STATE_OPTIONS = [
	{ label: __('Inherit', 'thinkrank'), value: INHERIT },
	{ label: __('On', 'thinkrank'), value: 'on' },
	{ label: __('Off', 'thinkrank'), value: 'off' },
];

/**
 * Columns this plugin has a feature for. The `analytics_enabled` switch gates
 * the GA4 tag ThinkRank Pro installs, so Pro adds that column through the
 * `thinkrank.contentTypeMatrix.featureColumns` filter.
 */
const BASE_FEATURE_COLUMNS = [
	{ key: 'meta_enabled', label: __('Metas', 'thinkrank') },
	{ key: 'schema_enabled', label: __('Schema', 'thinkrank') },
	{ key: 'open_graph_enabled', label: __('Open Graph', 'thinkrank') },
	{ key: 'twitter_card_enabled', label: __('Twitter card', 'thinkrank') },
];

const GROUP_LABELS = {
	post_type: __('Post types', 'thinkrank'),
	taxonomy: __('Taxonomies', 'thinkrank'),
	archive: __('Archives', 'thinkrank'),
	special: __('Special pages', 'thinkrank'),
};

const GROUP_ORDER = ['post_type', 'taxonomy', 'archive', 'special'];

const VIEW_OPTIONS = [
	{ label: __('All content types', 'thinkrank'), value: 'all' },
	{ label: __('Pinned only', 'thinkrank'), value: 'pinned' },
	{ label: __('Untouched only', 'thinkrank'), value: 'untouched' },
];

/**
 * How many features this row pins rather than inherits.
 *
 * This is the number that makes the matrix readable: a row at zero behaves
 * exactly like the site-wide settings, so anything above zero is the part of
 * the screen worth looking at.
 *
 * @param {Object} entity Entity row.
 * @return {number} Count of pinned features.
 */
/**
 * Which of Inherit / On / Off the No-index column shows for a row.
 *
 * With the pin off the row follows its own default, which is what Inherit means
 * here; `robots_meta` already resolves to that default in that case, so reading
 * it would show On or Off for a row that is in fact inheriting.
 *
 * @param {Object} entity Entity row.
 * @return {string} One of STATE_OPTIONS' values.
 */
/**
 * No-index options for one row, with Inherit labelled by what it resolves to.
 *
 * The feature columns can put "inherit = on" once in the column header because
 * every row inherits the same site-wide value. No-index cannot: the fallback is
 * per entity, and search and 404 default to noindex while everything else
 * defaults to indexable. So the resolution goes on the option itself, where it
 * is right for the row the user is actually looking at (#838).
 *
 * @param {Object} entity Entity row.
 * @return {Array} Options for the No-index select.
 */
/**
 * Whether a row matches what the user typed into the search box.
 *
 * The key as well as the label. This is the screen a developer opens to answer
 * "what is set for product_tag", and typing that found nothing because the row is
 * labelled "Product tags" (#839). The whole key is searched, prefix included, so
 * `taxonomy:product_tag`, `product_tag` and `Product tags` all reach the same row
 * without the component having to know the prefix vocabulary.
 *
 * @param {Object} entity Entity row.
 * @param {string} term   Search term, already lowercased and trimmed.
 * @return {boolean} Whether the row should be shown.
 */
export const matchesQuery = (entity, term) => {
	if (!term) {
		return true;
	}

	return `${entity?.label || ''} ${entity?.key || ''}`
		.toLowerCase()
		.includes(term);
};

export const noindexOptions = (entity) => [
	{
		label: entity.default_noindex
			? __('Inherit (on)', 'thinkrank')
			: __('Inherit (off)', 'thinkrank'),
		value: INHERIT,
	},
	{ label: __('On', 'thinkrank'), value: 'on' },
	{ label: __('Off', 'thinkrank'), value: 'off' },
];

export const noindexState = (entity) => {
	if (!entity.robots_meta_enabled) {
		return INHERIT;
	}

	return entity.robots_meta?.noindex ? 'on' : 'off';
};

/**
 * Whether the row's effective robots decision is noindex.
 *
 * The sitemap leaves out every item of a noindexed content type (#911), so a
 * No-index row cannot also show Sitemap as on: the two switches would disagree
 * about what the sitemap lists. Only a per-item index override puts an item
 * back, and that is set on the item, not here.
 *
 * @param {Object} entity Entity row.
 * @return {boolean} True when the row resolves to noindex.
 */
export const isNoindexed = (entity) =>
	entity.robots_meta_enabled
		? !!entity.robots_meta?.noindex
		: !!entity.default_noindex;

export const pinnedCount = (entity) => {
	const features = (entity.features || []).filter(
		(feature) => (entity.values?.[feature] || INHERIT) !== INHERIT
	).length;

	// The robots pin counts. When it is on, the frontend merges this row's whole
	// robots_meta array over the site-wide directives, so the row overrides
	// noarchive, noimageindex, nosnippet and nofollow as well as noindex: it is
	// at least as much of a deviation as any feature cell. Leaving it out is why
	// rows that override site-wide robots reported "All inherited", were hidden
	// from "Pinned only", and were offered no way back (#838).
	//
	// Sitemap inclusion deliberately does NOT count. It is not a tri-state and
	// has no inherit to return to: it is stored as the sitemap's own
	// include_*/exclude_* flags, every row always has a concrete value, and
	// counting it would mark most of the matrix as pinned and make the badge
	// useless. Decided explicitly rather than left to the undercount.
	return features + (entity.robots_meta_enabled ? 1 : 0);
};

/**
 * A cell the entity does not expose (schema on a search page, say).
 *
 * Rendered as a dash for scanning, with the reason available to assistive tech
 * and on hover rather than left as an unexplained gap.
 *
 * @return {JSX.Element} The placeholder cell content.
 */
const NotApplicable = () => (
	<span
		className="tr-text-slate-300"
		title={__('Not available for this content type', 'thinkrank')}
	>
		<span aria-hidden="true">&ndash;</span>
		<span className="screen-reader-text">
			{__('Not available for this content type', 'thinkrank')}
		</span>
	</span>
);

const ContentTypeMatrix = () => {
	const [entities, setEntities] = useState([]);
	const [globals, setGlobals] = useState({});
	// Resolved on mount rather than at import: Pro's bundle registers its
	// column after this module has been evaluated.
	const featureColumns = useMemo(
		() =>
			applyFilters(
				'thinkrank.contentTypeMatrix.featureColumns',
				BASE_FEATURE_COLUMNS
			),
		[]
	);
	const [isLoading, setIsLoading] = useState(true);
	const [loadFailed, setLoadFailed] = useState(false);
	const [savingKey, setSavingKey] = useState(null);
	const [query, setQuery] = useState('');
	const [view, setView] = useState('all');

	const loadMatrix = useCallback(async () => {
		setIsLoading(true);

		try {
			const response = await fetch(restUrl('global-seo/matrix'), {
				headers: { 'X-WP-Nonce': window.thinkrankAdmin.restNonce },
			});

			if (!response.ok) {
				throw new Error(`Matrix request failed: ${response.status}`);
			}

			const data = await response.json();

			if (!data.success || !data.data) {
				throw new Error('Matrix response carried no data');
			}

			setEntities(data.data.entities || []);
			setGlobals(data.data.globals || {});
			setLoadFailed(false);
		} catch (error) {
			logger.error('Error loading content type matrix:', error);
			setLoadFailed(true);
		} finally {
			setIsLoading(false);
		}
	}, []);

	useEffect(() => {
		loadMatrix();
	}, [loadMatrix]);

	/**
	 * Optimistically apply a row change, then persist it.
	 *
	 * The previous row is captured first so a failed request can put the
	 * control back where it was — a switch that looks committed but silently
	 * isn't reads as a bug. On success the row is reconciled with what the
	 * server actually stored: sitemap inclusion in particular is written
	 * through legacy flags that can resolve to something other than what was
	 * sent.
	 *
	 * @param {string} entityKey Entity key of the row.
	 * @param {Object} patch     Row fields to change locally.
	 * @param {Object} payload   Settings payload to POST.
	 */
	const persist = async (entityKey, patch, payload) => {
		const previous = entities.find((entity) => entity.key === entityKey);

		setEntities((current) =>
			current.map((entity) =>
				entity.key === entityKey ? { ...entity, ...patch } : entity
			)
		);
		setSavingKey(entityKey);

		try {
			const response = await fetch(restUrl('global-seo/matrix'), {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': window.thinkrankAdmin.restNonce,
				},
				body: JSON.stringify({ entity: entityKey, settings: payload }),
			});

			if (!response.ok) {
				throw new Error(`Save failed: ${response.status}`);
			}

			const saved = await response.json();

			if (saved?.success && saved?.data) {
				setEntities((current) =>
					current.map((entity) =>
						entity.key === entityKey
							? { ...entity, ...saved.data }
							: entity
					)
				);
			}
		} catch (error) {
			logger.error('Error saving content type matrix row:', error);

			setEntities((current) =>
				current.map((entity) =>
					entity.key === entityKey && previous ? previous : entity
				)
			);
			notify.error(
				__(
					'Could not save that change. Nothing was applied.',
					'thinkrank'
				)
			);
		} finally {
			setSavingKey(null);
		}
	};

	const setFeature = (entity, feature, value) =>
		persist(
			entity.key,
			{ values: { ...entity.values, [feature]: value } },
			{ [feature]: value }
		);

	const setNoindex = (entity, value) => {
		// Inherit is the only state that clears the pin. Before this existed the
		// switch had two positions and neither meant "inherit", so turning
		// No-index off still wrote robots_meta_enabled: true and the row left
		// inherit permanently the first time it was touched (#838).
		if (value === INHERIT) {
			return persist(
				entity.key,
				{ robots_meta_enabled: false },
				{ robots_meta_enabled: false }
			);
		}

		const noindex = value === 'on';
		const robots = {
			...entity.robots_meta,
			noindex,
			index: !noindex,
		};

		return persist(
			entity.key,
			{ robots_meta_enabled: true, robots_meta: robots },
			{ robots_meta_enabled: true, robots_meta: robots }
		);
	};

	const setSitemap = (entity, include) =>
		persist(
			entity.key,
			{ sitemap_include: include },
			{ sitemap_include: include }
		);

	// One request puts the whole row back on the site-wide settings, rather
	// than five trips through the dropdowns.
	const resetRow = (entity) => {
		const inherited = Object.fromEntries(
			(entity.features || []).map((feature) => [feature, INHERIT])
		);

		// The robots pin has to go too, or the button's label promises something
		// the payload does not deliver: the row stays off the site-wide robots
		// settings and still reports as pinned (#838).
		return persist(
			entity.key,
			{ values: inherited, robots_meta_enabled: false },
			{ ...inherited, robots_meta_enabled: false }
		);
	};

	const totalPinned = useMemo(
		() => entities.reduce((sum, entity) => sum + pinnedCount(entity), 0),
		[entities]
	);

	const visible = useMemo(() => {
		const term = query.trim().toLowerCase();

		return entities.filter((entity) => {
			if (term && !matchesQuery(entity, term)) {
				return false;
			}
			if (view === 'pinned') {
				return pinnedCount(entity) > 0;
			}
			if (view === 'untouched') {
				return pinnedCount(entity) === 0;
			}
			return true;
		});
	}, [entities, query, view]);

	const rowsByGroup = useMemo(
		() =>
			GROUP_ORDER.map((group) => ({
				group,
				rows: visible.filter((entity) => entity.group === group),
			})).filter(({ rows }) => rows.length > 0),
		[visible]
	);

	if (isLoading) {
		return <SkeletonCard />;
	}

	if (loadFailed) {
		return (
			<SettingsLoadError
				message={__(
					'Could not load the content type matrix. Nothing has been changed, retry before editing.',
					'thinkrank'
				)}
				onRetry={loadMatrix}
			/>
		);
	}

	const inheritHint = (feature) =>
		globals[feature]
			? __('inherit = on', 'thinkrank')
			: __('inherit = off', 'thinkrank');

	const columnCount = featureColumns.length + 3;

	return (
		<Card padding={false}>
			<SectionHeader
				title={__('Content Type Matrix', 'thinkrank')}
				description={__(
					'Switch each SEO feature per content type. Inherit follows the site-wide setting, so a matrix you never touch changes nothing.',
					'thinkrank'
				)}
				trailing={
					<Badge variant={totalPinned > 0 ? 'info' : 'neutral'}>
						{totalPinned > 0
							? sprintf(
									/* translators: %d: how many feature cells are pinned on or off rather than inherited. */
									_n(
										'%d cell pinned',
										'%d cells pinned',
										totalPinned,
										'thinkrank'
									),
									totalPinned
								)
							: __('All inherited', 'thinkrank')}
					</Badge>
				}
			/>

			{/* Toolbar: find a row, or narrow to the rows that differ. */}
			<div className="tr-flex tr-flex-wrap tr-items-end tr-gap-3 tr-px-6 tr-py-4 tr-border-b tr-border-secondary">
				<Input
					id="tr-ctm-search"
					label={__('Search content types', 'thinkrank')}
					hideLabelFromVision
					type="search"
					value={query}
					placeholder={__('Search content types…', 'thinkrank')}
					onChange={(event) => setQuery(event.target.value)}
					className="tr-w-56"
				/>
				<Select
					id="tr-ctm-view"
					label={__('View', 'thinkrank')}
					hideLabelFromVision
					value={view}
					options={VIEW_OPTIONS}
					onChange={(event) => setView(event.target.value)}
					className="tr-w-52"
				/>

				<p className="tr-m-0 tr-ml-auto tr-text-xs tr-text-tertiary tr-max-w-md tr-leading-relaxed">
					{__(
						'Inherit follows the site-wide value named under each column. On and Off pin the feature for that content type only.',
						'thinkrank'
					)}
				</p>
			</div>

			{visible.length === 0 ? (
				<EmptyState
					size="sm"
					illustration={false}
					title={__('No content types match', 'thinkrank')}
					description={__(
						'Nothing here matches your search and filter.',
						'thinkrank'
					)}
					action={
						<Button
							variant="secondary"
							size="sm"
							onClick={() => {
								setQuery('');
								setView('all');
							}}
						>
							{__('Clear filters', 'thinkrank')}
						</Button>
					}
				/>
			) : (
				// tr-relative makes the scroller the containing block of the
				// cells' visually hidden labels. They are absolutely positioned,
				// so without it they escape the overflow clip and widen the whole
				// page on a phone, which then pans sideways (#1256).
				<div className="tr-relative tr-overflow-x-auto tr-max-h-[70vh] tr-overflow-y-auto">
					<table className="tr-w-full tr-border-collapse tr-text-sm">
						<thead className="tr-sticky tr-top-0 tr-z-10 tr-bg-white">
							<tr className="tr-border-b tr-border-slate-200">
								{/* Pinned on both axes. The header is already sticky
								    vertically; this cell also has to outrank the
								    pinned body cells it crosses, hence z-20
								    against their z-10 (#839). An opaque
								    background is required on every sticky cell or
								    the scrolled columns show through. */}
								<th
									scope="col"
									className="tr-sticky tr-left-0 tr-top-0 tr-z-20 tr-bg-white tr-text-left tr-px-4 tr-py-3 tr-font-semibold tr-text-slate-500"
								>
									{__('Content type', 'thinkrank')}
								</th>
								{featureColumns.map((column) => (
									<th
										key={column.key}
										className="tr-text-left tr-px-2 tr-py-3 tr-font-semibold tr-text-slate-500"
									>
										{column.label}
										<span className="tr-block tr-text-[11px] tr-font-normal tr-text-slate-400 tr-mt-0.5">
											{inheritHint(column.key)}
										</span>
									</th>
								))}
								<th className="tr-text-left tr-px-2 tr-py-3 tr-font-semibold tr-text-slate-500">
									{__('No-index', 'thinkrank')}
									<span className="tr-block tr-text-[11px] tr-font-normal tr-text-slate-400 tr-mt-0.5">
										{__(
											'inherit varies by type',
											'thinkrank'
										)}
									</span>
								</th>
								<th className="tr-text-left tr-px-2 tr-py-3 tr-font-semibold tr-text-slate-500">
									{__('Sitemap', 'thinkrank')}
								</th>
							</tr>
						</thead>
						<tbody>
							{rowsByGroup.map(({ group, rows }) => (
								<Fragment key={group}>
									<tr className="tr-bg-slate-50">
										{/* Opaque, so the pinned column never has
										    scrolled cells showing through where it
										    crosses this row. The row's own grey
										    rather than white, matching its
										    unpinned appearance.

										    The sticky offset goes on the inner
										    span, not on the cell: this cell spans
										    every column, so its left edge is
										    already the row's left edge and
										    position:sticky on it has no room to
										    act — measured, the group label still
										    scrolled from x=253 to x=-447. A span
										    inside a full-width cell does have
										    room, so the label stays readable at
										    any scroll position (#839). */}
										<th
											scope="colgroup"
											colSpan={columnCount}
											className="tr-bg-slate-50 tr-text-left tr-px-4 tr-py-2 tr-text-xs tr-uppercase tr-tracking-wide tr-font-semibold tr-text-slate-500"
										>
											<span className="tr-sticky tr-left-4 tr-inline-block">
												{GROUP_LABELS[group] || group}
											</span>
										</th>
									</tr>
									{rows.map((entity) => {
										const pinned = pinnedCount(entity);
										const isSaving =
											savingKey === entity.key;

										return (
											<tr
												key={entity.key}
												className={`tr-border-b tr-border-slate-100 ${
													pinned > 0
														? 'tr-bg-brand-bg/30'
														: ''
												}`}
											>
												<td className="tr-sticky tr-left-0 tr-z-10 tr-bg-white tr-px-4 tr-py-2.5 tr-text-slate-700">
													<div className="tr-flex tr-items-center tr-gap-2">
														<span className="tr-font-medium">
															{entity.label}
														</span>
														{pinned > 0 && (
															<Badge variant="info">
																{pinned}
															</Badge>
														)}
													</div>
													{pinned > 0 && (
														<button
															type="button"
															className="tr-mt-1 tr-bg-transparent tr-border-0 tr-p-0 tr-text-xs tr-text-brand tr-cursor-pointer hover:tr-underline disabled:tr-opacity-50 disabled:tr-cursor-not-allowed"
															disabled={isSaving}
															onClick={() =>
																resetRow(entity)
															}
														>
															{__(
																'Reset to inherit',
																'thinkrank'
															)}
														</button>
													)}
												</td>
												{featureColumns.map(
													(column) => (
														<td
															key={column.key}
															className="tr-px-2 tr-py-2.5"
														>
															{entity.features.includes(
																column.key
															) ? (
																<Select
																	id={`${column.key}-${entity.key}`}
																	label={sprintf(
																		/* translators: 1: content type label, 2: feature name. */
																		__(
																			'%1$s, %2$s',
																			'thinkrank'
																		),
																		entity.label,
																		column.label
																	)}
																	hideLabelFromVision
																	value={
																		entity
																			.values?.[
																			column
																				.key
																		] ||
																		INHERIT
																	}
																	options={
																		STATE_OPTIONS
																	}
																	disabled={
																		isSaving
																	}
																	onChange={(
																		event
																	) =>
																		setFeature(
																			entity,
																			column.key,
																			event
																				.target
																				.value
																		)
																	}
																/>
															) : (
																<NotApplicable />
															)}
														</td>
													)
												)}
												<td className="tr-px-2 tr-py-2.5">
													{/* Honours supports_robots the
													    way the Sitemap cell
													    honours supports_sitemap.
													    Defensive today — every
													    entity reports true — but
													    it removes the asymmetry,
													    so an entity that cannot
													    carry robots directives
													    degrades instead of
													    offering a control that
													    writes nothing (#839). */}
													{entity.supports_robots ? (
														<Select
															id={`noindex-${entity.key}`}
															label={sprintf(
																/* translators: %s: content type label. */
																__(
																	'%s, No-index',
																	'thinkrank'
																),
																entity.label
															)}
															hideLabelFromVision
															value={noindexState(
																entity
															)}
															options={noindexOptions(
																entity
															)}
															disabled={isSaving}
															onChange={(event) =>
																setNoindex(
																	entity,
																	event.target
																		.value
																)
															}
														/>
													) : (
														<NotApplicable />
													)}
												</td>
												<td className="tr-px-2 tr-py-2.5">
													{entity.supports_sitemap ? (
														<Toggle
															id={`sitemap-${entity.key}`}
															checked={
																!!entity.sitemap_include &&
																!isNoindexed(
																	entity
																)
															}
															disabled={
																isSaving ||
																isNoindexed(
																	entity
																)
															}
															onChange={(
																checked
															) =>
																setSitemap(
																	entity,
																	checked
																)
															}
														/>
													) : (
														<NotApplicable />
													)}
												</td>
											</tr>
										);
									})}
								</Fragment>
							))}
						</tbody>
					</table>
				</div>
			)}
		</Card>
	);
};

export default ContentTypeMatrix;
