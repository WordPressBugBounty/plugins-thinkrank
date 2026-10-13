/**
 * Bulk Snippets
 *
 * Every post's SEO title, meta description and focus keyword for one post
 * type in a single table, filtered by what is wrong with it, with inline
 * editing and a bulk AI fill that is reviewed before anything is saved (#727).
 *
 * The server owns every judgement — which posts have which issue, what the
 * page renders when a field is empty — so this screen never disagrees with
 * the editor or with the list-snippet-issues ability. The client only holds
 * unsaved edits.
 *
 * @package
 * @since 2.8.0
 */

import {
	useState,
	useEffect,
	useCallback,
	useRef,
	useMemo,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import classnames from 'classnames';

import {
	Card,
	Button,
	Badge,
	Input,
	Textarea,
	Select,
	Tabs,
	CharacterCounter,
	SkeletonCard,
	Alert,
	EmptyState,
} from '../../ui';
import SectionHeader from '../../common/SectionHeader';
import DuplicatesCard from './DuplicatesCard';
import SearchPreview from '../../common/SearchPreview';
import notify from '../../../utils/notify';
import { navigate } from '../../../utils/router';
import logger from '../../../../shared/utils/logger';
import {
	ISSUES,
	PER_PAGE,
	readFilters,
	writeFilters,
	changedFields,
	mergeSuggestion,
	capRun,
	clampPage,
	pageItems,
	pageRange,
} from './state';

const PATH = '/thinkrank/v1/global-seo/snippets';

const ISSUE_LABELS = {
	empty_title: __('No custom title', 'thinkrank'),
	empty_description: __('No custom description', 'thinkrank'),
	title_too_short: __('Title too short', 'thinkrank'),
	title_too_long: __('Title too long', 'thinkrank'),
	description_too_short: __('Description too short', 'thinkrank'),
	description_too_long: __('Description too long', 'thinkrank'),
	no_focus_keyword: __('No focus keyword', 'thinkrank'),
	noindex: __('Noindex', 'thinkrank'),
	duplicate_title: __('Duplicate title', 'thinkrank'),
	duplicate_description: __('Duplicate description', 'thinkrank'),
};

// Severity for the row badges: what hides the page from search or makes it
// compete with itself reads as worse than a length that could be better.
const ISSUE_VARIANT = {
	noindex: 'poor',
	duplicate_title: 'poor',
	duplicate_description: 'poor',
	title_too_long: 'warning',
	description_too_long: 'warning',
	title_too_short: 'warning',
	description_too_short: 'warning',
	empty_title: 'neutral',
	empty_description: 'neutral',
	no_focus_keyword: 'neutral',
};

const STATUS_OPTIONS = [
	{ value: 'publish', label: __('Published', 'thinkrank') },
	{ value: 'draft', label: __('Drafts & scheduled', 'thinkrank') },
	{ value: 'any', label: __('All statuses', 'thinkrank') },
];

const BulkSnippetsTab = () => {
	const postTypes = useMemo(() => window.thinkrankAdmin?.postTypes || [], []);
	const postTypeSlugs = useMemo(
		() => postTypes.map((pt) => pt.slug),
		[postTypes]
	);

	const [filters, setFilters] = useState(() =>
		readFilters(window.location.search, postTypeSlugs)
	);
	const [searchDraft, setSearchDraft] = useState(filters.search);
	const [data, setData] = useState(null);
	const [isLoading, setIsLoading] = useState(true);
	const [loadError, setLoadError] = useState(null);

	// Unsaved edits, keyed by post ID then field. Only fields the user (or an
	// AI suggestion) touched are present.
	const [edits, setEdits] = useState({});
	// Rows whose pending edit came from AI, so the reviewer can tell.
	const [aiRows, setAiRows] = useState({});
	// Per-row transient state: 'saving' | 'generating' | { error: string }.
	const [rowState, setRowState] = useState({});
	const [selected, setSelected] = useState([]);
	const [focusedId, setFocusedId] = useState(null);
	const [isSavingAll, setIsSavingAll] = useState(false);
	const [aiRun, setAiRun] = useState(null); // { done, total }
	const cancelAi = useRef(false);
	// Identifies the AI run in progress. A response from a run that has since
	// been stopped or superseded must not write into the current screen.
	const aiRunId = useRef(0);
	// Identifies the latest list request. Filters can change faster than the
	// server answers; only the newest answer may reach the table.
	const loadSeq = useRef(0);
	const [isRefreshing, setIsRefreshing] = useState(false);

	// Leaving the screen stops a run and orphans any in-flight answer.
	useEffect(
		() => () => {
			cancelAi.current = true;
			aiRunId.current++;
			loadSeq.current++;
		},
		[]
	);

	// Keep the URL in step with the filters so a reload, a shared link or the
	// back button lands on the same list.
	useEffect(() => {
		const next = writeFilters(window.location.href, filters);
		if (next !== window.location.href) {
			window.history.replaceState(window.history.state, '', next);
		}
	}, [filters]);

	const rows = useMemo(() => data?.rows || [], [data]);
	const dirtyIds = useMemo(
		() =>
			rows
				.filter(
					(row) =>
						Object.keys(changedFields(row, edits[row.post_id]))
							.length > 0
				)
				.map((row) => row.post_id),
		[rows, edits]
	);

	const load = useCallback(async () => {
		const seq = ++loadSeq.current;
		setLoadError(null);
		setIsRefreshing(true);
		try {
			const response = await apiFetch({
				path: addQueryArgs(PATH, {
					post_type: filters.postType,
					issue: filters.issue,
					status: filters.status,
					search: filters.search,
					page: filters.page,
					per_page: PER_PAGE,
				}),
			});
			if (seq !== loadSeq.current) {
				return; // Superseded by a newer request.
			}
			setData(response);
		} catch (error) {
			if (seq !== loadSeq.current) {
				return;
			}
			logger.error('Bulk Snippets: load failed', error);
			setLoadError(
				error?.message ||
					__('The snippets could not be loaded.', 'thinkrank')
			);
		} finally {
			if (seq === loadSeq.current) {
				setIsLoading(false);
				setIsRefreshing(false);
			}
		}
	}, [filters]);

	useEffect(() => {
		load();
	}, [load]);

	// A page number out of a shared link, or one the list outgrew when a post
	// was fixed, lands past the end and the server answers with no rows. Step
	// back to the last real page rather than show an empty table. Nothing can
	// be dirty on a page that rendered no rows, so no confirmation is needed.
	const totalPages = data?.total_pages;
	useEffect(() => {
		if (!totalPages) {
			return;
		}
		setFilters((f) =>
			clampPage(f.page, totalPages) === f.page
				? f
				: { ...f, page: clampPage(f.page, totalPages) }
		);
	}, [totalPages]);

	// Posts not analysed yet: each request analyses another bounded batch, so
	// keep asking until none remain. Counts fill in as it goes.
	const pending = data?.index?.pending ?? 0;
	// Polls in a row that made no progress. The server always builds a batch,
	// so this only trips if something prevents that — and then polling stops
	// instead of hammering the server for the life of the tab.
	const stalled = useRef({ last: null, count: 0 });
	useEffect(() => {
		if (pending <= 0 || isRefreshing || aiRun) {
			return undefined;
		}
		const tracker = stalled.current;
		tracker.count =
			tracker.last !== null && pending >= tracker.last
				? tracker.count + 1
				: 0;
		tracker.last = pending;
		if (tracker.count >= 3) {
			logger.error('Bulk Snippets: analysis made no progress', pending);
			return undefined;
		}
		const timer = setTimeout(load, 600);
		return () => clearTimeout(timer);
	}, [pending, isRefreshing, aiRun, load]);

	// Debounce typing into the search box.
	useEffect(() => {
		if (searchDraft === filters.search) {
			return undefined;
		}
		const timer = setTimeout(() => {
			// Same guard as every other list change: a search that would hide
			// edited rows asks first, and declining puts the box back.
			if (!changeFilters({ search: searchDraft.trim() })) {
				setSearchDraft(filters.search);
			}
		}, 400);
		return () => clearTimeout(timer);
		// changeFilters is recreated each render; the draft is what matters.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [searchDraft, filters.search]);

	// Changing the list throws away unsaved edits on rows that disappear, so
	// ask first rather than lose someone's work to a stray click.
	const changeFilters = (patch) => {
		// The list is frozen while AI runs: the run holds these rows, and
		// swapping them out would scatter its drafts onto posts off screen.
		if (aiRun) {
			return false;
		}
		if (
			dirtyIds.length > 0 &&
			// eslint-disable-next-line no-alert
			!window.confirm(
				__(
					'You have unsaved changes on this page. Discard them?',
					'thinkrank'
				)
			)
		) {
			return false;
		}
		setEdits({});
		setAiRows({});
		setRowState({});
		setSelected([]);
		setFilters((f) => ({ ...f, page: 1, ...patch }));
		return true;
	};

	// Back to the whole post type. The draft has to go back with the filter,
	// or the debounce puts the search term straight back a moment later.
	const clearFilters = () => {
		if (changeFilters({ issue: '', status: 'publish', search: '' })) {
			setSearchDraft('');
		}
	};

	const setEdit = (postId, field, value) => {
		setEdits((all) => ({
			...all,
			[postId]: { ...(all[postId] || {}), [field]: value },
		}));
		setRowState((all) => {
			const next = { ...all };
			delete next[postId];
			return next;
		});
	};

	const discardRow = (postId) => {
		setEdits((all) => {
			const next = { ...all };
			delete next[postId];
			return next;
		});
		setAiRows((all) => {
			const next = { ...all };
			delete next[postId];
			return next;
		});
	};

	/**
	 * Save rows, then replace each saved row with the server's copy — the
	 * values actually stored and the issues they now have.
	 *
	 * @param {number[]} ids Post IDs to save.
	 */
	const saveRows = async (ids) => {
		const items = ids
			.map((id) => {
				const row = rows.find((r) => r.post_id === id);
				const changed = changedFields(row, edits[id]);
				if (!row || Object.keys(changed).length === 0) {
					return null;
				}
				return {
					post_id: id,
					...changed,
					// What the table showed, so a value a background job wrote
					// meanwhile is not blanked by this save.
					original: {
						title: row.title,
						description: row.description,
					},
				};
			})
			.filter(Boolean);

		if (items.length === 0) {
			return;
		}

		setRowState((all) => {
			const next = { ...all };
			items.forEach((item) => {
				next[item.post_id] = 'saving';
			});
			return next;
		});

		try {
			const response = await apiFetch({
				path: PATH,
				method: 'POST',
				data: { items },
			});
			applySaveResults(response?.results || []);

			// A save can change other rows too — fixing one of two duplicate
			// titles clears the other — and every chip count. Reload the page
			// so neighbours and counts are the server's, not stale; unsaved
			// edits on other rows are kept, since they live in their own state.
			load();

			if (response?.failed > 0) {
				notify.error(
					sprintf(
						/* translators: %d: number of rows that failed to save. */
						_n(
							'%d row could not be saved.',
							'%d rows could not be saved.',
							response.failed,
							'thinkrank'
						),
						response.failed
					)
				);
			} else {
				notify.success(
					sprintf(
						/* translators: %d: number of rows saved. */
						_n(
							'%d snippet saved.',
							'%d snippets saved.',
							response.saved,
							'thinkrank'
						),
						response.saved
					)
				);
			}
		} catch (error) {
			// A 400 still carries per-row results when every row failed.
			if (error?.results) {
				applySaveResults(error.results);
			} else {
				setRowState((all) => {
					const next = { ...all };
					items.forEach((item) => {
						next[item.post_id] = {
							error:
								error?.message ||
								__('Save failed.', 'thinkrank'),
						};
					});
					return next;
				});
			}
			notify.error(error?.message || __('Save failed.', 'thinkrank'));
		}
	};

	const applySaveResults = (results) => {
		const savedRows = {};
		const failures = {};
		results.forEach((result) => {
			if (result.success && result.row) {
				savedRows[result.post_id] = result.row;
			} else {
				failures[result.post_id] = {
					error: result.message || __('Save failed.', 'thinkrank'),
				};
			}
		});

		setData((current) =>
			current
				? {
						...current,
						rows: current.rows.map(
							(row) => savedRows[row.post_id] || row
						),
					}
				: current
		);
		setEdits((all) => {
			const next = { ...all };
			Object.keys(savedRows).forEach((id) => delete next[id]);
			return next;
		});
		setAiRows((all) => {
			const next = { ...all };
			Object.keys(savedRows).forEach((id) => delete next[id]);
			return next;
		});
		setRowState((all) => {
			const next = { ...all };
			Object.keys(savedRows).forEach((id) => delete next[id]);
			return { ...next, ...failures };
		});
	};

	const saveAll = async () => {
		setIsSavingAll(true);
		await saveRows(dirtyIds);
		setIsSavingAll(false);
	};

	/**
	 * Generate suggestions for the selected rows, one request at a time. Each
	 * lands in the row as an unsaved edit; nothing is written until the user
	 * saves.
	 */
	const runAi = async () => {
		const cap = data?.ai?.fill_cap ?? 25;
		const ids = capRun(
			rows.map((r) => r.post_id).filter((id) => selected.includes(id)),
			cap
		);
		if (ids.length === 0) {
			return;
		}

		cancelAi.current = false;
		const runId = ++aiRunId.current;
		const isCurrent = () => runId === aiRunId.current;
		setAiRun({ done: 0, total: ids.length });

		let failed = 0;
		for (let i = 0; i < ids.length; i++) {
			if (cancelAi.current || !isCurrent()) {
				break;
			}
			const id = ids[i];
			setRowState((all) => ({ ...all, [id]: 'generating' }));

			try {
				const response = await apiFetch({
					path: `${PATH}/${id}/generate`,
					method: 'POST',
				});
				if (!isCurrent()) {
					return; // Screen left or run superseded mid-request.
				}
				const row = rows.find((r) => r.post_id === id);
				setEdits((all) => ({
					...all,
					[id]: mergeSuggestion(row, all[id], response.suggestion),
				}));
				setAiRows((all) => ({ ...all, [id]: true }));
				setRowState((all) => {
					const next = { ...all };
					delete next[id];
					return next;
				});
			} catch (error) {
				if (!isCurrent()) {
					return;
				}
				failed++;
				// The row keeps whatever it had; only the message is added.
				setRowState((all) => ({
					...all,
					[id]: {
						error:
							error?.message ||
							__('AI generation failed.', 'thinkrank'),
					},
				}));
			}

			setAiRun({ done: i + 1, total: ids.length });
		}

		if (!isCurrent()) {
			return;
		}
		setAiRun(null);
		setSelected([]);
		notify.info(
			failed > 0
				? sprintf(
						/* translators: %d: rows that could not be generated. */
						__(
							'Suggestions are ready to review. %d could not be generated. See the rows marked in red.',
							'thinkrank'
						),
						failed
					)
				: __(
						'Suggestions are ready to review. Nothing is saved until you click Save.',
						'thinkrank'
					)
		);
	};

	const toggleSelected = (id) =>
		setSelected((all) =>
			all.includes(id) ? all.filter((x) => x !== id) : [...all, id]
		);
	const editableIds = rows.filter((r) => r.can_edit).map((r) => r.post_id);
	const allSelected =
		editableIds.length > 0 &&
		editableIds.every((id) => selected.includes(id));
	const someSelected = editableIds.some((id) => selected.includes(id));

	if (isLoading) {
		return (
			<div className="tr-flex tr-flex-col tr-gap-5">
				<SkeletonCard lines={2} />
				<SkeletonCard lines={8} />
			</div>
		);
	}

	const limits = data?.limits || {
		title_max: 60,
		description_max: 160,
	};
	const aiCap = data?.ai?.fill_cap ?? 25;
	const aiConfigured = !!data?.ai?.configured;
	const selectedCount = selected.length;
	const focusedRow = rows.find((r) => r.post_id === focusedId);
	const hasNarrowedFilters =
		!!filters.issue || filters.status !== 'publish' || !!filters.search;

	return (
		<div className="tr-flex tr-flex-col tr-gap-5">
			{/* Sitewide, and above the table on purpose: a duplicate is the one
			    problem here that the post-type tabs below cannot show, because
			    the other half of the pair is often a different post type. */}
			<DuplicatesCard
				onFilterIssue={(issue) => changeFilters({ issue })}
			/>

			<Card padding={false}>
				<SectionHeader
					title={__('Bulk Snippets', 'thinkrank')}
					description={__(
						"Every post's search title, meta description and focus keyword in one place. Filter to the posts with a problem, fix them inline, or draft them with AI and review before saving.",
						'thinkrank'
					)}
				/>

				<div className="tr-px-6 tr-pt-2">
					<Tabs
						tabs={postTypes.map((pt) => ({
							id: pt.slug,
							label: pt.plural_name || pt.label,
						}))}
						activeTab={filters.postType}
						disabled={!!aiRun}
						onTabChange={(slug) =>
							changeFilters({ postType: slug, issue: '' })
						}
					/>
				</div>

				<div className="tr-p-6 tr-flex tr-flex-col tr-gap-4">
					{/* Issue chips. The count on each is across the whole post
					    type and status, so it says how many rows clicking it
					    would show. */}
					<div
						className="tr-flex tr-flex-wrap tr-gap-2"
						role="group"
						aria-label={__('Filter by problem', 'thinkrank')}
					>
						<IssueChip
							label={__('All', 'thinkrank')}
							// total narrows to the active issue; total_all
							// does not, so All keeps its number like the rest.
							count={data?.total_all ?? data?.total ?? 0}
							active={!filters.issue}
							disabled={!!aiRun}
							onClick={() => changeFilters({ issue: '' })}
						/>
						{ISSUES.map((issue) => (
							<IssueChip
								key={issue}
								label={ISSUE_LABELS[issue]}
								count={data?.counts?.[issue] ?? 0}
								active={filters.issue === issue}
								disabled={!!aiRun}
								onClick={() => changeFilters({ issue })}
							/>
						))}
					</div>

					<div className="tr-flex tr-flex-wrap tr-items-end tr-gap-3">
						<Input
							label={__('Search', 'thinkrank')}
							value={searchDraft}
							onChange={(e) => setSearchDraft(e.target.value)}
							disabled={!!aiRun}
							placeholder={__('Search posts…', 'thinkrank')}
							className="tr-w-64"
						/>
						<Select
							label={__('Status', 'thinkrank')}
							value={filters.status}
							options={STATUS_OPTIONS}
							disabled={!!aiRun}
							onChange={(e) =>
								changeFilters({ status: e.target.value })
							}
							className="tr-w-48"
						/>
						<div className="tr-flex-1" />
						{dirtyIds.length > 0 && (
							<Button
								variant="primary"
								onClick={saveAll}
								loading={isSavingAll}
								disabled={!!aiRun}
							>
								{sprintf(
									/* translators: %d: number of changed rows. */
									__('Save all changed (%d)', 'thinkrank'),
									dirtyIds.length
								)}
							</Button>
						)}
					</div>

					{selectedCount > 0 && (
						<SelectionBar
							count={selectedCount}
							cap={aiCap}
							aiConfigured={aiConfigured}
							run={aiRun}
							onGenerate={runAi}
							onStop={() => {
								cancelAi.current = true;
							}}
							onClear={() => setSelected([])}
						/>
					)}

					{pending > 0 && (
						<Alert variant="info">
							{sprintf(
								/* translators: %d: posts still being analysed. */
								_n(
									'Analysing %d more post… Counts cover the posts analysed so far and update as it goes.',
									'Analysing %d more posts… Counts cover the posts analysed so far and update as it goes.',
									pending,
									'thinkrank'
								),
								pending
							)}
						</Alert>
					)}

					{loadError && (
						<Alert variant="error">
							{loadError}{' '}
							<Button variant="link" onClick={load}>
								{__('Try again', 'thinkrank')}
							</Button>
						</Alert>
					)}
				</div>

				{rows.length === 0 && !loadError ? (
					<div className="tr-px-6 tr-pb-8">
						<EmptyState
							title={
								filters.issue
									? __(
											'No posts with this problem',
											'thinkrank'
										)
									: __('No posts found', 'thinkrank')
							}
							description={
								filters.issue
									? __(
											'Every post in this list is clear of it.',
											'thinkrank'
										)
									: __(
											'Try another post type, status or search.',
											'thinkrank'
										)
							}
							action={
								hasNarrowedFilters ? (
									<Button
										variant="secondary"
										onClick={clearFilters}
									>
										{__('Clear filters', 'thinkrank')}
									</Button>
								) : null
							}
						/>
					</div>
				) : (
					<div className="tr-overflow-x-auto">
						<table className="tr-w-full tr-min-w-[940px] tr-text-sm tr-border-collapse">
							<colgroup>
								<col className="tr-w-10" />
								<col className="tr-w-[21%]" />
								<col className="tr-w-[23%]" />
								<col className="tr-w-[36%]" />
								<col className="tr-w-[12%]" />
								<col className="tr-w-20" />
							</colgroup>
							{/* Names the table so a screen reader can list it
							    among the page's tables, and says what it is
							    currently showing. Visually hidden: the card
							    header above already carries the title (#841).

							    screen-reader-text goes on an inner span, not on
							    the caption. On the caption it replaces
							    display:table-caption with block, and Chrome then
							    still reserves the class's 1px height, pushing
							    every body row down by a pixel. Measured: with the
							    class on the caption the first row sat at y=1024
							    against 1023 without it. A zero-box caption
							    wrapping a hidden span reads identically to
							    assistive tech and moves nothing. */}
							<caption
								style={{
									height: 0,
									padding: 0,
									margin: 0,
									border: 0,
								}}
							>
								<span className="screen-reader-text">
									{sprintf(
										/* translators: %d: number of posts listed. */
										_n(
											'Bulk snippets: %d post, with its SEO title, meta description and focus keyword.',
											'Bulk snippets: %d posts, each with its SEO title, meta description and focus keyword.',
											rows.length,
											'thinkrank'
										),
										rows.length
									)}
								</span>
							</caption>
							<thead className="tr-sticky tr-top-0 tr-z-10">
								<tr className="tr-border-y tr-border-secondary tr-bg-white tr-text-left tr-text-[11px] tr-font-semibold tr-uppercase tr-tracking-wider tr-text-tertiary [&>th]:tr-whitespace-nowrap">
									<th scope="col" className="tr-px-2 tr-py-2">
										<SelectAllCheckbox
											checked={allSelected}
											indeterminate={
												someSelected && !allSelected
											}
											disabled={
												!!aiRun ||
												editableIds.length === 0
											}
											onChange={() =>
												setSelected(
													allSelected
														? []
														: editableIds
												)
											}
										/>
									</th>
									<th scope="col" className="tr-px-2 tr-py-2">
										{__('Post', 'thinkrank')}
									</th>
									<th scope="col" className="tr-px-2 tr-py-2">
										{__('SEO title', 'thinkrank')}
									</th>
									<th scope="col" className="tr-px-2 tr-py-2">
										{__('Meta description', 'thinkrank')}
									</th>
									<th scope="col" className="tr-px-2 tr-py-2">
										{__('Focus keyword', 'thinkrank')}
									</th>
									<th scope="col" className="tr-px-2 tr-py-2">
										<span className="screen-reader-text">
											{__('Actions', 'thinkrank')}
										</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{rows.map((row) => (
									<SnippetRow
										key={row.post_id}
										row={row}
										edit={edits[row.post_id]}
										isAi={!!aiRows[row.post_id]}
										state={rowState[row.post_id]}
										selected={selected.includes(
											row.post_id
										)}
										focused={focusedId === row.post_id}
										limits={limits}
										// Rows about to be replaced by a list
										// request in flight are not editable.
										busy={!!aiRun || isRefreshing}
										onToggle={() =>
											toggleSelected(row.post_id)
										}
										onEdit={(field, value) =>
											setEdit(row.post_id, field, value)
										}
										onFocus={() =>
											setFocusedId(row.post_id)
										}
										onSave={() => saveRows([row.post_id])}
										onDiscard={() =>
											discardRow(row.post_id)
										}
									/>
								))}
							</tbody>
						</table>
					</div>
				)}

				{data && rows.length > 0 && (
					<Pagination
						page={data.page}
						totalPages={data.total_pages}
						total={data.total}
						perPage={data.per_page || PER_PAGE}
						disabled={!!aiRun || isRefreshing}
						onPageChange={(page) => changeFilters({ page })}
					/>
				)}
			</Card>

			{/* SERP preview for the row being edited: what this post would
			    look like in Google right now, unsaved edits included. */}
			{focusedRow && (
				<Card>
					<div className="tr-flex tr-items-start tr-justify-between tr-gap-3 tr-mb-2">
						<p className="tr-text-sm tr-font-medium tr-text-secondary tr-m-0">
							{sprintf(
								/* translators: %s: post title. */
								__('Search preview: %s', 'thinkrank'),
								focusedRow.post_title
							)}
						</p>
						<button
							type="button"
							onClick={() => setFocusedId(null)}
							className="tr-text-xs tr-text-tertiary tr-bg-transparent tr-border-0 tr-p-0 tr-cursor-pointer hover:tr-underline"
						>
							{__('Close', 'thinkrank')}
						</button>
					</div>
					<SearchPreview
						siteTitle={window.thinkrankAdmin?.siteName || ''}
						faviconURL={window.thinkrankAdmin?.faviconUrl || ''}
						permalink={focusedRow.permalink}
						title={
							(edits[focusedRow.post_id]?.title ??
								focusedRow.title) ||
							focusedRow.effective_title
						}
						description={
							(edits[focusedRow.post_id]?.description ??
								focusedRow.description) ||
							focusedRow.effective_description
						}
					/>
				</Card>
			)}
		</div>
	);
};

const SparkleIcon = ({ className }) => (
	<svg
		viewBox="0 0 16 16"
		fill="currentColor"
		className={className}
		aria-hidden="true"
	>
		<path d="M9.2 1.6a.4.4 0 0 0-.76 0l-.84 2.52a2.4 2.4 0 0 1-1.52 1.52L3.56 6.5a.4.4 0 0 0 0 .76l2.52.84a2.4 2.4 0 0 1 1.52 1.52l.84 2.52a.4.4 0 0 0 .76 0l.84-2.52a2.4 2.4 0 0 1 1.52-1.52l2.52-.84a.4.4 0 0 0 0-.76l-2.52-.84a2.4 2.4 0 0 1-1.52-1.52L9.2 1.6Z" />
		<path d="M4 10.2a.3.3 0 0 0-.57 0l-.4 1.2a1.2 1.2 0 0 1-.76.76l-1.2.4a.3.3 0 0 0 0 .57l1.2.4a1.2 1.2 0 0 1 .76.76l.4 1.2a.3.3 0 0 0 .57 0l.4-1.2a1.2 1.2 0 0 1 .76-.76l1.2-.4a.3.3 0 0 0 0-.57l-1.2-.4a1.2 1.2 0 0 1-.76-.76l-.4-1.2Z" />
	</svg>
);

/**
 * The bar that appears once rows are ticked: what is selected, what the AI
 * run would do to it, and the run itself.
 *
 * A run replaces the bar's contents rather than appearing beside them. While
 * it is going the only things that matter are how far along it is and how to
 * stop it, and the controls that started it would only invite a second click.
 *
 * @param {Object}   props              Component props.
 * @param {number}   props.count        Rows selected.
 * @param {number}   props.cap          Rows one run may process; 0 is unlimited.
 * @param {boolean}  props.aiConfigured Whether a provider is set up.
 * @param {?Object}  props.run          `{ done, total }` while generating.
 * @param {Function} props.onGenerate   Start a run.
 * @param {Function} props.onStop       Stop the run after the current row.
 * @param {Function} props.onClear      Drop the selection.
 */
const SelectionBar = ({
	count,
	cap,
	aiConfigured,
	run,
	onGenerate,
	onStop,
	onClear,
}) => {
	const capped = cap > 0 && count > cap;

	// Kept out of the JSX so the three cases stay readable as three cases.
	const hint = () => {
		if (!aiConfigured) {
			return __(
				'Add an AI provider to draft these with AI.',
				'thinkrank'
			);
		}
		if (capped) {
			return sprintf(
				/* translators: %d: rows one run may process. */
				__(
					'Runs the first %d. Nothing is saved until you review it.',
					'thinkrank'
				),
				cap
			);
		}
		return __('Nothing is saved until you review it.', 'thinkrank');
	};

	if (run) {
		const done = Math.min(run.done, run.total);
		return (
			<div className="tr-flex tr-flex-wrap tr-items-center tr-gap-x-4 tr-gap-y-2 tr-px-4 tr-py-3 tr-rounded-lg tr-border tr-border-brand/30 tr-bg-brand-bg">
				<span className="tr-text-sm tr-font-medium tr-text-primary">
					{sprintf(
						/* translators: 1: rows finished, 2: rows in this run. */
						__('Generating %1$d of %2$d', 'thinkrank'),
						Math.min(done + 1, run.total),
						run.total
					)}
				</span>
				<div
					className="tr-flex-1 tr-min-w-[8rem] tr-h-1.5 tr-rounded-full tr-bg-white tr-overflow-hidden"
					role="progressbar"
					aria-valuemin={0}
					aria-valuemax={run.total}
					aria-valuenow={done}
					aria-label={__('AI generation progress', 'thinkrank')}
				>
					<div
						className="tr-h-full tr-rounded-full tr-bg-brand tr-transition-all tr-duration-200"
						style={{
							width: `${Math.round((done / Math.max(1, run.total)) * 100)}%`,
						}}
					/>
				</div>
				<Button variant="secondary" size="sm" onClick={onStop}>
					{__('Stop', 'thinkrank')}
				</Button>
			</div>
		);
	}

	return (
		<div className="tr-flex tr-flex-wrap tr-items-center tr-gap-x-4 tr-gap-y-2 tr-px-4 tr-py-3 tr-rounded-lg tr-border tr-border-brand/30 tr-bg-brand-bg">
			<div className="tr-flex tr-items-baseline tr-gap-2">
				<span className="tr-text-sm tr-font-medium tr-text-primary">
					{sprintf(
						/* translators: %d: number of selected rows. */
						_n('%d selected', '%d selected', count, 'thinkrank'),
						count
					)}
				</span>
				<button
					type="button"
					onClick={onClear}
					className="tr-text-xs tr-text-secondary tr-bg-transparent tr-border-0 tr-p-0 tr-cursor-pointer hover:tr-underline"
				>
					{__('Clear', 'thinkrank')}
				</button>
			</div>

			<div className="tr-flex-1" />

			<span className="tr-text-xs tr-text-tertiary">{hint()}</span>

			{aiConfigured ? (
				<Button
					variant="ai"
					size="sm"
					icon={SparkleIcon}
					onClick={onGenerate}
				>
					{__('Generate with AI', 'thinkrank')}
				</Button>
			) : (
				<Button
					variant="secondary"
					size="sm"
					onClick={() => navigate('settings')}
				>
					{__('Set up a provider', 'thinkrank')}
				</Button>
			)}
		</div>
	);
};

const IssueChip = ({ label, count, active, onClick, disabled = false }) => (
	<button
		type="button"
		aria-pressed={active}
		onClick={onClick}
		disabled={disabled}
		className={classnames(
			'tr-inline-flex tr-items-center tr-gap-1.5 tr-h-8 tr-px-3 tr-rounded-full tr-border tr-text-xs tr-font-medium tr-cursor-pointer tr-transition-colors disabled:tr-opacity-50 disabled:tr-cursor-not-allowed',
			active
				? 'tr-border-brand tr-bg-brand-bg tr-text-brand'
				: 'tr-border-secondary tr-bg-white tr-text-secondary hover:tr-border-tertiary'
		)}
	>
		{label}
		<span
			className={classnames(
				'tr-rounded-full tr-px-1.5 tr-text-[11px]',
				active ? 'tr-bg-white' : 'tr-bg-line-2'
			)}
		>
			{count}
		</span>
	</button>
);

/**
 * The header checkbox. Half a page selected reads as half selected, which
 * only the DOM property can say — React has no `indeterminate` attribute.
 *
 * @param {Object}   props               Component props.
 * @param {boolean}  props.checked       Whether every selectable row is selected.
 * @param {boolean}  props.indeterminate Whether only some rows are selected.
 * @param {boolean}  props.disabled      Whether selection is unavailable.
 * @param {Function} props.onChange      Toggle handler.
 */
const SelectAllCheckbox = ({ checked, indeterminate, disabled, onChange }) => {
	const ref = useRef(null);

	useEffect(() => {
		if (ref.current) {
			ref.current.indeterminate = indeterminate;
		}
	}, [indeterminate]);

	return (
		<input
			ref={ref}
			id="tr-snippet-select-all"
			type="checkbox"
			aria-label={__('Select all on this page', 'thinkrank')}
			checked={checked}
			disabled={disabled}
			onChange={onChange}
		/>
	);
};

/**
 * Numbered pager for the table footer.
 *
 * @param {Object}   props              Component props.
 * @param {number}   props.page         Current page.
 * @param {number}   props.totalPages   Pages in the list.
 * @param {number}   props.total        Rows in the whole list.
 * @param {number}   props.perPage      Rows per page.
 * @param {boolean}  props.disabled     Whether paging is unavailable.
 * @param {Function} props.onPageChange Called with the page to move to.
 */
const Pagination = ({
	page,
	totalPages,
	total,
	perPage,
	disabled,
	onPageChange,
}) => {
	const { first, last } = pageRange(page, perPage, total);

	return (
		<div className="tr-flex tr-flex-wrap tr-items-center tr-justify-between tr-gap-3 tr-px-6 tr-py-4 tr-border-t tr-border-secondary">
			<span className="tr-text-sm tr-text-tertiary">
				{sprintf(
					/* translators: 1: first row on this page, 2: last row on this page, 3: rows in the whole list. */
					__('Showing %1$d–%2$d of %3$d posts', 'thinkrank'),
					first,
					last,
					total
				)}
			</span>

			{totalPages > 1 && (
				<nav
					className="tr-flex tr-items-center tr-gap-1"
					aria-label={__('Snippets pages', 'thinkrank')}
				>
					<Button
						variant="secondary"
						size="sm"
						disabled={page <= 1 || disabled}
						onClick={() => onPageChange(page - 1)}
					>
						{__('Previous', 'thinkrank')}
					</Button>
					{pageItems(page, totalPages).map((item) =>
						typeof item === 'number' ? (
							<Button
								key={item}
								variant={
									item === page ? 'primary' : 'secondary'
								}
								size="sm"
								aria-current={
									item === page ? 'page' : undefined
								}
								disabled={disabled}
								onClick={() => onPageChange(item)}
							>
								{item}
							</Button>
						) : (
							<span
								key={item}
								aria-hidden="true"
								className="tr-px-1 tr-text-sm tr-text-tertiary"
							>
								…
							</span>
						)
					)}
					<Button
						variant="secondary"
						size="sm"
						disabled={page >= totalPages || disabled}
						onClick={() => onPageChange(page + 1)}
					>
						{__('Next', 'thinkrank')}
					</Button>
				</nav>
			)}
		</div>
	);
};

/**
 * The path part of a permalink, for the line under a post's title. A URL the
 * browser cannot parse is shown as it came.
 *
 * @param {string} permalink Post permalink.
 * @return {string} Path with query string, or the input on failure.
 */
const permalinkPath = (permalink) => {
	try {
		const url = new URL(permalink);
		return `${url.pathname}${url.search}`;
	} catch {
		return permalink || '';
	}
};

// The shared Badge's `neutral` variant is white on white, which is right on a
// tinted card and invisible in a table cell. These chips are the row's own,
// sized to sit three-across in a narrow column.
const ISSUE_TONE = {
	poor: 'tr-bg-danger-bg tr-text-danger',
	warning: 'tr-bg-warning-bg tr-text-warning',
	neutral: 'tr-bg-line-2 tr-text-secondary',
};

const IssueBadge = ({ tone = 'neutral', children }) => (
	<span
		className={classnames(
			'tr-inline-flex tr-items-center tr-px-1.5 tr-py-0.5 tr-rounded',
			'tr-text-[11px] tr-font-medium tr-leading-4',
			ISSUE_TONE[tone] || ISSUE_TONE.neutral
		)}
	>
		{children}
	</span>
);

/**
 * A character count that stays out of the way. One under every field in every
 * row is noise, so it fades in with its cell on hover or focus — except when
 * the value is over the limit, which is the one time it has something to say
 * unprompted.
 *
 * It floats in the corner of its cell rather than sitting under the field: a
 * line reserved for something invisible is 18px on every row of the table, and
 * the field's own padding leaves room for it in the corner.
 *
 * @param {Object} props       Component props.
 * @param {string} props.value The value being counted.
 * @param {number} props.max   The limit.
 */
const FieldCounter = ({ value, max }) => (
	<CharacterCounter
		value={value}
		max={max}
		className={classnames(
			'tr-absolute tr-bottom-0.5 tr-right-1 tr-px-1 tr-rounded',
			'tr-bg-white/90 tr-text-[11px] tr-transition-opacity tr-pointer-events-none',
			(value || '').length > max
				? 'tr-opacity-100'
				: 'tr-opacity-0 group-hover:tr-opacity-100 group-focus-within:tr-opacity-100'
		)}
	/>
);

const CheckIcon = () => (
	<svg
		width="12"
		height="12"
		viewBox="0 0 16 16"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<path d="M3 8.5 6.5 12 13 4.5" />
	</svg>
);

// Exported for the markup tests: the table semantics a screen reader depends on
// are invisible to every other test here, and three of them were missing (#841).
export const SnippetRow = ({
	row,
	edit,
	isAi,
	state,
	selected,
	focused,
	limits,
	busy,
	onToggle,
	onEdit,
	onFocus,
	onSave,
	onDiscard,
}) => {
	const value = (field) => edit?.[field] ?? row[field] ?? '';
	const changed = Object.keys(changedFields(row, edit)).length > 0;
	const error = state && typeof state === 'object' ? state.error : null;
	const disabled = !row.can_edit || state === 'saving' || busy;

	return (
		<tr
			className={classnames(
				'tr-border-b tr-border-secondary tr-align-top tr-transition-colors',
				{
					// An unsaved edit outranks selection: it is the thing the
					// user still has to act on.
					'tr-bg-brand-bg/40': changed,
					'tr-bg-line-2/40': !changed && selected,
					'hover:tr-bg-line-2/30': !changed && !selected,
					'tr-animate-pulse': state === 'generating',
				}
			)}
		>
			<td
				className={classnames(
					// The cells either side hold a control whose own border
					// and padding push its first line 11px down; these two
					// hold plain text, so they match it by hand.
					'tr-px-2 tr-pt-[15px] tr-pb-2 tr-border-l-2',
					// The row the preview below the table is showing.
					focused ? 'tr-border-l-brand' : 'tr-border-l-transparent'
				)}
			>
				<input
					id={`tr-snippet-select-${row.post_id}`}
					type="checkbox"
					aria-label={sprintf(
						/* translators: %s: post title. */
						__('Select %s', 'thinkrank'),
						row.post_title
					)}
					checked={selected}
					disabled={!row.can_edit || busy}
					onChange={onToggle}
				/>
			</td>
			{/* The row header, not a plain cell: with this in place a screen
			    reader in table mode announces the post title on entering any
			    cell in the row, which is the context a bulk editor needs and
			    had no way to get (#841). `tr-text-left tr-font-normal` undoes
			    the centring and bolding a browser gives a <th>, so nothing
			    changes visually. */}
			<th
				scope="row"
				className="tr-px-2 tr-pt-[15px] tr-pb-2 tr-text-left tr-font-normal"
			>
				<div className="tr-flex tr-flex-wrap tr-items-center tr-gap-x-2 tr-gap-y-1">
					<a
						href={row.edit_url}
						className="tr-font-medium tr-text-primary tr-no-underline hover:tr-underline"
					>
						{row.post_title || __('(no title)', 'thinkrank')}
					</a>
					{row.status !== 'publish' && (
						<IssueBadge tone="neutral">{row.status}</IssueBadge>
					)}
					{!row.can_edit && (
						<IssueBadge tone="neutral">
							{__('Read only', 'thinkrank')}
						</IssueBadge>
					)}
				</div>
				<a
					href={row.permalink}
					target="_blank"
					rel="noreferrer"
					title={row.permalink}
					className="tr-block tr-text-xs tr-text-tertiary tr-truncate tr-no-underline hover:tr-underline"
				>
					{permalinkPath(row.permalink)}
				</a>
				{row.issues.length > 0 ? (
					<div className="tr-flex tr-flex-wrap tr-gap-1 tr-mt-1">
						{row.issues.map((issue) => (
							<IssueBadge
								key={issue}
								tone={ISSUE_VARIANT[issue] || 'neutral'}
							>
								{issue === 'noindex' && row.noindex_source
									? sprintf(
											/* translators: %s: where noindex is set (post, post_type, site). */
											__('Noindex (%s)', 'thinkrank'),
											{
												post: __('post', 'thinkrank'),
												post_type: __(
													'post type',
													'thinkrank'
												),
												site: __('site', 'thinkrank'),
											}[row.noindex_source] ||
												row.noindex_source
										)
									: ISSUE_LABELS[issue]}
							</IssueBadge>
						))}
					</div>
				) : (
					<span className="tr-inline-flex tr-items-center tr-gap-1 tr-mt-1 tr-text-xs tr-text-success">
						<CheckIcon />
						{__('No problems', 'thinkrank')}
					</span>
				)}
			</th>
			<td className="tr-px-2 tr-py-2 tr-group tr-relative">
				{/* A textarea, not a text input: 60 characters do not fit on
				    one line in this column, and a title you cannot read is
				    not one you can check. It grows instead of scrolling, and
				    Enter is swallowed so it stays a single line of text. */}
				<Textarea
					variant="ghost"
					autoGrow
					aria-label={sprintf(
						/* translators: 1: field name, 2: post title. */
						__('%1$s for %2$s', 'thinkrank'),
						__('SEO title', 'thinkrank'),
						row.post_title || __('(no title)', 'thinkrank')
					)}
					id={`tr-snippet-title-${row.post_id}`}
					rows={1}
					value={value('title')}
					// An empty field inherits the template; show what it renders.
					placeholder={row.effective_title}
					disabled={disabled}
					onFocus={onFocus}
					onKeyDown={(e) => e.key === 'Enter' && e.preventDefault()}
					onChange={(e) => onEdit('title', e.target.value)}
				/>
				<FieldCounter
					value={value('title') || row.effective_title}
					max={limits.title_max}
				/>
			</td>
			<td className="tr-px-2 tr-py-2 tr-group tr-relative">
				<Textarea
					variant="ghost"
					autoGrow
					aria-label={sprintf(
						/* translators: 1: field name, 2: post title. */
						__('%1$s for %2$s', 'thinkrank'),
						__('Meta description', 'thinkrank'),
						row.post_title || __('(no title)', 'thinkrank')
					)}
					id={`tr-snippet-description-${row.post_id}`}
					rows={2}
					value={value('description')}
					placeholder={row.effective_description}
					disabled={disabled}
					onFocus={onFocus}
					onChange={(e) => onEdit('description', e.target.value)}
				/>
				<FieldCounter
					value={value('description') || row.effective_description}
					max={limits.description_max}
				/>
			</td>
			<td className="tr-px-2 tr-py-2">
				<Textarea
					variant="ghost"
					autoGrow
					aria-label={sprintf(
						/* translators: 1: field name, 2: post title. */
						__('%1$s for %2$s', 'thinkrank'),
						__('Focus keyword', 'thinkrank'),
						row.post_title || __('(no title)', 'thinkrank')
					)}
					id={`tr-snippet-keyword-${row.post_id}`}
					rows={1}
					value={value('focus_keyword')}
					placeholder={__('None', 'thinkrank')}
					disabled={disabled}
					onFocus={onFocus}
					onKeyDown={(e) => e.key === 'Enter' && e.preventDefault()}
					onChange={(e) => onEdit('focus_keyword', e.target.value)}
				/>
			</td>
			<td className="tr-px-2 tr-pt-[11px] tr-pb-2">
				<div className="tr-flex tr-flex-col tr-items-end tr-gap-1.5">
					{isAi && changed && (
						<Badge variant="info" className="tr-text-[11px]">
							{__('AI draft', 'thinkrank')}
						</Badge>
					)}
					{changed && (
						<>
							<Button
								variant="primary"
								size="sm"
								onClick={onSave}
								loading={state === 'saving'}
								disabled={busy}
							>
								{__('Save', 'thinkrank')}
							</Button>
							<button
								type="button"
								onClick={onDiscard}
								disabled={state === 'saving' || busy}
								className="tr-text-xs tr-text-tertiary tr-bg-transparent tr-border-0 tr-p-0 tr-cursor-pointer hover:tr-underline"
							>
								{__('Discard', 'thinkrank')}
							</button>
						</>
					)}
					{error && (
						<span
							className="tr-text-xs tr-text-danger"
							role="alert"
						>
							{error}
						</span>
					)}
				</div>
			</td>
		</tr>
	);
};

export default BulkSnippetsTab;
