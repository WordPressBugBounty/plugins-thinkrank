/**
 * Instant Indexing Settings Component
 *
 * Redesigned — uses plugin UI components and Tailwind classes.
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SkeletonCard, Card, Input, Button, Container } from '../../ui';
import apiFetch from '@wordpress/api-fetch';
import SettingsCard from '../../common/SettingsCard';
import SectionHeader from '../../common/SectionHeader';
import FormField from '../../common/FormField';
import useAutosaveSettings from '../../../hooks/useAutosaveSettings';
import logger from '../../../../shared/utils/logger';

const IconSearch = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<circle cx="11" cy="11" r="8" />
		<line x1="21" y1="21" x2="16.65" y2="16.65" />
	</svg>
);

const IconCopy = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
		<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
	</svg>
);

const IconKey = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4" />
	</svg>
);

const IconLayers = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<polygon points="12 2 2 7 12 12 22 7 12 2" />
		<polyline points="2 17 12 22 22 17" />
		<polyline points="2 12 12 17 22 12" />
	</svg>
);

/**
 * Instant Indexing Settings Component
 */
const InstantIndexingSettings = () => {
	const [isLoading, setIsLoading] = useState(true);
	const [isSaving, setIsSaving] = useState(false);
	const [hasChanges, setHasChanges] = useState(false);
	const [postTypes, setPostTypes] = useState([]);
	const [settings, setSettings] = useState({
		enabled: false,
		auto_submit_post_types: ['post', 'page'],
		api_key: '',
	});
	const [notice, setNotice] = useState(null);

	const [loadError, setLoadError] = useState(false);
	// Active reachability check of the advertised key file (#247). null until a
	// check has run.
	const [keyStatus, setKeyStatus] = useState(null);
	const [keyChecking, setKeyChecking] = useState(false);

	useEffect(() => {
		loadData();
	}, []);

	const loadData = async () => {
		setIsLoading(true);
		try {
			const postTypesResponse = await apiFetch({
				path: '/thinkrank/v1/instant-indexing/post-types',
			});

			if (
				postTypesResponse &&
				postTypesResponse.success &&
				Array.isArray(postTypesResponse.data)
			) {
				setPostTypes(postTypesResponse.data);
			} else {
				setPostTypes([]);
			}

			const settingsResponse = await apiFetch({
				path: '/thinkrank/v1/instant-indexing/settings',
				method: 'GET',
			}).catch(() => null);

			if (settingsResponse && settingsResponse.data) {
				setLoadError(false);
				setSettings({
					enabled:
						settingsResponse.data.enabled !== undefined
							? settingsResponse.data.enabled
							: false,
					auto_submit_post_types:
						settingsResponse.data.auto_submit_post_types || [],
					// Never fall back to a placeholder key — an empty key drives the
					// explicit error state below instead of a plausible wrong key.
					api_key: settingsResponse.data.api_key || '',
				});
				// When active, confirm the advertised key file is actually
				// reachable so an unreachable configuration is caught here.
				if (
					settingsResponse.data.enabled &&
					settingsResponse.data.api_key
				) {
					verifyKey();
				}
			} else {
				setLoadError(true);
				setSettings((prev) => ({
					...prev,
					enabled: prev.enabled !== undefined ? prev.enabled : false,
					api_key: '',
				}));
				setNotice({
					status: 'error',
					message: __(
						'Could not load Instant Indexing settings. Please reload to try again.',
						'thinkrank'
					),
				});
			}
		} catch (error) {
			logger.error('Error loading data:', error);
			setNotice({
				status: 'error',
				message: __('Failed to load settings.', 'thinkrank'),
			});
			if (postTypes.length === 0) {
				setPostTypes([
					{ slug: 'post', name: 'Posts' },
					{ slug: 'page', name: 'Pages' },
					{ slug: 'media', name: 'Media' },
				]);
			}
		} finally {
			setIsLoading(false);
		}
	};

	// Ask the server to fetch the advertised key URL and confirm it returns the
	// key. Surfaces an unreachable-key configuration (read-only root + Plain
	// permalinks, a CDN edge rule, etc.) here instead of as a silent 403 at the
	// first submission.
	const verifyKey = async () => {
		setKeyChecking(true);
		try {
			const res = await apiFetch({
				path: '/thinkrank/v1/instant-indexing/verify-key',
			});
			setKeyStatus(res?.data || null);
		} catch (error) {
			logger.error('Key verification failed:', error);
			setKeyStatus(null);
		} finally {
			setKeyChecking(false);
		}
	};

	const handlePostTypeChange = (slug, isChecked) => {
		const currentTypes = settings.auto_submit_post_types;
		const newTypes = isChecked
			? [...currentTypes, slug]
			: currentTypes.filter((type) => type !== slug);
		updateSettings('auto_submit_post_types', newTypes);
	};

	const toggleAllPostTypes = () => {
		const allSlugs = postTypes.map((pt) => pt.slug);
		const areAllSelected = allSlugs.every((slug) =>
			settings.auto_submit_post_types.includes(slug)
		);
		updateSettings(
			'auto_submit_post_types',
			areAllSelected ? [] : allSlugs
		);
	};

	const updateSettings = (key, value) => {
		setSettings((prev) => ({ ...prev, [key]: value }));
		setHasChanges(true);
	};

	const saveSettings = async (settingsToSave) => {
		setIsSaving(true);
		setNotice(null);
		try {
			await apiFetch({
				path: '/thinkrank/v1/instant-indexing/settings',
				method: 'POST',
				data: settingsToSave,
			});
			setNotice({
				status: 'success',
				message: __('Settings saved successfully.', 'thinkrank'),
			});
			setHasChanges(false);
		} catch (error) {
			logger.error('Save failed:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to save settings. Please try again.',
					'thinkrank'
				),
			});
		} finally {
			setIsSaving(false);
		}
	};

	// The feature switch IS the save: flipping it persists immediately and rolls
	// back if the request fails. The post types and the API key keep using the
	// Save Changes button.
	const { update: updateFeature, isSaving: isTogglingFeature } =
		useAutosaveSettings({
			settings,
			setSettings,
			save: async (next) => {
				await apiFetch({
					path: '/thinkrank/v1/instant-indexing/settings',
					method: 'POST',
					data: next,
				});
			},
			logLabel: 'Instant Indexing',
		});

	const handleKeyChange = async () => {
		// eslint-disable-next-line no-alert
		const confirmChange = window.confirm(
			__(
				'Are you sure you want to generate a new API Key? This will require updating the key on any external services using it.',
				'thinkrank'
			)
		);
		if (confirmChange) {
			setIsSaving(true);
			try {
				const response = await apiFetch({
					path: '/thinkrank/v1/instant-indexing/regenerate-key',
					method: 'POST',
				});
				if (response && response.key) {
					updateSettings('api_key', response.key);
					setNotice({
						status: 'success',
						message: __(
							'API Key generated successfully.',
							'thinkrank'
						),
					});
					// Re-check reachability against the freshly written key.
					verifyKey();
				}
			} catch (e) {
				const randomKey = Array(32)
					.fill(0)
					.map(() => Math.random().toString(16)[2])
					.join('');
				updateSettings('api_key', randomKey);
			} finally {
				setIsSaving(false);
			}
		}
	};

	if (isLoading) {
		return (
			<div className="thinkrank-loading-container">
				<SkeletonCard lines={3} />
			</div>
		);
	}

	const allSelected =
		postTypes.length > 0 &&
		postTypes.every((pt) =>
			settings.auto_submit_post_types.includes(pt.slug)
		);
	// Only build the key-file URL when we actually have a key — otherwise the
	// location/Check-Key links point at a nonexistent ".txt".
	const keyFileUrl = settings.api_key
		? `${window.thinkrankAdmin?.siteUrl || ''}/${settings.api_key}.txt`
		: '';

	return (
		<div className="thinkrank-instant-indexing-settings">
			<SettingsCard
				title={__('Instant Indexing Settings', 'thinkrank')}
				enabled={settings.enabled}
				onToggle={(value) =>
					updateFeature(
						{ enabled: value },
						value
							? __('Instant Indexing is on.', 'thinkrank')
							: __('Instant Indexing is off.', 'thinkrank')
					)
				}
				toggleLabel={__('Enable Instant Indexing', 'thinkrank')}
				toggleBusy={isTogglingFeature}
				notice={notice}
				onNoticeRemove={() => setNotice(null)}
				showDisabledWarning={true}
				disabledWarningMessage={__(
					'Instant Indexing features are disabled. Enable to automatically submit content to search engines via IndexNow.',
					'thinkrank'
				)}
			>
				<Container direction="column" gap={4}>
					{/* ── Auto-Submit Post Types ─────────────────────────────── */}
					<Card padding={false}>
						<SectionHeader
							title={__('Auto-Submit Post Types', 'thinkrank')}
							description={__(
								'Choose which post types should trigger instant indexing on publish or update.',
								'thinkrank'
							)}
						/>
						<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
							<Button
								variant="secondary"
								size="sm"
								onClick={toggleAllPostTypes}
								disabled={!settings.enabled}
							>
								{allSelected
									? __('Deselect All', 'thinkrank')
									: __('Select / Deselect All', 'thinkrank')}
							</Button>

							<div className="tr-grid tr-grid-cols-2 tr-gap-3">
								{postTypes.map((type) => (
									<label
										key={type.slug}
										htmlFor={`tr-indexnow-pt-${type.slug}`}
										className={`tr-flex tr-items-center tr-gap-2.5 tr-cursor-pointer ${!settings.enabled ? 'tr-opacity-50 tr-cursor-not-allowed' : ''}`}
									>
										<input
											id={`tr-indexnow-pt-${type.slug}`}
											type="checkbox"
											className="tr-w-4 tr-h-4 tr-rounded tr-border-primary tr-accent-blue-600"
											checked={settings.auto_submit_post_types.includes(
												type.slug
											)}
											onChange={(e) =>
												handlePostTypeChange(
													type.slug,
													e.target.checked
												)
											}
											disabled={!settings.enabled}
										/>
										<span className="tr-text-sm tr-text-secondary">
											{type.name}
										</span>
									</label>
								))}
							</div>

							<p className="tr-text-xs tr-text-tertiary tr-m-0">
								{__(
									'Keep search engines updated by instantly notifying IndexNow when content from these post types is published, modified, or removed.',
									'thinkrank'
								)}
							</p>
						</div>
					</Card>

					{/* ── API Key ───────────────────────────────────────────── */}
					<Card padding={false}>
						<SectionHeader
							title={__('API Key', 'thinkrank')}
							description={__(
								'Your IndexNow API key and key-file location.',
								'thinkrank'
							)}
							trailing={
								<Button
									variant="secondary"
									size="sm"
									onClick={handleKeyChange}
									disabled={!settings.enabled}
								>
									{__('Change Key', 'thinkrank')}
								</Button>
							}
						/>
						<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
							<FormField
								id="tr-ii-api-key"
								label={__('API Key', 'thinkrank')}
								disabled={!settings.enabled}
							>
								<Input
									id="tr-ii-api-key"
									value={settings.api_key || ''}
									readOnly
									disabled={!settings.enabled}
								/>
							</FormField>

							<p className="tr-text-xs tr-text-tertiary tr-m-0">
								{__(
									'The IndexNow API key connects your indexing requests to this site and is generated automatically. You can replace it anytime if you think it has been exposed or shared.',
									'thinkrank'
								)}
							</p>

							{loadError && !settings.api_key && (
								<p className="tr-text-xs tr-text-red-700 tr-m-0">
									{__(
										'The API key could not be loaded. Reload the page to try again.',
										'thinkrank'
									)}
								</p>
							)}

							{settings.api_key && (
								<>
									<div>
										<p className="tr-text-sm tr-font-medium tr-text-secondary tr-m-0 tr-mb-2">
											{__(
												'API Key Location',
												'thinkrank'
											)}
										</p>
										<div
											className={`tr-flex tr-items-center tr-border tr-border-secondary tr-rounded-lg tr-bg-white tr-overflow-hidden ${!settings.enabled ? 'tr-opacity-60' : ''}`}
										>
											<input
												type="text"
												value={keyFileUrl}
												readOnly
												className="tr-flex-1 tr-bg-transparent tr-border-0 tr-px-3 tr-py-2 tr-text-sm tr-text-secondary tr-m-0"
												style={{
													fontFamily: 'monospace',
													outline: 'none',
													boxShadow: 'none',
												}}
											/>
											<button
												onClick={() =>
													navigator.clipboard.writeText(
														keyFileUrl
													)
												}
												className="tr-flex tr-items-center tr-justify-center tr-w-9 tr-h-9 tr-bg-transparent tr-border-0 tr-border-l tr-border-secondary tr-cursor-pointer tr-text-tertiary"
												disabled={!settings.enabled}
												aria-label={__(
													'Copy URL',
													'thinkrank'
												)}
												style={{ flexShrink: 0 }}
											>
												<IconCopy />
											</button>
										</div>
									</div>

									<p className="tr-text-xs tr-text-tertiary tr-m-0">
										{__(
											'The Check Key button quickly tests your IndexNow setup by opening the key file in a new tab so you can confirm it loads and matches the API key shown above.',
											'thinkrank'
										)}
									</p>

									{/* Active reachability result (#247): catches an
									    unreachable key here rather than at first submit. */}
									{settings.enabled && keyStatus && (
										<div
											className={`tr-flex tr-items-start tr-gap-2 tr-rounded-lg tr-px-3 tr-py-2 tr-text-xs ${
												keyStatus.reachable
													? 'tr-bg-success-bg tr-text-success'
													: 'tr-bg-warning-bg tr-text-warning'
											}`}
										>
											<span className="tr-font-semibold tr-shrink-0">
												{keyStatus.reachable
													? '✓'
													: '!'}
											</span>
											<span>
												{keyStatus.reachable
													? __(
															'Key file is reachable. Search engines can verify your site.',
															'thinkrank'
														)
													: keyStatus.reason ||
														__(
															'The key file could not be verified. Open it in a browser to confirm it shows the key.',
															'thinkrank'
														)}
											</span>
										</div>
									)}

									<div className="tr-flex tr-flex-wrap tr-gap-2">
										<a
											href={keyFileUrl}
											target="_blank"
											rel="noopener noreferrer"
											className={`tr-inline-flex tr-items-center tr-gap-2 tr-px-3 tr-py-2 tr-text-sm tr-border tr-border-secondary tr-rounded-lg tr-text-secondary tr-no-underline tr-bg-white ${!settings.enabled ? 'tr-opacity-50 tr-pointer-events-none' : 'hover:tr-bg-white'}`}
										>
											<IconSearch />
											{__('Check Key', 'thinkrank')}
										</a>
										<Button
											variant="secondary"
											size="sm"
											onClick={verifyKey}
											loading={keyChecking}
											disabled={!settings.enabled}
										>
											{__('Verify key file', 'thinkrank')}
										</Button>
									</div>
								</>
							)}
						</div>
					</Card>

					{/* Save button */}
					<div className="tr-flex tr-justify-end">
						<Button
							variant="primary"
							onClick={() => saveSettings(settings)}
							loading={isSaving}
							disabled={!hasChanges || isSaving}
						>
							{isSaving
								? __('Saving…', 'thinkrank')
								: __('Save Changes', 'thinkrank')}
						</Button>
					</div>
				</Container>
			</SettingsCard>
		</div>
	);
};

export default InstantIndexingSettings;
