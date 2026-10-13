import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Tabs, { TabPanel } from '../ui/Tabs';
import ContentPlannerTab from '../ai-tools/ContentPlannerTab';
import MetadataGeneratorTab from '../ai-tools/MetadataGeneratorTab';
import { Card } from '../ui';
import MCP from './MCP';
import MCPHubConnect from './MCPHubConnect';
import { TAB_EVENT } from '../../utils/hubStatus';

const TABS = [
	{ id: 'content-planner', label: __('Content Planner', 'thinkrank') },
	{ id: 'metadata-generator', label: __('Metadata Generator', 'thinkrank') },
	{ id: 'mcp', label: __('MCP', 'thinkrank') },
	{ id: 'hub', label: __('ThinkRank Hub', 'thinkrank') },
];

const VALID_TABS = TABS.map((t) => t.id);
const DEFAULT_TAB = 'content-planner';

/**
 * Sync the active tab to the URL via replaceState so reloads restore it
 * without polluting browser history.
 * @param tabId
 */
const writeTabToUrl = (tabId) => {
	try {
		const url = new URL(window.location.href);
		url.searchParams.set('tab', tabId);
		window.history.replaceState({}, '', url.toString());
	} catch (e) {
		// URL API failure shouldn't break tab switching
	}
};

const AITools = () => {
	const [activeTab, setActiveTab] = useState(DEFAULT_TAB);
	// The hub's grant is listed among the MCP tab's connected apps, so a hub
	// disconnect tells that list to read itself again. Both panels stay
	// mounted, which is why a reload is never what brings it up to date.
	const [hubDisconnects, setHubDisconnects] = useState(0);

	// Restore active tab from URL on mount
	useEffect(() => {
		try {
			const params = new URLSearchParams(window.location.search);
			const requested = params.get('tab');
			const tab = VALID_TABS.includes(requested)
				? requested
				: DEFAULT_TAB;
			setActiveTab(tab);
			writeTabToUrl(tab);
		} catch (e) {
			// Fall back to default on URL parse failure
		}
	}, []);

	const handleTabChange = useCallback((tabId) => {
		setActiveTab(tabId);
		writeTabToUrl(tabId);
	}, []);

	// The header's Hub chip, pressed while this page is already open.
	useEffect(() => {
		const onTab = (event) => {
			if (VALID_TABS.includes(event.detail)) {
				handleTabChange(event.detail);
			}
		};
		window.addEventListener(TAB_EVENT, onTab);
		return () => window.removeEventListener(TAB_EVENT, onTab);
	}, [handleTabChange]);

	return (
		<div>
			<Tabs
				tabs={TABS}
				activeTab={activeTab}
				onTabChange={handleTabChange}
				className="tr-mb-6"
			/>
			<TabPanel tabId="content-planner" activeTab={activeTab}>
				<ContentPlannerTab />
			</TabPanel>
			<TabPanel tabId="metadata-generator" activeTab={activeTab}>
				<MetadataGeneratorTab />
			</TabPanel>
			<TabPanel tabId="mcp" activeTab={activeTab}>
				<MCP appsRefreshSignal={hubDisconnects} />
			</TabPanel>
			<TabPanel tabId="hub" activeTab={activeTab}>
				{/* Mounted only while open, so the card reads the hub state
				    again each time, after MCP access was switched on the MCP
				    tab, for example. */}
				{activeTab === 'hub' && (
					<Card padding={false} className="tr-overflow-hidden">
						<MCPHubConnect
							onDisconnected={() =>
								setHubDisconnects((count) => count + 1)
							}
						/>
					</Card>
				)}
			</TabPanel>
		</div>
	);
};

export default AITools;
