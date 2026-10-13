/**
 * Dashboard Widget Entry Point
 *
 * Renders the "ThinkRank Website Insights" widget on the wp-admin Dashboard
 * (index.php). Mounts into the `#thinkrank-dashboard-widget` container provided
 * by PHP (see includes/admin/class-dashboard-widget.php).
 *
 * @package
 * @since 1.0.0
 */

import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';
import DashboardWidget from './DashboardWidget';
import './styles/dashboard-widget.scss';
import logger from '../../shared/utils/logger';

const config = window.thinkrankDashboardWidget || {};

// Send the REST nonce on every request.
if (config.restNonce) {
	apiFetch.use(apiFetch.createNonceMiddleware(config.restNonce));
}

const mount = () => {
	const container = document.getElementById('thinkrank-dashboard-widget');

	if (!container) {
		return;
	}

	try {
		container.innerHTML = '';
		const root = createRoot(container);
		root.render(<DashboardWidget config={config} />);
	} catch (error) {
		logger.error('ThinkRank: Failed to mount Dashboard Widget', error);
	}
};

// Optimization plugins can defer this bundle past DOMContentLoaded, and a
// listener added after the event never fires — the widget would stay on its
// PHP "Loading ThinkRank insights…" fallback forever.
if (document.readyState !== 'loading') {
	mount();
} else {
	document.addEventListener('DOMContentLoaded', mount);
}
