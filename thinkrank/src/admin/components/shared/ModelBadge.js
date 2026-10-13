/**
 * Model Badge Component
 *
 * Shared component for displaying AI model badges across the application.
 *
 * Styling lives here as Tailwind utilities rather than in a global stylesheet.
 * The previous `.model-badge` rules in styles/components.scss were nested under
 * a `.thinkrank-ui` ancestor that the admin SPA never renders, so every badge
 * fell back to unstyled inline text (#597). Utilities are scoped to `.tr-root`,
 * which the SPA does render, so they cannot silently detach the same way.
 *
 * @package
 * @since 1.0.0
 */

/**
 * Per-provider tone. Each entry is a complete literal class string so
 * Tailwind's content scanner can see it — never build these by interpolation.
 */
const PROVIDER_TONE = {
	openai: 'tr-bg-emerald-50 tr-text-emerald-700 tr-border-emerald-200',
	claude: 'tr-bg-amber-50 tr-text-amber-700 tr-border-amber-200',
	gemini: 'tr-bg-blue-50 tr-text-blue-700 tr-border-blue-200',
	openrouter: 'tr-bg-violet-50 tr-text-violet-700 tr-border-violet-200',
	openai_compatible: 'tr-bg-slate-50 tr-text-slate-700 tr-border-slate-200',
};

const FALLBACK_TONE = 'tr-bg-line-2 tr-text-secondary tr-border-secondary';

/**
 * Human-readable provider names, for the badge tooltip.
 */
const PROVIDER_LABEL = {
	openai: 'OpenAI',
	claude: 'Claude',
	gemini: 'Gemini',
	openrouter: 'OpenRouter',
	// The user's own server, whatever it runs (#721).
	openai_compatible: 'Custom endpoint',
};

/**
 * Model id → display name, per provider.
 *
 * Keep in sync with the pricing tables in
 * includes/api/class-usage-analytics-endpoint.php: any model that can be
 * priced should also have a name here, or the Usage Breakdown grid shows a
 * raw id next to a formatted one in the row above.
 */
const MODEL_NAMES = {
	openai: {
		'gpt-5': 'GPT‑5',
		'gpt-5-mini': 'GPT‑5‑mini',
		'gpt-5-nano': 'GPT‑5‑nano',
		'gpt-4.1': 'GPT‑4.1',
		'gpt-4o': 'GPT‑4o',
		'gpt-4o-mini': 'GPT‑4o mini',
		'gpt-4-turbo': 'GPT‑4 Turbo',
		'o3-mini': 'o3‑mini',
	},
	claude: {
		// Current models
		'claude-opus-5': 'Claude Opus 5',
		'claude-opus-4-8': 'Claude Opus 4.8',
		'claude-sonnet-5': 'Claude Sonnet 5',
		'claude-haiku-4-5': 'Claude Haiku 4.5',
		// Previous-generation 4.x models
		'claude-opus-4-6': 'Claude Opus 4.6',
		'claude-sonnet-4-6': 'Claude Sonnet 4.6',
		'claude-sonnet-4-0': 'Claude 4 Sonnet',
		'claude-opus-4-0': 'Claude 4 Opus',
		// Legacy 3.x ids, post snapshot-date strip.
		'claude-3-5-sonnet': 'Claude 3.5 Sonnet',
		'claude-3-5-haiku': 'Claude 3.5 Haiku',
		'claude-3-opus': 'Claude 3 Opus',
	},
	gemini: {
		'gemini-3.1-pro-preview': 'Gemini 3.1 Pro',
		'gemini-3.1-pro': 'Gemini 3.1 Pro',
		'gemini-3.5-flash': 'Gemini 3.5 Flash',
		'gemini-3.1-flash-lite': 'Gemini 3.1 Flash Lite',
		'gemini-2.5-flash': 'Gemini 2.5 Flash',
		'gemini-2.5-flash-lite': 'Gemini 2.5 Flash‑Lite',
		'gemini-2.5-pro': 'Gemini 2.5 Pro',
		'gemini-2.0-flash': 'Gemini 2.0 Flash',
		'gemini-1.5-flash': 'Gemini 1.5 Flash',
		'gemini-1.5-pro': 'Gemini 1.5 Pro',
	},
	openrouter: {
		'openai/gpt-4o-mini': 'GPT‑4o mini',
		'anthropic/claude-sonnet-5': 'Claude Sonnet 5',
		'google/gemini-3.5-flash': 'Gemini 3.5 Flash',
		// Retired upstream, kept so an already-stored id still reads well.
		'anthropic/claude-3.5-sonnet': 'Claude 3.5 Sonnet',
		'google/gemini-2.0-flash-001': 'Gemini 2.0 Flash',
		'meta-llama/llama-3.3-70b-instruct': 'Llama 3.3 70B',
		'deepseek/deepseek-chat': 'DeepSeek Chat',
	},
	// No map: the ids belong to whatever server the user pointed us at, so the
	// raw id is the only honest label and getModelDisplayName() falls back to it.
	openai_compatible: {},
};

/**
 * Strip a trailing snapshot date so dated model ids
 * (e.g. `gpt-5-nano-2025-08-07`, `claude-haiku-4-5-20251001`) resolve to
 * their base name instead of showing the raw date suffix.
 *
 * @param {string} id Model id.
 * @return {string} Model id without a trailing snapshot date.
 */
const stripSnapshotDate = (id) =>
	typeof id === 'string'
		? id.replace(/-\d{4}-\d{2}-\d{2}$/, '').replace(/-\d{8}$/, '')
		: id;

/**
 * Display name for a model, falling back to the raw id when unmapped.
 *
 * @param {string} provider Provider slug.
 * @param {string} rawModel Model id as stored.
 * @return {string} Display name.
 */
const getModelDisplayName = (provider, rawModel) => {
	const model = stripSnapshotDate(rawModel);
	return MODEL_NAMES[provider]?.[model] || model;
};

/**
 * Model Badge Component
 *
 * @param {Object} root0          Props.
 * @param {string} root0.provider Provider slug ('openai' | 'claude' | 'gemini' | 'openrouter' | 'openai_compatible').
 * @param {string} root0.model    Model id as stored on the usage row.
 * @return {JSX.Element|null} The badge, or null when either field is missing.
 */
const ModelBadge = ({ provider, model }) => {
	if (!provider || !model) return null;

	const displayName = getModelDisplayName(provider, model);
	const tone = PROVIDER_TONE[provider] || FALLBACK_TONE;
	const providerLabel = PROVIDER_LABEL[provider] || provider;

	return (
		<span
			className={`tr-inline-flex tr-items-center tr-px-2 tr-py-0.5 tr-rounded-md tr-border tr-text-xs tr-font-medium tr-whitespace-nowrap tr-align-middle ${tone}`}
			title={`Generated using ${displayName} (${providerLabel})`}
		>
			{displayName}
		</span>
	);
};

export default ModelBadge;
