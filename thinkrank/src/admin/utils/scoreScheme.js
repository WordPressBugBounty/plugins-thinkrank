/**
 * Shared SEO-score colour scheme.
 *
 * Mirrors the post-list "SEO" column badge exactly so a score reads the same
 * colour everywhere it appears (post list, metabox header ring, Analysis →
 * Overview). Source of truth on the PHP/CSS side:
 *   - includes/admin/class-post-list-columns.php  → get_score_class()
 *   - static/css/admin-columns.css                → .thinkrank-score-*
 *
 *   >= 90  great   green   text #065f46  bg #d1fae5
 *   >= 70  good    blue    text #1e40af  bg #dbeafe
 *   >= 50  medium  amber   text #92400e  bg #fef3c7
 *   <  50  poor    red     text #b91c1c  bg #fee2e2
 *
 * @package
 */

const BUCKETS = [
	{ min: 90, fg: '#065f46', bg: '#d1fae5' },
	{ min: 70, fg: '#1e40af', bg: '#dbeafe' },
	{ min: 50, fg: '#92400e', bg: '#fef3c7' },
	{ min: 0, fg: '#b91c1c', bg: '#fee2e2' },
];

/**
 * Resolve the colour bucket for a 0-100 score.
 *
 * @param {number} score Score value.
 * @return {{min:number,fg:string,bg:string}} Matching bucket.
 */
export function scoreBucket(score) {
	const n = Number(score);
	const safe = Number.isFinite(n) ? n : 0;
	return BUCKETS.find((b) => safe >= b.min) || BUCKETS[BUCKETS.length - 1];
}

/**
 * Foreground colour for a score (ring stroke, badge text).
 *
 * @param {number} score Score value.
 * @return {string} Hex colour.
 */
export function scoreColor(score) {
	return scoreBucket(score).fg;
}

/**
 * Inline style for a pill/badge background + text at a given score.
 *
 * @param {number} score Score value.
 * @return {{backgroundColor:string,color:string}} Style object.
 */
export function scoreBadgeStyle(score) {
	const bucket = scoreBucket(score);
	return { backgroundColor: bucket.bg, color: bucket.fg };
}
