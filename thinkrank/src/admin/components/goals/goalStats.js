/**
 * SEO Goals: figures drawn from the feed, shared by the Goals screen and
 * the Dashboard card (#728).
 *
 * @package
 * @since 2.22.0
 */

/**
 * Checklist items across every section, each goal counted once.
 *
 * @param {Object} checklists Section => items, from the feed.
 * @return {{done: number, total: number}} Progress.
 */
export const checklistProgress = (checklists = {}) => {
	const seen = new Map();
	Object.values(checklists || {}).forEach((items) => {
		(items || []).forEach((item) => {
			seen.set(item.id, Boolean(item.done) || seen.get(item.id));
		});
	});

	const total = seen.size;
	const done = [...seen.values()].filter(Boolean).length;

	return { done, total };
};

/**
 * Goals matching a filter: '' (all), a severity, or 'quick'.
 *
 * @param {Array}  goals  Ranked goals.
 * @param {string} filter Active filter.
 * @return {Array} Matching goals, rank kept.
 */
export const filterGoals = (goals, filter) =>
	goals
		.map((goal, index) => ({ goal, rank: index + 1 }))
		.filter(({ goal }) => {
			if (!filter) {
				return true;
			}
			if ('quick' === filter) {
				return 'S' === goal.effort;
			}
			return goal.severity === filter;
		});
