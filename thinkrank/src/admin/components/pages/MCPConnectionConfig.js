/**
 * MCP Connection Config
 *
 * The "connect an AI client" guide. The connection token is minted
 * automatically when MCP is enabled (see #244), so there is no manual
 * "create a token" step — this component just presents the ready-to-use
 * client recipes, split across two tabs:
 *   - "OAuth" for the connector clients (Claude web/mobile/desktop, ChatGPT,
 *     Gemini, Grok, Le Chat, opencode) that just need the server URL and sign
 *     in with a one-time approval, and for any other client that does the
 *     same (#1287).
 *   - "Editors & CLI" for deep-link installs (Cursor, VS Code),
 *     copy-a-payload clients (Claude Code, Codex, Gemini CLI, Windsurf), and
 *     any other client that takes the connection token as a header. Codex and
 *     Gemini CLI sign in with OAuth, so their cards stay when there is no
 *     token to show.
 *
 * @package
 * @since 1.0.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Alert,
	Badge,
	Button,
	Modal,
	Tabs,
	TabPanel,
	SkeletonCard,
} from '../ui';
import logger from '../../../shared/utils/logger';
import notify from '../../utils/notify';

const LEARN_MORE_MCP =
	'https://modelcontextprotocol.io/docs/concepts/transports';
const LEARN_MORE_CHATGPT =
	'https://developers.openai.com/apps-sdk/deploy/connect-chatgpt';

/* ------------------------------------------------------------------ icons */

const IconExternalLink = (props) => (
	<Svg {...props}>
		<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
		<polyline points="15 3 21 3 21 9" />
		<line x1="10" y1="14" x2="21" y2="3" />
	</Svg>
);

const IconCopy = (props) => (
	<Svg {...props}>
		<rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
		<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
	</Svg>
);

const IconCheck = ({ color = 'currentColor', ...props }) => (
	<Svg stroke={color} strokeWidth="2.5" {...props}>
		<polyline points="20 6 9 17 4 12" />
	</Svg>
);

const IconChevron = (props) => (
	<Svg {...props}>
		<polyline points="9 6 15 12 9 18" />
	</Svg>
);

