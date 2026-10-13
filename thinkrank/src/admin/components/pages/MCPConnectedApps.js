/**
 * MCP Connected AI apps
 *
 * Lists the AI apps currently connected to this site via MCP (see #244) and
 * lets an admin cut any of them off. Two kinds of connection appear:
 *   - OAuth apps (Claude web/mobile/desktop, ChatGPT) — each has its own
 *     identity, the approving admin, and a last-used date, and can be revoked
 *     individually.
 *   - A single combined "Static token connections" row standing in for every
 *     header-based client (Claude Code, Cursor, VS Code, Claude Desktop) that
 *     shares the one connection token. Revoking it resets that token, which
 *     disconnects all of them at once.
 *
 * Data comes from GET /thinkrank/v1/mcp/apps; revoking hits
 * POST /thinkrank/v1/mcp/apps/revoke, which returns the refreshed list.
 *
 * Governance (#717): every connection shows what it may read or change per
 * area, when its access expires, and how many changes it made in the last 30
 * days (a link into the Activity card). Edit access rewrites the scopes on
 * the connection's live tokens, so it applies on its next request without a
 * reconnect. Revoke all access cuts off every OAuth app and replaces the
 * connection token in one step.
 *
 * @package
 * @since 1.0.0
 */

import { __, sprintf, _n } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Alert,
	Badge,
	Modal,
	EmptyState,
	Select,
	SkeletonText,
} from '../ui';
import logger from '../../../shared/utils/logger';
import notify from '../../utils/notify';
import { setHubConnected } from '../../utils/hubStatus';

const IconPlug = () => (
	<svg
		width="18"
		height="18"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<path d="M12 22v-5" />
		<path d="M9 8V2" />
		<path d="M15 8V2" />
		<path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z" />
	</svg>
);

const IconRefresh = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<polyline points="23 4 23 10 17 10" />
		<polyline points="1 20 1 14 7 14" />
		<path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" />
	</svg>
);

const IconTrash = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<polyline points="3 6 5 6 21 6" />
		<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
		<line x1="10" y1="11" x2="10" y2="17" />
		<line x1="14" y1="11" x2="14" y2="17" />
	</svg>
);

const IconSliders = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<line x1="4" y1="21" x2="4" y2="14" />
		<line x1="4" y1="10" x2="4" y2="3" />
		<line x1="12" y1="21" x2="12" y2="12" />
		<line x1="12" y1="8" x2="12" y2="3" />
		<line x1="20" y1="21" x2="20" y2="16" />
		<line x1="20" y1="12" x2="20" y2="3" />
		<line x1="1" y1="14" x2="7" y2="14" />
		<line x1="9" y1="8" x2="15" y2="8" />
		<line x1="17" y1="16" x2="23" y2="16" />
	</svg>
);

const IconKey = () => (
	<svg
		width="20"
		height="20"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<circle cx="7.5" cy="15.5" r="5.5" />
		<path d="m21 2-9.6 9.6" />
		<path d="m15.5 7.5 3 3L22 7l-3-3" />
	</svg>
);

const IconSpinner = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		className="tr-animate-spin"
		aria-hidden="true"
	>
		<path d="M21 12a9 9 0 1 1-6.219-8.56" />
	</svg>
);

/* ------------------------------------------------------------ brand marks */
/* Logos are trademarks of their respective owners; used here only to identify
 * a connected app. Any client we don't recognize falls back to an initial
 * avatar (see resolveBrand), so we never show a wrong mark. */

// Claude's radiating "sunburst" mark (Anthropic).
const BrandClaude = ({ size = 20 }) => (
	<svg
		width={size}
		height={size}
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		aria-hidden="true"
	>
		<path d="M12 12L20.5 12M12 12L19.15 7.4M12 12L15.53 4.26M12 12L10.79 3.58M12 12L6.44 5.57M12 12L3.85 9.59M12 12L3.85 14.41M12 12L6.44 18.43M12 12L10.79 20.42M12 12L15.53 19.74M12 12L19.15 16.6" />
	</svg>
);

// OpenAI / ChatGPT logomark (real path).
const BrandOpenAI = ({ size = 20 }) => (
	<svg
		width={size}
		height={size}
		viewBox="0 0 24 24"
		fill="currentColor"
		aria-hidden="true"
	>
		<path d="M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.1419.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997z" />
	</svg>
);

