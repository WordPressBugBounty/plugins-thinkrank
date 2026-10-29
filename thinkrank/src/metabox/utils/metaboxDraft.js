/**
 * Local recovery draft for the SEO drawer.
 *
 * The drawer keeps unsaved edits in localStorage so a closed tab or a crash
 * does not lose them. The draft used to be a snapshot of the whole form,
 * written whenever the editor was open and restored over the server values
 * unless the post's modified time was newer. Out-of-band writers (MCP
 * `update-post-seo`, imports, bulk edits, Auto AI) change the SEO meta without
 * bumping post_modified, so a snapshot taken before such a write came back
 * over the new values, and the next save wrote the stale copy to the
 * database (#986).
 *
 * A draft now holds only the fields the user changed, each with the server
 * value it was edited from. A field is restored only while the server still
 * holds that same value, which catches every writer without relying on
 * post_modified.
 *
 * Every draft also names the editor instance that wrote it. Two tabs on the
 * same post share one key, so a tab removes the stored draft only while it
 * still holds that tab's own name, never one another tab wrote since.
 *
 * @package
 * @since 2.15.0
 */

import { withoutServerOwnedFields } from './draftFields';

/**
 * Draft format version. Drafts without it predate the baseline and are
 * discarded, since there is no way to tell whether they are stale.
 */
export const DRAFT_VERSION = 2;

/**
 * How long a draft stays restorable.
 */
export const DRAFT_MAX_AGE_MS = 24 * 60 * 60 * 1000;

/**
 * localStorage key for a post's draft.
 *
 * @param {number|string} postId Post ID.
 * @return {string} Storage key.
 */
export const draftKey = (postId) => `thinkrank_metabox_${postId}`;

/**
 * A name for one editor instance, stored with each draft it writes.
 *
 * @return {string} Random identifier.
 */
