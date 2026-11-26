/**
 * Integrations Tab Component
 *
 * Centralized management for all external API integrations
 * Following DRY and KISS principles for clean, maintainable code
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	SkeletonCard,
	Card,
	Input,
	Select,
	Toggle,
	Button,
	Alert,
	Container,
	Tabs,
	TabPanel,
	Badge,
} from '../ui';
import apiFetch from '@wordpress/api-fetch';
import { toast } from 'react-toastify';
import FormField from '../common/FormField';
import ConnectGoogleButton from './ConnectGoogleButton';
import SearchConsoleSettings from './SearchConsoleSettings';
import ProGatedTab from '../pro/ProGatedTab';
import {
	googleConnectCopy,
	googleConnectIncludesGa4,
} from './googleConnectCopy';
import ProTeaser from '../pro/ProTeaser';
import { GoogleAnalyticsIllustration } from '../pro/pro-teaser-illustrations';
import logger from '../../../shared/utils/logger';
import { getGoogleConnectUrl, RECONNECT_REASON } from '../../utils/googleOAuth';
import {
	adoptStoredSocialSettings,
	buildSocialPlatformCardPayload,
	settledSocialPlatformKeys,
	socialPlatformSaveErrors,
} from './socialPlatformCardPayload';

/**
 * Why the site needs to re-authorize with Google. Keyed by the reason PHP
 * stores in `thinkrank_google_reconnect_required`.
 */
const RECONNECT_MESSAGES = {
	contract: __(
		'ThinkRank now connects to Google through a hosted service, so your previous connection needs to be re-authorized once.',
		'thinkrank'
	),
	credentials: __(
		'Your saved Google credentials could not be read. This usually happens after the site’s security keys change or it is moved to a new server. Please connect again.',
		'thinkrank'
	),
	revoked: __(
		'Google has revoked access for this site. This happens if the connection was removed from your Google account, or replaced by a newer one. Please connect again.',
		'thinkrank'
	),
};

/**
 * Default integration settings
 * KISS: Simple, flat structure for Google API keys only
 */
const getDefaultIntegrationsSettings = () => ({
	// Google API Keys
	google_analytics_api_key: '',
	google_search_console_api_key: '',
	google_pagespeed_api_key: '',

	// Connection state, rendered as status rather than edited here.
	google_account_connected: false,
});

/*
 * Deliberately absent from the defaults above: api_timeout, cache_duration,
 * auto_test_connections and retry_failed_requests. Each was declared here with
 * no control anywhere in the admin to render it, so the tab claimed to manage
 * settings a user could not reach — and round-tripped them on every save, which
 * made them look maintained (#297). They remain real settings on the PHP side,
 * defaulted in Settings::defaults and adjustable by code or filter; retry
 * tuning in particular is ours to choose (see #288) rather than something a
 * site owner should have to reason about. Reintroduce a key here only together
 * with the control that edits it.
 */

/**
 * Default social media settings
 * DRY: Reuse existing social media settings structure
 */
const getDefaultSocialSettings = () => ({
	// Search-engine site verification. Plain values, not masked: every one of
	// these is printed in the page head, so hiding it here would only stop the
	// user checking what is stored (#631).
	google_site_verification: '',
	bing_site_verification: '',
	yandex_site_verification: '',
	baidu_site_verification: '',

	// Facebook settings
	facebook_app_id: '',
	facebook_admins: '',

	// Pinterest settings
	pinterest_site_verification: '',

	// Instagram settings
	instagram_verification: '',

	// TikTok settings
	tiktok_verification: '',

	// YouTube settings
	youtube_channel_id: '',

	// WhatsApp Business settings
	whatsapp_business_id: '',
});

/**
 * Integrations Tab Component
 * DRY: Reuses patterns from Analytics tab but simplified
 * @param root0
 * @param root0.activeSubSection
 */
/**
 * Fields owned by each Social Platforms card, keyed by card id. Each card in the
 * Social Platforms sub-section saves only its own fields, so users can configure
 * and persist one platform at a time from its header Save button.
 */
const SOCIAL_PLATFORM_CARDS = {
	'search-engines': [
		'google_site_verification',
		'bing_site_verification',
		'yandex_site_verification',
		'baidu_site_verification',
	],
	facebook: ['facebook_app_id', 'facebook_admins'],
	pinterest: ['pinterest_site_verification'],
	instagram: ['instagram_verification'],
	tiktok: ['tiktok_verification'],
	youtube: ['youtube_channel_id'],
	whatsapp: ['whatsapp_business_id'],
};

/**
 * The four search-engine verification fields, which are the only ones an empty
 * value is allowed to clear.
 *
 * Every other field here is dropped when empty so an untouched (and, for the
 * encrypted ones, masked) value cannot clobber what is stored. A verification
 * code has to be removable though: once a property is gone from Search Console
 * the tag has to come off the page. So an emptied one of these is forwarded,
 * but only when the user actually edited it — a failed settings load falls back
 * to all-empty defaults, and a section save after that must not wipe four codes
 * nobody touched.
 */
const SITE_VERIFICATION_KEYS = SOCIAL_PLATFORM_CARDS['search-engines'];

/**
 * Share-nodes mark for the Social Platforms hero, drawn in the same line-icon
 * style as the rest of the admin (matches the MCP page treatment).
 */
const IconSocial = () => (
	<svg
		width="22"
		height="22"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<circle cx="18" cy="5" r="3" />
		<circle cx="6" cy="12" r="3" />
		<circle cx="18" cy="19" r="3" />
		<line x1="8.59" y1="13.51" x2="15.42" y2="17.49" />
		<line x1="15.41" y1="6.51" x2="8.59" y2="10.49" />
	</svg>
);

/**
 * Tab metadata for the Social Platforms sub-section, in display order. Ids match
 * SOCIAL_PLATFORM_CARDS keys. Labels are resolved at render time so they stay
 * translatable.
 */
