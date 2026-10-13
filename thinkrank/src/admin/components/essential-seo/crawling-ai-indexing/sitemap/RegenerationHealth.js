/**
 * Automatic sitemap regeneration health banner.
 *
 * Automatic regeneration is handed to a WP-Cron single event. WP-Cron only runs
 * when a request arrives, so on a site with DISABLE_WP_CRON, a host that blocks
 * loopback requests, or very little traffic it may not run at all — and the
 * failure used to be completely silent: `last_generated` just stopped advancing
 * (#629). This surfaces the three states worth acting on.
 *
 * @package
 * @since 2.2.1
 */

import { __, sprintf } from '@wordpress/i18n';
import { Alert } from '../../../ui';

/**
 * Render an ISO-8601 timestamp in the browser's locale, or an em dash.
 *
 * @param {string|null} iso Timestamp.
 * @return {string} Human-readable time.
 */
const formatTime = (iso) => {
	if (!iso) {
		return '—';
	}

	const date = new Date(iso);

	return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
};

/**
 * @param {Object}      props        Component props.
 * @param {Object|null} props.health Regeneration health from the settings API.
 */
const RegenerationHealth = ({ health }) => {
	if (!health) {
		return null;
	}

	const {
		last_error: lastError,
		stale,
		cron_disabled: cronDisabled,
	} = health;
	const autoGenerate = health.auto_generate;

	if (lastError) {
		return (
			<Alert
				variant="error"
				title={__('Automatic sitemap regeneration failed', 'thinkrank')}
				className="tr-mb-4"
			>
				{sprintf(
					/* translators: 1: formatted date and time of the failed attempt, 2: failure message reported by the generator. */
					__(
						'The last attempt on %1$s failed: %2$s Use “Generate Sitemap” to rebuild it now.',
						'thinkrank'
					),
					formatTime(lastError.time),
					lastError.message
				)}
			</Alert>
		);
	}

	// With automatic regeneration off, a sitemap that lags the content is the
	// configured behaviour, not a fault worth warning about.
	if (!autoGenerate) {
		return null;
	}

	if (stale) {
		return (
			<Alert
				variant="warning"
				title={__('The sitemap is behind your content', 'thinkrank')}
				className="tr-mb-4"
			>
				{sprintf(
					/* translators: %s: formatted date and time the sitemap was last written. */
					__(
						'Published content has changed since the sitemap was last written on %s.',
						'thinkrank'
					),
					formatTime(health.last_generated)
				)}{' '}
				{cronDisabled
					? __(
							'WP-Cron is disabled on this site (DISABLE_WP_CRON), so regeneration falls back to running while you use the dashboard. Check that a real cron job calls wp-cron.php, or use “Generate Sitemap” to rebuild it now.',
							'thinkrank'
						)
					: __(
							'Use “Generate Sitemap” to rebuild it now if it does not catch up shortly.',
							'thinkrank'
						)}
			</Alert>
		);
	}

	if (cronDisabled) {
		return (
			<Alert
				variant="info"
				title={__('WP-Cron is disabled on this site', 'thinkrank')}
				className="tr-mb-4"
			>
				{__(
					'DISABLE_WP_CRON is set, so scheduled sitemap regeneration only runs if a real cron job calls wp-cron.php. ThinkRank rebuilds an overdue sitemap while you use the dashboard, so it still catches up.',
					'thinkrank'
				)}
			</Alert>
		);
	}

	return null;
};

export default RegenerationHealth;