export const newDraftOwner = () =>
	`${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;

/**
 * Structural equality for form values (strings, numbers, booleans, arrays and
 * plain objects such as the robots meta maps). Object key order is ignored.
 *
 * @param {*} a First value.
 * @param {*} b Second value.
 * @return {boolean} True when equal.
 */
export const isSameValue = (a, b) => {
	if (a === b) {
		return true;
	}
	if (
		a === null ||
		b === null ||
		typeof a !== 'object' ||
		typeof b !== 'object'
	) {
		return false;
	}
	if (Array.isArray(a) !== Array.isArray(b)) {
		return false;
	}
	const keysA = Object.keys(a);
	const keysB = Object.keys(b);
	if (keysA.length !== keysB.length) {
		return false;
	}
	return keysA.every(
		(key) =>
			Object.prototype.hasOwnProperty.call(b, key) &&
			isSameValue(a[key], b[key])
	);
};

/**
 * The part of the form a draft may carry.
 *
 * @param {Object} formData Form data.
 * @return {Object} Draftable fields.
 */
export const draftableFields = (formData) => withoutServerOwnedFields(formData);

/**
 * Draftable fields whose value differs from the baseline.
 *
 * @param {Object} formData Current form data.
 * @param {Object} baseline Draftable fields as last loaded from or saved to
 *                          the server.
 * @return {string[]} Changed field names.
 */
export const changedFields = (formData, baseline) => {
	const current = draftableFields(formData);
	const base = baseline || {};
	return Object.keys(current).filter(
		(key) => !isSameValue(current[key], base[key])
	);
};

/**
 * Build the draft to store, or null when nothing was edited.
 *
 * @param {Object}        formData Current form data.
 * @param {Object}        baseline Server values the form started from.
 * @param {number|string} postId   Post ID.
 * @param {number}        now      Timestamp in ms.
 * @param {?string}       owner    Editor instance writing the draft.
 * @return {?Object} Draft, or null when the form matches the baseline.
 */
export const buildDraft = (
	formData,
	baseline,
	postId,
	now = Date.now(),
	owner = null
) => {
	const keys = changedFields(formData, baseline);
	if (!keys.length) {
		return null;
	}
	const fields = {};
	const base = {};
	keys.forEach((key) => {
		fields[key] = formData[key];
		base[key] = baseline ? baseline[key] : undefined;
	});
	return {
		v: DRAFT_VERSION,
		postId,
		...(owner ? { owner } : {}),
		timestamp: now,
		formData: fields,
		baseline: base,
	};
};

/**
 * Work out which draft fields can safely be restored.
 *
 * A field comes back only when the server still holds the value the draft was
 * edited from, and only when it would actually change the form. Drafts from an
 * older format, for another post, or past their age are not restored at all.
 *
 * @param {*}             draft      Parsed draft (anything; it is validated).
 * @param {Object}        serverForm Form data as built from the server values.
 * @param {number|string} postId     Post being edited.
 * @param {number}        now        Timestamp in ms.
 * @return {{restore: Object, discard: boolean}} Fields to apply, and whether
 *   the stored draft should be removed because none of it is restorable.
 */
export const resolveDraft = (draft, serverForm, postId, now = Date.now()) => {
	const nothing = { restore: {}, discard: true };

	if (
		!draft ||
		typeof draft !== 'object' ||
		draft.v !== DRAFT_VERSION ||
		!draft.formData ||
		typeof draft.formData !== 'object' ||
		!draft.baseline ||
		typeof draft.baseline !== 'object'
	) {
		return nothing;
	}
	if (
		draft.postId !== undefined &&
		postId !== undefined &&
		String(draft.postId) !== String(postId)
	) {
		return nothing;
	}
	const age = now - Number(draft.timestamp);
	if (!Number.isFinite(age) || age < 0 || age >= DRAFT_MAX_AGE_MS) {
		return nothing;
	}

	const server = draftableFields(serverForm);
	const restore = {};
	Object.keys(draftableFields(draft.formData)).forEach((key) => {
		if (
			!Object.prototype.hasOwnProperty.call(server, key) ||
			!Object.prototype.hasOwnProperty.call(draft.baseline, key)
		) {
			return;
		}
		// Someone changed this field on the server since the draft was
		// written: the server value wins.
		if (!isSameValue(server[key], draft.baseline[key])) {
			return;
		}
		if (isSameValue(server[key], draft.formData[key])) {
			return;
		}
		restore[key] = draft.formData[key];
	});

	return { restore, discard: Object.keys(restore).length === 0 };
};

/**
 * Read a post's draft from localStorage.
 *
 * @param {number|string} postId Post ID.
 * @return {*} Parsed draft, or null when absent or unreadable.
 */
export const readDraft = (postId) => {
	try {
		const raw = window.localStorage.getItem(draftKey(postId));
		return raw ? JSON.parse(raw) : null;
	} catch (e) {
		return null;
	}
};

/**
 * Store the draft for the current form. Nothing is written when the form
 * matches the baseline, so merely opening the editor leaves no draft behind.
 *
 * @param {number|string} postId   Post ID.
 * @param {Object}        formData Current form data.
 * @param {Object}        baseline Server values the form started from.
 * @param {?string}       owner    Editor instance writing the draft.
 * @return {boolean} True when a draft was written.
 */
export const writeDraft = (postId, formData, baseline, owner = null) => {
	try {
		const draft = buildDraft(formData, baseline, postId, Date.now(), owner);
		if (!draft) {
			return false;
		}
		window.localStorage.setItem(draftKey(postId), JSON.stringify(draft));
		return true;
	} catch (e) {
		// Storage full or blocked: recovery is best-effort.
		return false;
	}
};

/**
 * Remove a post's draft, but only the one the given owner wrote.
 *
 * Another tab may have replaced the draft since this one wrote or restored
 * it; that draft holds the other tab's unsaved work and stays.
 *
 * @param {number|string} postId Post ID.
 * @param {?string}       owner  Owner of the draft to remove, as stored in
 *                               it (null for a draft that names none).
 */
export const clearDraft = (postId, owner = null) => {
	try {
		const stored = readDraft(postId);
		if (stored && (stored.owner ?? null) !== (owner ?? null)) {
			return;
		}
		window.localStorage.removeItem(draftKey(postId));
	} catch (e) {
		// Storage blocked: nothing to remove.
	}
};
