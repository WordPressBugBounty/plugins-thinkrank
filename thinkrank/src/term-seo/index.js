/**
 * ThinkRank Term SEO Entry Point
 *
 * Mounts the SEO panel on a taxonomy term's edit screen (#885). The PHP side
 * (Term_Seo_Fields) prints the mount point inside the term form and localizes
 * `thinkrankTermSeo`.
 *
 * @package
 * @since 2.16.0
 */

import { createRoot } from '@wordpress/element';
import './styles.scss';
import '../global/base.css';
import '../global/tailwind.css';
import TermSeoPanel from './components/TermSeoPanel';

const mount = () => {
	const container = document.getElementById('thinkrank-term-seo-root');
	if (!container || !window.thinkrankTermSeo) {
		return;
	}

	container.innerHTML = '';
	createRoot(container).render(
		<TermSeoPanel config={window.thinkrankTermSeo} />
	);
};

// Optimization plugins can defer this bundle past DOMContentLoaded, and a
// listener added after the event never fires.
if (document.readyState !== 'loading') {
	mount();
} else {
	document.addEventListener('DOMContentLoaded', mount);
}
