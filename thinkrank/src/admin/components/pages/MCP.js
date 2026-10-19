/**
 * MCP (Model Context Protocol) Page Component
 *
 * Lets site owners open a permission-scoped bridge between their SEO data and
 * MCP-capable AI assistants, and walks them through connecting a client.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Toggle, Card, Alert, Badge, SkeletonCard } from '../ui';
import logger from '../../../shared/utils/logger';
import MCPConnectionConfig from './MCPConnectionConfig';
import MCPConnectedApps from './MCPConnectedApps';
import MCPConnectionHealth from './MCPConnectionHealth';
import MCPActivity from './MCPActivity';

// What an agent can touch once connected, grouped by area. Covers the full
// tool catalog registered in Abilities_Registrar (keep in sync).
const CAPABILITIES = [
	__('Content SEO: titles, descriptions & canonicals', 'thinkrank'),
	__('Global SEO defaults per post type', 'thinkrank'),
	__('Schema & structured data', 'thinkrank'),
	__('Open Graph & Twitter previews', 'thinkrank'),
	__('Robots meta rules & robots.txt', 'thinkrank'),
	__('XML sitemaps', 'thinkrank'),
	__('Instant indexing (IndexNow) & history', 'thinkrank'),
	__('LLMs.txt generation & status', 'thinkrank'),
	__('Image SEO', 'thinkrank'),
	__('Author archives', 'thinkrank'),
	__('Site identity', 'thinkrank'),
	__('SEO scoring, checks, insights & analytics', 'thinkrank'),
];

const IconCheck = () => (
	<svg
		width="13"
		height="13"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
		className="tr-shrink-0"
	>
		<polyline points="20 6 9 17 4 12" />
	</svg>
);

// Official Model Context Protocol logo mark (nested rounded arcs), drawn in
// the same line-icon style as the rest of the page.
const IconMcp = () => (
	<svg
		width="22"
		height="22"
		viewBox="0 0 195 195"
		fill="none"
		stroke="currentColor"
		strokeWidth="12"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<path d="M25 97.85 92.88 29.97c9.37-9.37 24.57-9.37 33.94 0 9.38 9.37 9.38 24.57 0 33.94L75.56 115.18" />
		<path d="M76.27 114.47l50.55-50.56c9.37-9.37 24.57-9.37 33.95 0 9.37 9.38 9.37 24.58 0 33.95l-61.78 61.78c-2.34 2.34-2.34 6.13 0 8.47l19.34 19.35" />
		<path d="M109.85 46.94 59.29 97.5c-9.37 9.37-9.37 24.57 0 33.94 9.38 9.38 24.58 9.38 33.95 0l51.25-51.26" />
	</svg>
);

// Chevron for the collapsible capability list; rotates when expanded.
const IconChevron = ({ open }) => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
		style={{
			transition: 'transform 150ms ease',
			transform: open ? 'rotate(180deg)' : 'none',
		}}
	>
		<polyline points="6 9 12 15 18 9" />
	</svg>
);

const MCP = ({ appsRefreshSignal = 0 }) => {
	// Live (possibly unsaved) toggle value drives the status pill and the connect
	// guide, so both react the moment MCP is switched on.
	const [enableMcp, setEnableMcp] = useState(false);
	const [savedMcp, setSavedMcp] = useState(false);
	const [isLoading, setIsLoading] = useState(true);
	const [isSaving, setIsSaving] = useState(false);
	const [notice, setNotice] = useState(null);
	// The capability list is collapsed by default; expand on demand.
	const [showCapabilities, setShowCapabilities] = useState(false);
	// Connection the Activity card is filtered to; set from a connection's
	// "N changes" link in Connected AI apps.
	const [activityClient, setActivityClient] = useState('');
	const [activityClientName, setActivityClientName] = useState('');
	// Each card bumps the other's key when it changes what that one shows:
	// a revert changes the apps' change counts, and a revoke or an access
	// edit changes the log. A hub disconnect on the ThinkRank Hub tab ends a
	// grant the apps list, and arrives as appsRefreshSignal.
	const [appsRefreshKey, setAppsRefreshKey] = useState(0);
	const [activityRefreshKey, setActivityRefreshKey] = useState(0);
	// Bumped after Revoke all access, so the connect guide reloads the new
	// token instead of showing the one that was just replaced.
	const [configKey, setConfigKey] = useState(0);
	// Remounts the health card when the token's access or expiry changes,
	// so its verdict is fetched again rather than kept from page load.
	const [healthKey, setHealthKey] = useState(0);
	const activityRef = useRef(null);

	const viewActivity = (clientKey, clientName = '') => {
		setActivityClient(clientKey);
		setActivityClientName(clientName);
		activityRef.current?.scrollIntoView({ behavior: 'smooth' });
	};

	const globals =
		(typeof window !== 'undefined' && window.thinkrankAdmin?.mcp) || {};
	// The MCP server is built in; its tool catalog is the bundled Abilities
	// API. When that's missing (bundle not built), the endpoint has no tools.
	const dependenciesReady = !!globals.abilities_api_available;
	// Only an administrator may turn the server on or off (#1057); the tab is
	// open to a delegated role, which must not be offered a switch that the
	// save then refuses (#1070).
	const canToggle =
		typeof window !== 'undefined' && !!window.thinkrankAdmin?.isAdmin;

	useEffect(() => {
		loadSettings();
	}, []);

	const loadSettings = async () => {
		setIsLoading(true);
		try {
			const response = await apiFetch({ path: '/thinkrank/v1/settings' });
			setEnableMcp(!!response?.enable_mcp);
			setSavedMcp(!!response?.enable_mcp);
		} catch (error) {
			logger.error('ThinkRank: Failed to load MCP settings', error);
			setNotice({
				type: 'error',
				message: __('Couldn’t load your MCP settings.', 'thinkrank'),
			});
		} finally {
			setIsLoading(false);
		}
	};

	// Toggling saves immediately — no separate Save button. Optimistically flip
	// the switch, persist, then roll back if the request fails.
	const handleToggle = async (next) => {
		setEnableMcp(next);
		setIsSaving(true);
		setNotice(null);
		try {
			await apiFetch({
				path: '/thinkrank/v1/settings',
				method: 'POST',
				data: { enable_mcp: next },
			});
			setSavedMcp(next);
			setNotice({
				type: 'success',
				message: next
					? __('MCP is on. Connect an AI client below.', 'thinkrank')
					: __(
							'MCP is off. AI clients can no longer connect.',
							'thinkrank'
						),
			});
		} catch (error) {
			logger.error('ThinkRank: Failed to save MCP settings', error);
			setEnableMcp(savedMcp);
			// Say why when the server did, e.g. the administrator-only refusal.
			setNotice({
				type: 'error',
				message:
					error?.message ||
					__('Couldn’t save your MCP settings.', 'thinkrank'),
			});
		} finally {
			setIsSaving(false);
		}
	};

	if (isLoading) {
		return <SkeletonCard />;
	}

	// Status pill: reflects the live toggle + whether the adapter is present.
	let status = { variant: 'neutral', label: __('Off', 'thinkrank') };
	if (enableMcp && dependenciesReady) {
		status = { variant: 'good', label: __('Active', 'thinkrank') };
	} else if (enableMcp && !dependenciesReady) {
		status = { variant: 'warning', label: __('Setup needed', 'thinkrank') };
	}

	return (
		<div className="tr-flex tr-flex-col tr-gap-6">
			{notice && (
				<Alert variant={notice.type} onDismiss={() => setNotice(null)}>
					{notice.message}
				</Alert>
			)}

			<Card padding={false} className="tr-overflow-hidden">
				{/* Hero header — the enable toggle lives here, beside the title. */}
				<div className="tr-flex tr-items-start tr-gap-4 tr-p-6">
					<span className="tr-flex tr-items-center tr-justify-center tr-w-11 tr-h-11 tr-rounded-xl tr-bg-brand-bg tr-text-brand tr-shrink-0">
						<IconMcp />
					</span>
					<div className="tr-flex-1 tr-min-w-0">
						<div className="tr-flex tr-items-center tr-gap-2.5 tr-flex-wrap">
							<h2 className="tr-m-0 tr-text-base tr-font-semibold tr-text-primary">
								{__('AI agent access', 'thinkrank')}
							</h2>
							<Badge variant={status.variant}>
								{status.label}
							</Badge>
						</div>
						<p className="tr-mt-1 tr-mb-0 tr-text-sm tr-text-secondary tr-max-w-2xl">
							{__(
								'Open a secure, permission-scoped bridge so assistants like Claude, ChatGPT, and Cursor can review and improve your SEO for you. Access runs through a revocable connection token or a one-time OAuth approval, always tied to an administrator account. Turn it off and ThinkRank registers no tools every AI connection is refused.',
								'thinkrank'
							)}
						</p>
					</div>
					<div className="tr-flex tr-flex-col tr-items-end tr-gap-1 tr-shrink-0">
						<Toggle
							id="thinkrank-enable-mcp"
							checked={enableMcp}
							onChange={handleToggle}
							disabled={isSaving || !canToggle}
							label={__('Enable MCP access', 'thinkrank')}
						/>
						{!canToggle && (
							<span className="tr-text-xs tr-text-tertiary">
								{__(
									'Only an administrator can turn MCP on or off.',
									'thinkrank'
								)}
							</span>
						)}
						{isSaving && (
							<span className="tr-text-xs tr-text-tertiary">
								{__('Saving…', 'thinkrank')}
							</span>
						)}
					</div>
				</div>

				{/* Capability overview — collapsed by default; expand to view. */}
				<div className="tr-border-t tr-border-secondary">
					<button
						type="button"
						onClick={() => setShowCapabilities((v) => !v)}
						aria-expanded={showCapabilities}
						aria-controls="tr-mcp-capabilities"
						className="tr-flex tr-items-start tr-justify-between tr-gap-3 tr-w-full tr-p-6 tr-bg-transparent tr-border-0 tr-cursor-pointer tr-text-left"
					>
						<span className="tr-min-w-0">
							<span className="tr-block tr-text-sm tr-font-semibold tr-text-primary">
								{__(
									'What a connected agent can do',
									'thinkrank'
								)}
							</span>
							<span className="tr-block tr-mt-1 tr-text-sm tr-text-secondary">
								{__(
									'Every action honours the signed-in user’s capabilities and can be reviewed or reverted in ThinkRank.',
									'thinkrank'
								)}
							</span>
						</span>
						<span className="tr-flex tr-items-center tr-justify-center tr-w-6 tr-h-6 tr-mt-0.5 tr-text-tertiary tr-shrink-0">
							<IconChevron open={showCapabilities} />
						</span>
					</button>
					{showCapabilities && (
						<ul
							id="tr-mcp-capabilities"
							className="tr-grid tr-grid-cols-1 sm:tr-grid-cols-2 lg:tr-grid-cols-3 tr-gap-2.5 tr-list-none tr-m-0 tr-pt-0 tr-px-6 tr-pb-6"
						>
							{CAPABILITIES.map((item) => (
								<li
									key={item}
									className="tr-flex tr-items-center tr-gap-2.5 tr-m-0 tr-px-3 tr-py-2.5 tr-rounded-lg tr-border tr-border-secondary tr-bg-white tr-text-sm tr-text-secondary"
								>
									<span className="tr-flex tr-items-center tr-justify-center tr-w-5 tr-h-5 tr-rounded-full tr-bg-success-bg tr-text-success tr-shrink-0">
										<IconCheck />
									</span>
									<span className="tr-flex-1 tr-min-w-0">
										{item}
									</span>
								</li>
							))}
						</ul>
					)}
				</div>
			</Card>

			{savedMcp && (
				<Card>
					<div className="tr-flex tr-flex-col tr-gap-6">
						{!dependenciesReady && <DependencyMissingAlert />}
						<MCPConnectionConfig key={configKey} />
					</div>
				</Card>
			)}

			{savedMcp && dependenciesReady && (
				<Card>
					<MCPConnectedApps
						onViewActivity={viewActivity}
						onRevokedAll={() => setConfigKey((k) => k + 1)}
						onTokenChanged={() => setHealthKey((k) => k + 1)}
						onAccessChanged={() =>
							setActivityRefreshKey((k) => k + 1)
						}
						refreshKey={appsRefreshKey + appsRefreshSignal}
					/>
				</Card>
			)}

			{savedMcp && dependenciesReady && (
				<div ref={activityRef}>
					<Card>
						<MCPActivity
							client={activityClient}
							clientName={activityClientName}
							onClientChange={setActivityClient}
							onReverted={() => setAppsRefreshKey((k) => k + 1)}
							refreshKey={activityRefreshKey}
						/>
					</Card>
				</div>
			)}

			{savedMcp && (
				<Card>
					<MCPConnectionHealth key={`${configKey}-${healthKey}`} />
				</Card>
			)}
		</div>
	);
};

// Shown when MCP is on but the bundled Abilities API (the tool catalog) isn't
// loadable — i.e. the dependencies/ bundle wasn't built into this install.
const DependencyMissingAlert = () => (
	<Alert variant="warning" title={__('MCP runtime not loaded', 'thinkrank')}>
		<p className="tr-m-0">
			{__(
				'The bundled Abilities API could not be loaded, so no SEO tools can be served to AI clients. This usually means the plugin build is incomplete (dependencies/vendor is missing). Reinstall ThinkRank from an official package, or run "composer install" inside the plugin’s dependencies/ directory.',
				'thinkrank'
			)}
		</p>
	</Alert>
);

export default MCP;