// Per-client glyphs (semantic line icons, not brand logos — recognizable and
// consistent without risking an inaccurate trademark).
const IconCursor = (props) => (
	<Svg {...props}>
		<path d="M4 3l16 7-6.6 2.4L11 20 4 3z" />
	</Svg>
);
const IconCode = (props) => (
	<Svg {...props}>
		<polyline points="16 18 22 12 16 6" />
		<polyline points="8 6 2 12 8 18" />
	</Svg>
);
const IconSpark = (props) => (
	<Svg {...props}>
		<path d="M12 3v18M3 12h18M6.3 6.3l11.4 11.4M17.7 6.3L6.3 17.7" />
	</Svg>
);
const IconTerminal = (props) => (
	<Svg {...props}>
		<polyline points="4 17 10 11 4 5" />
		<line x1="12" y1="19" x2="20" y2="19" />
	</Svg>
);
// Any other client: a plug, since it is the server URL it plugs into.
const IconPlug = (props) => (
	<Svg {...props}>
		<path d="M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0V8zM12 18v4" />
	</Svg>
);
// Real OpenAI / ChatGPT logomark. Uses fill (not stroke), so it can't share
// the stroke-based Svg frame; the sizing prop still passes through.
const IconChatGPT = ({ size = 16, ...props }) => (
	<svg
		width={size}
		height={size}
		viewBox="0 0 24 24"
		fill="currentColor"
		aria-hidden="true"
		{...props}
	>
		<path d="M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.1419.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997z" />
	</svg>
);
// Gemini's four-point spark, the same mark Connected Apps uses. Filled, like
// the ChatGPT mark, so it skips the stroke-based Svg frame.
const IconGemini = ({ size = 16, ...props }) => (
	<svg
		width={size}
		height={size}
		viewBox="0 0 24 24"
		fill="currentColor"
		aria-hidden="true"
		{...props}
	>
		<path d="M12 2c.34 4.9 4.8 9.36 9.7 9.7.3.02.3.58 0 .6C16.8 12.64 12.34 17.1 12 22c-.02.3-.58.3-.6 0C11.06 17.1 6.6 12.64 1.7 12.3c-.3-.02-.3-.58 0-.6C6.6 11.36 11.06 6.9 11.4 2c.02-.3.58-.3.6 0Z" />
	</svg>
);
// Grok's logomark (the slashed ring). Filled, like the ChatGPT mark.
const IconGrok = ({ size = 16, ...props }) => (
	<svg
		width={size}
		height={size}
		viewBox="0 0 24 24"
		fill="currentColor"
		fillRule="evenodd"
		aria-hidden="true"
		{...props}
	>
		<path d="M9.27 15.29l7.978-5.897c.391-.29.95-.177 1.137.272.98 2.369.542 5.215-1.41 7.169-1.951 1.954-4.667 2.382-7.149 1.406l-2.711 1.257c3.889 2.661 8.611 2.003 11.562-.953 2.341-2.344 3.066-5.539 2.388-8.42l.006.007c-.983-4.232.242-5.924 2.75-9.383.06-.082.12-.164.179-.248l-3.301 3.305v-.01L9.267 15.292M7.623 16.723c-2.792-2.67-2.31-6.801.071-9.184 1.761-1.763 4.647-2.483 7.166-1.425l2.705-1.25a7.808 7.808 0 00-1.829-1A8.975 8.975 0 005.984 5.83c-2.533 2.536-3.33 6.436-1.962 9.764 1.022 2.487-.653 4.246-2.34 6.022-.599.63-1.199 1.259-1.682 1.925l7.62-6.815" />
	</svg>
);
// Mistral's pixel "M", for Le Chat.
const IconMistral = ({ size = 16, ...props }) => (
	<svg
		width={size}
		height={size}
		viewBox="0 0 24 24"
		fill="currentColor"
		fillRule="evenodd"
		aria-hidden="true"
		{...props}
	>
		<path d="M3.428 3.4h3.429v3.428h3.429v3.429h-.002 3.431V6.828h3.427V3.4h3.43v13.714H24v3.429H13.714v-3.428h-3.428v-3.429h-3.43v3.428h3.43v3.429H0v-3.429h3.428V3.4zm10.286 13.715h3.428v-3.429h-3.427v3.429z" />
	</svg>
);
// Windsurf's wave mark.
const IconWindsurf = ({ size = 16, ...props }) => (
	<svg
		width={size}
		height={size}
		viewBox="0 0 24 24"
		fill="currentColor"
		fillRule="evenodd"
		aria-hidden="true"
		{...props}
	>
		<path d="M23.78 5.004h-.228a2.187 2.187 0 00-2.18 2.196v4.912c0 .98-.804 1.775-1.76 1.775a1.818 1.818 0 01-1.472-.773L13.168 5.95a2.197 2.197 0 00-1.81-.95c-1.134 0-2.154.972-2.154 2.173v4.94c0 .98-.797 1.775-1.76 1.775-.57 0-1.136-.289-1.472-.773L.408 5.098C.282 4.918 0 5.007 0 5.228v4.284c0 .216.066.426.188.604l5.475 7.889c.324.466.8.812 1.351.938 1.377.316 2.645-.754 2.645-2.117V11.89c0-.98.787-1.775 1.76-1.775h.002c.586 0 1.135.288 1.472.773l4.972 7.163a2.15 2.15 0 001.81.95c1.158 0 2.151-.973 2.151-2.173v-4.939c0-.98.787-1.775 1.76-1.775h.194c.122 0 .22-.1.22-.222V5.225a.221.221 0 00-.22-.222z" />
	</svg>
);
// Claude's radiating "sunburst" mark, approximated as an 11-spoke burst.
const IconClaude = (props) => (
	<Svg {...props}>
		<path d="M12 12L20.5 12M12 12L19.15 7.4M12 12L15.53 4.26M12 12L10.79 3.58M12 12L6.44 5.57M12 12L3.85 9.59M12 12L3.85 14.41M12 12L6.44 18.43M12 12L10.79 20.42M12 12L15.53 19.74M12 12L19.15 16.6" />
	</Svg>
);

// Shared SVG frame so every icon inherits the same defaults.
function Svg({ size = 16, children, style, ...props }) {
	return (
		<svg
			width={size}
			height={size}
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
			style={{ transition: 'transform 150ms', ...style }}
			{...props}
		>
			{children}
		</svg>
	);
}

/* ---------------------------------------------------------------- helpers */

// Copy text to the clipboard. Prefers the async Clipboard API (only defined in
// secure contexts — HTTPS or http://localhost) and falls back to a
// hidden-textarea execCommand copy so it still works over plain HTTP (e.g.
// http://*.local) and in older browsers. Resolves true on success, false when
// every path failed so the caller can surface the failure.
const copyToClipboard = async (text) => {
	if (window.navigator?.clipboard?.writeText) {
		try {
			await window.navigator.clipboard.writeText(text);
			return true;
		} catch (e) {
			// Secure-context API present but blocked (permissions, no focus);
			// fall through to the execCommand path below.
		}
	}
	const el = document.createElement('textarea');
	el.value = text;
	el.style.cssText = 'position:fixed;left:-999999px;top:-999999px';
	document.body.appendChild(el);
	el.focus();
	el.select();
	let ok = false;
	try {
		ok = document.execCommand('copy');
	} catch (e) {
		ok = false;
	}
	document.body.removeChild(el);
	return ok;
};

// UTF-8 safe base64 (btoa chokes on multi-byte); used for the Cursor deep link.
const toBase64 = (str) => {
	try {
		return window.btoa(
			encodeURIComponent(str).replace(/%([0-9A-F]{2})/g, (_, p1) =>
				String.fromCharCode(parseInt(p1, 16))
			)
		);
	} catch (e) {
		return '';
	}
};

/* ============================================================= component */

