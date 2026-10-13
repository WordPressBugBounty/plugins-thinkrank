/**
 * Search Console Settings Component
 *
 * Displays Search Console configuration after Google account connection:
 * a property selector, a live performance preview (clicks / impressions /
 * CTR / average position for the selected property), and connection actions.
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, SearchableSelect, SkeletonCard } from '../ui';
import { purgeApiCache } from '../../utils/apiCache';
import logger from '../../../shared/utils/logger';

/**
 * Human-readable label for a GSC property.
 *
 * Domain properties come back as `sc-domain:example.com`, not a URL. Rendering
 * that raw makes a user searching "https://" miss their own property, so the
 * prefix is spelled out instead. The stored value stays the raw `siteUrl` —
 * that is what the Search Console API expects back.
 *
 * @param {string} siteUrl Raw siteUrl from the Search Console API.
 * @return {string} Label to display.
 */
const getSiteLabel = (siteUrl) => {
	if (typeof siteUrl === 'string' && siteUrl.startsWith('sc-domain:')) {
		return sprintf(
			/* translators: %s: bare domain of a Search Console domain property, e.g. example.com */
			__('Domain property: %s', 'thinkrank'),
			siteUrl.slice('sc-domain:'.length)
		);
	}

	return siteUrl;
};

/**
 * Human-readable label for a GSC permissionLevel.
 * @param level
 */
const getPermissionLabel = (level) => {
	switch (level) {
		case 'siteOwner':
			return __('Owner', 'thinkrank');
		case 'siteFullUser':
			return __('Full access', 'thinkrank');
		case 'siteRestrictedUser':
			return __('Restricted', 'thinkrank');
		case 'siteUnverifiedUser':
			return __('Unverified', 'thinkrank');
		default:
			return level ? level.replace(/^site/, '') : '';
	}
};

