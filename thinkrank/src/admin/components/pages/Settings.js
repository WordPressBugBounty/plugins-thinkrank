/**
 * Settings Page Component
 *
 * Plugin settings and configuration
 *
 * @package
 * @since 1.0.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import classnames from 'classnames';
import ModelBadge from '../shared/ModelBadge';
import logger from '../../../shared/utils/logger';
import linkifyMessage from '../../../shared/utils/linkifyMessage';
import notify from '../../utils/notify';
import Tabs, { TabPanel } from '../ui/Tabs';
import {
	Button,
	Input,
	Select,
	Toggle,
	Card,
	CardHeader,
	Alert,
	Badge,
	SkeletonCard,
} from '../ui';
import yoastLogo from '../../setup-wizard/assets/migration/yoast.png';
import rankMathLogo from '../../setup-wizard/assets/migration/rankmath.png';
import aioseoLogo from '../../setup-wizard/assets/migration/aioseo.png';
import seoPressLogo from '../../setup-wizard/assets/migration/seopress.svg';
import squirrlyLogo from '../../setup-wizard/assets/migration/squirrly.svg';
import slimSeoLogo from '../../setup-wizard/assets/migration/slimseo.svg';
import sureRankLogo from '../../setup-wizard/assets/migration/surerank.svg';
import { getPageUrl } from '../../utils/router';

// Canonical default AI model per provider — the JS mirror of the
// DEFAULT_*_MODEL constants in includes/core/class-settings.php. Single source
// of truth for the frontend; keep it in sync with the PHP side (see issue #273).
// Mirrors Settings::AI_PROVIDER_NONE — a fresh install has chosen no provider,
// and clicking a card is what makes one (#572).
const NO_PROVIDER = '';

const AI_DEFAULT_MODELS = {
	openai: 'gpt-5-nano',
	claude: 'claude-sonnet-5',
	gemini: 'gemini-3.5-flash',
	openrouter: 'openai/gpt-4o-mini',
	// No default: the model belongs to whatever server the user names (#721).
	openai_compatible: '',
};

// SEO plugins ThinkRank can migrate data from — mirrors the source list in
// includes/admin/importers/class-import-detector.php (keep in sync). The detail
// line summarises what each import pulls across.
const MIGRATION_PLATFORMS = [
	{
		slug: 'yoast',
		name: 'Yoast SEO',
		logo: yoastLogo,
		detail: __('Titles, meta, canonicals, social & redirects', 'thinkrank'),
	},
	{
		slug: 'rankmath',
		name: 'Rank Math',
		logo: rankMathLogo,
		detail: __('Titles, meta, schema, redirects & 404 logs', 'thinkrank'),
	},
	{
		slug: 'seopress',
		name: 'SEOPress',
		logo: seoPressLogo,
		detail: __('Titles, meta, social & redirects', 'thinkrank'),
	},
	{
		slug: 'aioseo',
		name: 'All in One SEO',
		logo: aioseoLogo,
		detail: __('Titles, meta, settings & redirects', 'thinkrank'),
	},
	{
		slug: 'squirrly',
		name: 'Squirrly SEO',
		logo: squirrlyLogo,
		detail: __('Titles, meta, social, patterns & redirects', 'thinkrank'),
	},
	{
		slug: 'slimseo',
		name: 'Slim SEO',
		logo: slimSeoLogo,
		detail: __('Titles, meta, social, redirects & 404 logs', 'thinkrank'),
	},
	{
		slug: 'surerank',
		name: 'SureRank',
		logo: sureRankLogo,
		detail: __('Titles, meta, social, robots & site entity', 'thinkrank'),
	},
];

// What a ThinkRank export carries, in export order. Mirrors the type list in
// includes/admin/importers/class-thinkrank-exporter.php (CORE_TYPES) and the
// labels on the Import / Export screen — keep the three in sync.
//
// `settings` is deliberately countless: Settings::get_all() always answers, so
// the exporter reports it as a single record. Showing "1" beside it would read
// as one setting rather than all of them.
const EXPORT_CONTENTS = [
	{
		type: 'postmeta',
		name: __('Post SEO data', 'thinkrank'),
		detail: __(
			'Titles, descriptions, keywords, robots, social',
			'thinkrank'
		),
	},
	{
		type: 'termmeta',
		name: __('Category & tag SEO data', 'thinkrank'),
		detail: __('Titles and descriptions for terms', 'thinkrank'),
	},
	{
		type: 'usermeta',
		name: __('Author SEO data', 'thinkrank'),
		detail: __('Author archive titles and descriptions', 'thinkrank'),
	},
	{
		type: 'settings',
		name: __('Settings', 'thinkrank'),
		detail: __('Every ThinkRank setting except API keys', 'thinkrank'),
		countless: true,
	},
];

// Two-way transfer mark for the Migration Tools hero, drawn in the same
// line-icon style as the rest of the settings screen.
const IconMigration = () => (
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
		<polyline points="17 1 21 5 17 9" />
		<path d="M3 11V9a4 4 0 0 1 4-4h14" />
		<polyline points="7 23 3 19 7 15" />
		<path d="M21 13v2a4 4 0 0 1-4 4H3" />
	</svg>
);

// Database mark for the Data Management hero, drawn in the same line-icon
// style as the Migration Tools hero.
const IconDatabase = () => (
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
		<ellipse cx="12" cy="5" rx="9" ry="3" />
		<path d="M3 5v6a9 3 0 0 0 18 0V5" />
		<path d="M3 11v6a9 3 0 0 0 18 0v-6" />
	</svg>
);

// Gauge mark for the AI spend-controls card (#448).
const IconGauge = () => (
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
		<path d="M12 14 15.5 9" />
		<path d="M3.5 18a9 9 0 1 1 17 0" />
		<circle cx="12" cy="18" r="1.5" />
	</svg>
);

// Box-with-arrow mark for the Import / Export hero. Matches the icon the
// Import / Export screen's own card uses, so the two read as one feature.
const IconExport = () => (
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
		<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
		<polyline points="17 8 12 3 7 8" />
		<line x1="12" y1="3" x2="12" y2="15" />
	</svg>
);

// Right arrow for the "Open Migration" call-to-action.
const IconArrowRight = () => (
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
		className="tr-shrink-0"
	>
		<line x1="5" y1="12" x2="19" y2="12" />
		<polyline points="12 5 19 12 12 19" />
	</svg>
);

// Small check used on each supported-platform row.
const IconPlatformCheck = () => (
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

// AI Provider Icons (using WordPress Dashicons as fallback)
const OpenAIIcon = () => (
	<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
		<path d="M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.142.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997Z" />
	</svg>
);

const AnthropicIcon = () => (
	<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
		<path
			d="M13.827 3.52h3.603L24 20.48h-3.603l-6.57-16.96zm-7.258 0h3.767l6.57 16.96H13.24l-1.343-3.484H5.017l-1.344 3.484H0l6.57-16.96zm4.132 10.226L8.492 7.717l-2.21 6.03h4.42z"
			fillRule="nonzero"
		/>
	</svg>
);

const OpenRouterIcon = () => (
	<svg
		width="24"
		height="24"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		xmlns="http://www.w3.org/2000/svg"
	>
		<title>OpenRouter</title>
		<path d="M3 7h6l3 5 3-5h6" />
		<path d="M3 17h6l3-5" />
		<polyline points="18 4 21 7 18 10" />
		<polyline points="18 14 21 17 18 20" />
	</svg>
);

// A plain server: this provider is defined by the protocol it speaks, not by a
// vendor, so it gets no brand mark.
const CompatibleEndpointIcon = () => (
	<svg
		height="1em"
		width="1em"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.8"
		strokeLinecap="round"
		strokeLinejoin="round"
		style={{ flex: 'none', lineHeight: 1 }}
		xmlns="http://www.w3.org/2000/svg"
	>
		<title>{__('OpenAI-compatible endpoint', 'thinkrank')}</title>
		<rect x="3" y="4" width="18" height="6" rx="1.5" />
		<rect x="3" y="14" width="18" height="6" rx="1.5" />
		<path d="M7 7h.01M7 17h.01" />
	</svg>
);

// Human-readable provider labels (single source of truth for UI copy).
const PROVIDER_LABELS = {
	openai: 'OpenAI',
	// The vendor, not the model family — the other three name vendors, and a
	// family name goes stale on every rename (#572). The `claude` KEY is the
	// stored provider id and must not change.
	claude: 'Anthropic',
	gemini: 'Gemini',
	openrouter: 'OpenRouter',
	// Named by the protocol, not by OpenAI: the host is the customer's own
	// (Ollama, LM Studio, vLLM, Azure OpenAI, a gateway).
	openai_compatible: 'OpenAI-compatible endpoint',
};

// Sentinel dropdown value that reveals a free-text field so users can run a
// model that isn't in the predefined list (e.g. a newly released one).
const CUSTOM_MODEL_VALUE = '__custom__';

const GeminiIcon = () => (
	<svg
		height="1em"
		style={{ flex: 'none', lineHeight: 1 }}
		viewBox="0 0 24 24"
		width="1em"
		xmlns="http://www.w3.org/2000/svg"
	>
		<title>Gemini</title>
		<path
			d="M20.616 10.835a14.147 14.147 0 01-4.45-3.001 14.111 14.111 0 01-3.678-6.452.503.503 0 00-.975 0 14.134 14.134 0 01-3.679 6.452 14.155 14.155 0 01-4.45 3.001c-.65.28-1.318.505-2.002.678a.502.502 0 000 .975c.684.172 1.35.397 2.002.677a14.147 14.147 0 014.45 3.001 14.112 14.112 0 013.679 6.453.502.502 0 00.975 0c.172-.685.397-1.351.677-2.003a14.145 14.145 0 013.001-4.45 14.113 14.113 0 016.453-3.678.503.503 0 000-.975 13.245 13.245 0 01-2.003-.678z"
			fill="#3186FF"
		/>
		<defs>
			<linearGradient
				gradientUnits="userSpaceOnUse"
				id="lobe-icons-gemini-fill-0"
				x1="7"
				x2="11"
				y1="15.5"
				y2="12"
			>
				<stop stopColor="#08B962" />
				<stop offset="1" stopColor="#08B962" stopOpacity="0" />
			</linearGradient>
			<linearGradient
				gradientUnits="userSpaceOnUse"
				id="lobe-icons-gemini-fill-1"
				x1="8"
				x2="11.5"
				y1="5.5"
				y2="11"
			>
				<stop stopColor="#F94543" />
				<stop offset="1" stopColor="#F94543" stopOpacity="0" />
			</linearGradient>
			<linearGradient
				gradientUnits="userSpaceOnUse"
				id="lobe-icons-gemini-fill-2"
				x1="3.5"
				x2="17.5"
				y1="13.5"
				y2="12"
			>
				<stop stopColor="#FABC12" />
				<stop offset=".46" stopColor="#FABC12" stopOpacity="0" />
			</linearGradient>
		</defs>
	</svg>
);

// Top-level Settings tabs. Each groups a self-contained settings area that
// saves independently (per-tab Save), so switching tabs never loses edits.
const SETTINGS_TABS = [
	{ id: 'ai-provider', label: __('AI Provider', 'thinkrank') },
	{ id: 'data-management', label: __('Data Management', 'thinkrank') },
	// Sits before Migration Tools: this one is about ThinkRank's own data
	// leaving and coming back, and it is always available. Migration Tools is
	// the other direction — another plugin's data coming in — and is gated.
	{ id: 'import-export', label: __('Import / Export', 'thinkrank') },
	{ id: 'migration-tools', label: __('Migration Tools', 'thinkrank') },
];
const VALID_SETTINGS_TABS = SETTINGS_TABS.map((t) => t.id);

// Every provider the settings response carries a key for.
const AI_PROVIDER_IDS = [
	'openai',
	'claude',
	'gemini',
	'openrouter',
	'openai_compatible',
];

// The provider configured by URL rather than by key. Its API key is optional —
// a local Ollama or LM Studio server wants none — so every "is there a key?"
// gate on this screen has to ask about the base URL and model id instead (#721).
const COMPATIBLE_PROVIDER = 'openai_compatible';
const DEFAULT_SETTINGS_TAB = 'ai-provider';

// Contextual help links for the AI Provider tab. `href` opens in a new tab.
const SETUP_HELP_LINKS = [
	{
		label: __('Documentation', 'thinkrank'),
		desc: __('Setup guides and provider walkthroughs.', 'thinkrank'),
		href: 'https://thinkrank.ai/docs/getting-started/connect-ai-provider/',
	},
	{
		label: __('Open a support ticket', 'thinkrank'),
		desc: __('Stuck connecting? Our team can help.', 'thinkrank'),
		href: 'https://wpdeveloper.com/support/new-ticket/',
	},
];

/**
 * Sync the active tab to the URL via replaceState so reloads restore it
 * without polluting browser history.
 * @param {string} tabId Active tab id to persist in the query string.
 */
const writeSettingsTabToUrl = (tabId) => {
	try {
		const url = new URL(window.location.href);
		url.searchParams.set('tab', tabId);
		window.history.replaceState({}, '', url.toString());
	} catch (e) {
		// URL API failure shouldn't break tab switching
	}
};

/**
 * Settings Component
 */