/**
 * MCP connection guide and token controls.
 *
 * @param {Object}   props              Component props.
 * @param {Function} props.onTokenReset Called after the connection token was
 *                                      reset, so the cards that show it (the
 *                                      Connected apps row, connection health)
 *                                      can load it again.
 */
const MCPConnectionConfig = ({ onTokenReset }) => {
	// Live connection status from /thinkrank/v1/mcp/connection. The token is
	// minted automatically (server-side) when MCP is enabled, so this usually
	// loads with `connected` true and the recipes populated. After Disconnect,
	// or Revoke all on a site that had no token, it stays false until the
	// owner creates one here (#1075).
	const [status, setStatus] = useState(null);
	const [isLoading, setIsLoading] = useState(true);
	const [error, setError] = useState('');
	// A single id tracks the most recently "copied" control so the check mark
	// shows on exactly one tile/button at a time.
	const [copiedId, setCopiedId] = useState(null);
	// Which client group is showing — OAuth clients vs. the rest.
	const [activeTab, setActiveTab] = useState('auth');
	// Reset-token confirm modal + in-flight state.
	const [showReset, setShowReset] = useState(false);
	const [resetting, setResetting] = useState(false);
	// A failed reset is reported inside its dialog: the dialog stays open on
	// failure, and the user's attention is on it rather than on the page.
	const [resetError, setResetError] = useState('');

	const closeReset = () => {
		setShowReset(false);
		setResetError('');
	};
	// Creating a connection token after the owner had none.
	const [creating, setCreating] = useState(false);

	const loadStatus = useCallback(async () => {
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/mcp/connection',
			});
			setStatus(response);
		} catch (err) {
			logger.error('ThinkRank: Failed to load MCP connection', err);
			setError(
				__('Couldn’t load the MCP connection status.', 'thinkrank')
			);
		} finally {
			setIsLoading(false);
		}
	}, []);

	useEffect(() => {
		loadStatus();
	}, [loadStatus]);

	const copyWith = async (text, id) => {
		try {
			const ok = await copyToClipboard(text);
			if (!ok) {
				throw new Error('Copy command was rejected by the browser.');
			}
			setError('');
			setCopiedId(id);
			setTimeout(
				() => setCopiedId((cur) => (cur === id ? null : cur)),
				2000
			);
		} catch (err) {
			logger.error('ThinkRank: Copy to clipboard failed', err);
			setError(
				__(
					'Couldn’t copy to the clipboard. Please select the text and copy it manually.',
					'thinkrank'
				)
			);
		}
	};

	// Rotate the shared connection token. This is the leaked-secret escape
	// hatch: it cuts off every header-based client (Claude Code, Cursor, VS
	// Code, Windsurf, Claude Desktop) at once without disturbing OAuth-connected
	// apps.
	const resetToken = async () => {
		setResetting(true);
		setError('');
		setResetError('');
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/mcp/rotate',
				method: 'POST',
			});
			setStatus(response);
			closeReset();
			notify.success(__('Connection token reset.', 'thinkrank'));
			// The Connected apps row and the health check still describe
			// the token that was just replaced.
			onTokenReset?.();
		} catch (err) {
			logger.error('ThinkRank: MCP token reset failed', err);
			setResetError(
				__(
					'Couldn’t reset the connection token. Please try again.',
					'thinkrank'
				)
			);
		} finally {
			setResetting(false);
		}
	};

	// Mint a connection token on request. The screen no longer gets one just
	// by loading once the owner has ended theirs, so this is how they opt
	// back in for header-based clients.
	const createToken = async () => {
		setCreating(true);
		setError('');
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/mcp/connect',
				method: 'POST',
			});
			setStatus(response);
			notify.success(__('Connection token created.', 'thinkrank'));
		} catch (err) {
			logger.error('ThinkRank: MCP token create failed', err);
			notify.error(
				__('Couldn’t create a connection token.', 'thinkrank')
			);
		} finally {
			setCreating(false);
		}
	};

	if (isLoading) {
		// A blank flash reads as broken; show the shape of what's coming.
		return <SkeletonCard lines={4} label={__('Loading…', 'thinkrank')} />;
	}

	const connected = !!status?.connected;
	const token = status?.connection_token || '';
	// A token exists and still authenticates the clients already holding it,
	// but this site can't decrypt it any more — the wp-config security keys
	// changed after it was minted. Every header-based recipe below would be
	// blank, so say so instead of rendering empty commands.
	const tokenSealed = !!status?.token_sealed;
	const endpoint = status?.mcp_endpoint || '';
	const claudeCodeCommand = status?.config?.cli || '';

	// HTTP server entry shared by the deep-link clients.
	const serverEntry = {
		url: endpoint,
		headers: { Authorization: `Bearer ${token}` },
	};
	const cursorDeepLink = `cursor://anysphere.cursor-deeplink/mcp/install?name=thinkrank&config=${encodeURIComponent(
		toBase64(JSON.stringify(serverEntry))
	)}`;
	const vscodeDeepLink = `vscode:mcp/install?${encodeURIComponent(
		JSON.stringify({ name: 'thinkrank', type: 'http', ...serverEntry })
	)}`;

	// OAuth connectors (Auth tab). Each just needs the connector URL and signs
	// in through a one-time approval; the numbered steps spell out the client's
	// own setup path.
	const authConnectors = [
		{
			id: 'claude',
			name: __('Claude (web, mobile & desktop)', 'thinkrank'),
			Icon: IconClaude,
			accent: '#d97757',
			steps: [
				__(
					'In Claude, open Settings → Connectors → Add Connector → Add custom connector.',
					'thinkrank'
				),
				__(
					'Paste the connector URL above and give it a name (e.g. ThinkRank).',
					'thinkrank'
				),
				__(
					'Click Add, then approve access when Claude asks you to sign in.',
					'thinkrank'
				),
			],
		},
		{
			id: 'chatgpt',
			name: 'ChatGPT',
			Icon: IconChatGPT,
			accent: '#000000',
			learnMore: LEARN_MORE_CHATGPT,
			steps: [
				__(
					'In ChatGPT, open Settings → Plugins → Add New.',
					'thinkrank'
				),
				__('Enter a name (e.g. ThinkRank).', 'thinkrank'),
				__('Paste the Connection URL above.', 'thinkrank'),
				__(
					'Save, then approve access when ChatGPT asks you to sign in.',
					'thinkrank'
				),
			],
		},
		{
			// Gemini lists custom connectors under Connected Apps, but only on
			// personal Google accounts: a Workspace account shows Google's own
			// connectors and no way to add one (#1438).
			id: 'gemini',
			name: 'Gemini',
			Icon: IconGemini,
			accent: '#1a73e8',
			steps: [
				__(
					'In Gemini on the web, open Customize → Connectors and add a custom app.',
					'thinkrank'
				),
				__(
					'Paste the connector URL above and give it a name (e.g. ThinkRank).',
					'thinkrank'
				),
				__(
					'Approve access when Gemini asks you to sign in.',
					'thinkrank'
				),
			],
			note: __(
				'Custom apps are available on personal Google accounts only. A work or school account has no option to add one, so use Gemini CLI under Editors & CLI instead.',
				'thinkrank'
			),
		},
		{
			// Grok adds a remote server as a custom connector and signs in
			// with OAuth (docs.x.ai/grok/connectors).
			id: 'grok',
			name: 'Grok',
			Icon: IconGrok,
			accent: '#111111',
			steps: [
				__(
					'In Grok, open Connectors (grok.com/connectors) → New Connector → Custom.',
					'thinkrank'
				),
				__('Paste the connector URL above.', 'thinkrank'),
				__(
					'Approve access when Grok asks you to sign in.',
					'thinkrank'
				),
			],
		},
		{
			// Le Chat adds a remote server as a custom MCP connector and finds
			// OAuth by itself (docs.mistral.ai, MCP Connectors).
			id: 'le-chat',
			name: 'Le Chat (Mistral)',
			Icon: IconMistral,
			accent: '#fa520f',
			steps: [
				__(
					'In Le Chat, open Intelligence → Connectors → Add Connector → Custom MCP Connector.',
					'thinkrank'
				),
				__(
					'Name it ThinkRank and paste the connector URL above.',
					'thinkrank'
				),
				__(
					'Click Connect, then approve access when Le Chat asks you to sign in.',
					'thinkrank'
				),
			],
		},
		{
			id: 'other',
			name: __('Other MCP client', 'thinkrank'),
			Icon: IconPlug,
			accent: '#475467',
			steps: [
				__(
					'In your client, add a remote MCP server over HTTP with the connector URL above.',
					'thinkrank'
				),
				__(
					'If it asks how to sign in, choose OAuth. It opens this site for you to approve access.',
					'thinkrank'
				),
				__(
					'Approve access, and the client connects. A client that only takes a fixed header can use the connection token under Editors & CLI.',
					'thinkrank'
				),
			],
		},
		{
			// opencode reads remote servers from its config file and signs in
			// with OAuth by itself (opencode.ai/docs/mcp-servers).
			id: 'opencode',
			name: 'opencode',
			Icon: IconTerminal,
			accent: '#0f172a',
			snippetLabel: 'opencode.json',
			snippet: JSON.stringify(
				{
					mcp: {
						thinkrank: {
							type: 'remote',
							url: endpoint,
							enabled: true,
						},
					},
				},
				null,
				2
			),
			steps: [
				__(
					'Add the entry above to opencode.json in your project, or to ~/.config/opencode/opencode.json, keeping your other servers.',
					'thinkrank'
				),
				__(
					'Start opencode. It asks you to sign in, or run opencode mcp auth thinkrank.',
					'thinkrank'
				),
				__('Approve access when this site asks.', 'thinkrank'),
			],
		},
	];

	// Editors & CLI clients — same card design as the OAuth connectors, but
	// each installs via a deep link ('link') or a payload to copy ('copy').
	const otherClients = [
		{
			id: 'claude-code',
			name: 'Claude Code',
			Icon: IconSpark,
			accent: '#d97757',
			badge: __('CLI', 'thinkrank'),
			action: 'copy',
			fieldLabel: __('Command', 'thinkrank'),
			payload: claudeCodeCommand,
			steps: [
				__('Copy the command above.', 'thinkrank'),
				__(
					'Run it in your terminal from your project directory.',
					'thinkrank'
				),
			],
		},
		{
			// Codex finds OAuth by itself when the server is added, and the
			// CLI, IDE extension and desktop app share its config, so one
			// command covers all three (`oauth`: no token in it).
			id: 'codex',
			name: 'Codex',
			Icon: IconChatGPT,
			accent: '#000000',
			badge: __('CLI', 'thinkrank'),
			oauth: true,
			action: 'copy',
			fieldLabel: __('Command', 'thinkrank'),
			payload: `codex mcp add thinkrank --url ${endpoint}`,
			steps: [
				__(
					'Copy the command above and run it in your terminal.',
					'thinkrank'
				),
				__(
					'Approve access when this site opens. If it does not, run codex mcp login thinkrank.',
					'thinkrank'
				),
			],
		},
		{
			// Gemini CLI signs in with OAuth by itself, so its command carries
			// no token and the card stays when there is none (`oauth`).
			id: 'gemini-cli',
			name: 'Gemini CLI',
			Icon: IconGemini,
			accent: '#1a73e8',
			badge: __('CLI', 'thinkrank'),
			oauth: true,
			action: 'copy',
			fieldLabel: __('Command', 'thinkrank'),
			payload: `gemini mcp add --scope user --transport http thinkrank ${endpoint}`,
			steps: [
				__(
					'Copy the command above and run it in your terminal.',
					'thinkrank'
				),
				__('Start gemini and run /mcp auth thinkrank.', 'thinkrank'),
				__('Approve access when this site asks.', 'thinkrank'),
			],
		},
		{
			id: 'cursor',
			name: 'Cursor',
			Icon: IconCursor,
			accent: '#0f172a',
			badge: __('Editor', 'thinkrank'),
			action: 'link',
			href: cursorDeepLink,
			cta: __('Add to Cursor', 'thinkrank'),
			steps: [
				__(
					'Click "Add to Cursor". It opens Cursor with ThinkRank prefilled.',
					'thinkrank'
				),
				__('Confirm the install when Cursor asks.', 'thinkrank'),
			],
		},
		{
			id: 'vscode',
			name: 'VS Code',
			Icon: IconCode,
			accent: '#0098ff',
			badge: __('Editor', 'thinkrank'),
			action: 'link',
			href: vscodeDeepLink,
			cta: __('Add to VS Code', 'thinkrank'),
			steps: [
				__(
					'Click "Add to VS Code". It opens VS Code with ThinkRank prefilled.',
					'thinkrank'
				),
				__('Confirm the install when VS Code asks.', 'thinkrank'),
			],
		},
		{
			// Windsurf reads remote servers from mcp_config.json, keyed
			// serverUrl rather than url; the token header connects without
			// relying on its OAuth support.
			id: 'windsurf',
			name: 'Windsurf',
			Icon: IconWindsurf,
			accent: '#0b100f',
			badge: __('Editor', 'thinkrank'),
			action: 'copy',
			fieldLabel: 'mcp_config.json',
			payload: JSON.stringify(
				{
					mcpServers: {
						thinkrank: {
							serverUrl: endpoint,
							headers: { Authorization: `Bearer ${token}` },
						},
					},
				},
				null,
				2
			),
			steps: [
				__(
					'In Windsurf, open Cascade → MCP servers → View raw config.',
					'thinkrank'
				),
				__(
					'Add the thinkrank entry above under mcpServers, keeping your other servers.',
					'thinkrank'
				),
				__('Save, then refresh the MCP servers list.', 'thinkrank'),
			],
		},
		{
			// Any client that takes a fixed header rather than OAuth (#1287).
			id: 'other-token',
			name: __('Other MCP client', 'thinkrank'),
			Icon: IconPlug,
			accent: '#475467',
			badge: __('Token', 'thinkrank'),
			action: 'copy',
			fieldLabel: __('Header', 'thinkrank'),
			payload: `Authorization: Bearer ${token}`,
			steps: [
				sprintf(
					/* translators: %s: this site's MCP server URL. */
					__(
						'In your client, add a remote MCP server over HTTP with the URL %s.',
						'thinkrank'
					),
					endpoint
				),
				__('Add the header above to it, then connect.', 'thinkrank'),
			],
		},
	];

	// Header-based cards need a token this site can show; a client that signs
	// in with OAuth works without one (#1438).
	const shownClients =
		connected && !tokenSealed
			? otherClients
			: otherClients.filter((client) => client.oauth);

	const TABS = [
		{ id: 'auth', label: __('OAuth', 'thinkrank') },
		{ id: 'others', label: __('Editors & CLI', 'thinkrank') },
	];

	return (
		<div className="tr-flex tr-flex-col tr-gap-6">
			<div className="tr-min-w-0">
				<h3 className="tr-m-0 tr-text-sm tr-font-semibold tr-text-primary">
					{__('Add ThinkRank to your client', 'thinkrank')}
				</h3>
				<p className="tr-mt-1 tr-mb-0 tr-text-sm tr-text-secondary">
					{__(
						'Pick your assistant below it opens or copies fully configured.',
						'thinkrank'
					)}{' '}
					<a
						href={LEARN_MORE_MCP}
						target="_blank"
						rel="noopener noreferrer"
						className="tr-text-brand hover:tr-underline tr-inline-flex tr-items-center tr-gap-1"
					>
						{__('How it works', 'thinkrank')}
						<IconExternalLink size={13} />
					</a>
				</p>
			</div>

			{error && (
				<Alert variant="error" onDismiss={() => setError('')}>
					{error}
				</Alert>
			)}

			<Tabs
				tabs={TABS}
				activeTab={activeTab}
				onTabChange={setActiveTab}
				className="tr-mb-4"
			/>

			<TabPanel tabId="auth" activeTab={activeTab}>
				<div className="tr-grid tr-grid-cols-1 lg:tr-grid-cols-2 tr-gap-3">
					{authConnectors.map((connector) => (
						<AuthConnectorCard
							key={connector.id}
							connector={connector}
							endpoint={endpoint}
							copied={copiedId === connector.id}
							onCopy={() => copyWith(endpoint, connector.id)}
							snippetCopied={
								copiedId === `${connector.id}-snippet`
							}
							onCopySnippet={() =>
								copyWith(
									connector.snippet,
									`${connector.id}-snippet`
								)
							}
						/>
					))}
				</div>
			</TabPanel>

			<TabPanel tabId="others" activeTab={activeTab}>
				{!connected && (
					<div className="tr-flex tr-flex-wrap tr-items-center tr-justify-between tr-gap-3">
						<p className="tr-m-0 tr-text-sm tr-text-secondary tr-max-w-lg">
							{__(
								'There is no connection token. Claude Code, Cursor, VS Code, Windsurf, and Claude Desktop need one to connect. OAuth apps, Codex and Gemini CLI don’t.',
								'thinkrank'
							)}
						</p>
						<Button
							variant="primary"
							size="sm"
							onClick={createToken}
							loading={creating}
						>
							{__('Create connection token', 'thinkrank')}
						</Button>
					</div>
				)}

				{connected && tokenSealed && (
					<Alert variant="warning" className="tr-mb-3">
						{__(
							'This site can no longer display the connection token: the security keys in wp-config.php changed after it was minted. Clients you’ve already set up keep working. Reset the token below to get one you can copy again; that disconnects those clients until you paste the new command.',
							'thinkrank'
						)}
					</Alert>
				)}

				{shownClients.length > 0 && (
					<div className="tr-grid tr-grid-cols-1 lg:tr-grid-cols-2 tr-gap-3">
						{shownClients.map((client) => (
							<ClientCard
								key={client.id}
								client={client}
								copied={copiedId === client.id}
								onCopy={() =>
									copyWith(client.payload, client.id)
								}
							/>
						))}
					</div>
				)}

				{/* Reset lives here: it only affects these header-based
						    (static-token) clients — OAuth apps are unaffected. */}
				{connected && (
					<div className="tr-flex tr-flex-wrap tr-items-center tr-justify-between tr-gap-3 tr-mt-4 tr-pt-4 tr-border-t tr-border-secondary">
						<p className="tr-m-0 tr-text-xs tr-text-tertiary tr-max-w-lg">
							{__(
								'Reset the connection token if it may have leaked. This disconnects Claude Code, Cursor, VS Code, Windsurf, and Claude Desktop. They’ll need the new token to reconnect. OAuth-connected apps, Codex and Gemini CLI are unaffected.',
								'thinkrank'
							)}
						</p>
						<Button
							variant="secondary"
							size="sm"
							onClick={() => setShowReset(true)}
						>
							{__('Reset token', 'thinkrank')}
						</Button>
					</div>
				)}
			</TabPanel>

			<Modal
				isOpen={showReset}
				onClose={() => (resetting ? null : closeReset())}
				title={__('Reset connection token?', 'thinkrank')}
				size="sm"
				footer={
					<div className="tr-flex tr-justify-end tr-gap-2">
						<Button
							variant="secondary"
							onClick={closeReset}
							disabled={resetting}
						>
							{__('Cancel', 'thinkrank')}
						</Button>
						<Button
							variant="danger"
							onClick={resetToken}
							loading={resetting}
						>
							{__('Reset token', 'thinkrank')}
						</Button>
					</div>
				}
			>
				{resetError && (
					<Alert variant="error" className="tr-mb-3">
						{resetError}
					</Alert>
				)}
				<p className="tr-m-0 tr-text-sm tr-text-secondary">
					{__(
						'A new token is generated immediately. Every header-based client (Claude Code, Cursor, VS Code, Windsurf, Claude Desktop) is disconnected and must reconnect with the new token. OAuth-connected apps like Claude on the web, Codex and Gemini CLI keep working.',
						'thinkrank'
					)}
				</p>
			</Modal>
		</div>
	);
};