// Google Gemini four-point spark.
const BrandGemini = ({ size = 20 }) => (
	<svg
		width={size}
		height={size}
		viewBox="0 0 24 24"
		fill="currentColor"
		aria-hidden="true"
	>
		<path d="M12 2c.34 4.9 4.8 9.36 9.7 9.7.3.02.3.58 0 .6C16.8 12.64 12.34 17.1 12 22c-.02.3-.58.3-.6 0C11.06 17.1 6.6 12.64 1.7 12.3c-.3-.02-.3-.58 0-.6C6.6 11.36 11.06 6.9 11.4 2c.02-.3.58-.3.6 0Z" />
	</svg>
);

// Grok (xAI) — angular slash mark (stylized).
const BrandGrok = ({ size = 20 }) => (
	<svg
		width={size}
		height={size}
		viewBox="0 0 24 24"
		fill="currentColor"
		aria-hidden="true"
	>
		<path d="M6.5 21H3.2l7.9-11.3 1.65 2.36L6.5 21Zm12.9-18-9.1 13 1.65 2.35L21 3h-3.3l-.05.02L18.4 3ZM14.2 3l3.1 4.45-1.65 2.36L11.9 4l-.05-.02L14.2 3Z" />
	</svg>
);

// Cursor editor — mono pointer/triangle.
const BrandCursor = ({ size = 20 }) => (
	<svg
		width={size}
		height={size}
		viewBox="0 0 24 24"
		fill="currentColor"
		aria-hidden="true"
	>
		<path d="M4 3l16 7-6.6 2.4L11 20 4 3z" />
	</svg>
);

// VS Code — folded ribbon mark.
const BrandVSCode = ({ size = 20 }) => (
	<svg
		width={size}
		height={size}
		viewBox="0 0 24 24"
		fill="currentColor"
		aria-hidden="true"
	>
		<path d="M17.5 2 9.4 9.6 4.9 6.2 3 7.1l3.9 3.9L3 14.9l1.9.9 4.5-3.4L17.5 22l3.5-1.7V3.7L17.5 2Zm.5 5.4v9.2l-6.2-4.6L18 7.4Z" />
	</svg>
);

/**
 * Match a connected app's (free-text) name to a known brand mark. Priority
 * ordered so more specific names win. Returns { Icon, accent } or null (→
 * initial-letter avatar). `claude` is tested before `code`/`gpt` so
 * "Claude Code" keeps the Claude mark.
 */
const BRANDS = [
	{ test: /claude/i, Icon: BrandClaude, accent: '#d97757' },
	// `codex` is OpenAI's CLI/agent and registers under that bare name, which
	// none of the other OpenAI aliases match — it fell through to the
	// initial-letter avatar. Safe here: the VS Code pattern below requires a
	// "vs " prefix, so "Codex" cannot be mistaken for it.
	{
		test: /(chatgpt|openai|gpt|codex)/i,
		Icon: BrandOpenAI,
		accent: '#0d0d0d',
	},
	{ test: /(vs ?code|visual studio)/i, Icon: BrandVSCode, accent: '#0098ff' },
	{ test: /cursor/i, Icon: BrandCursor, accent: '#0f172a' },
	{ test: /(grok|xai)/i, Icon: BrandGrok, accent: '#111111' },
	{ test: /(gemini|google)/i, Icon: BrandGemini, accent: '#1a73e8' },
];

const resolveBrand = (name) =>
	BRANDS.find((brand) => brand.test.test(name || '')) || null;

// Format a unix timestamp (seconds) as a short local date, or a "never" label
// when the connection has not been used yet.
const formatLastUsed = (ts) => {
	if (!ts) {
		return __('Not used yet', 'thinkrank');
	}
	try {
		const date = new Date(ts * 1000).toLocaleDateString();
		return sprintf(
			/* translators: %s: a date, e.g. 8/6/2026. */
			__('Last used %s', 'thinkrank'),
			date
		);
	} catch (e) {
		return __('Last used recently', 'thinkrank');
	}
};

const LEVEL_OPTIONS = [
	{ value: 'none', label: __('No access', 'thinkrank') },
	{ value: 'read', label: __('Read', 'thinkrank') },
	{ value: 'write', label: __('Read & Write', 'thinkrank') },
];

// Ordered so a compare answers "at most".
const LEVEL_RANK = { none: 0, read: 1, write: 2 };