const Settings = () => {
	const [apiKey, setApiKey] = useState('');
	const [aiProvider, setAiProvider] = useState(NO_PROVIDER);
	const [selectedModel, setSelectedModel] = useState('');
	// Provider-specific model states to avoid cross-provider bleed
	const [openaiModelState, setOpenaiModelState] = useState(
		AI_DEFAULT_MODELS.openai
	);
	const [claudeModelState, setClaudeModelState] = useState(
		AI_DEFAULT_MODELS.claude
	);
	const [geminiModelState, setGeminiModelState] = useState(
		AI_DEFAULT_MODELS.gemini
	);
	const [openrouterModelState, setOpenrouterModelState] = useState(
		AI_DEFAULT_MODELS.openrouter
	);
	// OpenAI-compatible endpoint (#721): configured by URL + model id, with an
	// optional key. The capability toggle is declared by the user because no
	// reliable probe exists — llama3.1 cannot see images, llava can.
	const [compatibleModelState, setCompatibleModelState] = useState('');
	const [compatibleBaseUrl, setCompatibleBaseUrl] = useState('');
	const [compatibleTimeout, setCompatibleTimeout] = useState(120);
	const [compatibleSupportsImages, setCompatibleSupportsImages] =
		useState(false);
	const [compatibleJsonMode, setCompatibleJsonMode] = useState(false);
	const [compatiblePrice, setCompatiblePrice] = useState('');
	// Models the endpoint reported from GET {base}/models, if it answers at all.
	// Held together with the URL they came from: a slow answer must not fill the
	// field with a model a different server serves (#721).
	const [endpointModels, setEndpointModels] = useState([]);
	const endpointModelsUrl = useRef('');
	const [isLoadingEndpointModels, setIsLoadingEndpointModels] =
		useState(false);
	// Whether the active provider is using a custom (free-text) model.
	const [useCustomModel, setUseCustomModel] = useState(false);
	const [isSaving, setIsSaving] = useState(false);
	const [isLoading, setIsLoading] = useState(true);
	const [isTesting, setIsTesting] = useState(false);
	const [notice, setNotice] = useState(null);
	const [hasExistingKey, setHasExistingKey] = useState(false);
	// The provider actually in use (persisted `ai_provider`). Unlike `aiProvider`
	// above — which is the *viewed* tab — this only changes when a provider is
	// saved, so switching tabs to inspect another provider never re-activates it.
	const [activeProviderId, setActiveProviderId] = useState(NO_PROVIDER);
	const [activeHasKey, setActiveHasKey] = useState(false);
	// Masked preview of the active provider's saved key (e.g. "sk-pr••••••••abc")
	// sent by the backend, shown read-only instead of an empty field.
	const [activeKeyMask, setActiveKeyMask] = useState('');
	// Masked key per provider. The settings response already carries all four,
	// so a provider the user is only *viewing* can still show its saved key —
	// previously only the active provider's was kept and every other one looked
	// unconfigured, which also made its key impossible to save over or clear.
	const [keyMasks, setKeyMasks] = useState({});
	// When true, the read-only saved key is swapped for an editable field.
	const [isReplacingKey, setIsReplacingKey] = useState(false);
	const [revealApiKey, setRevealApiKey] = useState(false);
	const [deleteDataOnUninstall, setDeleteDataOnUninstall] = useState(false);
	const [enableMigrationTools, setEnableMigrationTools] = useState(false);
	// Import / Export defaults ON — it is an opt-out, so the switch starts in
	// the position a fresh install is actually in while settings load.
	const [enableImportExport, setEnableImportExport] = useState(false);
	const [isSavingImportExport, setIsSavingImportExport] = useState(false);
	// Migration Tools saves instantly on toggle (like the MCP card), so it has
	// its own in-flight flag independent of the shared `isSaving`.
	const [isSavingMigration, setIsSavingMigration] = useState(false);
	// Data Management saves on its own Save button, independent of the AI
	// Provider tab's `isSaving`.
	const [isSavingData, setIsSavingData] = useState(false);

	// AI spend controls (#448). The pause switch saves on flip, the two limits
	// share their own Save button, and neither path goes through `handleSave`
	// so both still work on a site with no API key configured. `aiBudget` is
	// the live counter read back from /ai/status, not something the user edits.
	const [aiPaused, setAiPaused] = useState(false);
	const [dailyRequestLimit, setDailyRequestLimit] = useState(0);
	const [requestsPerMinute, setRequestsPerMinute] = useState(0);
	const [isSavingPause, setIsSavingPause] = useState(false);
	const [isSavingLimits, setIsSavingLimits] = useState(false);
	const [aiBudget, setAiBudget] = useState(null);

	// Import / Export tab: ThinkRank's own exportable data, as reported by
	// /import/detect. Null until the tab is first opened — see the effect below.
	const [nativeExport, setNativeExport] = useState(null);
	const [isLoadingExport, setIsLoadingExport] = useState(false);
	const [exportLoadFailed, setExportLoadFailed] = useState(false);
	// Whether the counts have been asked for, and whether we are still around
	// to receive them. See the effect below for why neither is state.
	const exportCountsRequested = useRef(false);
	const isMountedRef = useRef(true);

	useEffect(
		() => () => {
			isMountedRef.current = false;
		},
		[]
	);

	// Active top-level settings tab (AI Provider / Data Management /
	// Import / Export / Migration).
	const [activeTab, setActiveTab] = useState(DEFAULT_SETTINGS_TAB);

	// Restore the active tab from the URL on mount so reloads/deep-links land
	// on the right tab.
	useEffect(() => {
		try {
			const params = new URLSearchParams(window.location.search);
			const requested = params.get('tab');
			const tab = VALID_SETTINGS_TABS.includes(requested)
				? requested
				: DEFAULT_SETTINGS_TAB;
			setActiveTab(tab);
			writeSettingsTabToUrl(tab);
		} catch (e) {
			// Fall back to default on URL parse failure
		}
	}, []);

	const handleTabChange = useCallback((tabId) => {
		setActiveTab(tabId);
		writeSettingsTabToUrl(tabId);
	}, []);

	// Count ThinkRank's exportable data only once the tab is actually opened.
	// /import/detect runs COUNT queries over postmeta/termmeta/usermeta — cheap
	// enough behind its transient, but not worth paying on every Settings load
	// for a tab most visits never reach.
	//
	// Both guards are refs on purpose. Keeping the in-flight flag in the
	// dependency array would make the effect re-run on its own setState, and
	// the first run's cleanup would then cancel the request it had just
	// started — leaving the list on its skeleton for good. Tracking mount
	// state instead of tearing down per run also means switching tabs
	// mid-request does not strand the loader.
	useEffect(() => {
		if (activeTab !== 'import-export' || exportCountsRequested.current) {
			return;
		}

		exportCountsRequested.current = true;
		setIsLoadingExport(true);
		setExportLoadFailed(false);

		apiFetch({ path: '/thinkrank/v1/import/detect' })
			.then((response) => {
				if (!isMountedRef.current) {
					return;
				}
				// `native` is null when there is nothing to export at all.
				setNativeExport(response?.native || { counts: {}, total: 0 });
			})
			.catch((error) => {
				if (!isMountedRef.current) {
					return;
				}
				logger.error('Failed to load export data counts', error);
				setExportLoadFailed(true);
				// Let re-opening the tab try again rather than showing the
				// warning until the page is reloaded.
				exportCountsRequested.current = false;
			})
			.finally(() => {
				if (isMountedRef.current) {
					setIsLoadingExport(false);
				}
			});
	}, [activeTab]);

	// Model options per provider. Verified against vendor deprecation pages —
	// keep retired ids out of this list entirely; a saved id that is no longer
	// offered still works, shown through the "Custom model…" field (#572).
	const modelOptions = {
		openai: [
			{
				value: 'gpt-5-nano',
				label: 'GPT‑5‑nano (Default – fastest, lowest cost)',
			},
			{
				value: 'gpt-5-mini',
				label: 'GPT‑5‑mini (Balanced quality/cost)',
			},
			{ value: 'gpt-5', label: 'GPT‑5 (Highest quality)' },
			{ value: 'gpt-4o', label: 'GPT‑4o (Legacy)' },
		],
		claude: [
			{
				value: 'claude-sonnet-5',
				label: 'Claude Sonnet 5 (Recommended)',
			},
			{
				value: 'claude-opus-5',
				label: 'Claude Opus 5 (Most Capable)',
			},
			{ value: 'claude-opus-4-8', label: 'Claude Opus 4.8' },
			{ value: 'claude-haiku-4-5', label: 'Claude Haiku 4.5 (Fast)' },
		],
		gemini: [
			{
				value: 'gemini-3.5-flash',
				label: 'Gemini 3.5 Flash (Recommended)',
			},
			{
				value: 'gemini-3.1-flash-lite',
				label: 'Gemini 3.1 Flash Lite (Fastest)',
			},
			// Published only under its -preview id; the bare `gemini-3.1-pro`
			// this list used to offer does not resolve.
			{
				value: 'gemini-3.1-pro-preview',
				label: 'Gemini 3.1 Pro (Preview)',
			},
			// gemini-2.5-pro and gemini-2.5-flash-lite retire Oct 2026 — removed.
		],
		openrouter: [
			{
				value: 'openai/gpt-4o-mini',
				label: 'GPT‑4o mini (Default – fast, low cost)',
			},
			// Was anthropic/claude-3.5-sonnet (retired — Claude_Client::normalize_model
			// already self-heals that id on the direct path) and
			// google/gemini-2.0-flash-001 (shut down 1 Jun 2026).
			{
				value: 'anthropic/claude-sonnet-5',
				label: 'Claude Sonnet 5',
			},
			{ value: 'google/gemini-3.5-flash', label: 'Gemini 3.5 Flash' },
			{
				value: 'meta-llama/llama-3.3-70b-instruct',
				label: 'Llama 3.3 70B Instruct',
			},
			{ value: 'deepseek/deepseek-chat', label: 'DeepSeek Chat' },
		],
	};

	// Whether a model id belongs to a provider's predefined dropdown list.
	// A non-empty model that isn't listed is treated as a custom model.
	const isPredefinedModel = (provider, model) =>
		(modelOptions[provider] || []).some((o) => o.value === model);

	// Track if initial load is complete to prevent duplicate API calls
	const [initialLoadComplete, setInitialLoadComplete] = useState(false);

	// Load settings on component mount
	useEffect(() => {
		loadSettings();
		loadAiBudget();
	}, []);

	// Track the previous provider to detect actual user changes
	const [previousProvider, setPreviousProvider] = useState(null);

	// Handle provider changes (only for user-initiated changes)
	useEffect(() => {
		// Skip if this is the initial load, provider not set, or no previous provider to compare
		if (!initialLoadComplete || !aiProvider || previousProvider === null) {
			setPreviousProvider(aiProvider);
			return;
		}

		// Only proceed if this is an actual provider change (user-initiated)
		if (previousProvider !== aiProvider) {
			// Clear API key field when switching providers to avoid confusion.
			setApiKey('');
			setIsReplacingKey(false);
			// Every provider's mask is known (loadSettings keeps them all), so a
			// provider the user switches to shows its own saved key rather than
			// an empty field claiming nothing is configured.
			setHasExistingKey(Boolean(keyMasks[aiProvider]));
			setActiveKeyMask(keyMasks[aiProvider] || '');

			// Set selectedModel to the appropriate provider-specific model
			if (aiProvider === 'openai') {
				setSelectedModel(openaiModelState);
			} else if (aiProvider === 'claude') {
				setSelectedModel(claudeModelState);
			} else if (aiProvider === 'gemini') {
				setSelectedModel(geminiModelState);
			} else if (aiProvider === 'openrouter') {
				setSelectedModel(openrouterModelState);
			} else if (aiProvider === COMPATIBLE_PROVIDER) {
				setSelectedModel(compatibleModelState);
			}

			// Enter custom mode if the new provider's saved model is free-text.
			const switchedModel =
				aiProvider === 'claude'
					? claudeModelState
					: aiProvider === 'gemini'
						? geminiModelState
						: aiProvider === 'openrouter'
							? openrouterModelState
							: aiProvider === COMPATIBLE_PROVIDER
								? compatibleModelState
								: openaiModelState;
			// The compatible endpoint has no dropdown to fall out of — its model
			// id is always typed, so the custom-model flag does not apply.
			setUseCustomModel(
				aiProvider !== COMPATIBLE_PROVIDER &&
					!!switchedModel &&
					!isPredefinedModel(aiProvider, switchedModel)
			);

			// Switching providers only *views* one — clear any stale notice
			// from the previously viewed provider so nothing carries over.
			setNotice(null);

			// Update previous provider
			setPreviousProvider(aiProvider);
		}
	}, [
		aiProvider,
		keyMasks,
		initialLoadComplete,
		previousProvider,
		openaiModelState,
		claudeModelState,
		geminiModelState,
		openrouterModelState,
		compatibleModelState,
	]);

	// API key format validation
	const validateApiKey = (key, provider) => {
		if (!key || !key.trim()) return { valid: true, message: '' };

		// Whatever an Azure deployment, a gateway or a local server accepts is
		// its own business — there is no prefix to check against.
		if (provider === COMPATIBLE_PROVIDER)
			return { valid: true, message: '' };

		if (provider === 'openai') {
			if (!key.startsWith('sk-')) {
				return {
					valid: false,
					message: __(
						'OpenAI API keys should start with "sk-"',
						'thinkrank'
					),
				};
			}
			if (key.length < 20) {
				return {
					valid: false,
					message: __(
						'OpenAI API key appears too short',
						'thinkrank'
					),
				};
			}
		} else if (provider === 'claude') {
			if (!key.startsWith('sk-ant-')) {
				return {
					valid: false,
					message: __(
						'Claude API keys should start with "sk-ant-"',
						'thinkrank'
					),
				};
			}
			if (key.length < 30) {
				return {
					valid: false,
					message: __(
						'Claude API key appears too short',
						'thinkrank'
					),
				};
			}
		} else if (provider === 'gemini') {
			// Google AI Studio issues both the legacy "AIza…" keys and the newer
			// "AQ.…" format — accept either prefix.
			if (!key.startsWith('AIza') && !key.startsWith('AQ.')) {
				return {
					valid: false,
					message: __(
						'Gemini API keys should start with "AIza" or "AQ."',
						'thinkrank'
					),
				};
			}
			if (key.length < 30) {
				return {
					valid: false,
					message: __(
						'Gemini API key appears too short',
						'thinkrank'
					),
				};
			}
		} else if (provider === 'openrouter') {
			if (!key.startsWith('sk-or-')) {
				return {
					valid: false,
					message: __(
						'OpenRouter API keys should start with "sk-or-"',
						'thinkrank'
					),
				};
			}
			if (key.length < 20) {
				return {
					valid: false,
					message: __(
						'OpenRouter API key appears too short',
						'thinkrank'
					),
				};
			}
		}

		return { valid: true, message: '' };
	};

	const loadSettings = async () => {
		setIsLoading(true);
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/settings',
			});

			// Check if response has the expected structure
			const settings = response.success ? response.data : response;

			if (settings) {
				// Always use saved provider from backend (this is the source of truth)
				// '' is a real stored value ("no provider chosen"), not a missing
				// one — defaulting to OpenAI here is what put every fresh install
				// on a provider it never picked (#572).
				const savedProvider = settings.ai_provider || NO_PROVIDER;

				// Keep every provider's mask, not just the active one.
				const masks = {};
				AI_PROVIDER_IDS.forEach((id) => {
					const value = settings[`${id}_api_key`] || '';
					masks[id] = value.includes('••••') ? value : '';
				});
				setKeyMasks(masks);

				const currentApiKey =
					settings[`${savedProvider}_api_key`] || '';

				// Check if we have a masked API key (indicates existing key)
				const hasKey = currentApiKey && currentApiKey.includes('••••');
				setHasExistingKey(hasKey);

				// The saved provider is the one actually in use. Track it apart
				// from the viewed tab so the "Active" marker doesn't move on tab
				// switches — only on save (which round-trips through here).
				setActiveProviderId(savedProvider);
				setActiveHasKey(hasKey);
				setActiveKeyMask(hasKey ? currentApiKey : '');
				setIsReplacingKey(false);

				// Don't show masked key in input
				setApiKey(hasKey ? '' : currentApiKey);

				// Always sync frontend state with backend settings
				setAiProvider(savedProvider);
				setPreviousProvider(savedProvider); // Set initial previous provider

				// Set all provider-specific model states from backend settings
				const openaiModel =
					settings.openai_model || AI_DEFAULT_MODELS.openai;
				const claudeModel =
					settings.claude_model || AI_DEFAULT_MODELS.claude;
				const geminiModel =
					settings.gemini_model || AI_DEFAULT_MODELS.gemini;
				const openrouterModel =
					settings.openrouter_model || AI_DEFAULT_MODELS.openrouter;
				const compatibleModel = settings.openai_compatible_model || '';

				setCompatibleModelState(compatibleModel);
				setCompatibleBaseUrl(settings.openai_compatible_base_url || '');
				setCompatibleTimeout(
					Number(settings.openai_compatible_timeout) || 120
				);
				setCompatibleSupportsImages(
					!!settings.openai_compatible_supports_images
				);
				setCompatibleJsonMode(!!settings.openai_compatible_json_mode);
				setCompatiblePrice(
					Number(settings.openai_compatible_price_per_million) > 0
						? String(settings.openai_compatible_price_per_million)
						: ''
				);

				setOpenaiModelState(openaiModel);
				setClaudeModelState(claudeModel);
				setGeminiModelState(geminiModel);
				setOpenrouterModelState(openrouterModel);

				// Set selectedModel based on current provider
				if (savedProvider === 'claude') {
					setSelectedModel(claudeModel);
				} else if (savedProvider === 'gemini') {
					setSelectedModel(geminiModel);
				} else if (savedProvider === 'openrouter') {
					setSelectedModel(openrouterModel);
				} else if (savedProvider === COMPATIBLE_PROVIDER) {
					setSelectedModel(compatibleModel);
				} else {
					setSelectedModel(openaiModel);
				}

				// Detect a saved custom model for the active provider so the
				// free-text field is shown instead of the dropdown selection.
				const savedModel =
					savedProvider === 'claude'
						? claudeModel
						: savedProvider === 'gemini'
							? geminiModel
							: savedProvider === 'openrouter'
								? openrouterModel
								: savedProvider === COMPATIBLE_PROVIDER
									? compatibleModel
									: openaiModel;
				setUseCustomModel(
					savedProvider !== COMPATIBLE_PROVIDER &&
						!!savedModel &&
						!isPredefinedModel(savedProvider, savedModel)
				);

				// Set delete data on uninstall setting (defaults to false - preserve data)
				// Use truthy check because the API may return '1'/'' strings rather than real booleans
				setDeleteDataOnUninstall(!settings.keep_data_on_uninstall);
				setEnableMigrationTools(!!settings.enable_migration_tools);
				// Absent (an older payload) means off: this setting defaults
				// false, same as Migration Tools. Truthy check because the API
				// may return '1'/'' strings rather than real booleans.
				setEnableImportExport(!!settings.enable_import_export);

				// AI spend controls. Both limits are plain integers where 0
				// means "no limit", so `|| 0` is the right fallback for a
				// payload from an older build that does not carry them yet.
				setAiPaused(!!settings.ai_paused);
				setDailyRequestLimit(
					Number(settings.ai_daily_request_limit) || 0
				);
				setRequestsPerMinute(
					Number(settings.max_requests_per_minute) || 0
				);

				// Show appropriate notice based on API key status. With no
				// provider chosen there is nothing to warn about — the empty
				// state below already asks the user to pick one (#572).
				if (!savedProvider) {
					setNotice(null);
				} else if (savedProvider === COMPATIBLE_PROVIDER) {
					// Its key is optional; what it needs is a URL and a model.
					const missing =
						!settings.openai_compatible_base_url ||
						!settings.openai_compatible_model;
					setNotice(
						missing
							? {
									type: 'warning',
									message: __(
										'Add the base URL and model id of your OpenAI-compatible endpoint to enable AI features.',
										'thinkrank'
									),
								}
							: null
					);
				} else if (!hasKey) {
					const providerName =
						PROVIDER_LABELS[savedProvider] || savedProvider;
					setNotice({
						type: 'warning',
						message: sprintf(
							/* translators: %s: AI provider name (e.g. OpenAI, Claude). */
							__(
								'No %s API key found. Please enter your API key to use this provider.',
								'thinkrank'
							),
							providerName
						),
					});
				} else {
					// Clear any existing notices if key exists
					setNotice(null);
				}
			}
		} catch (error) {
			logger.error('Failed to load settings:', error);
			setNotice({
				type: 'error',
				message: __('Failed to load settings.', 'thinkrank'),
			});
		} finally {
			setIsLoading(false);
			setInitialLoadComplete(true); // Mark initial load as complete
		}
	};

	// Same instant-save shape as the Migration Tools switch below: optimistic
	// flip, persist, roll back on failure. Separate from handleSave so it never
	// trips the AI-provider API-key requirement.
	const handleToggleImportExport = async (next) => {
		const previous = enableImportExport;
		setEnableImportExport(next);
		setIsSavingImportExport(true);
		setNotice(null);
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/settings',
				method: 'POST',
				data: { enable_import_export: next },
			});
			if (!response?.success) {
				throw new Error(
					__(
						'Failed to save the Import / Export setting.',
						'thinkrank'
					)
				);
			}
			notify.success(
				next
					? __(
							'Import / Export enabled. Reload the page to see the menu item.',
							'thinkrank'
						)
					: __(
							'Import / Export disabled. Your data is untouched. Turn it back on any time to export it.',
							'thinkrank'
						)
			);
		} catch (e) {
			setEnableImportExport(previous);
			notify.error(
				e?.message ||
					__(
						'Failed to save the Import / Export setting.',
						'thinkrank'
					)
			);
		} finally {
			setIsSavingImportExport(false);
		}
	};

	// Toggling saves the "Enable Migration Tools" flag immediately — no separate
	// Save button. Optimistically flip the switch, persist, then roll back if
	// the request fails. Kept separate from handleSave so it never trips the
	// AI-provider API-key requirement — this setting is unrelated to AI and must
	// save on sites with no API key configured.
	const handleToggleMigrationTools = async (next) => {
		const previous = enableMigrationTools;
		setEnableMigrationTools(next);
		setIsSavingMigration(true);
		setNotice(null);
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/settings',
				method: 'POST',
				data: { enable_migration_tools: next },
			});
			if (!response?.success) {
				throw new Error(
					__(
						'Failed to save the Migration Tools setting.',
						'thinkrank'
					)
				);
			}
			notify.success(
				next
					? __(
							'Migration Tools enabled. Reload the page to see the Migration menu.',
							'thinkrank'
						)
					: __('Migration Tools disabled.', 'thinkrank')
			);
		} catch (e) {
			// Roll the switch back to its last saved value on failure.
			setEnableMigrationTools(previous);
			notify.error(
				e?.message ||
					__(
						'Failed to save the Migration Tools setting.',
						'thinkrank'
					)
			);
		} finally {
			setIsSavingMigration(false);
		}
	};

	// The running count of AI requests used today. Read separately from the
	// settings payload because it is a live counter, not a setting: it moves
	// while the page is open and has to be re-read after every save, or the
	// number the user is watching stops matching the one being enforced.
	const loadAiBudget = async () => {
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/ai/status',
			});
			if (isMountedRef.current) {
				setAiBudget(response?.budget || null);
			}
		} catch (e) {
			// A missing counter is not worth a notice: the controls below
			// still save, they just cannot show usage this render.
			logger.error('Failed to load AI budget status:', e);
		}
	};

	// The kill switch saves on flip, like the Migration Tools switch: a switch
	// that looks committed but is waiting for a Save button reads as a bug, and
	// this one in particular is reached by someone who wants spending to stop
	// now. Optimistic flip, persist, roll back on failure.
	const handleToggleAiPause = async (next) => {
		const previous = aiPaused;
		setAiPaused(next);
		setIsSavingPause(true);
		setNotice(null);
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/settings',
				method: 'POST',
				data: { ai_paused: next },
			});
			if (!response?.success) {
				throw new Error(
					__('Failed to save the AI pause setting.', 'thinkrank')
				);
			}
			notify.success(
				next
					? __(
							'AI paused. ThinkRank will not send any requests to your AI provider until you turn this back off.',
							'thinkrank'
						)
					: __('AI resumed.', 'thinkrank')
			);
			loadAiBudget();
		} catch (e) {
			setAiPaused(previous);
			notify.error(
				e?.message ||
					__('Failed to save the AI pause setting.', 'thinkrank')
			);
		} finally {
			setIsSavingPause(false);
		}
	};

	// The two numeric limits share a Save button, because typing a number is an
	// edit the user finishes rather than a state they flip. Kept out of
	// handleSave for the same reason as the handlers above: a spend ceiling has
	// to be settable before an API key is.
	const handleSaveAiLimits = async () => {
		setIsSavingLimits(true);
		setNotice(null);
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/settings',
				method: 'POST',
				data: {
					ai_daily_request_limit: Math.max(
						0,
						Number(dailyRequestLimit) || 0
					),
					max_requests_per_minute: Math.max(
						0,
						Number(requestsPerMinute) || 0
					),
				},
			});

			if (!response?.success) {
				throw new Error(
					response?.message ||
						__('Failed to save the AI limits.', 'thinkrank')
				);
			}

			notify.success(__('AI limits saved.', 'thinkrank'));
			loadAiBudget();
		} catch (error) {
			logger.error('Failed to save AI limits:', error);
			notify.error(
				error?.message ||
					__('Failed to save the AI limits.', 'thinkrank')
			);
		} finally {
			setIsSavingLimits(false);
		}
	};

	// Data Management saves only its own flag. Kept separate from handleSave for
	// the same reason as the Migration Tools handler: the uninstall setting is
	// unrelated to AI and must save on sites with no API key configured.
	const handleSaveDataManagement = async () => {
		setIsSavingData(true);
		setNotice(null);
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/settings',
				method: 'POST',
				data: { keep_data_on_uninstall: !deleteDataOnUninstall },
			});

			if (!response?.success) {
				throw new Error(
					response?.message ||
						__('Failed to save settings.', 'thinkrank')
				);
			}

			notify.success(__('Settings saved successfully!', 'thinkrank'));
		} catch (error) {
			logger.error('Failed to save data management settings:', error);
			notify.error(
				error?.message ||
					__(
						'Failed to save settings. Please try again.',
						'thinkrank'
					)
			);
		} finally {
			setIsSavingData(false);
		}
	};

	const handleSave = async () => {
		// Nothing to save until a provider is chosen — and the key/model state
		// below belongs to no provider yet (#572).
		if (!aiProvider) {
			notify.error(__('Choose an AI provider first.', 'thinkrank'));
			return;
		}

		// The compatible endpoint is configured by URL + model id; its key is
		// optional, so the key requirement below does not apply to it (#721).
		if (aiProvider === COMPATIBLE_PROVIDER) {
			if (!compatibleBaseUrl.trim()) {
				notify.error(
					__(
						'Enter the base URL of your endpoint, for example http://localhost:11434/v1',
						'thinkrank'
					)
				);
				return;
			}

			if (!compatibleModelState.trim()) {
				notify.error(
					__(
						'Enter the model id your endpoint should use, for example llama3.1',
						'thinkrank'
					)
				);
				return;
			}
		} else if (!apiKey.trim() && !hasExistingKey) {
			const providerName = PROVIDER_LABELS[aiProvider] || aiProvider;
			notify.error(
				sprintf(
					/* translators: %s: AI provider name (e.g. OpenAI, Claude). */
					__(
						'Enter your %s API key for the selected provider.',
						'thinkrank'
					),
					providerName
				)
			);
			return;
		}

		// Validate API key format if provided
		if (apiKey.trim()) {
			const validation = validateApiKey(apiKey, aiProvider);
			if (!validation.valid) {
				notify.error(validation.message);
				return;
			}
		}

		setIsSaving(true);
		setNotice(null);

		try {
			const saveData = {
				ai_provider: aiProvider,
				keep_data_on_uninstall: !deleteDataOnUninstall, // Invert because UI asks "delete data"
				enable_migration_tools: enableMigrationTools,
			};

			// Include API key if user entered a new one
			if (apiKey.trim()) {
				saveData[`${aiProvider}_api_key`] = apiKey;
			}

			// Include model selection (use provider-specific state)
			if (aiProvider === 'claude') {
				saveData.claude_model = claudeModelState;
			} else if (aiProvider === 'gemini') {
				saveData.gemini_model = geminiModelState;
			} else if (aiProvider === 'openrouter') {
				saveData.openrouter_model = openrouterModelState;
			} else if (aiProvider === COMPATIBLE_PROVIDER) {
				saveData.openai_compatible_base_url = compatibleBaseUrl.trim();
				saveData.openai_compatible_model = compatibleModelState.trim();
				saveData.openai_compatible_timeout = Number(compatibleTimeout);
				saveData.openai_compatible_supports_images =
					compatibleSupportsImages;
				saveData.openai_compatible_json_mode = compatibleJsonMode;
				// Blank means "unknown pricing", which the usage panel shows as
				// "—" rather than as a zero cost.
				saveData.openai_compatible_price_per_million =
					compatiblePrice === '' ? 0 : Number(compatiblePrice);
			} else {
				saveData.openai_model = openaiModelState;
			}

			const response = await apiFetch({
				path: '/thinkrank/v1/settings',
				method: 'POST',
				data: saveData,
			});

			if (response.success) {
				notify.success(__('Settings saved successfully!', 'thinkrank'));

				// If we saved a new API key, mark that we have one and clear input
				if (apiKey.trim()) {
					setHasExistingKey(true);
					setApiKey('');

					// Un-gate "Generate with AI" buttons without a page reload
					if (window.thinkrankAdmin) {
						window.thinkrankAdmin.aiConfigured = true;
					}
				}

				// The compatible endpoint configures AI without any key, so it
				// has to un-gate those buttons too.
				if (
					aiProvider === COMPATIBLE_PROVIDER &&
					window.thinkrankAdmin
				) {
					window.thinkrankAdmin.aiConfigured = true;
				}

				// Refresh the settings to ensure UI is in sync
				await loadSettings();
			} else {
				notify.error(
					response.message ||
						__('Failed to save settings.', 'thinkrank')
				);
			}
		} catch (error) {
			logger.error('Failed to save settings:', error);
			// A rejected base URL comes back as a 400 with the reason — "Use
			// https:// for example.com", "that host is a cloud metadata
			// service". Swallowing it for a generic retry message left the user
			// with no idea what was wrong (#721).
			notify.error(
				error?.message ||
					__(
						'Failed to save settings. Please try again.',
						'thinkrank'
					)
			);
		} finally {
			setIsSaving(false);
		}
	};

	// Ask the endpoint what it serves. Ollama, LM Studio and vLLM all answer
	// GET {base}/models; a gateway that does not simply leaves the field typed
	// by hand, which is why this is a button and not a required step.
	const handleLoadEndpointModels = async () => {
		const requestedUrl = compatibleBaseUrl.trim();

		if (!requestedUrl) {
			notify.error(
				__('Enter your endpoint base URL first.', 'thinkrank')
			);
			return;
		}

		setIsLoadingEndpointModels(true);
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/ai/models',
				method: 'POST',
				data: {
					base_url: requestedUrl,
					...(apiKey.trim() ? { api_key: apiKey.trim() } : {}),
				},
			});

			// The URL on screen may have changed while this was in flight.
			// Answering for a server the user has moved on from would leave the
			// form holding a model the current endpoint does not serve.
			if (compatibleBaseUrl.trim() !== requestedUrl) {
				return;
			}

			const models = response?.models || [];
			endpointModelsUrl.current = requestedUrl;
			setEndpointModels(models);
			notify.success(
				response?.truncated
					? sprintf(
							/* translators: %d: number of models listed, after the plugin's cap. */
							__(
								'Showing the first %d models this endpoint lists.',
								'thinkrank'
							),
							models.length
						)
					: sprintf(
							/* translators: %d: number of models the endpoint listed. */
							__(
								'%d models available on this endpoint.',
								'thinkrank'
							),
							models.length
						)
			);

			// Nothing chosen yet: the first id is a better starting point than
			// an empty field.
			if (!compatibleModelState.trim() && models.length > 0) {
				setCompatibleModelState(models[0]);
				setSelectedModel(models[0]);
			}
		} catch (error) {
			logger.error('Failed to list endpoint models:', error);
			if (compatibleBaseUrl.trim() === requestedUrl) {
				endpointModelsUrl.current = '';
				setEndpointModels([]);
				notify.error(
					error?.message ||
						__(
							'Could not list models. Type the model id by hand instead.',
							'thinkrank'
						)
				);
			}
		} finally {
			setIsLoadingEndpointModels(false);
		}
	};

	const handleTestConnection = async () => {
		const typedKey = apiKey.trim();

		// An empty field means one of two things: nothing is configured yet, or
		// a key IS saved and deliberately hidden behind its mask. Only the first
		// is an error — the backend resolves the stored key when none is sent.
		// The compatible endpoint is exempt: it is tested by URL, not by key.
		if (aiProvider === COMPATIBLE_PROVIDER && !compatibleBaseUrl.trim()) {
			notify.error(
				__('Enter your endpoint base URL first.', 'thinkrank')
			);
			return;
		}

		if (
			aiProvider !== COMPATIBLE_PROVIDER &&
			!typedKey &&
			!hasExistingKey
		) {
			notify.error(__('Please enter an API key first.', 'thinkrank'));
			return;
		}

		// Validate only what the user actually typed.
		if (typedKey) {
			const validation = validateApiKey(apiKey, aiProvider);
			if (!validation.valid) {
				notify.error(validation.message);
				return;
			}
		}

		setIsTesting(true);
		setNotice(null);

		// Test the model the user is looking at, not the one last saved — a
		// freshly picked or hand-typed model id is the whole reason to press
		// Test, and the backend falls back to the stored model without this.
		const modelUnderTest = (
			aiProvider === 'claude'
				? claudeModelState
				: aiProvider === 'gemini'
					? geminiModelState
					: aiProvider === 'openrouter'
						? openrouterModelState
						: aiProvider === COMPATIBLE_PROVIDER
							? compatibleModelState
							: openaiModelState
		)?.trim();

		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/ai/test-connection',
				method: 'POST',
				// Omit api_key entirely when nothing was typed, so the backend
				// tests the stored key for this provider.
				data: {
					provider: aiProvider,
					...(typedKey ? { api_key: apiKey } : {}),
					...(modelUnderTest ? { model: modelUnderTest } : {}),
					// Test the URL on screen, so an endpoint can be verified
					// before it is saved.
					...(aiProvider === COMPATIBLE_PROVIDER
						? {
								base_url: compatibleBaseUrl.trim(),
								json_mode: compatibleJsonMode,
							}
						: {}),
				},
			});

			if (response?.success && response.json_mode_supported === false) {
				// Connected, but the JSON mode toggle on screen does not suit
				// this server: a warning, not a failure.
				notify.warning(response.message);
			} else if (response && response.success) {
				// Prefer the server's wording: it names the model it verified.
				notify.success(
					response.message ||
						__('API connection successful!', 'thinkrank')
				);
			} else {
				const errorMessage =
					response?.message ||
					__(
						'API connection failed. Please check your API key and try again.',
						'thinkrank'
					);
				notify.error(errorMessage);
			}
		} catch (error) {
			logger.error('Failed to test connection:', error);

			// Better error handling for different types of failures
			let errorMessage = __(
				'Failed to test API connection.',
				'thinkrank'
			);

			if (error.message) {
				if (error.message.includes('fetch')) {
					errorMessage = __(
						'Network error. Please check your internet connection.',
						'thinkrank'
					);
				} else if (
					error.message.includes('401') ||
					error.message.includes('403')
				) {
					errorMessage = __(
						'Invalid API key. Please check your credentials.',
						'thinkrank'
					);
				} else if (error.message.includes('429')) {
					errorMessage = __(
						'Rate limit exceeded. Please try again later.',
						'thinkrank'
					);
				} else {
					errorMessage = `${__('Connection failed:', 'thinkrank')} ${error.message}`;
				}
			}

			notify.error(errorMessage);
		} finally {
			setIsTesting(false);
		}
	};

	const handleClearApiKey = async () => {
		if (!hasExistingKey) {
			notify.warning(__('No API key is currently saved.', 'thinkrank'));
			return;
		}

		if (
			!confirm(
				__(
					'Are you sure you want to clear the saved API key? This action cannot be undone.',
					'thinkrank'
				)
			)
		) {
			return;
		}

		setIsSaving(true);
		setNotice(null);

		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/settings',
				method: 'POST',
				data: {
					[`${aiProvider}_api_key`]: '', // Clear the API key
				},
			});

			if (response.success) {
				notify.success(
					__('API key cleared successfully.', 'thinkrank')
				);
				setHasExistingKey(false);
				setApiKey('');

				// Refresh the settings to ensure UI is in sync
				await loadSettings();
			} else {
				notify.error(
					response.message ||
						__('Failed to clear API key.', 'thinkrank')
				);
			}
		} catch (error) {
			logger.error('Failed to clear API key:', error);
			notify.error(
				__('Failed to clear API key. Please try again.', 'thinkrank')
			);
		} finally {
			setIsSaving(false);
		}
	};

	const providerName = PROVIDER_LABELS[aiProvider] || aiProvider;

	// The compatible endpoint's key is optional, and its label would otherwise
	// read "OpenAI-compatible endpoint API Key" — long, and a promise this
	// provider does not make (#721).
	const apiKeyFieldLabel =
		aiProvider === COMPATIBLE_PROVIDER
			? __('API key (optional)', 'thinkrank')
			: `${providerName} ${__('API Key', 'thinkrank')}`;
	const apiKeyPlaceholders = {
		openai: 'sk-…',
		claude: 'sk-ant-…',
		gemini: 'AQ.… or AIza…',
		openrouter: 'sk-or-…',
	};
	const apiKeyPlaceholder = hasExistingKey
		? __('Enter new API key to update…', 'thinkrank')
		: apiKeyPlaceholders[aiProvider] || '';

	const providers = [
		{
			id: 'openai',
			label: 'OpenAI',
			sub: 'OpenAI API',
			icon: <OpenAIIcon />,
		},
		{
			id: 'claude',
			label: 'Anthropic',
			sub: 'Claude models',
			icon: <AnthropicIcon />,
		},
		{
			id: 'gemini',
			label: 'Gemini',
			sub: 'Google AI',
			icon: <GeminiIcon />,
		},
		{
			id: 'openrouter',
			label: 'OpenRouter',
			sub: 'Unified API',
			icon: <OpenRouterIcon />,
		},
		{
			id: COMPATIBLE_PROVIDER,
			label: __('Custom endpoint', 'thinkrank'),
			sub: __('Ollama, LM Studio, Azure…', 'thinkrank'),
			icon: <CompatibleEndpointIcon />,
		},
	];

	// Predefined options for the active provider, plus a "Custom model…" entry
	// that lets users type any model id not in the list.
	const providerModelOptions = modelOptions[aiProvider] || [];
	const currentModelOptions = [
		...providerModelOptions,
		{ value: CUSTOM_MODEL_VALUE, label: __('Custom model…', 'thinkrank') },
	];

	const currentModelState =
		aiProvider === 'claude'
			? claudeModelState
			: aiProvider === 'gemini'
				? geminiModelState
				: aiProvider === 'openrouter'
					? openrouterModelState
					: aiProvider === COMPATIBLE_PROVIDER
						? compatibleModelState
						: openaiModelState;

	const setCurrentModel = (v) => {
		if (aiProvider === 'claude') {
			setClaudeModelState(v);
		} else if (aiProvider === 'gemini') {
			setGeminiModelState(v);
		} else if (aiProvider === 'openrouter') {
			setOpenrouterModelState(v);
		} else if (aiProvider === COMPATIBLE_PROVIDER) {
			setCompatibleModelState(v);
		} else {
			setOpenaiModelState(v);
		}
		setSelectedModel(v);
	};

	// The dropdown shows "Custom model…" while a free-text field holds the value.
	const handleModelSelect = (v) => {
		if (v === CUSTOM_MODEL_VALUE) {
			setUseCustomModel(true);
			return;
		}
		setUseCustomModel(false);
		setCurrentModel(v);
	};

	if (isLoading) {
		return (
			<div className="tr-grid tr-grid-cols-1 xl:tr-grid-cols-3 tr-gap-6">
				<div className="xl:tr-col-span-2 tr-flex tr-flex-col tr-gap-5">
					<SkeletonCard lines={3} />
					<SkeletonCard lines={2} />
				</div>
				<aside className="tr-flex tr-flex-col tr-gap-5">
					<SkeletonCard lines={4} />
				</aside>
			</div>
		);
	}

	// Sidebar "Active configuration" reflects the provider actually in use, not
	// the viewed tab — so it stays stable while the user inspects other tabs.
	const activeProvider = providers.find((p) => p.id === activeProviderId);
	const activeModelState =
		activeProviderId === 'claude'
			? claudeModelState
			: activeProviderId === 'gemini'
				? geminiModelState
				: activeProviderId === 'openrouter'
					? openrouterModelState
					: activeProviderId === COMPATIBLE_PROVIDER
						? compatibleModelState
						: openaiModelState;
	const providerKeyDocs = {
		openai: {
			url: 'https://platform.openai.com/api-keys',
			label: 'platform.openai.com',
		},
		claude: {
			url: 'https://console.anthropic.com/settings/keys',
			label: 'console.anthropic.com',
		},
		gemini: {
			url: 'https://aistudio.google.com/app/apikey',
			label: 'aistudio.google.com',
		},
		openrouter: {
			url: 'https://openrouter.ai/keys',
			label: 'openrouter.ai',
		},
		// Not a key page: this provider often needs no key at all, so the link
		// goes where someone gets a model running on their own machine.
		[COMPATIBLE_PROVIDER]: {
			url: 'https://ollama.com/download',
			label: __('ollama.com: run a model locally', 'thinkrank'),
		},
	};

	return (
		<div>
			{notice && (
				<div className="tr-mb-5">
					<Alert
						variant={
							notice.type === 'error'
								? 'error'
								: notice.type === 'success'
									? 'success'
									: notice.type === 'warning'
										? 'warning'
										: 'info'
						}
						onDismiss={() => setNotice(null)}
					>
						{linkifyMessage(notice.message)}
					</Alert>
				</div>
			)}

			<Tabs
				tabs={SETTINGS_TABS}
				activeTab={activeTab}
				onTabChange={handleTabChange}
				className="tr-mb-6"
			/>

			{/* AI Provider tab */}
			<TabPanel tabId="ai-provider" activeTab={activeTab}>
				<div className="tr-grid tr-grid-cols-1 xl:tr-grid-cols-3 tr-gap-6">
					<div className="xl:tr-col-span-2 tr-flex tr-flex-col tr-gap-5">
						{/* AI Settings card */}
						<Card>
							{/* Provider chooser — a card grid (not tabs) so it reads
							    as "pick a provider", distinct from the page tabs above. */}
							<div className="tr-mb-6">
								<p className="tr-text-sm tr-font-medium tr-text-secondary tr-mt-0 tr-mb-2.5">
									{__('Choose provider', 'thinkrank')}
								</p>
								<div
									role="radiogroup"
									aria-label={__('AI provider', 'thinkrank')}
									className="tr-grid tr-grid-cols-2 sm:tr-grid-cols-4 tr-gap-3"
								>
									{providers.map((p) => {
										const isViewed = aiProvider === p.id;
										// "Active" only counts when the provider actually
										// has a connected key — a saved-but-keyless
										// provider isn't in use.
										// A keyless endpoint is still in use, so
										// "Active" cannot depend on a key here.
										const isActiveProvider =
											activeProviderId === p.id &&
											(activeHasKey ||
												p.id === COMPATIBLE_PROVIDER);
										return (
											<button
												key={p.id}
												type="button"
												role="radio"
												aria-checked={isViewed}
												onClick={() => {
													setAiProvider(p.id);
													setRevealApiKey(false);
												}}
												style={{
													WebkitTapHighlightColor:
														'transparent',
												}}
												className={classnames(
													'tr-relative tr-flex tr-flex-col tr-items-center tr-justify-center tr-gap-2 tr-px-3 tr-py-4 tr-rounded-lg tr-border-[1.5px] tr-cursor-pointer tr-transition-colors tr-duration-200 focus:tr-outline-none',
													isViewed
														? 'tr-border-brand tr-bg-brand-bg'
														: 'tr-border-secondary tr-bg-white hover:tr-border-tertiary hover:tr-bg-line-2'
												)}
											>
												{/* Selected check — fades in on the chosen card. */}
												<span
													className={classnames(
														'tr-absolute tr-top-2 tr-right-2 tr-flex tr-items-center tr-justify-center tr-w-4 tr-h-4 tr-rounded-full tr-bg-brand tr-text-white tr-transition-opacity tr-duration-200',
														isViewed
															? 'tr-opacity-100'
															: 'tr-opacity-0'
													)}
													aria-hidden="true"
												>
													<svg
														className="tr-w-2.5 tr-h-2.5"
														fill="none"
														stroke="currentColor"
														viewBox="0 0 24 24"
													>
														<path
															strokeLinecap="round"
															strokeLinejoin="round"
															strokeWidth={3}
															d="M5 13l4 4L19 7"
														/>
													</svg>
												</span>
												{/* text-2xl fixes the Gemini icon, sized in em, to match the 24px icons */}
												<span
													className={classnames(
														'tr-h-7 tr-flex tr-items-center tr-justify-center tr-text-2xl tr-transition-colors tr-duration-200',
														isViewed
															? 'tr-text-brand'
															: 'tr-text-secondary'
													)}
												>
													{p.icon}
												</span>
												<span
													className={classnames(
														'tr-text-sm tr-font-semibold tr-transition-colors tr-duration-200',
														isViewed
															? 'tr-text-brand'
															: 'tr-text-primary'
													)}
												>
													{p.label}
												</span>
												{isActiveProvider && (
													<span className="tr-inline-flex tr-items-center tr-gap-1 tr-text-[10px] tr-font-semibold tr-uppercase tr-tracking-wide tr-leading-none tr-text-success">
														<span className="tr-w-1.5 tr-h-1.5 tr-rounded-full tr-bg-success" />
														{__(
															'Active',
															'thinkrank'
														)}
													</span>
												)}
											</button>
										);
									})}
								</div>
							</div>

							{!aiProvider ? (
								/* Nothing chosen yet: ask for the one decision
								   that has to happen first, instead of showing a
								   key field with no provider to attach it to. */
								<div className="tr-flex tr-flex-col tr-items-center tr-gap-1.5 tr-py-8 tr-px-4 tr-rounded-lg tr-border tr-border-dashed tr-border-secondary tr-text-center">
									<p className="tr-text-sm tr-font-medium tr-text-primary tr-m-0">
										{__(
											'Choose an AI provider to get started',
											'thinkrank'
										)}
									</p>
									<p className="tr-text-sm tr-text-tertiary tr-m-0">
										{__(
											'Select a provider above, then add its API key to enable AI features.',
											'thinkrank'
										)}
									</p>
								</div>
							) : (
								<div>
									{/* API key + model */}
									<div className="tr-flex tr-flex-col tr-gap-4">
										{aiProvider === COMPATIBLE_PROVIDER && (
											<Input
												label={__(
													'Endpoint base URL',
													'thinkrank'
												)}
												value={compatibleBaseUrl}
												onChange={(e) => {
													setCompatibleBaseUrl(
														e.target.value
													);
													// The suggestions belong to
													// the old server; keeping
													// them would offer models
													// this one may not serve.
													if (
														endpointModelsUrl.current &&
														endpointModelsUrl.current !==
															e.target.value.trim()
													) {
														endpointModelsUrl.current =
															'';
														setEndpointModels([]);
													}
												}}
												placeholder="http://localhost:11434/v1"
												description={__(
													'The OpenAI-compatible base URL, ending in /v1. Plain http:// is accepted for localhost and private addresses; anything public must use https:// so your key is never sent in the clear.',
													'thinkrank'
												)}
											/>
										)}
										{hasExistingKey && !isReplacingKey ? (
											<div className="tr-flex tr-flex-col tr-gap-1.5">
												<span className="tr-text-sm tr-font-medium tr-text-secondary">
													{apiKeyFieldLabel}
												</span>
												<div className="tr-flex tr-items-center tr-gap-2">
													<div className="tr-flex tr-flex-1 tr-items-center tr-gap-2 tr-h-10 tr-px-3 tr-rounded-md tr-border-[1.5px] tr-border-secondary tr-bg-white tr-min-w-0">
														<svg
															className="tr-w-4 tr-h-4 tr-text-tertiary tr-shrink-0"
															fill="none"
															stroke="currentColor"
															viewBox="0 0 24 24"
														>
															<path
																strokeLinecap="round"
																strokeLinejoin="round"
																strokeWidth={2}
																d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
															/>
														</svg>
														<code className="tr-bg-transparent tr-p-0 tr-text-sm tr-text-primary tr-font-mono tr-tracking-wide tr-truncate">
															{activeKeyMask}
														</code>
														<span className="tr-ml-auto tr-inline-flex tr-items-center tr-gap-1 tr-text-xs tr-font-medium tr-text-success tr-shrink-0">
															<span className="tr-w-1.5 tr-h-1.5 tr-rounded-full tr-bg-success" />
															{__(
																'Connected',
																'thinkrank'
															)}
														</span>
													</div>
													<Button
														variant="secondary"
														onClick={() => {
															setIsReplacingKey(
																true
															);
															setApiKey('');
															setRevealApiKey(
																false
															);
														}}
														disabled={
															isSaving ||
															isTesting
														}
													>
														{__(
															'Replace',
															'thinkrank'
														)}
													</Button>
												</div>
												<p className="tr-text-xs tr-text-tertiary tr-m-0">
													{__(
														'Masked preview of your saved key. Choose Replace to enter a new one.',
														'thinkrank'
													)}
												</p>
											</div>
										) : (
											<div className="tr-flex tr-flex-col tr-gap-1.5">
												<label
													htmlFor="tr-provider-api-key"
													className="tr-text-sm tr-font-medium tr-text-secondary"
												>
													{apiKeyFieldLabel}
												</label>
												<div className="tr-relative tr-flex tr-items-center">
													<input
														id="tr-provider-api-key"
														// A `type="password"` field makes
														// browsers and password managers offer
														// to autofill the admin's saved WP
														// login here. Use `type="text"` (with
														// autofill hints off) and mask the
														// value via CSS so the key still stays
														// hidden without that hijacking.
														type="text"
														autoComplete="off"
														autoCorrect="off"
														autoCapitalize="off"
														spellCheck={false}
														data-lpignore="true"
														data-1p-ignore="true"
														value={apiKey}
														onChange={(e) =>
															setApiKey(
																e.target.value
															)
														}
														placeholder={
															apiKeyPlaceholder
														}
														className="tr-w-full tr-h-10 tr-rounded-md tr-bg-white tr-text-sm tr-text-primary tr-border-[1.5px] tr-border-primary tr-transition-colors tr-duration-150 placeholder:tr-text-tertiary hover:tr-border-[#A9AEC0] focus:tr-outline-none focus-visible:tr-border-brand tr-pl-3 tr-pr-10"
														style={
															revealApiKey
																? undefined
																: {
																		WebkitTextSecurity:
																			'disc',
																	}
														}
													/>
													<button
														type="button"
														onClick={() =>
															setRevealApiKey(
																(prev) => !prev
															)
														}
														className="tr-absolute tr-right-2 tr-flex tr-items-center tr-justify-center tr-w-7 tr-h-7 tr-p-0 tr-bg-transparent tr-border-0 tr-cursor-pointer tr-text-tertiary hover:tr-text-secondary"
														aria-label={
															revealApiKey
																? __(
																		'Hide API key',
																		'thinkrank'
																	)
																: __(
																		'Show API key',
																		'thinkrank'
																	)
														}
														aria-pressed={
															revealApiKey
														}
													>
														{revealApiKey ? (
															<svg
																className="tr-w-4 tr-h-4"
																fill="none"
																stroke="currentColor"
																viewBox="0 0 24 24"
																aria-hidden="true"
															>
																<path
																	strokeLinecap="round"
																	strokeLinejoin="round"
																	strokeWidth={
																		2
																	}
																	d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
																/>
															</svg>
														) : (
															<svg
																className="tr-w-4 tr-h-4"
																fill="none"
																stroke="currentColor"
																viewBox="0 0 24 24"
																aria-hidden="true"
															>
																<path
																	strokeLinecap="round"
																	strokeLinejoin="round"
																	strokeWidth={
																		2
																	}
																	d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
																/>
																<path
																	strokeLinecap="round"
																	strokeLinejoin="round"
																	strokeWidth={
																		2
																	}
																	d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
																/>
															</svg>
														)}
													</button>
												</div>
												<p className="tr-text-xs tr-text-tertiary tr-m-0">
													{hasExistingKey
														? __(
																'Enter a new key to replace the saved one.',
																'thinkrank'
															)
														: aiProvider ===
															  COMPATIBLE_PROVIDER
															? __(
																	'Leave empty for a local server that needs no key. Anything you enter is encrypted and sent only to the URL above.',
																	'thinkrank'
																)
															: __(
																	'Encrypted and stored securely. You pay the provider directly.',
																	'thinkrank'
																)}
												</p>
												{hasExistingKey &&
													isReplacingKey && (
														<button
															type="button"
															onClick={() => {
																setIsReplacingKey(
																	false
																);
																setApiKey('');
																setRevealApiKey(
																	false
																);
															}}
															className="tr-self-start tr-text-xs tr-text-brand tr-bg-transparent tr-border-0 tr-p-0 tr-cursor-pointer hover:tr-underline"
														>
															{__(
																'Cancel and keep current key',
																'thinkrank'
															)}
														</button>
													)}
											</div>
										)}

										{aiProvider === COMPATIBLE_PROVIDER ? (
											/* No dropdown: the model list belongs
											   to the server the user named, so it
											   is either loaded from that server or
											   typed. */
											<>
												<div className="tr-flex tr-flex-col tr-gap-2">
													<Input
														label={__(
															'Model id',
															'thinkrank'
														)}
														value={
															compatibleModelState
														}
														onChange={(e) =>
															setCompatibleModelState(
																e.target.value
															)
														}
														placeholder="llama3.1"
														list="thinkrank-endpoint-models"
														description={__(
															'The exact model id this server serves, as it appears in its own model list.',
															'thinkrank'
														)}
													/>
													{endpointModels.length >
														0 && (
														<datalist id="thinkrank-endpoint-models">
															{endpointModels.map(
																(m) => (
																	<option
																		key={m}
																		value={
																			m
																		}
																	/>
																)
															)}
														</datalist>
													)}
													<Button
														variant="secondary"
														size="sm"
														onClick={
															handleLoadEndpointModels
														}
														loading={
															isLoadingEndpointModels
														}
														disabled={
															!compatibleBaseUrl.trim()
														}
														className="tr-self-start"
													>
														{__(
															'Load models',
															'thinkrank'
														)}
													</Button>
													{endpointModels.length >
														0 && (
														<p className="tr-text-xs tr-text-tertiary tr-m-0">
															{sprintf(
																/* translators: %s: comma-separated model ids. */
																__(
																	'Available: %s',
																	'thinkrank'
																),
																endpointModels
																	.slice(0, 8)
																	.join(', ')
															)}
														</p>
													)}
												</div>

												<Input
													type="number"
													label={__(
														'Request timeout (seconds)',
														'thinkrank'
													)}
													value={compatibleTimeout}
													min={10}
													max={600}
													onChange={(e) =>
														setCompatibleTimeout(
															e.target.value
														)
													}
													description={__(
														'A local model on CPU can take minutes for a content brief. Raise this if generation times out.',
														'thinkrank'
													)}
												/>

												<Toggle
													id="thinkrank-endpoint-supports-images"
													checked={
														compatibleSupportsImages
													}
													onChange={
														setCompatibleSupportsImages
													}
													label={__(
														'This model can describe images',
														'thinkrank'
													)}
													description={__(
														'Turn on only for a vision model (llava, gpt-4o, a Gemini deployment). AI alt text stays hidden while this is off, because a text-only model answers a vision request with an error.',
														'thinkrank'
													)}
												/>

												<Toggle
													id="thinkrank-endpoint-json-mode"
													checked={compatibleJsonMode}
													onChange={
														setCompatibleJsonMode
													}
													label={__(
														'Force valid JSON answers',
														'thinkrank'
													)}
													description={__(
														'Sends response_format json_object, which Ollama, vLLM and llama.cpp use to stop a small model breaking the JSON of a content brief or metadata. Leave off for a server that rejects it (LM Studio accepts only json_schema).',
														'thinkrank'
													)}
												/>

												<Input
													type="number"
													label={__(
														'Price per 1M tokens (optional)',
														'thinkrank'
													)}
													value={compatiblePrice}
													min={0}
													step="0.01"
													onChange={(e) =>
														setCompatiblePrice(
															e.target.value
														)
													}
													placeholder="0.00"
													description={__(
														'Only for cost estimates. Leave empty when the model is free or you do not want estimates. Usage is then reported without a cost rather than as zero.',
														'thinkrank'
													)}
												/>
											</>
										) : (
											<>
												<Select
													label={`${providerName} ${__('Model', 'thinkrank')}`}
													value={
														useCustomModel
															? CUSTOM_MODEL_VALUE
															: currentModelState
													}
													options={
														currentModelOptions
													}
													onChange={(e) =>
														handleModelSelect(
															e.target.value
														)
													}
													description={__(
														'Model used for all AI content generation.',
														'thinkrank'
													)}
												/>

												{useCustomModel && (
													<Input
														label={__(
															'Custom model',
															'thinkrank'
														)}
														value={
															currentModelState
														}
														onChange={(e) =>
															setCurrentModel(
																e.target.value
															)
														}
														placeholder={
															aiProvider ===
															'openrouter'
																? 'vendor/model-name'
																: 'model-name'
														}
														description={__(
															'Enter the exact model id from your provider. Use this to run a newly released or unlisted model.',
															'thinkrank'
														)}
													/>
												)}
											</>
										)}
									</div>
								</div>
							)}

							{/* Actions */}
							<div className="tr-flex tr-items-center tr-gap-3 tr-mt-5">
								<Button
									variant="primary"
									onClick={handleSave}
									loading={isSaving}
									disabled={isTesting || !aiProvider}
								>
									{__('Save Settings', 'thinkrank')}
								</Button>
								<Button
									variant="secondary"
									onClick={handleTestConnection}
									loading={isTesting}
									disabled={
										isSaving ||
										!aiProvider ||
										(aiProvider === COMPATIBLE_PROVIDER
											? !compatibleBaseUrl.trim()
											: !apiKey.trim() && !hasExistingKey)
									}
								>
									{__('Test Connection', 'thinkrank')}
								</Button>
								{hasExistingKey && (
									<Button
										variant="danger"
										onClick={handleClearApiKey}
										disabled={isSaving || isTesting}
									>
										{__('Clear API Key', 'thinkrank')}
									</Button>
								)}
							</div>
						</Card>

						{/* AI spend controls (#448). The per-minute throttle has
						    existed since 1.0 but was rendered nowhere, so this card is
						    the first time a user can see or change any AI limit.

						    Not folded by Basic mode: the switch that would fold
						    it lives on the Essential SEO screen, and a control
						    on one screen must not quietly hide a card on
						    another (#730). */}
						<Card padding={false} className="tr-overflow-hidden">
							<div className="tr-flex tr-items-start tr-gap-4 tr-p-6">
								<span className="tr-flex tr-items-center tr-justify-center tr-w-11 tr-h-11 tr-rounded-xl tr-bg-brand-bg tr-text-brand tr-shrink-0">
									<IconGauge />
								</span>
								<div className="tr-flex-1 tr-min-w-0">
									<div className="tr-flex tr-items-center tr-gap-2.5 tr-flex-wrap">
										<h2 className="tr-m-0 tr-text-base tr-font-semibold tr-text-primary">
											{__('AI usage limits', 'thinkrank')}
										</h2>
										<Badge
											variant={
												aiPaused ? 'warning' : 'good'
											}
										>
											{aiPaused
												? __('Paused', 'thinkrank')
												: __('Active', 'thinkrank')}
										</Badge>
									</div>
									<p className="tr-mt-1 tr-mb-0 tr-text-sm tr-text-secondary tr-max-w-2xl">
										{__(
											'Cap what ThinkRank may spend on your AI provider key, or stop it outright. Both limits apply to every AI feature, including the ones that run on a schedule.',
											'thinkrank'
										)}
									</p>
								</div>
								<div className="tr-flex tr-flex-col tr-items-end tr-gap-1 tr-shrink-0">
									<Toggle
										id="thinkrank-ai-paused"
										checked={aiPaused}
										onChange={handleToggleAiPause}
										disabled={isSavingPause}
										label={__('Pause all AI', 'thinkrank')}
									/>
									{isSavingPause && (
										<span className="tr-text-xs tr-text-tertiary">
											{__('Saving…', 'thinkrank')}
										</span>
									)}
								</div>
							</div>
							<div className="tr-border-t tr-border-secondary tr-p-6 tr-flex tr-flex-col tr-gap-5">
								{aiPaused && (
									<Alert variant="warning">
										{__(
											'AI is paused. No requests are being sent to your provider, so AI features will report that they are unavailable until you turn this back off. Your API key is untouched.',
											'thinkrank'
										)}
									</Alert>
								)}

								{aiBudget && (
									<div>
										<div className="tr-flex tr-items-baseline tr-justify-between tr-gap-3 tr-flex-wrap">
											<span className="tr-text-sm tr-font-semibold tr-text-primary">
												{__('Used today', 'thinkrank')}
											</span>
											<span className="tr-text-sm tr-text-secondary">
												{aiBudget.daily_limit > 0
													? sprintf(
															/* translators: 1: requests used today, 2: daily request limit. */
															__(
																'%1$s of %2$s requests',
																'thinkrank'
															),
															aiBudget.used_today,
															aiBudget.daily_limit
														)
													: sprintf(
															/* translators: %s: requests used today. */
															__(
																'%s requests, no daily limit set',
																'thinkrank'
															),
															aiBudget.used_today
														)}
											</span>
										</div>
										{aiBudget.daily_limit > 0 && (
											<div
												className="tr-mt-2 tr-h-2 tr-w-full tr-rounded-full tr-bg-secondary tr-overflow-hidden"
												role="progressbar"
												aria-valuenow={
													aiBudget.used_today
												}
												aria-valuemin={0}
												aria-valuemax={
													aiBudget.daily_limit
												}
											>
												<div
													className={classnames(
														'tr-h-full tr-rounded-full',
														// Coloured by how much of the
														// allowance is gone, not by
														// aiBudget.blocked. blocked is
														// also true while AI is paused,
														// which turned this meter red at
														// 8% used and read as "out of
														// quota" when nothing was wrong
														// with the quota. Pause already
														// says so three other ways on
														// this card.
														aiBudget.used_today >=
															aiBudget.daily_limit
															? 'tr-bg-danger'
															: 'tr-bg-brand'
													)}
													style={{
														width: `${Math.min(
															100,
															Math.round(
																(aiBudget.used_today /
																	aiBudget.daily_limit) *
																	100
															)
														)}%`,
													}}
												/>
											</div>
										)}
										<p className="tr-mt-2 tr-mb-0 tr-text-xs tr-text-tertiary">
											{__(
												'The count covers every AI request this site sends and resets at midnight, site time.',
												'thinkrank'
											)}
										</p>
									</div>
								)}

								<div className="tr-grid tr-grid-cols-1 md:tr-grid-cols-2 tr-gap-4">
									<Input
										id="thinkrank-ai-daily-limit"
										type="number"
										min={0}
										value={dailyRequestLimit}
										onChange={(e) =>
											setDailyRequestLimit(
												parseInt(e.target.value, 10) ||
													0
											)
										}
										label={__(
											'Daily request limit',
											'thinkrank'
										)}
										description={__(
											'Most AI requests this site may send in a day. 0 means no daily limit.',
											'thinkrank'
										)}
									/>
									<Input
										id="thinkrank-ai-per-minute"
										type="number"
										min={0}
										value={requestsPerMinute}
										onChange={(e) =>
											setRequestsPerMinute(
												parseInt(e.target.value, 10) ||
													0
											)
										}
										label={__(
											'Requests per minute',
											'thinkrank'
										)}
										description={__(
											'Most AI requests one user may send in a minute. 0 means no per-minute limit.',
											'thinkrank'
										)}
									/>
								</div>

								<div>
									<Button
										variant="primary"
										onClick={handleSaveAiLimits}
										loading={isSavingLimits}
									>
										{__('Save limits', 'thinkrank')}
									</Button>
								</div>
							</div>
						</Card>
					</div>

					{/* Sidebar — specific to AI provider setup, so it lives on
						    this tab only. */}
					<AiProviderSidebar
						activeProvider={activeProvider}
						activeProviderId={activeProviderId}
						activeModelState={activeModelState}
						activeHasKey={activeHasKey}
						providers={providers}
						providerKeyDocs={providerKeyDocs}
					/>
				</div>
			</TabPanel>

			{/* Data Management tab */}
			<TabPanel tabId="data-management" activeTab={activeTab}>
				{/* Data Management card — mirrors the Migration Tools hero:
				    icon badge, title + status pill, description, and the
				    toggle on the right, with the detail below a divider. */}
				<Card padding={false} className="tr-overflow-hidden">
					<div className="tr-flex tr-items-start tr-gap-4 tr-p-6">
						<span className="tr-flex tr-items-center tr-justify-center tr-w-11 tr-h-11 tr-rounded-xl tr-bg-brand-bg tr-text-brand tr-shrink-0">
							<IconDatabase />
						</span>
						<div className="tr-flex-1 tr-min-w-0">
							<div className="tr-flex tr-items-center tr-gap-2.5 tr-flex-wrap">
								<h2 className="tr-m-0 tr-text-base tr-font-semibold tr-text-primary">
									{__('Data Management', 'thinkrank')}
								</h2>
								<Badge
									variant={
										deleteDataOnUninstall
											? 'warning'
											: 'neutral'
									}
								>
									{deleteDataOnUninstall
										? __('On', 'thinkrank')
										: __('Off', 'thinkrank')}
								</Badge>
							</div>
							<p className="tr-mt-1 tr-mb-0 tr-text-sm tr-text-secondary tr-max-w-2xl">
								{__(
									'Control what happens to your data when the plugin is removed. When enabled, all plugin data including API keys, content briefs, and configuration will be permanently erased on uninstall.',
									'thinkrank'
								)}
							</p>
						</div>
						<div className="tr-flex tr-flex-col tr-items-end tr-gap-1 tr-shrink-0">
							<Toggle
								id="thinkrank-delete-on-uninstall"
								checked={deleteDataOnUninstall}
								onChange={setDeleteDataOnUninstall}
								label={__(
									'Delete all data on uninstall',
									'thinkrank'
								)}
							/>
						</div>
					</div>

					<div className="tr-border-t tr-border-secondary tr-p-6 tr-flex tr-flex-col tr-gap-5">
						{/* Destructive warning — only shown when toggle is on */}
						{deleteDataOnUninstall && (
							<Alert variant="warning">
								{__(
									'Data deletion is enabled. Uninstalling ThinkRank will permanently erase all settings and content briefs. This cannot be undone.',
									'thinkrank'
								)}
							</Alert>
						)}

						{/* Data fate grid */}
						<div className="tr-grid tr-grid-cols-1 sm:tr-grid-cols-2 tr-gap-4">
							{/* Preserved by default */}
							<div className="tr-rounded-xl tr-border tr-border-success/30 tr-bg-success-bg tr-p-4">
								<div className="tr-flex tr-items-center tr-gap-2.5 tr-mb-3 tr-pb-3 tr-border-b tr-border-success/20">
									<span className="tr-flex tr-items-center tr-justify-center tr-w-8 tr-h-8 tr-rounded-lg tr-bg-success/10 tr-shrink-0">
										<svg
											className="tr-w-4 tr-h-4 tr-text-success"
											fill="none"
											stroke="currentColor"
											viewBox="0 0 24 24"
										>
											<path
												strokeLinecap="round"
												strokeLinejoin="round"
												strokeWidth={2.5}
												d="M5 13l4 4L19 7"
											/>
										</svg>
									</span>
									<div className="tr-min-w-0">
										<p className="tr-text-sm tr-font-semibold tr-text-success tr-m-0 tr-leading-tight">
											{__(
												'Preserved by default',
												'thinkrank'
											)}
										</p>
										<p className="tr-text-xs tr-text-success/70 tr-m-0 tr-mt-0.5">
											{__(
												'Kept after uninstall',
												'thinkrank'
											)}
										</p>
									</div>
								</div>
								<ul className="tr-m-0 tr-pl-0 tr-list-none tr-flex tr-flex-col tr-gap-2">
									{[
										__(
											'API keys & provider settings',
											'thinkrank'
										),
										__(
											'Generated content briefs',
											'thinkrank'
										),
										__('Plugin configuration', 'thinkrank'),
									].map((item, i) => (
										<li
											key={i}
											className="tr-flex tr-items-center tr-gap-2 tr-text-sm tr-text-secondary"
										>
											<svg
												className="tr-w-4 tr-h-4 tr-text-success tr-shrink-0"
												fill="none"
												stroke="currentColor"
												viewBox="0 0 24 24"
											>
												<path
													strokeLinecap="round"
													strokeLinejoin="round"
													strokeWidth={2.5}
													d="M5 13l4 4L19 7"
												/>
											</svg>
											{item}
										</li>
									))}
								</ul>
							</div>

							{/* Always cleaned up */}
							<div className="tr-rounded-xl tr-border tr-border-secondary tr-bg-white tr-p-4">
								<div className="tr-flex tr-items-center tr-gap-2.5 tr-mb-3 tr-pb-3 tr-border-b tr-border-secondary">
									<span className="tr-flex tr-items-center tr-justify-center tr-w-8 tr-h-8 tr-rounded-lg tr-bg-line-2 tr-shrink-0">
										<svg
											className="tr-w-4 tr-h-4 tr-text-tertiary"
											fill="none"
											stroke="currentColor"
											viewBox="0 0 24 24"
										>
											<path
												strokeLinecap="round"
												strokeLinejoin="round"
												strokeWidth={2}
												d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
											/>
										</svg>
									</span>
									<div className="tr-min-w-0">
										<p className="tr-text-sm tr-font-semibold tr-text-secondary tr-m-0 tr-leading-tight">
											{__(
												'Always cleaned up',
												'thinkrank'
											)}
										</p>
										<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
											{__(
												'Removed on uninstall',
												'thinkrank'
											)}
										</p>
									</div>
								</div>
								<ul className="tr-m-0 tr-pl-0 tr-list-none tr-flex tr-flex-col tr-gap-2">
									{[
										__('Temporary cache data', 'thinkrank'),
										__('Usage analytics', 'thinkrank'),
										__(
											'Plugin activation flags',
											'thinkrank'
										),
									].map((item, i) => (
										<li
											key={i}
											className="tr-flex tr-items-center tr-gap-2 tr-text-sm tr-text-secondary"
										>
											<svg
												className="tr-w-4 tr-h-4 tr-text-tertiary tr-shrink-0"
												fill="none"
												stroke="currentColor"
												viewBox="0 0 24 24"
											>
												<path
													strokeLinecap="round"
													strokeLinejoin="round"
													strokeWidth={2.5}
													d="M6 12h12"
												/>
											</svg>
											{item}
										</li>
									))}
								</ul>
							</div>
						</div>

						{/* Footer action */}
						<div className="tr-flex tr-justify-end">
							<Button
								variant="primary"
								onClick={handleSaveDataManagement}
								loading={isSavingData}
							>
								{__('Save Settings', 'thinkrank')}
							</Button>
						</div>
					</div>
				</Card>
			</TabPanel>

			{/* Migration Tools tab */}
			<TabPanel tabId="import-export" activeTab={activeTab}>
				<div className="tr-flex tr-flex-col tr-gap-6">
					{/* Hero card — same shape as the Migration Tools hero: icon
					    badge, title + status pill, description, and the enable
					    toggle on the right. Like that one this defaults OFF, so
					    the switch is how the feature is found: the copy below
					    has to sell the screen, not just label it. */}
					<Card padding={false} className="tr-overflow-hidden">
						<div className="tr-flex tr-items-start tr-gap-4 tr-p-6">
							<span className="tr-flex tr-items-center tr-justify-center tr-w-11 tr-h-11 tr-rounded-xl tr-bg-brand-bg tr-text-brand tr-shrink-0">
								<IconExport />
							</span>
							<div className="tr-flex-1 tr-min-w-0">
								<div className="tr-flex tr-items-center tr-gap-2.5 tr-flex-wrap">
									<h2 className="tr-m-0 tr-text-base tr-font-semibold tr-text-primary">
										{__('Import / Export', 'thinkrank')}
									</h2>
									<Badge
										variant={
											enableImportExport
												? 'good'
												: 'neutral'
										}
									>
										{enableImportExport
											? __('On', 'thinkrank')
											: __('Off', 'thinkrank')}
									</Badge>
									{enableImportExport &&
										nativeExport?.total > 0 && (
											<Badge variant="neutral">
												{sprintf(
													/* translators: %s: formatted record count. */
													__(
														'%s records',
														'thinkrank'
													),
													Number(
														nativeExport.total
													).toLocaleString()
												)}
											</Badge>
										)}
								</div>
								<p className="tr-mt-1 tr-mb-0 tr-text-sm tr-text-secondary tr-max-w-2xl">
									{__(
										'Download everything ThinkRank stores as a single file, and load it back on this site or any other. Useful as a backup before big changes, for moving a site, or simply so your SEO data is never locked in here.',
										'thinkrank'
									)}
								</p>
							</div>
							<div className="tr-flex tr-flex-col tr-items-end tr-gap-1 tr-shrink-0">
								<Toggle
									id="thinkrank-enable-import-export"
									checked={enableImportExport}
									onChange={handleToggleImportExport}
									disabled={isSavingImportExport}
									label={__(
										'Enable Import / Export',
										'thinkrank'
									)}
								/>
								{isSavingImportExport && (
									<span className="tr-text-xs tr-text-tertiary">
										{__('Saving…', 'thinkrank')}
									</span>
								)}
							</div>
						</div>

						{/* What an export carries — the counterpart to the
						    "Platforms you can migrate from" list next door. */}
						<div className="tr-border-t tr-border-secondary tr-p-6">
							<span className="tr-block tr-text-sm tr-font-semibold tr-text-primary">
								{__('What an export includes', 'thinkrank')}
							</span>
							<span className="tr-block tr-mt-1 tr-text-sm tr-text-secondary">
								{__(
									'Everything below travels in one JSON file. A CSV view of your post SEO data is available too, for spreadsheets or another plugin’s importer.',
									'thinkrank'
								)}
							</span>

							{isLoadingExport && (
								<div className="tr-mt-4">
									<SkeletonCard />
								</div>
							)}

							{exportLoadFailed && !isLoadingExport && (
								<div className="tr-mt-4">
									<Alert variant="warning">
										{__(
											'Could not read your data counts just now. The export itself is unaffected. Open Import / Export to run one.',
											'thinkrank'
										)}
									</Alert>
								</div>
							)}

							{!isLoadingExport && !exportLoadFailed && (
								<ul className="tr-grid tr-grid-cols-1 sm:tr-grid-cols-2 tr-gap-2.5 tr-list-none tr-m-0 tr-mt-4 tr-p-0">
									{EXPORT_CONTENTS.map((item) => {
										const count = Number(
											nativeExport?.counts?.[item.type] ||
												0
										);
										const present =
											item.countless || count > 0;

										return (
											<li
												key={item.type}
												className="tr-flex tr-items-center tr-gap-3 tr-m-0 tr-px-3 tr-py-2.5 tr-rounded-lg tr-border tr-border-secondary tr-bg-white"
											>
												<span className="tr-flex-1 tr-min-w-0">
													<span className="tr-block tr-text-sm tr-font-medium tr-text-primary tr-truncate">
														{item.name}
														{!item.countless &&
															count > 0 && (
																<span className="tr-ml-1 tr-font-normal tr-text-tertiary">
																	{sprintf(
																		'(%s)',
																		count.toLocaleString()
																	)}
																</span>
															)}
													</span>
													<span className="tr-block tr-text-xs tr-text-tertiary tr-truncate">
														{item.detail}
													</span>
												</span>
												{present ? (
													<span className="tr-inline-flex tr-items-center tr-gap-1.5 tr-text-xs tr-font-medium tr-text-success tr-shrink-0">
														<span className="tr-flex tr-items-center tr-justify-center tr-w-5 tr-h-5 tr-rounded-full tr-bg-success-bg tr-text-success">
															<IconPlatformCheck />
														</span>
														{__(
															'Included',
															'thinkrank'
														)}
													</span>
												) : (
													<span className="tr-text-xs tr-font-medium tr-text-tertiary tr-shrink-0">
														{__(
															'Nothing yet',
															'thinkrank'
														)}
													</span>
												)}
											</li>
										);
									})}
								</ul>
							)}

							{/* Anchor + SPA navigate, so middle-click and
							    open-in-new-tab still behave. Hidden when off,
							    since the page it points at is gone. */}
							{enableImportExport ? (
								<div className="tr-mt-4 tr-pt-4 tr-border-t tr-border-secondary tr-flex tr-justify-end">
									{/* A real navigation, not an SPA one. Both
									    the submenu registration and the
									    localized flags for that screen are
									    decided when its HTML is built, so
									    following this straight after flipping
									    the switch has to re-ask the server. */}
									<a
										href={getPageUrl('import-export')}
										className="tr-inline-flex tr-items-center tr-justify-center tr-gap-2 tr-h-10 tr-px-4 tr-text-sm tr-font-medium tr-rounded-md tr-border tr-border-transparent tr-bg-brand tr-text-white hover:tr-bg-brand tr-no-underline tr-transition-colors tr-duration-150 focus:tr-outline-none focus:tr-ring-2 focus:tr-ring-brand focus:tr-ring-offset-1"
									>
										{__(
											'Open Import / Export',
											'thinkrank'
										)}
										<IconArrowRight />
									</a>
								</div>
							) : (
								<p className="tr-mt-4 tr-pt-4 tr-border-t tr-border-secondary tr-mb-0 tr-text-sm tr-text-tertiary">
									{__(
										'Turned off the Import / Export screen is hidden. Nothing has been deleted; switch this on whenever you want to export or restore.',
										'thinkrank'
									)}
								</p>
							)}
						</div>
					</Card>

					{/* API keys never travel. Worth stating plainly here rather
					    than only in the type list, since it is the first thing
					    a cautious admin wants to know before sharing a file. */}
					{enableImportExport && (
						<Alert
							variant="info"
							title={__('Your API keys stay here', 'thinkrank')}
						>
							{__(
								'Provider API keys and verification tokens are excluded from every export, so a file is safe to hand to a teammate or keep as a backup. Re-enter them after restoring onto a new site.',
								'thinkrank'
							)}
						</Alert>
					)}
				</div>
			</TabPanel>

			<TabPanel tabId="migration-tools" activeTab={activeTab}>
				<div className="tr-flex tr-flex-col tr-gap-6">
					{/* Hero card — mirrors the "AI agent access" MCP card: icon
					    badge, title + status pill, description, and the enable
					    toggle on the right. Saved via its own handler (no
					    API-key gate). */}
					<Card padding={false} className="tr-overflow-hidden">
						<div className="tr-flex tr-items-start tr-gap-4 tr-p-6">
							<span className="tr-flex tr-items-center tr-justify-center tr-w-11 tr-h-11 tr-rounded-xl tr-bg-brand-bg tr-text-brand tr-shrink-0">
								<IconMigration />
							</span>
							<div className="tr-flex-1 tr-min-w-0">
								<div className="tr-flex tr-items-center tr-gap-2.5 tr-flex-wrap">
									<h2 className="tr-m-0 tr-text-base tr-font-semibold tr-text-primary">
										{__('Migration Tools', 'thinkrank')}
									</h2>
									<Badge
										variant={
											enableMigrationTools
												? 'good'
												: 'neutral'
										}
									>
										{enableMigrationTools
											? __('On', 'thinkrank')
											: __('Off', 'thinkrank')}
									</Badge>
								</div>
								<p className="tr-mt-1 tr-mb-0 tr-text-sm tr-text-secondary tr-max-w-2xl">
									{__(
										'Bring your existing SEO data into ThinkRank. Turning this on adds a "Migration" menu item so you can import titles, descriptions, canonicals, robots rules, social meta, redirects, and settings from another SEO plugin handy if you skipped migration during setup or need to re-import updated data.',
										'thinkrank'
									)}
								</p>
							</div>
							<div className="tr-flex tr-flex-col tr-items-end tr-gap-1 tr-shrink-0">
								<Toggle
									id="thinkrank-enable-migration"
									checked={enableMigrationTools}
									onChange={handleToggleMigrationTools}
									disabled={isSavingMigration}
									label={__(
										'Enable Migration Tools',
										'thinkrank'
									)}
								/>
								{isSavingMigration && (
									<span className="tr-text-xs tr-text-tertiary">
										{__('Saving…', 'thinkrank')}
									</span>
								)}
							</div>
						</div>

						{/* Supported platforms — shows which SEO plugins we can
						    migrate from today. */}
						<div className="tr-border-t tr-border-secondary tr-p-6">
							<span className="tr-block tr-text-sm tr-font-semibold tr-text-primary">
								{__(
									'Platforms you can migrate from',
									'thinkrank'
								)}
							</span>
							<span className="tr-block tr-mt-1 tr-text-sm tr-text-secondary">
								{__(
									'ThinkRank imports SEO data directly from these plugins even after your initial setup.',
									'thinkrank'
								)}
							</span>
							<ul className="tr-grid tr-grid-cols-1 sm:tr-grid-cols-2 tr-gap-2.5 tr-list-none tr-m-0 tr-mt-4 tr-p-0">
								{MIGRATION_PLATFORMS.map((platform) => (
									<li
										key={platform.slug}
										className="tr-flex tr-items-center tr-gap-3 tr-m-0 tr-px-3 tr-py-2.5 tr-rounded-lg tr-border tr-border-secondary tr-bg-white"
									>
										<span className="tr-flex tr-items-center tr-justify-center tr-w-9 tr-h-9 tr-rounded-lg tr-bg-gray-50 tr-border tr-border-secondary tr-shrink-0 tr-overflow-hidden">
											<img
												src={platform.logo}
												alt=""
												width="24"
												height="24"
												className="tr-w-6 tr-h-6 tr-object-contain"
											/>
										</span>
										<span className="tr-flex-1 tr-min-w-0">
											<span className="tr-block tr-text-sm tr-font-medium tr-text-primary tr-truncate">
												{platform.name}
											</span>
											<span className="tr-block tr-text-xs tr-text-tertiary tr-truncate">
												{platform.detail}
											</span>
										</span>
										<span className="tr-inline-flex tr-items-center tr-gap-1.5 tr-text-xs tr-font-medium tr-text-success tr-shrink-0">
											<span className="tr-flex tr-items-center tr-justify-center tr-w-5 tr-h-5 tr-rounded-full tr-bg-success-bg tr-text-success">
												<IconPlatformCheck />
											</span>
											{__('Supported', 'thinkrank')}
										</span>
									</li>
								))}
							</ul>

							{/* Once enabled, offer a direct jump to the Migration
							    screen (also reachable from the ThinkRank submenu
							    after a reload). Anchor + SPA navigate so
							    middle-click / open-in-new-tab still work. */}
							{enableMigrationTools && (
								<div className="tr-mt-4 tr-pt-4 tr-border-t tr-border-secondary tr-flex tr-justify-end">
									{/* Full load, same reason as the Import /
									    Export card above. */}
									<a
										href={getPageUrl('migration')}
										className="tr-inline-flex tr-items-center tr-justify-center tr-gap-2 tr-h-10 tr-px-4 tr-text-sm tr-font-medium tr-rounded-md tr-border tr-border-transparent tr-bg-brand tr-text-white hover:tr-bg-brand tr-no-underline tr-transition-colors tr-duration-150 focus:tr-outline-none focus:tr-ring-2 focus:tr-ring-brand focus:tr-ring-offset-1"
									>
										{__('Open Migration', 'thinkrank')}
										<IconArrowRight />
									</a>
								</div>
							)}
						</div>
					</Card>

					{/* Caution — only shown when enabled */}
					{enableMigrationTools && (
						<Alert
							variant="warning"
							title={__('Before you re-import', 'thinkrank')}
						>
							{__(
								'Running a migration again may overwrite existing ThinkRank settings and metadata where applicable. We recommend creating a database backup before re-importing.',
								'thinkrank'
							)}
						</Alert>
					)}
				</div>
			</TabPanel>
		</div>
	);
};

