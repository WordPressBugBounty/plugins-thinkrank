/**
 * MCP Server step — connect AI assistants (Claude & ChatGPT) to ThinkRank.
 *
 * Enabling turns on the built-in MCP server and mints a connection token in one
 * click, then reveals the OAuth connector URL each client needs. The two clients
 * connect from their own side via a one-time OAuth approval, so there's nothing
 * to save on this step — it's skippable and never blocks the wizard.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { FormToggle } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import logger from '../../../shared/utils/logger';

/* ------------------------------------------------------------------ icons */

// Claude's radiating "sunburst" mark, approximated as an 11-spoke burst.
const IconClaude = () => (
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
		<path d="M12 12L20.5 12M12 12L19.15 7.4M12 12L15.53 4.26M12 12L10.79 3.58M12 12L6.44 5.57M12 12L3.85 9.59M12 12L3.85 14.41M12 12L6.44 18.43M12 12L10.79 20.42M12 12L15.53 19.74M12 12L19.15 16.6" />
	</svg>
);

// OpenAI / ChatGPT logomark (fill, not stroke).
const IconChatGPT = () => (
	<svg
		width="22"
		height="22"
		viewBox="0 0 24 24"
		fill="currentColor"
		aria-hidden="true"
	>
		<path d="M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.1419.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997z" />
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
		aria-hidden="true"
	>
		<rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
		<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
	</svg>
);

const IconCheck = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<polyline points="20 6 9 17 4 12" />
	</svg>
);

/* ---------------------------------------------------------------- helpers */