/* --------------------------------------------------------------- pieces */

// An OAuth connector card (Auth tab): client header, the connector URL to
// copy, and a numbered setup walkthrough for that client.
const AuthConnectorCard = ({
	connector,
	endpoint,
	copied,
	onCopy,
	snippetCopied,
	onCopySnippet,
}) => {
	const {
		name,
		Icon,
		accent,
		steps,
		learnMore,
		snippet,
		snippetLabel,
		note,
	} = connector;
	const [snippetOpen, setSnippetOpen] = useState(false);

	return (
		<div className="tr-rounded-lg tr-border tr-border-secondary tr-bg-white tr-p-4 tr-flex tr-flex-col tr-gap-4">
			{/* Client header */}
			<div className="tr-flex tr-items-center tr-gap-3">
				<span
					className="tr-flex tr-items-center tr-justify-center tr-w-9 tr-h-9 tr-rounded-md tr-shrink-0"
					style={{ color: accent, backgroundColor: `${accent}14` }}
				>
					<Icon size={18} />
				</span>
				<div className="tr-min-w-0 tr-flex-1">
					<div className="tr-flex tr-items-center tr-gap-2 tr-flex-wrap">
						<span className="tr-text-sm tr-font-semibold tr-text-primary">
							{name}
						</span>
						<Badge variant="info">{__('OAuth', 'thinkrank')}</Badge>
					</div>
					<span className="tr-block tr-text-xs tr-text-tertiary">
						{__(
							'Signs in with a one-time approval. No token to paste.',
							'thinkrank'
						)}
					</span>
				</div>
			</div>

			{/* Connector URL to copy */}
			<div className="tr-flex tr-flex-col tr-gap-1.5">
				<span className="tr-text-xs tr-font-medium tr-text-secondary">
					{__('Connector URL', 'thinkrank')}
				</span>
				<div className="tr-flex tr-items-center tr-gap-2 tr-rounded-lg tr-border-[1.5px] tr-border-secondary tr-bg-transparent tr-pl-3 tr-pr-1.5 tr-py-1.5 focus-within:tr-border-brand tr-transition-colors">
					<code className="tr-flex-1 tr-min-w-0 tr-text-xs tr-font-mono tr-text-secondary tr-truncate tr-bg-transparent tr-p-0">
						{endpoint}
					</code>
					<button
						type="button"
						onClick={onCopy}
						className="tr-flex tr-items-center tr-gap-1.5 tr-h-7 tr-px-2.5 tr-rounded-md tr-text-brand hover:tr-bg-brand-bg tr-bg-transparent tr-border-0 tr-cursor-pointer tr-shrink-0 tr-text-xs tr-font-medium tr-transition-colors"
						title={__('Copy connector URL', 'thinkrank')}
					>
						{copied ? (
							<>
								<IconCheck size={14} color="#027A48" />
								{__('Copied!', 'thinkrank')}
							</>
						) : (
							<>
								<IconCopy size={14} />
								{__('Copy', 'thinkrank')}
							</>
						)}
					</button>
				</div>
			</div>

			{/* A client that reads its servers from a file: the entry to copy.
			    Collapsed by default, so the steps stay in view; Copy works
			    either way. */}
			{snippet && (
				<div className="tr-flex tr-flex-col tr-gap-1.5">
					<div className="tr-flex tr-items-center tr-justify-between tr-gap-2">
						<button
							type="button"
							onClick={() => setSnippetOpen((open) => !open)}
							aria-expanded={snippetOpen}
							className="tr-inline-flex tr-items-center tr-gap-1 tr-p-0 tr-bg-transparent tr-border-0 tr-cursor-pointer tr-text-xs tr-font-medium tr-text-secondary hover:tr-text-brand"
						>
							<IconChevron
								size={12}
								style={{
									transform: snippetOpen
										? 'rotate(90deg)'
										: 'none',
								}}
							/>
							{snippetLabel}
						</button>
						<button
							type="button"
							onClick={onCopySnippet}
							className="tr-flex tr-items-center tr-gap-1.5 tr-h-7 tr-px-2.5 tr-rounded-md tr-text-brand hover:tr-bg-brand-bg tr-bg-transparent tr-border-0 tr-cursor-pointer tr-shrink-0 tr-text-xs tr-font-medium tr-transition-colors"
							title={__('Copy', 'thinkrank')}
						>
							{snippetCopied ? (
								<>
									<IconCheck size={14} color="#027A48" />
									{__('Copied!', 'thinkrank')}
								</>
							) : (
								<>
									<IconCopy size={14} />
									{__('Copy', 'thinkrank')}
								</>
							)}
						</button>
					</div>
					{snippetOpen && (
						<div className="tr-rounded-lg tr-border-[1.5px] tr-border-secondary tr-bg-transparent tr-px-3 tr-py-1.5">
							<pre className="tr-m-0 tr-text-xs tr-font-mono tr-text-secondary tr-whitespace-pre tr-overflow-x-auto tr-bg-transparent tr-p-0">
								{snippet}
							</pre>
						</div>
					)}
				</div>
			)}

			{/* Step-by-step */}
			<ol className="tr-flex tr-flex-col tr-gap-2.5 tr-m-0 tr-p-0 tr-list-none">
				{steps.map((step, i) => (
					<li
						key={step}
						className="tr-flex tr-items-start tr-gap-2.5 tr-text-sm tr-text-secondary"
					>
						<span className="tr-flex tr-items-center tr-justify-center tr-w-5 tr-h-5 tr-rounded-full tr-bg-brand-bg tr-text-brand tr-text-xs tr-font-semibold tr-shrink-0">
							{i + 1}
						</span>
						<span className="tr-flex-1 tr-min-w-0">{step}</span>
					</li>
				))}
			</ol>

			{/* A limit of the client itself, such as which accounts get it. */}
			{note && (
				<p className="tr-m-0 tr-text-xs tr-text-tertiary">{note}</p>
			)}

			{learnMore && (
				<a
					href={learnMore}
					target="_blank"
					rel="noopener noreferrer"
					className="tr-text-xs tr-text-tertiary hover:tr-text-brand tr-inline-flex tr-items-center tr-gap-1 tr-self-start"
				>
					{__('How to add a connector', 'thinkrank')}
					<IconExternalLink size={11} />
				</a>
			)}
		</div>
	);
};