// The choices for one area. A ThinkRank Hub grant is only narrowed here (the
// server refuses anything above what it holds, #1088), so its modal offers
// nothing above its current level in that area.
const levelOptionsFor = (ceiling, areaKey) => {
	if (!ceiling) {
		return LEVEL_OPTIONS;
	}
	const max = LEVEL_RANK[ceiling[areaKey]] ?? 0;
	return LEVEL_OPTIONS.filter((option) => LEVEL_RANK[option.value] <= max);
};

const EXPIRY_OPTIONS = [
	{ value: '30', label: __('30 days from now', 'thinkrank') },
	{ value: '90', label: __('90 days from now', 'thinkrank') },
	{ value: '365', label: __('1 year from now', 'thinkrank') },
	{ value: '0', label: __('Never expires', 'thinkrank') },
];

// One badge for a level map: full access, read-only, or how narrow it is.
const accessBadge = (levels = {}, areas = []) => {
	const values = areas.map((area) => levels[area.key] || 'none');
	if (values.length && values.every((v) => v === 'write')) {
		return { variant: 'good', label: __('Full access', 'thinkrank') };
	}
	if (values.length && values.every((v) => v === 'read')) {
		return { variant: 'warning', label: __('Read-only', 'thinkrank') };
	}
	if (values.every((v) => v === 'none')) {
		return { variant: 'poor', label: __('No access', 'thinkrank') };
	}
	return { variant: 'info', label: __('Limited', 'thinkrank') };
};

const formatDate = (ts) => {
	try {
		return new Date(ts * 1000).toLocaleDateString();
	} catch (e) {
		return '';
	}
};

// "Access expires 1/4/2027", or the expired / never wording.
const expiryLine = (app) => {
	if (app.expired) {
		return sprintf(
			/* translators: %s: a date. */
			__('Expired %s', 'thinkrank'),
			formatDate(app.expires_at)
		);
	}
	if (!app.expires_at) {
		return __('Never expires', 'thinkrank');
	}
	return sprintf(
		/* translators: %s: a date. */
		__('Expires %s', 'thinkrank'),
		formatDate(app.expires_at)
	);
};

// One connection row: avatar, name + access badge, a meta line, the activity
// link, and the edit / revoke actions.
const AppRow = ({
	name,
	icon,
	badge,
	meta,
	activity,
	warning,
	onViewActivity,
	onEdit,
	onRevoke,
	revoking,
	// True while the list is being replaced: the row may be about to change,
	// so it offers no action until the new one is in.
	busy = false,
}) => {
	const initial = (name || '?').trim().charAt(0).toUpperCase() || '?';
	const brand = icon ? null : resolveBrand(name);
	const BrandIcon = brand?.Icon;
	const changes = activity?.changes || 0;

	let avatar = (
		<span
			className="tr-flex tr-items-center tr-justify-center tr-w-10 tr-h-10 tr-rounded-lg tr-bg-brand-bg tr-text-brand tr-text-base tr-font-semibold tr-shrink-0"
			aria-hidden="true"
		>
			{icon || initial}
		</span>
	);
	if (brand) {
		avatar = (
			<span
				className="tr-flex tr-items-center tr-justify-center tr-w-10 tr-h-10 tr-rounded-lg tr-shrink-0"
				style={{
					color: brand.accent,
					backgroundColor: `${brand.accent}14`,
				}}
				aria-hidden="true"
			>
				<BrandIcon size={20} />
			</span>
		);
	}

	return (
		<div className="tr-flex tr-items-center tr-gap-3.5 tr-py-3.5 tr-border-b tr-border-secondary last:tr-border-b-0">
			{avatar}
			<div className="tr-min-w-0 tr-flex-1">
				<div className="tr-flex tr-items-center tr-gap-2 tr-flex-wrap">
					<span className="tr-text-sm tr-font-semibold tr-text-primary tr-truncate">
						{name}
					</span>
					<Badge variant={badge.variant}>{badge.label}</Badge>
				</div>
				<span className="tr-block tr-mt-0.5 tr-text-xs tr-text-secondary">
					{meta}
					{' · '}
					<button
						type="button"
						onClick={onViewActivity}
						className="tr-p-0 tr-border-0 tr-bg-transparent tr-text-xs tr-text-brand tr-cursor-pointer hover:tr-underline"
					>
						{sprintf(
							/* translators: %d: number of changes. */
							_n(
								'%d change in 30 days',
								'%d changes in 30 days',
								changes,
								'thinkrank'
							),
							changes
						)}
					</button>
				</span>
				{warning && (
					<span className="tr-block tr-mt-1 tr-text-xs tr-text-warning">
						{warning}
					</span>
				)}
			</div>
			<button
				type="button"
				onClick={onEdit}
				disabled={busy}
				aria-label={sprintf(
					/* translators: %s: connection name. */
					__('Edit access for %s', 'thinkrank'),
					name
				)}
				title={__('Edit access', 'thinkrank')}
				className="tr-flex tr-items-center tr-justify-center tr-w-8 tr-h-8 tr-rounded-md tr-border-0 tr-bg-transparent tr-text-tertiary tr-cursor-pointer tr-transition-colors hover:tr-bg-brand-bg hover:tr-text-brand disabled:tr-opacity-50 disabled:tr-cursor-not-allowed tr-shrink-0"
			>
				<IconSliders />
			</button>
			{onRevoke && (
				<button
					type="button"
					onClick={onRevoke}
					disabled={revoking || busy}
					aria-label={sprintf(
						/* translators: %s: connection name. */
						__('Revoke %s', 'thinkrank'),
						name
					)}
					title={__('Revoke', 'thinkrank')}
					className="tr-flex tr-items-center tr-justify-center tr-w-8 tr-h-8 tr-rounded-md tr-border-0 tr-bg-transparent tr-text-tertiary tr-cursor-pointer tr-transition-colors hover:tr-bg-danger-bg hover:tr-text-danger disabled:tr-opacity-50 disabled:tr-cursor-not-allowed tr-shrink-0"
				>
					{revoking ? <IconSpinner /> : <IconTrash />}
				</button>
			)}
		</div>
	);
};

