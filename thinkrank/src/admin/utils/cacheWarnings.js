/**
 * Cache warnings gathered across a batched run.
 *
 * Endpoints that write something a page cache may already hold, such as the
 * Media Library alt fill, return `cache.warnings`: sentences naming what the
 * user still has to clear by hand. A batched run gets one set per request, and
 * most batches repeat the same sentence, so they are merged without
 * duplicates and shown once when the run ends (#926).
 *
 * @package
 * @since 2.15.0
 */

/**
 * Add one response's cache warnings to those already collected.
 *
 * @param {string[]} collected Warnings gathered so far.
 * @param {Object}   response  A batch response that may carry `cache.warnings`.
 * @return {string[]} A new array: the collected warnings, then any new ones in order.
 */
export const collectCacheWarnings = (collected, response) => {
	const incoming = response?.cache?.warnings;

	if (!Array.isArray(incoming)) {
		return collected;
	}

	const merged = [...collected];

	incoming.forEach((warning) => {
		if (
			typeof warning === 'string' &&
			warning !== '' &&
			!merged.includes(warning)
		) {
			merged.push(warning);
		}
	});

	return merged;
};
