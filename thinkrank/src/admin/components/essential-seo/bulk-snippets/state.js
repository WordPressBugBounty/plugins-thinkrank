/**
 * Bulk Snippets — pure state helpers.
 *
 * Kept free of React and of the DOM beyond the URL they are handed, so the
 * rules the screen depends on (what survives a reload, what counts as an
 * unsaved change, how an AI suggestion merges into an edit) are unit-tested
 * rather than trusted (#727).
 *
 * @package
 * @since 2.8.0
 */

export const ISSUES = [
	'empty_title',
	'empty_description',
	'title_too_short',
	'title_too_long',
	'description_too_short',
	'description_too_long',
	'no_focus_keyword',
	'noindex',
	'duplicate_title',
	'duplicate_description',
];

export const STATUSES = ['publish', 'draft', 'any'];

// Rows per page. Ten fits on one screen with the fields open, so a page can
// be reviewed and saved without scrolling past the row being edited.
export const PER_PAGE = 10;

export const EDITABLE_FIELDS = ['title', 'description', 'focus_keyword'];

// Query-string keys. Prefixed so they cannot collide with the SPA's own
// page / nav_section / nav_item parameters on the same URL.
const PARAM = {
	postType: 'snippet_type',
	issue: 'snippet_issue',
	status: 'snippet_status',
	search: 'snippet_search',
	page: 'snippet_page',
};

/**
 * Read the filters from a URL, falling back to safe defaults for anything
 * missing or not one of the known values — a hand-edited or stale link must
 * never produce a request the endpoint rejects.
 *
 * @param {string}   search    `window.location.search`.
 * @param {string[]} postTypes Post type slugs the screen offers.
 * @return {{postType: string, issue: string, status: string, search: string, page: number}} Filters.
 */
export function readFilters(search, postTypes) {
	const params = new URLSearchParams(search || '');

	const postType = params.get(PARAM.postType);
	const issue = params.get(PARAM.issue);
	const status = params.get(PARAM.status);
	const page = parseInt(params.get(PARAM.page), 10);

	return {
		postType:
			postType && postTypes.includes(postType)
				? postType
				: postTypes[0] || 'post',
		issue: issue && ISSUES.includes(issue) ? issue : '',
		status: status && STATUSES.includes(status) ? status : 'publish',
		search: (params.get(PARAM.search) || '').slice(0, 200),
		page: Number.isFinite(page) && page > 0 ? page : 1,
	};
}

/**
 * Write the filters into a URL, keeping every parameter that is not ours and
 * leaving defaults out so a plain visit keeps a plain URL.
 *
 * @param {string} href    Current `window.location.href`.
 * @param {Object} filters Filters from {@see readFilters}.
 * @return {string} The URL to `replaceState` to.
 */
export function writeFilters(href, filters) {
	const url = new URL(href);
	const set = (key, value, fallback) => {
		if (value === undefined || value === null || value === fallback) {
			url.searchParams.delete(key);
		} else {
			url.searchParams.set(key, String(value));
		}
	};

	set(PARAM.postType, filters.postType, undefined);
	set(PARAM.issue, filters.issue, '');
	set(PARAM.status, filters.status, 'publish');
	set(PARAM.search, filters.search, '');
	set(PARAM.page, filters.page, 1);

	return url.toString();
}

/**
 * The fields of an edit that differ from what is stored.
 *
 * An edit typed back to the stored value is not a change, so the row is not
 * dirty and "Save all changed" does not send it.
 *
 * @param {Object} row  Row as loaded.
 * @param {Object} edit Pending edits for the row, keyed by field.
 * @return {Object} Only the changed fields.
 */
export function changedFields(row, edit) {
	const changed = {};
	if (!row || !edit) {
		return changed;
	}

	EDITABLE_FIELDS.forEach((field) => {
		if (
			Object.prototype.hasOwnProperty.call(edit, field) &&
			(edit[field] ?? '') !== (row[field] ?? '')
		) {
			changed[field] = edit[field];
		}
	});

	return changed;
}

