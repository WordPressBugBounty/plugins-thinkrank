/**
 * Admin Dashboard Entry Point
 *
 * Main entry point for React admin interface
 *
 * @package
 * @since 1.0.0
 */

import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
// Register the browser-side API cache middleware before anything fetches.
// Pro components share the same apiFetch instance, so they benefit too.
import { purgeApiCache } from './utils/apiCache';
import { hasOAuthResult } from './utils/googleOAuth';
import './stores';
import '../global/tailwind.css';
import '../global/base.css';
import './styles/admin.scss';
import App from './components/App';
import notify from './utils/notify';
import logger from '../shared/utils/logger';

// Pro renders inside this React tree and already shares our apiFetch instance
// and --tr-* tokens; the toast layer is the same kind of shared surface. Pro is
// a separate bundle with splitChunks disabled, so a module import is
// unreachable from there — publishing the wrapper here (before anything mounts)
// is what lets a Pro panel confirm a save the same way a free one does.
window.thinkrank = window.thinkrank || {};
window.thinkrank.notify = notify;

// The Google connect flow finishes in PHP and returns here as a full page load,
// so nothing goes through apiFetch to trigger the cache's write-purge. Without
// this, the pre-connect `integrations/settings` response is served from
// sessionStorage and the screen keeps showing "Connect with Google" for the
// rest of the TTL — a reload does not help, since sessionStorage survives it.
if (hasOAuthResult()) {
	purgeApiCache();
}

/**
 * Initialize admin interface
 */
const mount = () => {
	// Find admin containers
	const containers = {
		dashboard: document.getElementById('thinkrank-dashboard'),
		'essential-seo': document.getElementById('thinkrank-essential-seo'),
		'ai-tools': document.getElementById('thinkrank-ai-tools'),
		settings: document.getElementById('thinkrank-settings'),
		mcp: document.getElementById('thinkrank-mcp'),
		credits: document.getElementById('thinkrank-credits'),
		analytics: document.getElementById('thinkrank-analytics'),
		'import-export': document.getElementById('thinkrank-import-export'),
		migration: document.getElementById('thinkrank-migration'),
	};

	// Render appropriate component based on container
	Object.entries(containers).forEach(([page, container]) => {
		if (container) {
			renderAdminPage(page, container);
		}
	});
};

// Optimization plugins can defer this bundle past DOMContentLoaded, and a
// listener added after the event never fires — the admin app would never mount.
if (document.readyState !== 'loading') {
	mount();
} else {
	document.addEventListener('DOMContentLoaded', mount);
}

/**
 * Render admin page component
 *
 * @param {string}  page      Page identifier
 * @param {Element} container DOM container
 */
function renderAdminPage(page, container) {
	try {
		// Create root and render component using React 18 API
		const root = createRoot(container);
		root.render(<App page={page} />);
	} catch (error) {
		logger.error('ThinkRank: Failed to render admin page', error);

		// Fallback error display
		container.innerHTML = `
            <div class="notice notice-error">
                <p>${__('Failed to load ThinkRank interface. Please refresh the page.', 'thinkrank')}</p>
            </div>
        `;
	}
}
