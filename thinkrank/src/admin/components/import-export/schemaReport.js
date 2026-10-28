/**
 * Schema import report
 *
 * Folds the schema counters the migrate route returns into the lines the
 * import card shows once a migration finishes (#1286). Before this, a Rank
 * Math import finished with "Migration complete" while it had dropped every
 * Recipe, JobPosting and Course schema, every Schema Template and every
 * per-post-type default, and staged the rest in the editor without saying
 * that it needs deploying.
 *
 * @package
 * @since 2.18.0
 */

import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Merge one migrate response into the running schema totals.
 *
 * @param {Object} totals Totals so far ({staged, unsupported, notImported}).
 * @param {Object} result One /import/migrate response.
 * @return {Object} New totals.
 */
export const addSchemaResult = (totals, result) => {
	const next = {
		staged: totals.staged + (Number(result?.schema_staged) || 0),
		unsupported: { ...totals.unsupported },
		notImported: totals.notImported,
	};

	Object.entries(result?.schema_unsupported || {}).forEach(
		([type, count]) => {
			next.unsupported[type] =
				(next.unsupported[type] || 0) + (Number(count) || 0);
		}
	);

	if (Array.isArray(result?.schema_not_imported)) {
		next.notImported = result.schema_not_imported;
	}

	return next;
};

export const EMPTY_SCHEMA_TOTALS = {
	staged: 0,
	unsupported: {},
	notImported: [],
};

/**
 * Lines naming the schema an import left behind.
 *
 * The settings record's site-wide counts win over the per-chunk ones, which
 * only cover the posts that were migrated; the chunk counts are the fallback
 * for a snapshot exported before the site-wide counts existed.
 *
 * @param {Object} totals Schema totals.
 * @return {string[]} One line per bucket; [] when nothing was left behind.
 */
export const schemaNotImportedLines = (totals) => {
	const buckets = totals?.notImported || [];
	const lines = buckets.map((bucket) =>
		sprintf(
			/* translators: 1: what was not imported, 2: how many. */
			__('%1$s: %2$d', 'thinkrank'),
			bucket.label,
			bucket.count
		)
	);

	const hasSiteWideTypes = buckets.some(
		(bucket) => bucket.key === 'unsupported_post_schemas'
	);
	const types = Object.keys(totals?.unsupported || {});
	if (!hasSiteWideTypes && types.length > 0) {
		lines.unshift(
			sprintf(
				/* translators: %s: comma-separated schema types, each with a post count. */
				__(
					'Post schema types with no ThinkRank equivalent: %s',
					'thinkrank'
				),
				types
					.map((type) => `${type} (${totals.unsupported[type]})`)
					.join(', ')
			)
		);
	}

	return lines;
};

/**
 * The sentence about schema staged in the editor, or '' when there is none.
 *
 * @param {Object} totals Schema totals.
 * @return {string} Sentence.
 */
export const schemaStagedNote = (totals) => {
	const staged = totals?.staged || 0;
	if (staged <= 0) {
		return '';
	}

	return sprintf(
		/* translators: %d: number of posts. */
		_n(
			'%d post got its schema type and fields in the editor. Open the Schema tab on that post and deploy it to publish the schema.',
			'%d posts got their schema type and fields in the editor. Open the Schema tab on each post and deploy it to publish the schema.',
			staged,
			'thinkrank'
		),
		staged
	);
};