// Sidebar shown on the AI Provider tab only — surfaces the active configuration
// and where to get an API key, both specific to AI provider setup.
const AiProviderSidebar = ({
	activeProvider,
	activeProviderId,
	activeModelState,
	activeHasKey,
	providers,
	providerKeyDocs,
}) => (
	<aside className="tr-flex tr-flex-col tr-gap-5">
		{/* Active configuration — only once a provider is actually connected.
		    "Connected" is the same test the provider grid uses for its Active
		    badge: a saved provider that has a key. Before that there is no
		    configuration to describe, and the card rendered an empty icon, a
		    blank name and a "Not set" pill on every fresh install (#572). */}
		{Boolean(activeProviderId && activeHasKey) && (
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Active configuration', 'thinkrank')}
					</h3>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<div className="tr-flex tr-items-center tr-gap-3">
						<span className="tr-w-10 tr-h-10 tr-rounded-lg tr-bg-brand-bg tr-flex tr-items-center tr-justify-center tr-shrink-0">
							{activeProvider?.icon}
						</span>
						<div className="tr-min-w-0">
							<p className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0 tr-truncate">
								{activeProvider?.label}
							</p>
							<p className="tr-text-xs tr-text-tertiary tr-mt-0.5 tr-mb-0 tr-truncate">
								{activeProvider?.sub}
							</p>
						</div>
					</div>

					<div className="tr-flex tr-items-center tr-justify-between tr-text-sm tr-pt-1">
						<span className="tr-text-tertiary">
							{__('Model', 'thinkrank')}
						</span>
						<ModelBadge
							provider={activeProviderId}
							model={activeModelState}
						/>
					</div>

					<div className="tr-flex tr-items-center tr-justify-between tr-text-sm">
						<span className="tr-text-tertiary">
							{__('API key', 'thinkrank')}
						</span>
						<span className="tr-inline-flex tr-items-center tr-gap-1.5 tr-text-xs tr-font-medium tr-px-2 tr-py-1 tr-rounded-full tr-bg-success-bg tr-text-success">
							<span className="tr-w-1.5 tr-h-1.5 tr-rounded-full tr-bg-success" />
							{__('Configured', 'thinkrank')}
						</span>
					</div>
				</div>
			</Card>
		)}

		{/* Get API key */}
		<Card padding={false}>
			<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
				<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
					{__('Get an API key', 'thinkrank')}
				</h3>
				<p className="tr-text-xs tr-text-tertiary tr-mt-1 tr-mb-0">
					{__(
						'Sign in to your provider and create a key.',
						'thinkrank'
					)}
				</p>
			</div>
			<ul className="tr-m-0 tr-p-0 tr-list-none">
				{providers.map((p) => (
					<li
						key={p.id}
						className="tr-border-b tr-border-secondary last:tr-border-b-0"
					>
						<a
							href={providerKeyDocs[p.id].url}
							target="_blank"
							rel="noopener noreferrer"
							className="tr-flex tr-items-center tr-gap-3 tr-px-5 tr-py-3 tr-no-underline hover:tr-bg-white tr-text-secondary"
						>
							<span className="tr-w-8 tr-h-8 tr-rounded-md tr-bg-brand-bg tr-flex tr-items-center tr-justify-center tr-shrink-0">
								{p.icon}
							</span>
							<span className="tr-min-w-0 tr-flex-1">
								<span className="tr-block tr-text-sm tr-font-medium tr-text-primary">
									{p.label}
								</span>
								<span className="tr-block tr-text-xs tr-text-tertiary tr-truncate">
									{providerKeyDocs[p.id].label}
								</span>
							</span>
							<svg
								className="tr-w-4 tr-h-4 tr-text-tertiary tr-shrink-0"
								fill="none"
								stroke="currentColor"
								viewBox="0 0 24 24"
							>
								<path
									strokeLinecap="round"
									strokeLinejoin="round"
									strokeWidth={2}
									d="M14 5l7 7m0 0l-7 7m7-7H3"
								/>
							</svg>
						</a>
					</li>
				))}
			</ul>
		</Card>

		{/* Bring-your-own-key benefits */}
		<Card>
			<div className="tr-flex tr-items-center tr-gap-2 tr-mb-3">
				<span className="tr-flex tr-items-center tr-justify-center tr-w-7 tr-h-7 tr-rounded-md tr-bg-brand-bg tr-text-brand tr-shrink-0">
					<svg
						className="tr-w-4 tr-h-4"
						fill="none"
						stroke="currentColor"
						viewBox="0 0 24 24"
					>
						<path
							strokeLinecap="round"
							strokeLinejoin="round"
							strokeWidth={2}
							d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
						/>
					</svg>
				</span>
				<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
					{__('Why your own key?', 'thinkrank')}
				</h3>
			</div>
			<ul className="tr-m-0 tr-pl-0 tr-list-none tr-flex tr-flex-col tr-gap-2.5">
				{[
					__(
						'Pay your AI provider directly, with no markup.',
						'thinkrank'
					),
					__('Unlimited generations at provider rates.', 'thinkrank'),
					__(
						'Encrypted at rest and stored only on your site.',
						'thinkrank'
					),
					__(
						'Switch providers anytime without losing data.',
						'thinkrank'
					),
				].map((item, i) => (
					<li
						key={i}
						className="tr-flex tr-items-start tr-gap-2 tr-text-sm tr-text-secondary tr-leading-snug"
					>
						<svg
							className="tr-w-4 tr-h-4 tr-text-success tr-shrink-0 tr-mt-0.5"
							fill="none"
							stroke="currentColor"
							viewBox="0 0 24 24"
						>
							<path
								strokeLinecap="round"
								strokeLinejoin="round"
								strokeWidth={2.5}
								d="M5 13l4 4L19 7"
							/>
						</svg>
						<span>{item}</span>
					</li>
				))}
			</ul>
		</Card>

		{/* Need help — contextual links to docs and support. */}
		<Card padding={false}>
			<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
				<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
					{__('Need help?', 'thinkrank')}
				</h3>
			</div>
			<ul className="tr-m-0 tr-p-0 tr-list-none">
				{SETUP_HELP_LINKS.map((link) => (
					<li
						key={link.href}
						className="tr-border-b tr-border-secondary last:tr-border-b-0"
					>
						<a
							href={link.href}
							target="_blank"
							rel="noopener noreferrer"
							className="tr-flex tr-items-center tr-gap-3 tr-px-5 tr-py-3 tr-no-underline hover:tr-bg-white tr-text-secondary"
						>
							<span className="tr-min-w-0 tr-flex-1">
								<span className="tr-block tr-text-sm tr-font-medium tr-text-primary">
									{link.label}
								</span>
								<span className="tr-block tr-text-xs tr-text-tertiary tr-truncate">
									{link.desc}
								</span>
							</span>
							<svg
								className="tr-w-4 tr-h-4 tr-text-tertiary tr-shrink-0"
								fill="none"
								stroke="currentColor"
								viewBox="0 0 24 24"
							>
								<path
									strokeLinecap="round"
									strokeLinejoin="round"
									strokeWidth={2}
									d="M14 5l7 7m0 0l-7 7m7-7H3"
								/>
							</svg>
						</a>
					</li>
				))}
			</ul>
		</Card>
	</aside>
);

export default Settings;
