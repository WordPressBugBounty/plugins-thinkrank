/**
 * Setup Wizard Entry Point
 *
 * Full-screen onboarding wizard rendered on the `thinkrank_setup_wizard` admin page.
 * Mounts into the `#thinkrank-setup-wizard` container provided by PHP.
 *
 * @package
 * @since 1.0.0
 */

import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';
import SetupWizard from './SetupWizard';
// Tailwind utilities (`tr-*`) — shared components such as MediaPicker are built
// on them, and this entry does not load the admin bundle that normally emits
// them. Without this import those components render unstyled here.
import '../../global/tailwind.css';
import './styles/setup-wizard.scss';
import logger from '../../shared/utils/logger';

const config = window.thinkrankWizard || {};

// Send the REST nonce on every request.
if (config.restNonce) {
	apiFetch.use(apiFetch.createNonceMiddleware(config.restNonce));
}

const mount = () => {
	const container = document.getElementById('thinkrank-setup-wizard');

	if (!container) {
		return;
	}

	try {
		const root = createRoot(container);
		root.render(<SetupWizard config={config} />);
	} catch (error) {
		logger.error('ThinkRank: Failed to mount Setup Wizard', error);
	}
};

// Optimization plugins can defer this bundle past DOMContentLoaded, and a
// listener added after the event never fires — the wizard would never mount.
if (document.readyState !== 'loading') {
	mount();
} else {
	document.addEventListener('DOMContentLoaded', mount);
}