const SOCIAL_PLATFORM_TABS = [
	{ id: 'search-engines', label: 'Search Engines' },
	{ id: 'facebook', label: 'Facebook' },
	{ id: 'pinterest', label: 'Pinterest' },
	{ id: 'instagram', label: 'Instagram' },
	{ id: 'tiktok', label: 'TikTok' },
	{ id: 'youtube', label: 'YouTube' },
	{ id: 'whatsapp', label: 'WhatsApp Business' },
];

const IntegrationsTab = ({ activeSubSection = 'google-services' }) => {
	const [isLoading, setIsLoading] = useState(true);
	const [isSaving, setIsSaving] = useState(false);
	// Per-card dirty tracking + which card is currently saving, so each Social
	// Platforms card's header Save button enables/spins independently.
	const [dirtyKeys, setDirtyKeys] = useState(() => new Set());
	const [savingCard, setSavingCard] = useState(null);
	// Which platform tab is active within the Social Platforms sub-section.
	const [activePlatformTab, setActivePlatformTab] = useState('facebook');
	const [isTestingConnection, setIsTestingConnection] = useState(false);
	const [integrationsSettings, setIntegrationsSettings] = useState(
		getDefaultIntegrationsSettings()
	);
	const [socialSettings, setSocialSettings] = useState(
		getDefaultSocialSettings()
	);
	// The values as of the latest render, so a save that resolves later can
	// tell which fields were edited while its request was in flight.
	const socialSettingsRef = useRef(socialSettings);
	socialSettingsRef.current = socialSettings;
	const [hasChanges, setHasChanges] = useState(false);
	const [notice, setNotice] = useState(null);
	const [connectionStatus, setConnectionStatus] = useState(null);

	// Load settings on mount
	useEffect(() => {
		loadSettings();
	}, []);

	/**
	 * Load integration settings
	 * DRY: Reuses Settings Manager pattern for both categories
	 */
	const loadSettings = async () => {
		try {
			setIsLoading(true);

			// Load both settings categories in parallel — each REST call costs
			// a full WP bootstrap server-side, so sequential awaits double the
			// wait for no reason.
			const [integrationsResponse, socialResponse] = await Promise.all([
				apiFetch({
					path: '/thinkrank/v1/integrations/settings',
					method: 'GET',
				}),
				apiFetch({
					path: '/thinkrank/v1/social-platforms/settings',
					method: 'GET',
				}),
			]);

			if (integrationsResponse.success) {
				const loadedSettings = {
					...getDefaultIntegrationsSettings(),
					...integrationsResponse.data.settings,
				};

				// Masked API keys (XXXX pattern) will be displayed in password fields
				// The save logic prevents these masked values from being sent back to the server

				setIntegrationsSettings(loadedSettings);
			} else {
				setIntegrationsSettings(getDefaultIntegrationsSettings());
			}

			if (socialResponse.success) {
				const loadedSocialSettings = {
					...getDefaultSocialSettings(),
					...socialResponse.data.settings,
				};

				// Masked verification codes (XXXX pattern) will be displayed in text fields
				// Public IDs (facebook_app_id, facebook_admins, youtube_channel_id, whatsapp_business_id) are not encrypted and show full values
				// Verification codes (pinterest_site_verification, instagram_verification, tiktok_verification) are encrypted and show masked values
				// The save logic prevents masked values from being sent back to the server

				setSocialSettings(loadedSocialSettings);
			} else {
				setSocialSettings(getDefaultSocialSettings());
			}
		} catch (error) {
			logger.error('Failed to load integration settings:', error);
			setNotice({
				status: 'error',
				message: __(
					'Failed to load integration settings. Please refresh the page.',
					'thinkrank'
				),
			});
		} finally {
			setIsLoading(false);
		}
	};

	/**
	 * Handle integrations setting changes (Google API keys)
	 * KISS: Simple state update pattern
	 * @param key
	 * @param value
	 */
	const handleIntegrationsSettingChange = (key, value) => {
		setIntegrationsSettings((prev) => ({ ...prev, [key]: value }));
		setHasChanges(true);
		setNotice(null);
	};

	/**
	 * Handle social setting changes (Platform IDs and verification codes)
	 * DRY: Same pattern as integrations settings
	 * @param key
	 * @param value
	 */
	const handleSocialSettingChange = (key, value) => {
		setSocialSettings((prev) => ({ ...prev, [key]: value }));
		setDirtyKeys((prev) => new Set(prev).add(key));
		setHasChanges(true);
		setNotice(null);
	};

	/**
	 * Whether a Social Platforms card has unsaved edits.
	 * @param {string} cardId Key in SOCIAL_PLATFORM_CARDS.
	 * @return {boolean} True when any of the card's fields changed since load/save.
	 */
	const isCardDirty = (cardId) =>
		(SOCIAL_PLATFORM_CARDS[cardId] || []).some((key) => dirtyKeys.has(key));

	/**
	 * Save a single Social Platforms card's fields.
	 *
	 * Sends only this card's keys, dropping empty and still-masked values so an
	 * untouched (encrypted) secret is never clobbered by its placeholder; an
	 * emptied verification code is sent as a removal. Clears the dirty state of
	 * the fields it sent on success. An emptied field this route cannot clear is
	 * reported and left dirty, never settled as if it had saved.
	 * @param {string} cardId Key in SOCIAL_PLATFORM_CARDS.
	 * @param {string} label  Platform name for the notice.
	 */
	const saveSocialPlatformCard = async (cardId, label) => {
		const keys = SOCIAL_PLATFORM_CARDS[cardId] || [];
		const snapshot = socialSettings;

		const { settings, unsentRemovals } = buildSocialPlatformCardPayload(
			keys,
			socialSettings,
			dirtyKeys,
			SITE_VERIFICATION_KEYS
		);

		// Settle only what was sent, plus the untouched masked placeholders that
		// had nothing to send. A removal that could not be sent stays dirty, and
		// so does a field edited again while the request was in flight.
		const clearDirty = () => {
			const settled = settledSocialPlatformKeys(
				keys,
				socialSettingsRef.current,
				snapshot,
				unsentRemovals
			);
			setDirtyKeys((prev) => {
				const next = new Set(prev);
				settled.forEach((key) => next.delete(key));
				return next;
			});
		};

		const reportUnsentRemovals = () =>
			toast.error(
				sprintf(
					// translators: %s: social platform name.
					__(
						'%s: an emptied field cannot be cleared here, so the saved value was kept. Enter a new value to replace it.',
						'thinkrank'
					),
					label
				)
			);

		// Nothing to persist. Unchanged masked placeholders just settle; an
		// emptied field that cannot be cleared is an error, not a save.
		if (Object.keys(settings).length === 0) {
			if (unsentRemovals.length > 0) {
				reportUnsentRemovals();
				return;
			}
			clearDirty();
			return;
		}

		try {
			setSavingCard(cardId);

			const response = await apiFetch({
				path: '/thinkrank/v1/social-platforms/settings',
				method: 'POST',
				data: { settings },
			});

			if (response.success) {
				// Adopt what the server stored, not what was typed. The save
				// route normalises: a whole <meta> tag pasted into a
				// verification field is stored as the code inside it, and
				// leaving the tag sitting in the box afterwards reads as a save
				// that did not take (#631). This is what a reload would show,
				// so the two agree. Only this card's sent fields are adopted,
				// and only where they were not edited again meanwhile: the
				// response holds every platform, and merging all of it threw
				// away unsaved edits on the other cards.
				const stored = response?.data?.settings;
				setSocialSettings((current) =>
					adoptStoredSocialSettings(
						current,
						snapshot,
						settings,
						stored
					)
				);

				clearDirty();
				toast.success(
					sprintf(
						// translators: %s: social platform name.
						__('%s settings saved successfully!', 'thinkrank'),
						label
					)
				);
				if (unsentRemovals.length > 0) {
					reportUnsentRemovals();
				}
			} else {
				throw new Error('Failed to save social platform settings');
			}
		} catch (error) {
			logger.error('Save error:', error);
			const reason = socialPlatformSaveErrors(error);
			toast.error(
				reason
					? sprintf(
							// translators: 1: social platform name, 2: why the value was refused.
							__('%1$s not saved: %2$s', 'thinkrank'),
							label,
							reason
						)
					: sprintf(
							// translators: %s: social platform name.
							__(
								'Failed to save %s settings. Please try again.',
								'thinkrank'
							),
							label
						)
			);
		} finally {
			setSavingCard(null);
		}
	};

	/**
	 * Reusable Save button for a Social Platforms card.
	 * @param {string} cardId Key in SOCIAL_PLATFORM_CARDS.
	 * @param {string} label  Platform name.
	 * @return {JSX.Element} The Save button element.
	 */
	const cardSaveButton = (cardId, label) => (
		<Button
			variant="primary"
			size="md"
			onClick={() => saveSocialPlatformCard(cardId, label)}
			loading={savingCard === cardId}
			disabled={!isCardDirty(cardId) || savingCard !== null}
		>
			{savingCard === cardId
				? __('Saving…', 'thinkrank')
				: __('Save', 'thinkrank')}
		</Button>
	);

	/**
	 * Card footer holding the Save button, right-aligned below the fields.
	 * @param {string} cardId Key in SOCIAL_PLATFORM_CARDS.
	 * @param {string} label  Platform name.
	 * @return {JSX.Element} The footer row.
	 */
	const cardSaveFooter = (cardId, label) => (
		<div className="tr-flex tr-justify-end tr-border-t tr-border-secondary tr-px-6 tr-py-4">
			{cardSaveButton(cardId, label)}
		</div>
	);

	/**
	 * Save settings for current active tab only
	 * KISS: Only save the current tab's settings
	 */
	const saveSettings = async () => {
		try {
			setIsSaving(true);
			setNotice(null);

			if (activeSubSection === 'google-services') {
				// Save Google Services (Integrations) settings only
				const integrationsData = { ...integrationsSettings };

				// Remove empty API keys and masked placeholders to avoid overwriting existing encrypted keys
				if (
					!integrationsData.google_analytics_api_key ||
					!integrationsData.google_analytics_api_key.trim() ||
					integrationsData.google_analytics_api_key.includes('XXXX')
				) {
					delete integrationsData.google_analytics_api_key;
				}
				if (
					!integrationsData.google_search_console_api_key ||
					!integrationsData.google_search_console_api_key.trim() ||
					integrationsData.google_search_console_api_key.includes(
						'XXXX'
					)
				) {
					delete integrationsData.google_search_console_api_key;
				}
				if (
					!integrationsData.google_pagespeed_api_key ||
					!integrationsData.google_pagespeed_api_key.trim() ||
					integrationsData.google_pagespeed_api_key.includes('XXXX')
				) {
					delete integrationsData.google_pagespeed_api_key;
				}

				const response = await apiFetch({
					path: '/thinkrank/v1/integrations/settings',
					method: 'POST',
					data: { settings: integrationsData },
				});

				if (response.success) {
					setHasChanges(false);
					toast.success(
						__(
							'Google Services settings saved successfully!',
							'thinkrank'
						)
					);
				} else {
					throw new Error('Failed to save Google Services settings');
				}
			} else if (activeSubSection === 'social-platforms') {
				// Save Social Platforms settings only
				const snapshot = socialSettings;
				const socialData = { ...socialSettings };

				// An empty verification field only clears the stored code when
				// the user emptied it. A failed load leaves these at their
				// all-empty defaults, and a section save after that must not
				// take four codes off the page nobody asked about.
				SITE_VERIFICATION_KEYS.forEach((key) => {
					if (!socialData[key]?.trim() && !dirtyKeys.has(key)) {
						delete socialData[key];
					}
				});

				// Remove empty or still-masked verification codes to avoid
				// overwriting existing encrypted codes. Codes are loaded masked
				// (e.g. `a1b2XXXX`); resending an untouched masked value would
				// clobber the real secret, so strip it exactly like the Google
				// Services keys above. The server also guards against this.
				if (
					!socialData.pinterest_site_verification?.trim() ||
					socialData.pinterest_site_verification.includes('XXXX')
				) {
					delete socialData.pinterest_site_verification;
				}
				if (
					!socialData.instagram_verification?.trim() ||
					socialData.instagram_verification.includes('XXXX')
				) {
					delete socialData.instagram_verification;
				}
				if (
					!socialData.tiktok_verification?.trim() ||
					socialData.tiktok_verification.includes('XXXX')
				) {
					delete socialData.tiktok_verification;
				}

				const response = await apiFetch({
					path: '/thinkrank/v1/social-platforms/settings',
					method: 'POST',
					data: { settings: socialData },
				});

				if (response.success) {
					// Adopt what the server stored, not what was typed. The
					// save route normalises: a whole <meta> tag pasted into a
					// verification field is stored as the code inside it, and
					// leaving the tag sitting in the box afterwards reads as a
					// save that did not take (#631). This is also what a page
					// reload would show, so the two agree. A field edited
					// while the request was in flight keeps the new edit.
					const stored = response?.data?.settings;
					setSocialSettings((current) =>
						adoptStoredSocialSettings(
							current,
							snapshot,
							socialData,
							stored
						)
					);

					setHasChanges(false);
					toast.success(
						__(
							'Social Platform settings saved successfully!',
							'thinkrank'
						)
					);
				} else {
					throw new Error('Failed to save Social Platform settings');
				}
			}
		} catch (error) {
			logger.error('Save error:', error);
			const reason = socialPlatformSaveErrors(error);
			toast.error(
				reason
					? sprintf(
							// translators: %s: why a value was refused.
							__('Settings not saved: %s', 'thinkrank'),
							reason
						)
					: __(
							'Failed to save settings. Please try again.',
							'thinkrank'
						)
			);
		} finally {
			setIsSaving(false);
		}
	};

	/**
	 * Test API connections
	 * DRY: Reuses connection testing pattern
	 */
	const testConnections = async () => {
		try {
			setIsTestingConnection(true);
			setNotice(null);

			const response = await apiFetch({
				path: '/thinkrank/v1/integrations/test-connections',
				method: 'POST',
			});

			if (response.success) {
				setConnectionStatus(response.data);
				setNotice({
					status: 'success',
					message: __(
						'Connection test completed successfully!',
						'thinkrank'
					),
				});
			} else {
				throw new Error(response.error || 'Connection test failed');
			}
		} catch (error) {
			logger.error('Connection test error:', error);
			setNotice({
				status: 'error',
				message: __(
					'Connection test failed. Please check your API keys.',
					'thinkrank'
				),
			});
		} finally {
			setIsTestingConnection(false);
		}
	};

	/**
	 * Render Google Services section
	 * KISS: Clean, focused component
	 */
	const renderGoogleServices = () => {
		// Google Analytics slot: Pro injects the real card via filter; off the
		// paid path the shared Pro teaser stands in.
		//
		// This has to ask the plan before the filter, not after. Pro's admin
		// bundle is enqueued even when the licence is invalid — deliberately, so
		// the License screen stays reachable (#512) — so its addFilter always
		// runs and a filter-first gate always took the Pro branch. On an
		// installed-but-unlicensed site GoogleAnalyticsSettings then rendered and
		// called /thinkrank-pro/v1/google-analytics/accounts, a route
		// load_components() never registered, so this section died on
		// "No route was found matching the URL and request method" where an
		// upsell belonged. ProGatedTab is the same gate the twelve other Pro
		// slots use (#533/#534); this one was wrongly cleared in that audit.
		const gaNode = (
			<ProGatedTab
				filter="thinkrank.integrations.gaAnalyticsSection"
				teaser={
					<ProTeaser
						illustration={<GoogleAnalyticsIllustration />}
						title={__('Google Analytics', 'thinkrank')}
						description={__(
							'Connect Google Analytics 4 to bring sessions, users, and engagement data into your ThinkRank dashboards no code or extra plugin required.',
							'thinkrank'
						)}
						features={[
							__(
								'Pick your GA4 account, property, and data stream in a few clicks',
								'thinkrank'
							),
							__(
								'See sessions, active users, and bounce rate alongside your SEO',
								'thinkrank'
							),
							__(
								'Optionally install the GA4 tracking code straight from ThinkRank',
								'thinkrank'
							),
						]}
					/>
				}
			/>
		);

		// GA4 comes with the connection only on licensed Pro (#1584).
		const connectCopy = googleConnectCopy(googleConnectIncludesGa4());

		return (
			<div className="thinkrank-tab-content">
				{/* Google Services Integration Workflow */}
				{integrationsSettings.google_account_connected ? (
					<div className="tr-mb-6">
						{/* Single column — Search Console and Google Analytics stacked */}
						<div
							style={{
								display: 'flex',
								flexDirection: 'column',
								gap: '16px',
							}}
						>
							<SearchConsoleSettings
								onDisconnect={async () => {
									if (
										!window.confirm(
											__(
												'Are you sure you want to disconnect your Google account? This will erase all saved authentication data.',
												'thinkrank'
											)
										)
									) {
										return;
									}

									try {
										await apiFetch({
											path: '/thinkrank/v1/integrations/google/disconnect',
											method: 'POST',
										});

										// Clear local state
										handleIntegrationsSettingChange(
											'google_account_connected',
											false
										);
										handleIntegrationsSettingChange(
											'google_access_token',
											''
										);
										handleIntegrationsSettingChange(
											'google_refresh_token',
											''
										);
										handleIntegrationsSettingChange(
											'google_token_expires_in',
											''
										);
										handleIntegrationsSettingChange(
											'google_token_created',
											''
										);
										// `google_search_console_site` is not a
										// declared setting — the write was a
										// no-op. The property picker uses
										// `search_console_property`.
										handleIntegrationsSettingChange(
											'search_console_property',
											''
										);
									} catch (error) {
										logger.error(
											'Error disconnecting:',
											error
										);
										// Even if API fails, we should probably clear local state to allow retry
										toast.error(
											__(
												'Error disconnecting account. Please check console.',
												'thinkrank'
											)
										);
									}
								}}
								onReconnect={async () => {
									try {
										// Clear existing tokens via API
										await apiFetch({
											path: '/thinkrank/v1/integrations/google/disconnect',
											method: 'POST',
										});

										// Restart the same signed connect flow the
										// button uses — the proxy owns the consent
										// URL, so there is nothing to keep in sync.
										window.location.href =
											getGoogleConnectUrl();
									} catch (error) {
										logger.error(
											'Error during reconnect:',
											error
										);
										toast.error(
											__(
												'Failed to reconnect. Please try again.',
												'thinkrank'
											)
										);
									}
								}}
								onTestConnection={testConnections}
							/>

							{/* Google Analytics — real Pro card or the shared teaser (computed above) */}
							{gaNode}
						</div>
					</div>
				) : (
					<Card padding={false} className="tr-mb-6">
						<div className="tr-px-6 tr-py-10 tr-flex tr-flex-col tr-items-center tr-text-center tr-gap-5">
							<div className="tr-w-16 tr-h-16 tr-rounded-2xl tr-bg-white tr-border tr-border-secondary tr-flex tr-items-center tr-justify-center">
								<svg
									width="36"
									height="36"
									viewBox="0 0 18 18"
									xmlns="http://www.w3.org/2000/svg"
									aria-hidden="true"
								>
									<path
										fill="#4285F4"
										d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844a4.14 4.14 0 0 1-1.796 2.716v2.258h2.908c1.702-1.567 2.684-3.875 2.684-6.615z"
									/>
									<path
										fill="#34A853"
										d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z"
									/>
									<path
										fill="#FBBC05"
										d="M3.964 10.707A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.707V4.961H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.04l3.007-2.333z"
									/>
									<path
										fill="#EA4335"
										d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.962L3.964 7.29C4.672 5.166 6.656 3.58 9 3.58z"
									/>
								</svg>
							</div>
							<div className="tr-flex tr-flex-col tr-gap-1.5 tr-max-w-md">
								<h3 className="tr-m-0 tr-text-lg tr-font-semibold tr-text-primary">
									{__(
										'Bring your Google data into ThinkRank',
										'thinkrank'
									)}
								</h3>
								<p className="tr-m-0 tr-text-sm tr-text-tertiary tr-leading-relaxed">
									{connectCopy.description}
								</p>
							</div>
							<ul className="tr-m-0 tr-p-0 tr-list-none tr-flex tr-flex-wrap tr-justify-center tr-gap-x-5 tr-gap-y-2">
								{connectCopy.perks.map((perk) => (
									<li
										key={perk}
										className="tr-flex tr-items-center tr-gap-1.5 tr-text-sm tr-text-secondary"
									>
										<svg
											width="14"
											height="14"
											viewBox="0 0 20 20"
											fill="currentColor"
											className="tr-text-success"
											aria-hidden="true"
										>
											<path
												fillRule="evenodd"
												d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.5 7.55a1 1 0 0 1-1.42 0L3.29 9.754a1 1 0 1 1 1.42-1.41l3.79 3.79 6.79-6.84a1 1 0 0 1 1.414-.005Z"
												clipRule="evenodd"
											/>
										</svg>
										{perk}
									</li>
								))}
							</ul>
							{RECONNECT_REASON && (
								<p
									className="tr-m-0 tr-text-sm tr-text-warning"
									role="status"
								>
									{RECONNECT_MESSAGES[RECONNECT_REASON] ||
										RECONNECT_MESSAGES.contract}
								</p>
							)}
							{/* No redirectTo: PHP already returns to this screen. */}
							<ConnectGoogleButton
								onConnect={() => {
									handleIntegrationsSettingChange(
										'google_account_connected',
										true
									);
								}}
							/>
							<p className="tr-m-0 tr-text-xs tr-text-tertiary">
								{__(
									'Read-only access. You can disconnect at any time.',
									'thinkrank'
								)}
							</p>
						</div>
					</Card>
				)}

				{/* <div className="thinkrank-settings-grid">
                <Card padding={false} className="tr-mb-6">
                    <div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
                        <h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">{__('Google API Keys', 'thinkrank')}</h3>
                        <p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
                            {__('Configure API keys for Google services integration', 'thinkrank')}
                        </p>
                    </div>
                    <div className="tr-p-6 tr-flex tr-flex-col tr-gap-4">
                        <Input
                            label={__('Google Analytics Data API Key', 'thinkrank')}
                            value={integrationsSettings.google_analytics_api_key}
                            onChange={(e) => handleIntegrationsSettingChange('google_analytics_api_key', e.target.value)}
                            description={
                                <>
                                    {__('Required for GA4 Analytics data retrieval. This will be encrypted and stored securely. ', 'thinkrank')}
                                    <a href="https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com" target="_blank" rel="noopener noreferrer">
                                        {__('Enable Analytics Data API →', 'thinkrank')}
                                    </a>
                                    {__(' | ', 'thinkrank')}
                                    <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">
                                        {__('Create API Key →', 'thinkrank')}
                                    </a>
                                </>
                            }
                            type="text"
                        />

                        <Input
                            label={__('Google Search Console API Key', 'thinkrank')}
                            value={integrationsSettings.google_search_console_api_key}
                            onChange={(e) => handleIntegrationsSettingChange('google_search_console_api_key', e.target.value)}
                            description={
                                <>
                                    {__('Required for Search Console data and keyword rankings. This will be encrypted and stored securely. ', 'thinkrank')}
                                    <a href="https://console.cloud.google.com/apis/library/searchconsole.googleapis.com" target="_blank" rel="noopener noreferrer">
                                        {__('Enable Search Console API →', 'thinkrank')}
                                    </a>
                                    {__(' | ', 'thinkrank')}
                                    <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">
                                        {__('Create API Key →', 'thinkrank')}
                                    </a>
                                </>
                            }
                            type="text"
                        />

                        <Input
                            label={__('Google PageSpeed Insights API Key', 'thinkrank')}
                            value={integrationsSettings.google_pagespeed_api_key}
                            onChange={(e) => handleIntegrationsSettingChange('google_pagespeed_api_key', e.target.value)}
                            description={
                                <>
                                    {__('Required for Core Web Vitals and performance data. This will be encrypted and stored securely. ', 'thinkrank')}
                                    <a href="https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com" target="_blank" rel="noopener noreferrer">
                                        {__('Enable PageSpeed Insights API →', 'thinkrank')}
                                    </a>
                                    {__(' | ', 'thinkrank')}
                                    <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">
                                        {__('Create API Key →', 'thinkrank')}
                                    </a>
                                </>
                            }
                            type="text"
                        />
                    </div>
                </Card>

                <Card padding={false} className="tr-mb-6">
                    <div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
                        <h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">{__('Connection Testing', 'thinkrank')}</h3>
                    </div>
                    <div className="tr-p-6 tr-flex tr-flex-col tr-gap-4">
                        <div className="tr-flex tr-items-center tr-gap-3">
                            <span className="tr-text-xs tr-text-tertiary tr-m-0">
                                {__('Test your API connections to ensure everything is working correctly.', 'thinkrank')}
                            </span>
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={testConnections}
                                loading={isTestingConnection}
                                disabled={isTestingConnection}
                            >
                                {isTestingConnection ? __('Testing...', 'thinkrank') : __('Test Connections', 'thinkrank')}
                            </Button>
                        </div>
                    </div>
                </Card>

                {connectionStatus && (
                    <Card padding={false} className="tr-mb-6">
                        <div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
                            <h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">{__('Connection Status', 'thinkrank')}</h3>
                        </div>
                        <div className="tr-p-6 tr-flex tr-flex-col tr-gap-4">
                            {Object.entries(connectionStatus).map(([service, status]) => (
                                <div key={service} className="tr-flex tr-items-center tr-gap-3 tr-py-2">
                                    <span className="tr-flex-1 tr-text-xs tr-text-tertiary tr-m-0">
                                        {service === 'google_analytics' ? __('Google Analytics', 'thinkrank') :
                                            service === 'search_console' ? __('Search Console', 'thinkrank') :
                                                service === 'pagespeed' ? __('PageSpeed', 'thinkrank') :
                                                    service.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                    </span>
                                    <span className={`tr-inline-flex tr-items-center tr-px-2 tr-py-1 tr-rounded tr-text-xs tr-font-medium tr-border ${status.status === 'configured' ? 'tr-bg-success-bg tr-text-success' :
                                        status.status === 'error' ? 'tr-bg-danger-bg tr-text-danger' :
                                            'tr-bg-white tr-text-tertiary'
                                        }`}>
                                        {status.status === 'configured' ? __('Configured', 'thinkrank') :
                                            status.status === 'error' ? __('Error', 'thinkrank') :
                                                __('Not Configured', 'thinkrank')}
                                    </span>
                                    {status.message && (
                                        <p className="tr-text-xs tr-text-tertiary tr-m-0">{status.message}</p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </Card>
                )}
            </div> */}
			</div>
		);
	};

	/**
	 * Render Social Platforms section
	 * DRY: Moved from Social Media tab, preserving exact functionality
	 */
	const renderSocialPlatforms = () => (
		<div className="thinkrank-tab-content">
			<Container direction="column" gap={4}>
				{/* Site verification — hero header */}
				<Card padding={false} className="tr-overflow-hidden">
					<div className="tr-flex tr-items-start tr-gap-4 tr-p-6">
						<span className="tr-flex tr-items-center tr-justify-center tr-w-11 tr-h-11 tr-rounded-xl tr-bg-brand-bg tr-text-brand tr-shrink-0">
							<IconSocial />
						</span>
						<div className="tr-flex-1 tr-min-w-0">
							<div className="tr-flex tr-items-center tr-gap-2.5 tr-flex-wrap">
								<h2 className="tr-m-0 tr-text-base tr-font-semibold tr-text-primary">
									{__('Site verification', 'thinkrank')}
								</h2>
								{(() => {
									const total = SOCIAL_PLATFORM_TABS.length;
									const set = SOCIAL_PLATFORM_TABS.filter(
										(t) =>
											(
												SOCIAL_PLATFORM_CARDS[t.id] ||
												[]
											).some(
												(k) =>
													(
														socialSettings[k] || ''
													).trim() !== ''
											)
									).length;
									const variant =
										set === 0
											? 'neutral'
											: set === total
												? 'good'
												: 'info';
									return (
										<Badge variant={variant}>
											{sprintf(
												// translators: 1: number of configured platforms, 2: total platforms.
												__(
													'%1$d/%2$d set',
													'thinkrank'
												),
												set,
												total
											)}
										</Badge>
									);
								})()}
							</div>
							<p className="tr-mt-1 tr-mb-0 tr-text-sm tr-text-secondary tr-max-w-2xl">
								{__(
									'Confirm site ownership by adding the verification code each search engine or social network gives you. Every code is output as a meta tag in your site’s HTML head. Social codes are stored encrypted; search engine codes are not, since the tag publishes them anyway. Configure each one in its tab below.',
									'thinkrank'
								)}
							</p>
						</div>
					</div>
				</Card>

				<Card padding={false}>
					<Tabs
						tabs={SOCIAL_PLATFORM_TABS.map((t) => ({
							id: t.id,
							label: __(t.label, 'thinkrank'),
							tag: isCardDirty(t.id) ? '•' : undefined,
						}))}
						activeTab={activePlatformTab}
						onTabChange={setActivePlatformTab}
					/>

					<TabPanel tabId="facebook" activeTab={activePlatformTab}>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<FormField
								id="tr-soc-fb-app"
								label={__('Facebook App ID', 'thinkrank')}
								description={
									<>
										{__(
											'Your Facebook App ID for analytics. ',
											'thinkrank'
										)}
										<a
											href="https://developers.facebook.com/apps/"
											target="_blank"
											rel="noopener noreferrer"
										>
											{__(
												'Get your App ID here →',
												'thinkrank'
											)}
										</a>
									</>
								}
							>
								<Input
									id="tr-soc-fb-app"
									value={socialSettings.facebook_app_id}
									onChange={(e) =>
										handleSocialSettingChange(
											'facebook_app_id',
											e.target.value
										)
									}
									type="text"
									placeholder={__(
										'e.g., 1234567890123456',
										'thinkrank'
									)}
								/>
							</FormField>
							<FormField
								id="tr-soc-fb-admins"
								label={__('Facebook Admins', 'thinkrank')}
								description={
									<>
										{__(
											'Comma-separated Facebook user IDs for admin access. ',
											'thinkrank'
										)}
										<a
											href="https://www.facebook.com/help/1503421039731588"
											target="_blank"
											rel="noopener noreferrer"
										>
											{__(
												'How to find your Facebook ID →',
												'thinkrank'
											)}
										</a>
									</>
								}
								className="tr-pt-6"
							>
								<Input
									id="tr-soc-fb-admins"
									value={socialSettings.facebook_admins}
									onChange={(e) =>
										handleSocialSettingChange(
											'facebook_admins',
											e.target.value
										)
									}
									type="text"
									placeholder={__(
										'e.g., 1234567890, 9876543210',
										'thinkrank'
									)}
								/>
							</FormField>
						</Container>
						{cardSaveFooter(
							'facebook',
							__('Facebook', 'thinkrank')
						)}
					</TabPanel>

					<TabPanel
						tabId="search-engines"
						activeTab={activePlatformTab}
					>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<FormField
								id="tr-soc-gsv"
								label={__('Google Search Console', 'thinkrank')}
								description={
									<>
										{__(
											'The content value of the google-site-verification tag. Paste the whole meta tag if that is easier; the code is taken out of it. ',
											'thinkrank'
										)}
										<a
											href="https://support.google.com/webmasters/answer/9008080"
											target="_blank"
											rel="noopener noreferrer"
										>
											{__(
												'Get verification code →',
												'thinkrank'
											)}
										</a>
									</>
								}
							>
								<Input
									id="tr-soc-gsv"
									value={
										socialSettings.google_site_verification
									}
									onChange={(e) =>
										handleSocialSettingChange(
											'google_site_verification',
											e.target.value
										)
									}
									type="text"
									placeholder={__(
										'e.g., dBw5CvburAxi537Rp9qi5uG2174Vb6JwHwIRwPSLIK8',
										'thinkrank'
									)}
								/>
							</FormField>
							<FormField
								id="tr-soc-bsv"
								label={__('Bing Webmaster Tools', 'thinkrank')}
								description={
									<>
										{__(
											'The content value of the msvalidate.01 tag. Paste the whole meta tag if that is easier. ',
											'thinkrank'
										)}
										<a
											href="https://www.bing.com/webmasters/help/add-and-verify-site-12184f8b"
											target="_blank"
											rel="noopener noreferrer"
										>
											{__(
												'Get verification code →',
												'thinkrank'
											)}
										</a>
									</>
								}
							>
								<Input
									id="tr-soc-bsv"
									value={
										socialSettings.bing_site_verification
									}
									onChange={(e) =>
										handleSocialSettingChange(
											'bing_site_verification',
											e.target.value
										)
									}
									type="text"
									placeholder={__(
										'e.g., B2D8B7B1E5E0E1E7C0A1F2B3C4D5E6F7',
										'thinkrank'
									)}
								/>
							</FormField>
							<FormField
								id="tr-soc-ysv"
								label={__('Yandex Webmaster', 'thinkrank')}
								description={
									<>
										{__(
											'The content value of the yandex-verification tag. Paste the whole meta tag if that is easier. ',
											'thinkrank'
										)}
										<a
											href="https://yandex.com/support/webmaster/service/rights.html"
											target="_blank"
											rel="noopener noreferrer"
										>
											{__(
												'Get verification code →',
												'thinkrank'
											)}
										</a>
									</>
								}
							>
								<Input
									id="tr-soc-ysv"
									value={
										socialSettings.yandex_site_verification
									}
									onChange={(e) =>
										handleSocialSettingChange(
											'yandex_site_verification',
											e.target.value
										)
									}
									type="text"
									placeholder={__(
										'e.g., a1b2c3d4e5f6a7b8',
										'thinkrank'
									)}
								/>
							</FormField>
							<FormField
								id="tr-soc-bdsv"
								label={__('Baidu Webmaster', 'thinkrank')}
								description={
									<>
										{__(
											'The content value of the baidu-site-verification tag. Paste the whole meta tag if that is easier. ',
											'thinkrank'
										)}
										<a
											href="https://ziyuan.baidu.com/site/index"
											target="_blank"
											rel="noopener noreferrer"
										>
											{__(
												'Get verification code →',
												'thinkrank'
											)}
										</a>
									</>
								}
							>
								<Input
									id="tr-soc-bdsv"
									value={
										socialSettings.baidu_site_verification
									}
									onChange={(e) =>
										handleSocialSettingChange(
											'baidu_site_verification',
											e.target.value
										)
									}
									type="text"
									placeholder={__(
										'e.g., codeva-A1b2C3d4E5',
										'thinkrank'
									)}
								/>
							</FormField>
						</Container>
						{cardSaveFooter(
							'search-engines',
							__('Search Engines', 'thinkrank')
						)}
					</TabPanel>

					<TabPanel tabId="pinterest" activeTab={activePlatformTab}>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<FormField
								id="tr-soc-pin"
								label={__(
									'Pinterest Site Verification',
									'thinkrank'
								)}
								description={
									<>
										{__(
											'Pinterest site verification code for Pinterest Business. This will be encrypted and stored securely. ',
											'thinkrank'
										)}
										<a
											href="https://help.pinterest.com/en/business/article/claim-your-website"
											target="_blank"
											rel="noopener noreferrer"
										>
											{__(
												'Get verification code →',
												'thinkrank'
											)}
										</a>
									</>
								}
							>
								<Input
									id="tr-soc-pin"
									value={
										socialSettings.pinterest_site_verification
									}
									onChange={(e) =>
										handleSocialSettingChange(
											'pinterest_site_verification',
											e.target.value
										)
									}
									type="text"
									placeholder={__(
										'e.g., a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
										'thinkrank'
									)}
								/>
							</FormField>
						</Container>
						{cardSaveFooter(
							'pinterest',
							__('Pinterest', 'thinkrank')
						)}
					</TabPanel>

					<TabPanel tabId="instagram" activeTab={activePlatformTab}>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<FormField
								id="tr-soc-ig"
								label={__(
									'Instagram Verification',
									'thinkrank'
								)}
								description={
									<>
										{__(
											'Instagram verification code for business features. This will be encrypted and stored securely. ',
											'thinkrank'
										)}
										<a
											href="https://business.instagram.com/getting-started"
											target="_blank"
											rel="noopener noreferrer"
										>
											{__(
												'Instagram Business setup →',
												'thinkrank'
											)}
										</a>
									</>
								}
							>
								<Input
									id="tr-soc-ig"
									value={
										socialSettings.instagram_verification
									}
									onChange={(e) =>
										handleSocialSettingChange(
											'instagram_verification',
											e.target.value
										)
									}
									type="text"
									placeholder={__(
										'e.g., abc123_verification_code_xyz789',
										'thinkrank'
									)}
								/>
							</FormField>
						</Container>
						{cardSaveFooter(
							'instagram',
							__('Instagram', 'thinkrank')
						)}
					</TabPanel>

					<TabPanel tabId="tiktok" activeTab={activePlatformTab}>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<FormField
								id="tr-soc-tt"
								label={__('TikTok Verification', 'thinkrank')}
								description={
									<>
										{__(
											'TikTok verification code for business features. This will be encrypted and stored securely. ',
											'thinkrank'
										)}
										<a
											href="https://support.tiktok.com/en/using-tiktok/growing-your-audience/switching-to-a-creator-or-business-account"
											target="_blank"
											rel="noopener noreferrer"
										>
											{__(
												'TikTok Business account setup →',
												'thinkrank'
											)}
										</a>
									</>
								}
							>
								<Input
									id="tr-soc-tt"
									value={socialSettings.tiktok_verification}
									onChange={(e) =>
										handleSocialSettingChange(
											'tiktok_verification',
											e.target.value
										)
									}
									type="text"
									placeholder={__(
										'e.g., abc123_verification_code_xyz789',
										'thinkrank'
									)}
								/>
							</FormField>
						</Container>
						{cardSaveFooter('tiktok', __('TikTok', 'thinkrank'))}
					</TabPanel>

					<TabPanel tabId="youtube" activeTab={activePlatformTab}>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<FormField
								id="tr-soc-yt"
								label={__('YouTube Channel ID', 'thinkrank')}
								description={
									<>
										{__(
											'Your YouTube channel ID (starts with UC). ',
											'thinkrank'
										)}
										<a
											href="https://support.google.com/youtube/answer/3250431"
											target="_blank"
											rel="noopener noreferrer"
										>
											{__(
												'Find your Channel ID →',
												'thinkrank'
											)}
										</a>
									</>
								}
							>
								<Input
									id="tr-soc-yt"
									value={socialSettings.youtube_channel_id}
									onChange={(e) =>
										handleSocialSettingChange(
											'youtube_channel_id',
											e.target.value
										)
									}
									type="text"
									placeholder={__(
										'e.g., UCabcDEF1234567890ghIJklMN',
										'thinkrank'
									)}
								/>
							</FormField>
						</Container>
						{cardSaveFooter('youtube', __('YouTube', 'thinkrank'))}
					</TabPanel>

					<TabPanel tabId="whatsapp" activeTab={activePlatformTab}>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<FormField
								id="tr-soc-wa"
								label={__('WhatsApp Business ID', 'thinkrank')}
								description={
									<>
										{__(
											'Your WhatsApp Business account ID (digits only). ',
											'thinkrank'
										)}
										<a
											href="https://developers.facebook.com/docs/graph-api/reference/whats-app-business-account/"
											target="_blank"
											rel="noopener noreferrer"
										>
											{__(
												'WhatsApp Business API setup →',
												'thinkrank'
											)}
										</a>
									</>
								}
							>
								<Input
									id="tr-soc-wa"
									value={socialSettings.whatsapp_business_id}
									onChange={(e) =>
										handleSocialSettingChange(
											'whatsapp_business_id',
											e.target.value
										)
									}
									type="text"
									placeholder={__(
										'e.g., 1234567890',
										'thinkrank'
									)}
								/>
							</FormField>
						</Container>
						{cardSaveFooter(
							'whatsapp',
							__('WhatsApp Business', 'thinkrank')
						)}
					</TabPanel>
				</Card>
			</Container>
		</div>
	);

	if (isLoading) {
		return (
			<div className="tr-text-center tr-p-12">
				<SkeletonCard lines={3} />
			</div>
		);
	}

	return (
		<div className="thinkrank-integrations-tab">
			{notice && (
				<div className="tr-mb-6">
					<Alert
						variant={
							notice.status === 'success'
								? 'success'
								: notice.status === 'error'
									? 'error'
									: notice.status === 'warning'
										? 'warning'
										: 'info'
						}
						onDismiss={() => setNotice(null)}
					>
						{notice.message}
					</Alert>
				</div>
			)}

			{/* Render content based on active sub-section */}
			{(() => {
				switch (activeSubSection) {
					case 'google-services':
						return renderGoogleServices();
					case 'social-platforms':
						return renderSocialPlatforms();
					default:
						return renderGoogleServices();
				}
			})()}

			{/* Save Button — Google Services saves as one section; the Social
			    Platforms sub-section saves per-card from each card header. */}
			{activeSubSection === 'google-services' && (
				<div className="tr-flex tr-justify-end tr-mt-6">
					<Button
						variant="primary"
						size="md"
						onClick={saveSettings}
						loading={isSaving}
						disabled={!hasChanges || isSaving}
					>
						{isSaving
							? __('Saving…', 'thinkrank')
							: __('Save Changes', 'thinkrank')}
					</Button>
				</div>
			)}
		</div>
	);
};

export default IntegrationsTab;
