/**
 * AI Insights guidance cards — pure state → copy rules (#734).
 *
 * Kept free of React so what each card says for each state is unit-tested.
 * Copy stays within the claims policy: it states where the site stands and
 * never promises citations or estimates exposure.
 *
 * @package
 * @since 2.21.3
 */

import { __, _n, sprintf } from '@wordpress/i18n';

const DAY = 86400;

/**
 * "today", "yesterday", "N days ago" — site-agnostic, in whole days.
 *
 * @param {number} timestamp Unix seconds.
 * @param {number} now       Unix seconds.
 * @return {string} Relative day phrase.
 */
export const daysAgo = (timestamp, now) => {
	const days = Math.max(0, Math.floor((now - timestamp) / DAY));
	if (days === 0) {
		return __('today', 'thinkrank');
	}
	if (days === 1) {
		return __('yesterday', 'thinkrank');
	}
	return sprintf(
		/* translators: %d: number of days. */
		_n('%d day ago', '%d days ago', days, 'thinkrank'),
		days
	);
};

/**
 * The AI crawler access card.
 *
 * @param {Object} crawlers `guidance.crawlers` from the REST payload.
 * @return {{tone: string, status: string, detail: string}} Card copy.
 */
export const crawlerCard = (crawlers) => {
	const total = crawlers?.total ?? 0;
	const blocked = crawlers?.blocked ?? 0;
	const allowed = crawlers?.allowed ?? total - blocked;

	if (total === 0) {
		return {
			tone: 'neutral',
			status: __('No AI crawler rules', 'thinkrank'),
			detail: __(
				'Set which AI crawlers may fetch your pages.',
				'thinkrank'
			),
		};
	}

	if (blocked === 0) {
		return {
			tone: 'good',
			status: sprintf(
				/* translators: %d: number of AI crawlers. */
				_n(
					'All %d AI crawler allowed',
					'All %d AI crawlers allowed',
					total,
					'thinkrank'
				),
				total
			),
			detail: __(
				'Every AI crawler ThinkRank knows may fetch your pages.',
				'thinkrank'
			),
		};
	}

	return {
		tone: blocked === total ? 'poor' : 'warning',
		status: sprintf(
			/* translators: 1: allowed AI crawlers, 2: all known AI crawlers. */
			__('%1$d of %2$d AI crawlers allowed', 'thinkrank'),
			allowed,
			total
		),
		detail: sprintf(
			/* translators: %d: number of blocked AI crawlers. */
			_n(
				'%d is blocked in robots.txt. Blocking training crawlers is a choice; blocking an assistant keeps it from reading your pages.',
				'%d are blocked in robots.txt. Blocking training crawlers is a choice; blocking an assistant keeps it from reading your pages.',
				blocked,
				'thinkrank'
			),
			blocked
		),
	};
};

/**
 * The llms.txt card.
 *
 * @param {Object} llms `guidance.llms_txt` from the REST payload.
 * @param {number} now  Unix seconds.
 * @return {{tone: string, status: string, detail: string}} Card copy.
 */
export const llmsCard = (llms, now) => {
	if (!llms?.published) {
		return {
			tone: 'warning',
			status: __('llms.txt is not published', 'thinkrank'),
			detail: __(
				'AI crawlers get no guide to your site. Generate one in a few clicks.',
				'thinkrank'
			),
		};
	}

	return {
		tone: 'good',
		status: llms.updated_at
			? sprintf(
					/* translators: %s: relative time, e.g. "3 days ago". */
					__('llms.txt is live · updated %s', 'thinkrank'),
					daysAgo(llms.updated_at, now)
				)
			: __('llms.txt is live', 'thinkrank'),
		detail: __(
			'AI crawlers can read a summary of your site at /llms.txt.',
			'thinkrank'
		),
	};
};
