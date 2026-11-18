/**
 * Build the request body for saving one Social Platforms card.
 *
 * Empty fields are normally dropped so an untouched value cannot clobber what
 * is stored, and a still-masked placeholder (`a1b2XXXX`) is dropped so it
 * cannot overwrite the encrypted secret behind it. Two exceptions:
 *
 *  - a clearable field (a search-engine verification code) the user emptied is
 *    sent as '' so the server removes it and the tag comes off the page;
 *  - clearable fields are public and never masked, so XXXX inside one is part
 *    of the code, not a placeholder.
 *
 * Any other field the user emptied cannot be cleared through this route, so it
 * is listed in `unsentRemovals` for the caller to report rather than settle as
 * if it had been saved.
 *
 * @param {string[]}               keys          Fields owned by the card.
 * @param {Object<string, string>} values        Current field values.
 * @param {Set<string>}            dirtyKeys     Fields edited since load/save.
 * @param {string[]}               clearableKeys Fields an empty value may clear.
 * @return {{settings: Object<string, string>, unsentRemovals: string[]}} Payload and dropped removals.
 */
export function buildSocialPlatformCardPayload(
	keys,
	values,
	dirtyKeys,
	clearableKeys
) {
	const settings = {};
	const unsentRemovals = [];

	keys.forEach((key) => {
		const value = values[key];
		const clearable = clearableKeys.includes(key);

		if (!value || !value.trim()) {
			if (dirtyKeys.has(key)) {
				if (clearable) {
					settings[key] = '';
				} else {
					unsentRemovals.push(key);
				}
			}
			return;
		}

		if (!clearable && value.includes('XXXX')) {
			return;
		}

		settings[key] = value;
	});

	return { settings, unsentRemovals };
}

/**
 * Merge what the server stored after a save into the current field values.
 *
 * The save route normalises (a pasted <meta> tag is stored as its code), so the
 * fields adopt the stored value. But only for the fields that request sent, and
 * only while a field still holds the value it was sent with: the response
 * carries every platform's settings, and adopting all of it replaced unsaved
 * edits on other cards, and edits typed while the request was in flight, with
 * the old stored values.
 *
 * @param {Object<string, string>} current  Field values now.
 * @param {Object<string, string>} snapshot Field values when the save started.
 * @param {Object<string, string>} sent     The request's settings payload.
 * @param {Object<string, string>} stored   Settings from the save response.
 * @return {Object<string, string>} Field values with the stored ones adopted.
 */
export function adoptStoredSocialSettings(current, snapshot, sent, stored) {
	if (!stored || typeof stored !== 'object') {
		return current;
	}

	const next = { ...current };

	Object.keys(sent).forEach((key) => {
		if (
			Object.prototype.hasOwnProperty.call(stored, key) &&
			current[key] === snapshot[key]
		) {
			next[key] = stored[key];
		}
	});

	return next;
}

/**
 * The fields a finished save may mark clean.
 *
 * A field is settled only when it still holds the value it had when the save
 * started; one edited while the request was in flight has an edit that was not
 * sent, so it stays dirty. An emptied field the route cannot clear stays dirty
 * as well.
 *
 * @param {string[]}               keys           Fields owned by the card.
 * @param {Object<string, string>} current        Field values now.
 * @param {Object<string, string>} snapshot       Field values when the save started.
 * @param {string[]}               unsentRemovals Emptied fields that were not sent.
 * @return {string[]} Fields to remove from the dirty set.
 */
export function settledSocialPlatformKeys(
	keys,
	current,
	snapshot,
	unsentRemovals
) {
	return keys.filter(
		(key) => !unsentRemovals.includes(key) && current[key] === snapshot[key]
	);
}

/**
 * The field messages a refused Social Platforms save carries, joined.
 *
 * The route answers a malformed or code-less verification value with a 400
 * whose `errors` maps each field to its message, and apiFetch rejects with
 * that body. Without reading it the screen could only say "please try
 * again", which is no help when retrying sends the same value (#631).
 *
 * @param {*} error What apiFetch rejected with.
 * @return {string} The messages, or '' when there are none.
 */
export function socialPlatformSaveErrors(error) {
	const errors = error?.errors ?? error?.data?.errors;
	if (!errors || typeof errors !== 'object') {
		return '';
	}

	return Object.values(errors)
		.filter((message) => typeof message === 'string' && message !== '')
		.join(' ');
}
