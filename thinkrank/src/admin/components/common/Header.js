/**
 * Header Component
 *
 * Compact header for admin pages with page title integration
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import logger from '../../../shared/utils/logger';

/**
 * ThinkRank Icon Component
 * Same icon used in WordPress admin menu
 * @param root0
 * @param root0.className
 * @param root0.size
 */
const ThinkRankIcon = ({ className = '', size = 22 }) => (
	<svg
		className={className}
		xmlns="http://www.w3.org/2000/svg"
		width={size}
		height={size}
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72" />
		<path d="m14 7 3 3" />
		<path d="M5 6v4" />
		<path d="M19 14v4" />
		<path d="M10 2v2" />
		<path d="M7 8H3" />
		<path d="M21 16h-4" />
		<path d="M11 3H9" />
	</svg>
);

/**
 * Header Component
 * @param root0
 * @param root0.pageTitle
 * @param root0.pageDescription
 * @param root0.showHelp
 */
const Header = ({ pageTitle, pageDescription, showHelp = false }) => {
	const { credits, pluginVersion, hasApiKey } = useSelect((select) => {
		try {
			const coreStore = select('thinkrank/core');
			const creditsStore = select('thinkrank/credits');
			const settingsStore = select('thinkrank/settings');

			// Safely get settings
			const settings = settingsStore?.getSettings?.() || {};

			return {
				credits: creditsStore?.getBalance?.() || 0,
				pluginVersion: window.thinkrankAdmin?.version || '1.0.0',
				hasApiKey: Boolean(
					settings.openai_api_key || settings.claude_api_key
				),
			};
		} catch (error) {
			logger.warn('ThinkRank Header: Error accessing stores', error);
			return {
				credits: 0,
				pluginVersion: window.thinkrankAdmin?.version || '1.0.0',
				hasApiKey: false,
			};
		}
	}, []);

	return (
		<div className="thinkrank-header-compact">
			<div className="header-main">
				<div className="header-branding">
					<div className="brand-info">
						<h1 className="brand-name">
							<ThinkRankIcon className="brand-icon" size={22} />
							{__('ThinkRank', 'thinkrank')}
						</h1>
					</div>

					{pageTitle && (
						<div className="page-info">
							<h2 className="page-title">{pageTitle}</h2>
							{pageDescription && (
								<p className="page-description">
									{pageDescription}
								</p>
							)}
						</div>
					)}
				</div>

				<div className="header-actions">
					<span className="version-info">v{pluginVersion}</span>
				</div>
			</div>
		</div>
	);
};

export default Header;