// The hub's grant is listed here too, so a list read from the server also
// says whether the header's "Hub connected" chip should stay on: the server
// counts the site connected while any grant carries the hub mark (#1143).
// A payload without the list says nothing about the hub, so it leaves the
// chip as it was.
const reportHub = (payload) => {
	if (!Array.isArray(payload?.oauth_apps)) {
		return;
	}
	setHubConnected(payload.oauth_apps.some((app) => app.hub));
};

const MCPConnectedApps = ({
	onViewActivity,
	onRevokedAll,
	onTokenChanged,
	onAccessChanged,
	refreshKey = 0,
}) => {
	const [data, setData] = useState(null);
	const [loading, setLoading] = useState(true);
	const [loadError, setLoadError] = useState('');
	// The connection awaiting revoke confirmation: { client_id, name }.
	const [pending, setPending] = useState(null);
	const [revoking, setRevoking] = useState(false);
	const [actionError, setActionError] = useState('');
	// The connection whose access is being edited, with draft values.
	const [editing, setEditing] = useState(null);
	const [saving, setSaving] = useState(false);
	const [confirmAll, setConfirmAll] = useState(false);
	const [revokingAll, setRevokingAll] = useState(false);

	// Bumped by every load and every access change. Only the newest may set
	// the list, so a refresh that answers after a revoke or a scope edit
	// cannot put the old access back on screen.
	const dataVersion = useRef(0);
	const latestLoad = useRef(0);
	const claimData = () => ++dataVersion.current;

	// `announce` fires a toast on the outcome — passed only for the user's
	// Refresh click, never for the silent load on mount.
	const load = useCallback(async (announce = false) => {
		const request = ++latestLoad.current;
		const version = claimData();
		setLoading(true);
		setLoadError('');
		try {
			const response = await apiFetch({ path: '/thinkrank/v1/mcp/apps' });
			if (version !== dataVersion.current) {
				return;
			}
			setData(response);
			reportHub(response);
			if (announce) {
				notify.success(__('Connected apps refreshed.', 'thinkrank'));
			}
		} catch (error) {
			if (version !== dataVersion.current) {
				return;
			}
			logger.error('ThinkRank: Failed to load connected apps', error);
			setLoadError(
				__(
					'Couldn’t load connected apps. Reload and try again.',
					'thinkrank'
				)
			);
			if (announce) {
				notify.error(
					__('Couldn’t refresh connected apps.', 'thinkrank')
				);
			}
		} finally {
			if (request === latestLoad.current) {
				setLoading(false);
			}
		}
	}, []);

	// refreshKey: a revert in Activity changes each row's change count.
	useEffect(() => {
		load();
	}, [load, refreshKey]);

	// While a modal is open its overlay hides the page, so a failed action
	// is reported inside the modal; opening or closing one starts clean.
	const modalOpen = !!editing || !!pending || confirmAll;
	useEffect(() => {
		setActionError('');
	}, [modalOpen]);

	const modalError = actionError ? (
		<Alert variant="error">{actionError}</Alert>
	) : null;

	const confirmRevoke = useCallback(async () => {
		if (!pending) {
			return;
		}
		setRevoking(true);
		setActionError('');
		claimData();
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/mcp/apps/revoke',
				method: 'POST',
				data: { target: 'oauth', client_id: pending.client_id },
			});
			setData((current) => ({ ...current, ...response }));
			reportHub(response);
			setPending(null);
			if (onAccessChanged) {
				onAccessChanged();
			}
		} catch (error) {
			logger.error('ThinkRank: Revoke failed', error);
			setActionError(
				__(
					'Couldn’t revoke that connection. Please try again.',
					'thinkrank'
				)
			);
		} finally {
			// A load that began while this ran may carry the old list.
			claimData();
			setRevoking(false);
		}
	}, [pending, onAccessChanged]);

	const saveAccess = useCallback(async () => {
		if (!editing) {
			return;
		}
		setSaving(true);
		setActionError('');
		claimData();
		try {
			// One request, so the areas never land without the expiry.
			const body = {
				client_id: editing.client_id,
				levels: editing.levels,
			};
			if (editing.isStatic && editing.expiry !== '') {
				body.expiry_days = Number(editing.expiry);
			}
			const response = await apiFetch({
				path: '/thinkrank/v1/mcp/apps/scopes',
				method: 'POST',
				data: body,
			});
			setData(response);
			if (editing.isStatic && onTokenChanged) {
				onTokenChanged();
			}
			if (onAccessChanged) {
				onAccessChanged();
			}
			setEditing(null);
			notify.success(
				__(
					'Access updated. It applies on the connection’s next request.',
					'thinkrank'
				)
			);
		} catch (error) {
			logger.error('ThinkRank: Saving access failed', error);
			// The hub refusal says what to do instead, so it is shown as the
			// server wrote it.
			setActionError(
				error?.code === 'thinkrank_mcp_hub_widen' && error?.message
					? error.message
					: __(
							'Couldn’t save that access. Please try again.',
							'thinkrank'
						)
			);
		} finally {
			claimData();
			setSaving(false);
		}
	}, [editing, onTokenChanged, onAccessChanged]);

	const revokeAll = useCallback(async () => {
		setRevokingAll(true);
		setActionError('');
		claimData();
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/mcp/revoke-all',
				method: 'POST',
			});
			setData(response.apps);
			reportHub(response.apps);
			setConfirmAll(false);
			notify.success(
				__(
					'All access revoked and the connection token replaced.',
					'thinkrank'
				)
			);
			if (onRevokedAll) {
				onRevokedAll();
			}
			if (onAccessChanged) {
				onAccessChanged();
			}
		} catch (error) {
			logger.error('ThinkRank: Revoke all failed', error);
			setActionError(
				__('Couldn’t revoke access. Please try again.', 'thinkrank')
			);
		} finally {
			claimData();
			setRevokingAll(false);
		}
	}, [onRevokedAll, onAccessChanged]);

	if (loading && !data) {
		return (
			<div
				role="status"
				aria-label={__('Loading connected apps…', 'thinkrank')}
			>
				<SkeletonText lines={3} />
			</div>
		);
	}

	const areas = Array.isArray(data?.areas) ? data.areas : [];
	const oauthApps = Array.isArray(data?.oauth_apps) ? data.oauth_apps : [];
	const staticToken = data?.static_token || null;
	const isEmpty = oauthApps.length === 0 && !staticToken;

	let staticWarning = '';
	if (staticToken?.expired) {
		staticWarning = __(
			'This token has expired, so Claude Code, Cursor and VS Code are disconnected. Reset the token under Editors & CLI to reconnect them.',
			'thinkrank'
		);
	} else if (staticToken?.expires_soon) {
		staticWarning = sprintf(
			/* translators: %s: a date. */
			__(
				'This token expires on %s. Reset it or extend it before then to keep those clients connected.',
				'thinkrank'
			),
			formatDate(staticToken.expires_at)
		);
	} else if (staticToken && !staticToken.expiry_set) {
		staticWarning = __(
			'This token was created before tokens could expire, so it never does. Set an expiry under Edit access.',
			'thinkrank'
		);
	}

	const openEditor = (app, name, isStatic) =>
		setEditing({
			client_id: app.client_id,
			name,
			isStatic,
			levels: { ...app.levels },
			// What a hub grant holds now: the most this modal may offer.
			ceiling: app.hub ? { ...app.levels } : null,
			expiry: '',
		});

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			<div className="tr-flex tr-items-start tr-justify-between tr-gap-4 tr-flex-wrap">
				<div className="tr-min-w-0">
					<h3 className="tr-m-0 tr-flex tr-items-center tr-gap-2 tr-text-sm tr-font-semibold tr-text-primary">
						<span className="tr-text-brand">
							<IconPlug />
						</span>
						{__('Connected AI apps', 'thinkrank')}
					</h3>
					<p className="tr-mt-1 tr-mb-0 tr-text-xs tr-text-secondary tr-max-w-xl">
						{__(
							'Apps connected to this site via MCP. Each one acts as the administrator who approved it, limited to the areas you allow. Revoking cuts it off immediately.',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-flex tr-items-center tr-gap-2 tr-shrink-0">
					<Button
						variant="secondary"
						size="sm"
						icon={IconRefresh}
						onClick={() => load(true)}
						disabled={loading || revoking || saving || revokingAll}
					>
						{__('Refresh', 'thinkrank')}
					</Button>
					<Button
						variant="danger"
						size="sm"
						onClick={() => setConfirmAll(true)}
						disabled={isEmpty || loading}
					>
						{__('Revoke all access', 'thinkrank')}
					</Button>
				</div>
			</div>

			{actionError && !modalOpen && (
				<Alert variant="error" onDismiss={() => setActionError('')}>
					{actionError}
				</Alert>
			)}

			{/* Inside the card, so Refresh stays reachable after a failed load. */}
			{loadError && <Alert variant="error">{loadError}</Alert>}

			{data &&
				(isEmpty ? (
					<EmptyState
						title={__('No apps connected yet', 'thinkrank')}
						description={__(
							'When an AI client connects to this site, it will appear here.',
							'thinkrank'
						)}
					/>
				) : (
					<div>
						{staticToken && (
							<AppRow
								name={__('Connection token', 'thinkrank')}
								icon={<IconKey />}
								badge={accessBadge(staticToken.levels, areas)}
								meta={`${__('Claude Code, Cursor, VS Code', 'thinkrank')} · ${expiryLine(
									staticToken
								)} · ${formatLastUsed(staticToken.last_used)}`}
								activity={staticToken.activity}
								warning={staticWarning}
								onViewActivity={() =>
									onViewActivity &&
									onViewActivity(
										'static',
										__('Connection token', 'thinkrank')
									)
								}
								busy={loading}
								onEdit={() =>
									openEditor(
										staticToken,
										__('Connection token', 'thinkrank'),
										true
									)
								}
							/>
						)}
						{oauthApps.map((app) => (
							<AppRow
								key={app.client_id}
								name={app.name}
								badge={accessBadge(app.levels, areas)}
								meta={`${app.approved_by} · ${expiryLine(
									app
								)} · ${formatLastUsed(app.last_used)}`}
								activity={app.activity}
								onViewActivity={() =>
									onViewActivity &&
									onViewActivity(app.client_id, app.name)
								}
								busy={loading}
								onEdit={() => openEditor(app, app.name, false)}
								revoking={
									revoking &&
									pending?.client_id === app.client_id
								}
								onRevoke={() =>
									setPending({
										client_id: app.client_id,
										name: app.name,
									})
								}
							/>
						))}
					</div>
				))}

			<Modal
				isOpen={!!editing}
				onClose={() => (saving ? null : setEditing(null))}
				title={sprintf(
					/* translators: %s: connection name. */
					__('Access for %s', 'thinkrank'),
					editing?.name || ''
				)}
				size="md"
				footer={
					<div className="tr-flex tr-justify-end tr-gap-2">
						<Button
							variant="secondary"
							onClick={() => setEditing(null)}
							disabled={saving}
						>
							{__('Cancel', 'thinkrank')}
						</Button>
						<Button
							variant="primary"
							onClick={saveAccess}
							loading={saving}
						>
							{__('Save access', 'thinkrank')}
						</Button>
					</div>
				}
			>
				<div className="tr-flex tr-flex-col tr-gap-4">
					{modalError}
					<p className="tr-m-0 tr-text-sm tr-text-secondary">
						{__(
							'Choose what this connection may read or change in each area. Tools outside its areas are hidden from it, and the change applies on its next request without reconnecting.',
							'thinkrank'
						)}
					</p>
					{editing?.ceiling && (
						<p className="tr-m-0 tr-text-sm tr-text-secondary">
							{__(
								'ThinkRank Hub’s access can only be narrowed here. To let the hub read or change more, disconnect it on the ThinkRank Hub card and connect it again with the access you want.',
								'thinkrank'
							)}
						</p>
					)}
					{areas.map((area) => (
						<div
							key={area.key}
							className="tr-flex tr-items-start tr-justify-between tr-gap-4"
						>
							<div className="tr-min-w-0">
								<span className="tr-block tr-text-sm tr-font-medium tr-text-primary">
									{area.label}
								</span>
								<span className="tr-block tr-mt-0.5 tr-text-xs tr-text-secondary">
									{area.description}
								</span>
							</div>
							<Select
								id={`tr-mcp-area-${area.key}`}
								label={area.label}
								hideLabelFromVision
								className="tr-w-[170px] tr-shrink-0"
								options={levelOptionsFor(
									editing?.ceiling,
									area.key
								)}
								value={editing?.levels?.[area.key] || 'none'}
								disabled={saving}
								onChange={(event) => {
									const value = event.target.value;
									setEditing((current) => ({
										...current,
										levels: {
											...current.levels,
											[area.key]: value,
										},
									}));
								}}
							/>
						</div>
					))}
					{editing?.isStatic && (
						<Select
							id="tr-mcp-token-expiry"
							label={__('Token expiry', 'thinkrank')}
							description={
								staticToken
									? sprintf(
											/* translators: %s: the token's expiry, e.g. "Expires 1/2/2027" or "Expired 1/2/2026". */
											__('Now: %s.', 'thinkrank'),
											expiryLine(staticToken)
										)
									: ''
							}
							options={[
								{
									value: '',
									label: __('Keep as it is', 'thinkrank'),
								},
								...EXPIRY_OPTIONS,
							]}
							value={editing.expiry}
							disabled={saving}
							onChange={(event) => {
								const value = event.target.value;
								setEditing((current) => ({
									...current,
									expiry: value,
								}));
							}}
						/>
					)}
				</div>
			</Modal>

			<Modal
				isOpen={!!pending}
				onClose={() => (revoking ? null : setPending(null))}
				title={__('Revoke connection?', 'thinkrank')}
				size="sm"
				footer={
					<div className="tr-flex tr-justify-end tr-gap-2">
						<Button
							variant="secondary"
							onClick={() => setPending(null)}
							disabled={revoking}
						>
							{__('Cancel', 'thinkrank')}
						</Button>
						<Button
							variant="danger"
							onClick={confirmRevoke}
							loading={revoking}
						>
							{__('Revoke', 'thinkrank')}
						</Button>
					</div>
				}
			>
				{modalError}
				<p className="tr-m-0 tr-text-sm tr-text-secondary">
					{sprintf(
						/* translators: %s: app name. */
						__(
							'“%s” will lose access immediately and must be re-approved to reconnect.',
							'thinkrank'
						),
						pending?.name || ''
					)}
				</p>
			</Modal>

			<Modal
				isOpen={confirmAll}
				onClose={() => (revokingAll ? null : setConfirmAll(false))}
				title={__('Revoke all AI access?', 'thinkrank')}
				size="sm"
				footer={
					<div className="tr-flex tr-justify-end tr-gap-2">
						<Button
							variant="secondary"
							onClick={() => setConfirmAll(false)}
							disabled={revokingAll}
						>
							{__('Cancel', 'thinkrank')}
						</Button>
						<Button
							variant="danger"
							onClick={revokeAll}
							loading={revokingAll}
						>
							{__('Revoke all access', 'thinkrank')}
						</Button>
					</div>
				}
			>
				{modalError}
				<p className="tr-m-0 tr-text-sm tr-text-secondary">
					{__(
						'Every OAuth app loses access now and has to be approved again, and the connection token is replaced, which disconnects Claude Code, Cursor and VS Code until you paste the new command. Use this if you think a credential has leaked. MCP stays on.',
						'thinkrank'
					)}
				</p>
			</Modal>
		</div>
	);
};

export default MCPConnectedApps;
