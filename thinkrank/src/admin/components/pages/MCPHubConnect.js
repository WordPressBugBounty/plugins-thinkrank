/**
 * Connect to ThinkRank Hub
 *
 * One card, laid out like the AI agent access card: what connecting does and
 * the button that starts it in the header. How far the hub reaches is chosen
 * per area on the consent page, not here. Connecting is an ordinary grant through this site's
 * own consent page, so it is listed with the other connected apps and ends
 * the same way; what this card adds is the start (the MCP switch turned on if
 * it is off, and a one-time ticket that proves the person approving started
 * here), the connected state with the grants and
 * when the hub last read the site, and Disconnect. The hub's address is
 * not offered here: owners never need it, and a host that does pins it with
 * THINKRANK_HUB_URL in wp-config.php.
 *
 * The setup wizard shows the same card with `from="wizard"`, so the hub
 * sends the owner back to the wizard rather than to AI Tools.
 *
 * Nothing on this screen or anywhere else in the plugin waits on the hub:
 * everything in ThinkRank works without it (thinkrank#1002).
 *
 * @package
 * @since 2.15.0
 */

import { __ } from '@wordpress/i18n';
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Alert, Badge, Button, SkeletonText, Toggle } from '../ui';
import logger from '../../../shared/utils/logger';
import HubIcon from '../common/HubIcon';
import { setHubConnected } from '../../utils/hubStatus';

const HUB = '/thinkrank/v1/hub';

// Connected the way the card means it: a grant the state still lists.
const isConnected = (state) =>
	Boolean(state?.connected && (state.apps || []).length);

// Same marks as the AI agent access card, so the two read as one family.
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

// What a connected hub does with this site, for the collapsible list.
const HUB_CAPABILITIES = [
	__('This site’s SEO score and checks beside your other sites', 'thinkrank'),
	__('SEO reports and settings, read on your behalf', 'thinkrank'),
	__('Fixes you ask for, each one undoable in ThinkRank', 'thinkrank'),
	__('Every connected site from one AI connection', 'thinkrank'),
	__('No content you did not ask for, and no permalink changes', 'thinkrank'),
	__(
		'Disconnect any time: everything in ThinkRank works without it',
		'thinkrank'
	),
];

// One labelled fact in the connected state's details grid.
const Detail = ({ label, children }) => (
	<div className="tr-min-w-0">
		<dt className="tr-text-xs tr-font-medium tr-uppercase tr-tracking-wide tr-text-tertiary">
			{label}
		</dt>
		<dd className="tr-m-0 tr-mt-1 tr-text-sm tr-text-primary tr-break-words">
			{children}
		</dd>
	</div>
);

// A moment in the reader's locale, from the ISO stamp the state carries.
const formatWhen = (iso) => {
	const date = new Date(iso);
	return Number.isNaN(date.getTime()) ? iso : date.toLocaleString();
};

// The one line each refusal gets, alone or among others.
const REFUSALS = {
	staging: {
		alone: __(
			'This site is connected to ThinkRank Hub, but this copy is marked as a staging environment, so the hub’s access is paused here. The connection is kept, and the hub is let in again on the live site or once the mark is removed.',
			'thinkrank'
		),
		among: __('This copy is marked as a staging environment.', 'thinkrank'),
	},
	connection: {
		alone: __(
			'This site is connected to ThinkRank Hub, but MCP access is off, so the hub’s access is paused. Turn it on in the MCP tab to let the hub in again.',
			'thinkrank'
		),
		among: __('MCP access is off.', 'thinkrank'),
	},
};

