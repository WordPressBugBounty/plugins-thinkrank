/**
 * Fields the local metabox draft must never carry.
 *
 * Each of these can change without the post being saved. The per-field
 * baseline check in metaboxDraft.js would now catch that too, but they stay
 * out of the draft altogether:
 *
 * - Focus keywords are written server-side by imports and bulk edits.
 * - The redirect lives in ThinkRank Pro's rules table, which the Redirections
 *   manager, an import or an MCP agent can change at any time.
 *
 * A stale copy restored over the server value is not merely shown: the
 * metabox posts its hidden fields on the next save, so an empty redirect in an
 * old draft deletes the rule that was added since.
 */
export const SERVER_OWNED_FIELDS = [
	'thinkrank_focus_keyword',
	'thinkrank_focus_keywords',
	'thinkrank_redirect_url',
	'thinkrank_redirect_type',
];

/**
 * Copy of form data without the server-owned fields.
 *
 * @param {Object} data Form data.
 * @return {Object} Data safe to store in, or restore from, a local draft.
 */
export const withoutServerOwnedFields = (data) => {
	const copy = { ...(data || {}) };
	SERVER_OWNED_FIELDS.forEach((key) => {
		delete copy[key];
	});
	return copy;
};
