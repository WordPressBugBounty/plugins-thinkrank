/**
 * Main Admin App Component
 *
 * Router component for admin interface
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
// Page Components
import Dashboard from './pages/Dashboard';
import EssentialSEO from './pages/EssentialSEO';
import AITools from './pages/AITools';
import Settings from './pages/Settings';
import MCP from './pages/MCP';
import UsageAnalytics from './pages/UsageAnalytics';
import ImportExport from './pages/ImportExport';
import Migration from './pages/Migration';

// Layout Components
import Shell from './layout/Shell';
import TopNav from './layout/TopNav';
import TopBar from './layout/TopBar';
import ModeSwitch from './common/ModeSwitch';
import PageWrapper from './layout/PageWrapper';

// SPA router
import { getPageFromUrl, subscribe, setTitleResolver } from '../utils/router';

// Common Components
import ErrorBoundary from './common/ErrorBoundary';
import { Alert } from './ui';

// Toast notifications (non-blocking, replaces native alert())
import { ToastContainer, Slide } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';
// On-brand overrides — must load after the base stylesheet above.
import '../styles/toast.css';

/**
 * Main App Component
 *
 * @param {Object} props      Component props
 * @param {string} props.page Current page identifier
 */
const App = ({ page = 'dashboard' }) => {
	// The URL is the source of truth (SPA routing); the container id passed
	// by PHP only seeds the very first render.
	const [currentPage, setCurrentPage] = useState(
		() => getPageFromUrl() || page
	);

	// SPA routing: re-render on navigate() and browser back/forward, and let
	// the router resolve document titles from the page map below.
	useEffect(() => {
		setTitleResolver((pageId) => getPageInfo(pageId).title);
		return subscribe(setCurrentPage);
	}, []);

	const pageInfo = getPageInfo(currentPage);

	return (
		<ErrorBoundary>
			<Shell topNav={<TopNav currentPage={currentPage} />}>
				<TopBar
					title={pageInfo.title}
					description={pageInfo.description}
					page={currentPage}
					// The Basic / Advanced switch governs the Essential SEO
					// menu and the advanced settings folded inside its
					// screens, so it sits in that page's header rather than
					// above pages it does not touch (#730).
					actions={
						'essential-seo' === currentPage ? <ModeSwitch /> : null
					}
				/>
				<PageWrapper>{renderCurrentPage(currentPage)}</PageWrapper>
			</Shell>
			<ToastContainer
				position="bottom-right"
				autoClose={4000}
				limit={3}
				newestOnTop
				closeOnClick
				pauseOnHover
				draggable
				transition={Slide}
				theme="light"
				icon
			/>
		</ErrorBoundary>
	);
};

function getPageInfo(page) {
	switch (page) {
		case 'dashboard':
			return {
				title: __('Dashboard', 'thinkrank'),
				description: __(
					'Overview of your SEO optimization progress',
					'thinkrank'
				),
			};
		case 'essential-seo':
			return {
				title: __('Essential SEO', 'thinkrank'),
				description: __(
					'Configure your site-wide SEO settings',
					'thinkrank'
				),
			};
		case 'ai-tools':
			return {
				title: __('AI Tools', 'thinkrank'),
				description: __(
					'AI-powered content and metadata tools',
					'thinkrank'
				),
			};
		case 'settings':
			return {
				title: __('Settings', 'thinkrank'),
				description: __(
					'Configure AI API keys and plugin preferences',
					'thinkrank'
				),
			};
		case 'mcp':
			return {
				title: __('MCP', 'thinkrank'),
				description: __(
					'Configure AI client access to ThinkRank through the Model Context Protocol',
					'thinkrank'
				),
			};
		case 'analytics':
			return {
				title: __('Usages', 'thinkrank'),
				description: __(
					'Track AI usage, costs, and performance',
					'thinkrank'
				),
			};
		case 'import-export':
			return {
				title: __('Import / Export', 'thinkrank'),
				description: __(
					'Download your ThinkRank data as a file, or restore it from one',
					'thinkrank'
				),
			};
		case 'migration':
			return {
				title: __('Migration', 'thinkrank'),
				description: __(
					'Import SEO data from Yoast, Rank Math, SEOPress, All in One SEO, Squirrly SEO, Slim SEO or SureRank',
					'thinkrank'
				),
			};
		default:
			return { title: __('ThinkRank', 'thinkrank'), description: '' };
	}
}

function renderCurrentPage(page) {
	switch (page) {
		case 'dashboard':
			return <Dashboard />;

		case 'essential-seo':
			return <EssentialSEO />;

		case 'ai-tools':
			return <AITools />;

		case 'settings':
			return <Settings />;

		case 'mcp':
			return <MCP />;

		case 'analytics':
			return <UsageAnalytics />;

		case 'import-export':
			return <ImportExport />;

		case 'migration':
			return <Migration />;

		default:
			return (
				<Alert variant="warning">
					{__(
						'Page not found. Redirecting to dashboard…',
						'thinkrank'
					)}
				</Alert>
			);
	}
}

export default App;