/**
 * Merge an AI suggestion into a row's pending edit.
 *
 * Title and description are the point of the run and are proposed. The focus
 * keyword is proposed only when the post has none: a keyword the user chose
 * is a decision, and the suggestion was generated to serve it.
 *
 * @param {Object} row        Row as loaded.
 * @param {Object} edit       Existing pending edit (may be undefined).
 * @param {Object} suggestion `{ title, description, focus_keyword }`.
 * @return {Object} The new pending edit.
 */
export function mergeSuggestion(row, edit, suggestion) {
	const next = { ...(edit || {}) };

	if (suggestion.title) {
		next.title = suggestion.title;
	}
	if (suggestion.description) {
		next.description = suggestion.description;
	}
	if (suggestion.focus_keyword && !(row.focus_keyword || '').trim()) {
		next.focus_keyword = suggestion.focus_keyword;
	}

	return next;
}

/**
 * Which selected rows one AI run should process, respecting the cap.
 *
 * @param {number[]} selectedIds Selected post IDs, in table order.
 * @param {number}   cap         Rows per run; 0 or less means unlimited.
 * @return {number[]} IDs to run.
 */
export function capRun(selectedIds, cap) {
	if (!cap || cap <= 0) {
		return selectedIds.slice();
	}
	return selectedIds.slice(0, cap);
}

/**
 * Clamp a page number to a list that turned out to be shorter than it.
 *
 * A shared link, the back button or a filter that narrowed the list can all
 * ask for a page past the end, which the server answers with no rows at all.
 * Stepping back to the last real page shows the list instead of an empty
 * table the user cannot explain.
 *
 * @param {number} page       Requested page.
 * @param {number} totalPages Pages the list actually has.
 * @return {number} A page within the list.
 */
export function clampPage(page, totalPages) {
	const requested = Math.floor(Number(page));

	if (!Number.isFinite(requested) || requested < 1) {
		return 1;
	}

	return Math.min(
		requested,
		Math.max(1, Math.floor(Number(totalPages)) || 1)
	);
}

/**
 * The entries a numbered pager should render: the first and last page, the
 * pages either side of the current one, and a gap marker wherever pages were
 * left out.
 *
 * A gap of exactly one page is filled with that page instead of an ellipsis —
 * "1 … 3 4 5" hides a page the ellipsis is no shorter than.
 *
 * Gap markers are strings (`gap-2`), so a caller can tell them from page
 * numbers and still use them as React keys.
 *
 * @param {number} current Current page.
 * @param {number} total   Total pages.
 * @param {number} spread  Pages to show either side of the current one.
 * @return {Array<number|string>} Page numbers and gap markers, in order.
 */
export function pageItems(current, total, spread = 1) {
	const pages = Math.max(0, Math.floor(Number(total)) || 0);
	if (pages < 1) {
		return [];
	}

	const page = clampPage(current, pages);
	const shown = new Set([1, pages]);
	for (let i = page - spread; i <= page + spread; i++) {
		if (i >= 1 && i <= pages) {
			shown.add(i);
		}
	}

	const sorted = [...shown].sort((a, b) => a - b);
	const items = [];
	sorted.forEach((number, index) => {
		const previous = sorted[index - 1];
		if (previous !== undefined && number - previous > 1) {
			items.push(
				number - previous === 2 ? previous + 1 : `gap-${previous}`
			);
		}
		items.push(number);
	});

	return items;
}

/**
 * The range of rows a page covers, for "Showing 1–10 of 34".
 *
 * @param {number} page    Current page.
 * @param {number} perPage Rows per page.
 * @param {number} total   Rows in the whole list.
 * @return {{first: number, last: number}} 1-based, inclusive; `{0, 0}` when empty.
 */
export function pageRange(page, perPage, total) {
	const rows = Math.max(0, Math.floor(Number(total)) || 0);
	if (rows === 0) {
		return { first: 0, last: 0 };
	}

	const size = Math.max(1, Math.floor(Number(perPage)) || 1);
	const first = (Math.max(1, Math.floor(Number(page)) || 1) - 1) * size + 1;

	// A page past the end has no rows to describe; report the last page's.
	if (first > rows) {
		return { first: Math.max(1, rows - ((rows - 1) % size)), last: rows };
	}

	return { first, last: Math.min(rows, first + size - 1) };
}