const MCPHubConnect = ({ onNavigate, onDisconnected, from = '' }) => {
	const [hub, setHub] = useState(null);
	const [loadFailed, setLoadFailed] = useState(false);
	const [attempt, setAttempt] = useState(0);
	const [armed, setArmed] = useState(false);
	const [ended, setEnded] = useState(false);
	// Which request is in flight: '' | 'connect' | 'settings' | 'disconnect'.
	const [busy, setBusy] = useState('');
	const [error, setError] = useState('');
	// The capability list is collapsed by default, as on the MCP card.
	const [showCapabilities, setShowCapabilities] = useState(false);
	// Only the newest load may set the state, and an action invalidates
	// every load that overlaps it: bumped when an action starts and settles.
	const version = useRef(0);

	const navigate = onNavigate || ((url) => window.location.assign(url));

	const load = useCallback(async () => {
		const mine = ++version.current;
		setLoadFailed(false);
		try {
			const data = await apiFetch({ path: HUB });
			if (mine !== version.current) {
				return;
			}
			setHub(data);
			setHubConnected(isConnected(data));
		} catch (loadError) {
			if (mine !== version.current) {
				return;
			}
			logger.error(
				'ThinkRank: Failed to load the hub connection',
				loadError
			);
			setLoadFailed(true);
		}
	}, []);

	useEffect(() => {
		load();
	}, [load, attempt]);

	// One action at a time; each bumps the version on both sides so a load
	// that overlaps it cannot put the state it replaced back.
	const run = async (name, request, onDone) => {
		version.current += 1;
		setBusy(name);
		setError('');
		let keepBusy = false;
		try {
			const result = await request();
			keepBusy = Boolean(onDone && onDone(result));
		} catch (actionError) {
			setError(
				actionError?.message ||
					__('That did not go through. Try again.', 'thinkrank')
			);
		} finally {
			version.current += 1;
			if (!keepBusy) {
				setBusy('');
			}
		}
	};

	const connect = () =>
		run(
			'connect',
			() =>
				apiFetch({
					path: `${HUB}/connect`,
					method: 'POST',
					// The widest ticket: the consent page preselects read and
					// change and the owner narrows it there, per area. A read
					// ticket would take write off that page altogether.
					// `from` names the screen the hub sends the owner back to.
					data: from
						? { access: 'write', from }
						: { access: 'write' },
				}),
			(result) => {
				if (result?.redirect) {
					// The browser leaves for the hub; the button stays busy
					// rather than inviting a second press on the way out.
					navigate(result.redirect);
					return true;
				}
				setError(
					__(
						'The hub’s address did not come back, so nothing was started.',
						'thinkrank'
					)
				);
				return false;
			}
		);

	const disconnect = () =>
		run(
			'disconnect',
			() => apiFetch({ path: `${HUB}/disconnect`, method: 'POST' }),
			(result) => {
				setHub(result);
				setHubConnected(isConnected(result));
				setArmed(false);
				setEnded(true);
				// The hub's grant is listed among the connected apps, so
				// the screen reloads that list rather than keep it there.
				if (onDisconnected) {
					onDisconnected();
				}
				return false;
			}
		);

	// A read that failed draws the card and says so. Showing nothing would
	// take the only Disconnect off the screen while a hub may well still
	// hold access.
	if (loadFailed) {
		return (
			<div className="tr-p-6">
				<Alert variant="error" title={__('ThinkRank Hub', 'thinkrank')}>
					<p className="tr-m-0 tr-mb-3">
						{__(
							'Couldn’t read the hub connection, so this cannot say whether the hub still has access. Nothing about it has changed.',
							'thinkrank'
						)}
					</p>
					<Button
						variant="secondary"
						size="sm"
						onClick={() => setAttempt((n) => n + 1)}
					>
						{__('Try again', 'thinkrank')}
					</Button>
				</Alert>
			</div>
		);
	}

	if (!hub) {
		return (
			<div className="tr-p-6">
				<SkeletonText lines={3} />
			</div>
		);
	}

	const apps = hub.apps || [];
	// The grant that reaches furthest, newest first among equals: a site
	// holds one hub grant, but the card describes the widest it finds.
	const app = apps.find((grant) => !grant.read_only) || apps[0] || null;
	const connected = Boolean(hub.connected && app);
	const refusals = Array.isArray(hub.refused_by) ? hub.refused_by : [];
	const paused = connected && hub.reachable === false && refusals.length > 0;
	const refusal =
		refusals.length > 1
			? [
					__(
						'This site is connected to ThinkRank Hub, but the hub’s access is paused for more than one reason. It is let in again once all of them are undone.',
						'thinkrank'
					),
					...refusals
						.map((key) => REFUSALS[key]?.among)
						.filter(Boolean),
				].join(' ')
			: REFUSALS[refusals[0]]?.alone;

	let status = {
		variant: 'neutral',
		label: __('Not connected', 'thinkrank'),
	};
	if (connected && paused) {
		status = { variant: 'warning', label: __('Paused', 'thinkrank') };
	} else if (connected) {
		status = { variant: 'good', label: __('Connected', 'thinkrank') };
	}

	const disabled = busy !== '';

	let description = __(
		'See this site’s SEO score, its checks and what to fix beside your other sites, and reach every site from one AI connection.',
		'thinkrank'
	);
	if (connected && app.read_only) {
		description = __(
			'This site is connected to ThinkRank Hub, which can read its SEO reports and settings and change nothing.',
			'thinkrank'
		);
	} else if (connected) {
		description = __(
			'This site is connected to ThinkRank Hub, which can read its SEO reports and settings and make changes that can be undone.',
			'thinkrank'
		);
	}

	const hasNotices =
		error ||
		hub.pin_invalid ||
		(ended && !connected) ||
		(connected && paused && refusal) ||
		(!connected && hub.staging) ||
		(connected && armed);

	return (
		<div>
			{/* Header: the connect or disconnect action lives here, beside
			    the title, where the MCP card keeps its switch. */}
			<div className="tr-flex tr-items-start tr-gap-4 tr-p-6">
				<span className="tr-flex tr-items-center tr-justify-center tr-w-11 tr-h-11 tr-rounded-xl tr-bg-brand-bg tr-text-brand tr-shrink-0">
					<HubIcon />
				</span>
				<div className="tr-flex-1 tr-min-w-0">
					<div className="tr-flex tr-items-center tr-gap-2.5 tr-flex-wrap">
						<h2 className="tr-m-0 tr-text-base tr-font-semibold tr-text-primary">
							{__('ThinkRank Hub', 'thinkrank')}
						</h2>
						<Badge variant={status.variant}>{status.label}</Badge>
					</div>
					<p className="tr-mt-1 tr-mb-0 tr-text-sm tr-text-secondary tr-max-w-2xl">
						{description}
					</p>
				</div>
				<div className="tr-flex tr-flex-col tr-items-end tr-gap-1 tr-shrink-0">
					{/* A switch, like Enable MCP access: on starts the
					    connection (the browser leaves for the hub), off asks
					    for the disconnect confirmation below, and the switch
					    stays on until that is confirmed. */}
					<Toggle
						id="thinkrank-hub-connect"
						checked={connected || busy === 'connect'}
						onChange={(next) => (next ? connect() : setArmed(true))}
						disabled={
							disabled ||
							armed ||
							(!connected && (hub.pin_invalid || hub.staging))
						}
						label={__('Connect to ThinkRank Hub', 'thinkrank')}
					/>
					{busy === 'connect' && (
						<span className="tr-text-xs tr-text-tertiary">
							{__('Opening the hub…', 'thinkrank')}
						</span>
					)}
					{busy !== 'connect' && !connected && !hub.enabled && (
						<span className="tr-text-xs tr-text-tertiary">
							{__('Connecting turns on MCP access.', 'thinkrank')}
						</span>
					)}
				</div>
			</div>

			{hasNotices && (
				<div className="tr-flex tr-flex-col tr-gap-4 tr-px-6 tr-pb-6">
					{/* The one address problem an owner can meet: a pin that
					    cannot be used, which is also why the switch is off. */}
					{hub.pin_invalid && (
						<Alert variant="warning">
							{__(
								'The hub address set in wp-config.php (THINKRANK_HUB_URL) is not an https address this site can use, so it will not connect until that is corrected.',
								'thinkrank'
							)}
						</Alert>
					)}

					{error && (
						<Alert variant="error" onDismiss={() => setError('')}>
							{error}
						</Alert>
					)}

					{ended && !connected && (
						<Alert variant="success">
							<span role="status">
								{__(
									'Disconnected from ThinkRank Hub.',
									'thinkrank'
								)}
							</span>
						</Alert>
					)}

					{connected && paused && refusal && (
						<Alert variant="warning">{refusal}</Alert>
					)}

					{!connected && hub.staging && (
						<Alert variant="warning">
							{__(
								'This copy of the site is marked as a staging environment, which pauses the hub’s access, so connect the live site instead.',
								'thinkrank'
							)}
						</Alert>
					)}

					{connected && armed && (
						<div className="tr-flex tr-flex-col tr-gap-3 tr-p-4 tr-rounded-lg tr-border tr-border-danger/30 tr-bg-danger-bg">
							<p className="tr-m-0 tr-text-sm tr-text-primary">
								{__(
									'Disconnecting means the hub can no longer read or change this site. Connecting again needs a hub sign-in and your approval.',
									'thinkrank'
								)}
							</p>
							<div className="tr-flex tr-gap-2">
								<Button
									variant="danger"
									onClick={disconnect}
									loading={busy === 'disconnect'}
									disabled={disabled}
								>
									{__('Yes, disconnect', 'thinkrank')}
								</Button>
								<Button
									variant="secondary"
									onClick={() => setArmed(false)}
									disabled={disabled}
								>
									{__('Cancel', 'thinkrank')}
								</Button>
							</div>
						</div>
					)}
				</div>
			)}

			{connected && (
				<dl className="tr-grid tr-grid-cols-1 sm:tr-grid-cols-2 lg:tr-grid-cols-4 tr-gap-4 tr-m-0 tr-p-6 tr-border-t tr-border-secondary">
					<Detail label={__('Hub', 'thinkrank')}>{app.host}</Detail>
					<Detail label={__('Access', 'thinkrank')}>
						{app.read_only
							? __('Read only', 'thinkrank')
							: __('Read & Write', 'thinkrank')}
					</Detail>
					<Detail label={__('Connected since', 'thinkrank')}>
						{app.granted_at
							? formatWhen(app.granted_at)
							: __('Recently', 'thinkrank')}
					</Detail>
					<Detail label={__('Last read', 'thinkrank')}>
						{hub.last_read
							? formatWhen(hub.last_read)
							: __('Not yet', 'thinkrank')}
					</Detail>
				</dl>
			)}

			{/* What the hub does with the site: collapsed by default. */}
			<div className="tr-border-t tr-border-secondary">
				<button
					type="button"
					onClick={() => setShowCapabilities((v) => !v)}
					aria-expanded={showCapabilities}
					aria-controls="tr-hub-capabilities"
					className="tr-flex tr-items-start tr-justify-between tr-gap-3 tr-w-full tr-p-6 tr-bg-transparent tr-border-0 tr-cursor-pointer tr-text-left"
				>
					<span className="tr-min-w-0">
						<span className="tr-block tr-text-sm tr-font-semibold tr-text-primary">
							{__('What the hub can do', 'thinkrank')}
						</span>
						<span className="tr-block tr-mt-1 tr-text-sm tr-text-secondary">
							{__(
								'You choose Read or Read & Write for each area on this site’s approval page, and can narrow it later under MCP > Connected AI apps.',
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
						id="tr-hub-capabilities"
						className="tr-grid tr-grid-cols-1 sm:tr-grid-cols-2 lg:tr-grid-cols-3 tr-gap-2.5 tr-list-none tr-m-0 tr-pt-0 tr-px-6 tr-pb-6"
					>
						{HUB_CAPABILITIES.map((item) => (
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
		</div>
	);
};

export default MCPHubConnect;
