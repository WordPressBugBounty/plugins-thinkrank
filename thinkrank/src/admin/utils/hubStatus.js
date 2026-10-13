/**
 * Whether this site is connected to ThinkRank Hub, for the header chip.
 *
 * The state arrives localized as `thinkrankAdmin.mcp.hub_connected` (true only
 * for administrators, who are the only ones who can open the Hub card). The
 * Hub card reports every state it reads or causes, and so does the Connected
 * AI apps list, whose Revoke and Revoke all access can end the hub's grant
 * too (#1143). The chip therefore follows a disconnect without a reload; a
 * connect always ends in a full page load on the way back from the hub.
 *
 * Kept free of the router: the setup wizard, which is not the admin app,
 * shows the same card and must not load the admin app's menu handling.
 *
 * @package
 * @since 2.15.0
 */

import { useState, useEffect } from '@wordpress/element';

const STATUS_EVENT = 'thinkrank:hub-status';
export const TAB_EVENT = 'thinkrank:ai-tools-tab';

/**
 * The connection state known to this page.
 *
 * @return {boolean} Connected.
 */
export const getHubConnected = () =>
	Boolean(window.thinkrankAdmin?.mcp?.hub_connected);

/**
 * Record a connection state read from the server and tell the subscribers.
 *
 * @param {boolean} connected Connected.
 */
export const setHubConnected = (connected) => {
	const next = Boolean(connected);
	if (window.thinkrankAdmin) {
		window.thinkrankAdmin.mcp = {
			...(window.thinkrankAdmin.mcp || {}),
			hub_connected: next,
		};
	}
	window.dispatchEvent(new CustomEvent(STATUS_EVENT, { detail: next }));
};

/**
 * The connection state, kept current.
 *
 * @return {boolean} Connected.
 */
export const useHubConnected = () => {
	const [connected, setConnected] = useState(getHubConnected);

	useEffect(() => {
		const onStatus = (event) => setConnected(Boolean(event.detail));
		window.addEventListener(STATUS_EVENT, onStatus);
		return () => window.removeEventListener(STATUS_EVENT, onStatus);
	}, []);

	return connected;
};