// An editor / CLI client card — same design as AuthConnectorCard: header, a
// primary action (a payload to copy or a deep-link button), then numbered
// setup steps.
const ClientCard = ({ client, copied, onCopy }) => {
	const {
		name,
		Icon,
		accent,
		badge,
		action,
		fieldLabel,
		payload,
		href,
		cta,
		steps,
	} = client;

	return (
		<div className="tr-rounded-lg tr-border tr-border-secondary tr-bg-white tr-p-4 tr-flex tr-flex-col tr-gap-4">
			{/* Client header */}
			<div className="tr-flex tr-items-center tr-gap-3">
				<span
					className="tr-flex tr-items-center tr-justify-center tr-w-9 tr-h-9 tr-rounded-md tr-shrink-0"
					style={{ color: accent, backgroundColor: `${accent}14` }}
				>
					<Icon size={18} />
				</span>
				<div className="tr-min-w-0 tr-flex-1">
					<div className="tr-flex tr-items-center tr-gap-2 tr-flex-wrap">
						<span className="tr-text-sm tr-font-semibold tr-text-primary">
							{name}
						</span>
						{badge && <Badge variant="neutral">{badge}</Badge>}
					</div>
				</div>
			</div>

			{/* Primary action: copy a payload or open a deep link. */}
			{action === 'copy' ? (
				<div className="tr-flex tr-flex-col tr-gap-1.5">
					<span className="tr-text-xs tr-font-medium tr-text-secondary">
						{fieldLabel}
					</span>
					<div className="tr-flex tr-items-center tr-gap-2 tr-rounded-lg tr-border-[1.5px] tr-border-secondary tr-bg-transparent tr-pl-3 tr-pr-1.5 tr-py-1.5 focus-within:tr-border-brand tr-transition-colors">
						<code className="tr-flex-1 tr-min-w-0 tr-text-xs tr-font-mono tr-text-secondary tr-truncate tr-bg-transparent tr-p-0">
							{payload}
						</code>
						<button
							type="button"
							onClick={onCopy}
							className="tr-flex tr-items-center tr-gap-1.5 tr-h-7 tr-px-2.5 tr-rounded-md tr-text-brand hover:tr-bg-brand-bg tr-bg-transparent tr-border-0 tr-cursor-pointer tr-shrink-0 tr-text-xs tr-font-medium tr-transition-colors"
							title={__('Copy', 'thinkrank')}
						>
							{copied ? (
								<>
									<IconCheck size={14} color="#027A48" />
									{__('Copied!', 'thinkrank')}
								</>
							) : (
								<>
									<IconCopy size={14} />
									{__('Copy', 'thinkrank')}
								</>
							)}
						</button>
					</div>
				</div>
			) : (
				<a
					href={href}
					className="tr-inline-flex tr-items-center tr-gap-2 tr-self-start tr-h-9 tr-px-4 tr-rounded-md tr-border tr-border-brand tr-text-brand hover:tr-bg-brand-bg tr-text-sm tr-font-medium tr-no-underline tr-transition-colors"
				>
					<IconExternalLink size={14} />
					{cta}
				</a>
			)}

			{/* Step-by-step */}
			<ol className="tr-flex tr-flex-col tr-gap-2.5 tr-m-0 tr-p-0 tr-list-none">
				{steps.map((step, i) => (
					<li
						key={step}
						className="tr-flex tr-items-start tr-gap-2.5 tr-text-sm tr-text-secondary"
					>
						<span className="tr-flex tr-items-center tr-justify-center tr-w-5 tr-h-5 tr-rounded-full tr-bg-brand-bg tr-text-brand tr-text-xs tr-font-semibold tr-shrink-0">
							{i + 1}
						</span>
						<span className="tr-flex-1 tr-min-w-0">{step}</span>
					</li>
				))}
			</ol>
		</div>
	);
};

export default MCPConnectionConfig;