// Copy text to the clipboard. Prefers the async Clipboard API (secure contexts
// only) and falls back to a hidden-textarea execCommand copy so it still works
// over plain HTTP (e.g. http://*.test) and in older browsers.
const copyToClipboard = async (text) => {
	if (window.navigator?.clipboard?.writeText) {
		try {
			await window.navigator.clipboard.writeText(text);
			return true;
		} catch (e) {
			// Present but blocked — fall through to the execCommand path.
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

// The main admin app caches GET responses in sessionStorage (see
// utils/apiCache — keys prefixed `thinkrank_api_`). The wizard is a separate
// bundle that doesn't share that middleware, so a write here won't purge it.
// Clear the ThinkRank cache entries directly so the MCP page
// (thinkrank-ai-tools&tab=mcp) reflects the new enable/connection state
// immediately instead of serving a stale cached one for up to the TTL.
const purgeAdminApiCache = () => {
	try {
		const store = window.sessionStorage;
		if (!store) {
			return;
		}
		for (let i = store.length - 1; i >= 0; i--) {
			const key = store.key(i);
			if (key && key.startsWith('thinkrank_api_')) {
				store.removeItem(key);
			}
		}
	} catch (e) {
		// sessionStorage unavailable (private mode / disabled) — nothing to do.
	}
};

const CONNECTORS = [
	{
		id: 'claude',
		name: 'Claude',
		accent: '#d97757',
		Icon: IconClaude,
		detail: __(
			'Add ThinkRank as a custom connector on web, desktop, or mobile.',
			'thinkrank'
		),
		steps: [
			__(
				'In Claude, open Settings → Connectors → Add custom connector.',
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
		accent: '#000000',
		Icon: IconChatGPT,
		detail: __(
			'Add ThinkRank as a connector in ChatGPT settings.',
			'thinkrank'
		),
		steps: [
			__('In ChatGPT, open Settings → Connectors → Add.', 'thinkrank'),
			__(
				'Enter a name (e.g. ThinkRank) and paste the connector URL above.',
				'thinkrank'
			),
			__(
				'Save, then approve access when ChatGPT asks you to sign in.',
				'thinkrank'
			),
		],
	},
];

/* ============================================================= component */

export default function StepMcpServer() {
	const [loading, setLoading] = useState(true);
	const [enabled, setEnabled] = useState(false);
	const [status, setStatus] = useState(null);
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState('');
	const [copiedId, setCopiedId] = useState(null);

	const load = useCallback(async () => {
		try {
			const [settings, connection] = await Promise.all([
				apiFetch({ path: '/thinkrank/v1/settings' }),
				apiFetch({ path: '/thinkrank/v1/mcp/connection' }),
			]);
			setEnabled(!!settings?.enable_mcp);
			setStatus(connection);
		} catch (e) {
			logger.error('ThinkRank: Failed to load MCP status', e);
			setError(__('Couldn’t load the MCP server status.', 'thinkrank'));
		} finally {
			setLoading(false);
		}
	}, []);

	useEffect(() => {
		load();
	}, [load]);

	const connected = enabled && !!status?.connected;
	const endpoint = status?.mcp_endpoint || '';

	// Toggle MCP on/off. Turning it on also mints a connection token (if one
	// doesn't exist yet) so the OAuth connectors are ready to use. Turning it
	// off disables the server — ThinkRank then registers no tools.
	const handleToggle = useCallback(
		async (next) => {
			setBusy(true);
			setError('');
			try {
				await apiFetch({
					path: '/thinkrank/v1/settings',
					method: 'POST',
					data: { enable_mcp: next },
				});
				setEnabled(next);

				if (next && !status?.connected) {
					const response = await apiFetch({
						path: '/thinkrank/v1/mcp/connect',
						method: 'POST',
						data: { read_only: false },
					});
					setStatus(response);
				}

				// Keep the standalone MCP page (thinkrank-ai-tools&tab=mcp) in
				// sync — its cached settings/connection reads would otherwise
				// lag this change.
				purgeAdminApiCache();
			} catch (e) {
				logger.error('ThinkRank: Failed to update MCP server', e);
				setError(
					e?.message ||
						__(
							'Couldn’t update the MCP server. Please try again.',
							'thinkrank'
						)
				);
			} finally {
				setBusy(false);
			}
		},
		[status]
	);

	const copy = useCallback(async (text, id) => {
		const ok = await copyToClipboard(text);
		if (!ok) {
			setError(
				__(
					'Couldn’t copy the URL. Please select it and copy manually.',
					'thinkrank'
				)
			);
			return;
		}
		setError('');
		setCopiedId(id);
		setTimeout(() => setCopiedId((cur) => (cur === id ? null : cur)), 2000);
	}, []);

	if (loading) {
		return (
			<div className="tr-wizard__mcp-loading">
				{__('Loading the MCP server status…', 'thinkrank')}
			</div>
		);
	}

	return (
		<div className="tr-wizard__stack">
			{error && (
				<div className="tr-wizard__error" role="alert">
					{error}
				</div>
			)}

			<div className="tr-wizard__mcp-access">
				<div className="tr-wizard__mcp-access-text">
					<div className="tr-wizard__mcp-access-head">
						<span className="tr-wizard__row-title">
							{__('AI agent access', 'thinkrank')}
						</span>
						<span
							className={`tr-wizard__badge ${
								enabled ? 'is-success' : 'is-neutral'
							}`}
						>
							{enabled
								? __('Active', 'thinkrank')
								: __('Off', 'thinkrank')}
						</span>
					</div>
					<p className="tr-wizard__mcp-access-desc">
						{__(
							'Open a secure, permission-scoped bridge so Claude and ChatGPT can review and improve your SEO for you.',
							'thinkrank'
						)}
					</p>
				</div>
				<FormToggle
					checked={enabled}
					disabled={busy}
					onChange={(e) => handleToggle(e.target.checked)}
					aria-label={__('Enable MCP access', 'thinkrank')}
				/>
			</div>

			{/* Client connectors only appear once the MCP server is enabled. */}
			{connected && (
				<div className="tr-wizard__rows">
					{CONNECTORS.map((connector) => {
						const { id, name, accent, Icon, detail, steps } =
							connector;
						return (
							<div key={id} className="tr-wizard__mcp-connector">
								<div className="tr-wizard__row-head">
									<div className="tr-wizard__row-main">
										<span
											className="tr-wizard__mcp-icon"
											style={{
												color: accent,
												background: `${accent}14`,
											}}
										>
											<Icon />
										</span>
										<div className="tr-wizard__row-text">
											<span className="tr-wizard__row-title">
												{name}
											</span>
											<span className="tr-wizard__row-detail">
												{detail}
											</span>
										</div>
									</div>
									<span className="tr-wizard__badge is-success">
										{__('Ready', 'thinkrank')}
									</span>
								</div>

								<div className="tr-wizard__mcp-detail">
									<span className="tr-wizard__mcp-field-label">
										{__('Connector URL', 'thinkrank')}
									</span>
									<div className="tr-wizard__mcp-url">
										<code>{endpoint}</code>
										<button
											type="button"
											className="tr-wizard__mcp-copy"
											onClick={() => copy(endpoint, id)}
											title={__(
												'Copy connector URL',
												'thinkrank'
											)}
										>
											{copiedId === id ? (
												<>
													<IconCheck />
													{__('Copied', 'thinkrank')}
												</>
											) : (
												<>
													<IconCopy />
													{__('Copy', 'thinkrank')}
												</>
											)}
										</button>
									</div>
									<ol className="tr-wizard__mcp-steps">
										{steps.map((stepText, i) => (
											<li key={stepText}>
												<span className="tr-wizard__mcp-step-num">
													{i + 1}
												</span>
												<span>{stepText}</span>
											</li>
										))}
									</ol>
								</div>
							</div>
						);
					})}
				</div>
			)}
		</div>
	);
}