const SearchConsoleSettings = ({
	onDisconnect,
	onReconnect,
	onTestConnection,
}) => {
	const [isLoading, setIsLoading] = useState(true);
	const [isSaving, setIsSaving] = useState(false);
	const [sites, setSites] = useState([]);
	const [settings, setSettings] = useState({
		site: '',
		enableIndexStatus: false,
	});
	const [notice, setNotice] = useState(null);
	const [isRefreshing, setIsRefreshing] = useState(false);

	useEffect(() => {
		loadData();
	}, []);

	const loadData = async () => {
		setIsLoading(true);
		try {
			// Fetch verified sites
			const sitesResponse = await apiFetch({
				path: '/thinkrank/v1/integrations/search-console/sites',
				method: 'GET',
			});

			if (sitesResponse.success) {
				setSites(sitesResponse.data.sites || []);
			}

			// Fetch current settings
			const settingsResponse = await apiFetch({
				path: '/thinkrank/v1/settings-management/category/seo_analytics',
				method: 'GET',
			});

			if (settingsResponse.success && settingsResponse.data.settings) {
				setSettings({
					site:
						settingsResponse.data.settings
							.search_console_property || '',
					enableIndexStatus:
						settingsResponse.data.settings.enable_index_status ||
						false,
				});
			} else {
				setSettings({
					site: '',
					enableIndexStatus: false,
				});
			}
		} catch (error) {
			logger.error('Error loading Search Console data:', error);

			let errorMessage = __(
				'Failed to load Search Console data. Please try refreshing.',
				'thinkrank'
			);

			if (error.code === 'token_expired' || error.data?.status === 401) {
				errorMessage = __(
					'Google session expired. Please reconnect your account in the Integrations tab.',
					'thinkrank'
				);
			} else if (error.message) {
				errorMessage = error.message;
			}

			setNotice({
				status: 'error',
				message: errorMessage,
			});
			setSites([]); // Ensure sites is empty on error
		} finally {
			setIsLoading(false);
		}
	};

	/**
	 * Re-query Google for the property list.
	 *
	 * A property added in Search Console after the list was cached stays
	 * invisible for the cache lifetime on both layers, so this has to purge the
	 * browser cache and tell the server to bypass its transient. Only the sites
	 * are refetched — reloading settings here would discard an unsaved
	 * property selection.
	 */
	const handleRefreshSites = async () => {
		setIsRefreshing(true);
		setNotice(null);
		try {
			purgeApiCache();

			const response = await apiFetch({
				path: '/thinkrank/v1/integrations/search-console/sites?refresh=1',
				method: 'GET',
			});

			if (!response.success) {
				throw new Error(
					response.message || 'Failed to refresh property list'
				);
			}

			setSites(response.data.sites || []);
			setNotice({
				status: 'success',
				message: __('Property list refreshed.', 'thinkrank'),
			});
		} catch (error) {
			logger.error('Error refreshing Search Console sites:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to refresh the property list.',
					'thinkrank'
				),
			});
		} finally {
			setIsRefreshing(false);
		}
	};

	const handleSave = async () => {
		setIsSaving(true);
		setNotice(null);
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/settings-management/category/seo_analytics',
				method: 'POST',
				data: {
					settings: {
						search_console_property: settings.site,
						enable_index_status: settings.enableIndexStatus,
					},
				},
			});

			if (response.success) {
				setNotice({
					status: 'success',
					message: __('Settings saved successfully.', 'thinkrank'),
				});
			} else {
				throw new Error(response.message || 'Failed to save settings');
			}
		} catch (error) {
			logger.error('Error saving Search Console settings:', error);
			setNotice({
				status: 'error',
				message: __('Failed to save settings.', 'thinkrank'),
			});
		} finally {
			setIsSaving(false);
		}
	};

	if (isLoading) {
		return (
			<div className="thinkrank-p-8 thinkrank-flex thinkrank-justify-center">
				<SkeletonCard lines={3} />
			</div>
		);
	}

	const selectedSite = sites.find((s) => s.siteUrl === settings.site);
	const permissionLabel = selectedSite
		? getPermissionLabel(selectedSite.permissionLevel)
		: '';

	return (
		<div
			style={{
				border: '1px solid var(--tr-border-card)',
				borderRadius: '10px',
				// Not `hidden`: the Property combobox's dropdown is
				// absolutely positioned and would be clipped by the card edge.
				overflow: 'visible',
				display: 'flex',
				flexDirection: 'column',
				background: '#fff',
				height: '100%',
			}}
		>
			<style>{`
                .tr-gsc-action { background: none; border: 0; padding: 0; cursor: pointer; font-size: 13px; font-weight: 500; color: var(--tr-text-subtle); transition: color .15s ease; }
                .tr-gsc-action:hover { color: var(--tr-text-heading); }
                .tr-gsc-action--danger:hover { color: var(--tr-danger-text); }
            `}</style>

			{/* Header */}
			<div
				style={{
					padding: '14px 16px',
					borderBottom: '1px solid var(--tr-border-light)',
					display: 'flex',
					alignItems: 'center',
					justifyContent: 'space-between',
					gap: '12px',
					flexWrap: 'wrap',
				}}
			>
				<div
					style={{
						display: 'flex',
						alignItems: 'center',
						gap: '10px',
					}}
				>
					<span
						style={{
							fontWeight: 600,
							fontSize: '16px',
							color: 'var(--tr-text-heading)',
						}}
					>
						{__('Search Console', 'thinkrank')}
					</span>
					<span
						style={{
							display: 'inline-flex',
							alignItems: 'center',
							gap: '6px',
							background: 'var(--tr-success-bg)',
							color: 'var(--tr-success-text)',
							padding: '3px 10px',
							borderRadius: '999px',
							fontSize: '12px',
							fontWeight: 600,
						}}
					>
						<span
							style={{
								width: '6px',
								height: '6px',
								borderRadius: '50%',
								background: 'currentColor',
							}}
							aria-hidden="true"
						/>
						{__('Connected', 'thinkrank')}
					</span>
					{permissionLabel && (
						<span
							style={{
								background: 'var(--tr-accent-bg)',
								color: 'var(--tr-accent)',
								padding: '2px 8px',
								borderRadius: '8px',
								fontSize: '12px',
								fontWeight: 500,
							}}
						>
							{permissionLabel}
						</span>
					)}
				</div>
				<div
					style={{
						display: 'flex',
						alignItems: 'center',
						gap: '12px',
					}}
				>
					<button
						type="button"
						onClick={onTestConnection}
						className="tr-gsc-action"
					>
						{__('Test Connection', 'thinkrank')}
					</button>
					<span
						aria-hidden="true"
						style={{
							width: '1px',
							height: '12px',
							background: 'var(--tr-border-card)',
						}}
					/>
					<button
						type="button"
						onClick={onReconnect}
						className="tr-gsc-action"
					>
						{__('Reconnect', 'thinkrank')}
					</button>
					<span
						aria-hidden="true"
						style={{
							width: '1px',
							height: '12px',
							background: 'var(--tr-border-card)',
						}}
					/>
					<button
						type="button"
						onClick={onDisconnect}
						className="tr-gsc-action tr-gsc-action--danger"
					>
						{__('Disconnect', 'thinkrank')}
					</button>
				</div>
			</div>

			{/* Body */}
			<div
				style={{
					padding: '16px',
					display: 'flex',
					flexDirection: 'column',
					gap: '16px',
					flex: 1,
				}}
			>
				{notice && (
					<div
						style={{
							padding: '10px 12px',
							borderRadius: '8px',
							fontSize: '13px',
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'space-between',
							gap: '12px',
							background:
								notice.status === 'success'
									? 'var(--tr-success-bg)'
									: 'var(--tr-danger-bg)',
							color:
								notice.status === 'success'
									? 'var(--tr-success-text)'
									: 'var(--tr-danger-text)',
						}}
					>
						<span>{notice.message}</span>
						<button
							type="button"
							onClick={() => setNotice(null)}
							style={{
								background: 'none',
								border: 'none',
								cursor: 'pointer',
								color: 'inherit',
								fontSize: '16px',
								lineHeight: 1,
								padding: 0,
							}}
							aria-label={__('Dismiss', 'thinkrank')}
						>
							×
						</button>
					</div>
				)}

				<SearchableSelect
					label={__('Property', 'thinkrank')}
					value={settings.site}
					placeholder={__('Search or select a site…', 'thinkrank')}
					options={sites.map((site) => ({
						label: getSiteLabel(site.siteUrl),
						value: site.siteUrl,
					}))}
					onChange={(site) => setSettings({ ...settings, site })}
				/>

				<div style={{ marginTop: '8px' }}>
					<Button
						variant="ghost"
						size="sm"
						onClick={handleRefreshSites}
						loading={isRefreshing}
						disabled={isRefreshing}
					>
						{isRefreshing
							? __('Refreshing…', 'thinkrank')
							: __('Refresh property list', 'thinkrank')}
					</Button>
				</div>

				<div
					style={{
						display: 'flex',
						justifyContent: 'flex-end',
						alignItems: 'center',
						marginTop: 'auto',
						paddingTop: '16px',
					}}
				>
					<Button
						variant="primary"
						onClick={handleSave}
						loading={isSaving}
						disabled={isSaving}
					>
						{__('Save Settings', 'thinkrank')}
					</Button>
				</div>
			</div>
		</div>
	);
};

export default SearchConsoleSettings;
