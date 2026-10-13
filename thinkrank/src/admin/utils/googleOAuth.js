/**
 * Google OAuth entry point.
 *
 * The plugin holds no Google app credentials and builds no consent URL. The
 * connect link points at admin-post.php; PHP signs the request and bounces the
 * browser to the ThinkRank OAuth proxy, which owns the client ID/secret and
 * performs the code exchange. Tokens never reach the browser.
 *
 * @package
 * @since 1.0.0
 */

// The admin SPA localizes `thinkrankAdmin`; the Setup Wizard is a separate
// entry point that localizes `thinkrankWizard`. Read either so the shared
// ConnectGoogleButton works on both without knowing which page it is on.
const config =
	(typeof window !== 'undefined' &&
		(window.thinkrankAdmin?.googleOAuth ||
			window.thinkrankWizard?.googleOAuth)) ||
	{};

/**
 * Why the site must re-authorize, or '' when nothing is wrong.
 *
 * 'contract'    — upgraded from the pre-proxy token flow.
 * 'credentials' — stored tokens can no longer be decrypted, which in practice
 *                 means wp_salt('auth') changed under them.
 *
 * @type {string}
 */
export const RECONNECT_REASON = config.reconnectReason || '';

/**
 * Nonce-signed URL that starts the Google connect flow.
 *
 * @param {string} [returnUrl] Admin URL to land on when the flow completes.
 *                             Defaults server-side to the Google Services screen.
 * @return {string} Connect URL, or an empty string if PHP didn't localize one.
 */
export const getGoogleConnectUrl = (returnUrl) => {
	if (!config.connectUrl) {
		return '';
	}

	if (!returnUrl) {
		return config.connectUrl;
	}

	return `${config.connectUrl}&return=${encodeURIComponent(returnUrl)}`;
};

/**
 * Whether this page load is the return leg of the Google connect flow.
 *
 * Unlike consumeOAuthResult() this does not scrub the params, so it is safe to
 * call during boot — ConnectGoogleButton still gets to read the outcome.
 *
 * @return {boolean} True when the URL carries an OAuth result.
 */
export const hasOAuthResult = () => {
	if (typeof window === 'undefined') {
		return false;
	}

	const params = new URL(window.location.href).searchParams;

	return (
		params.get('thinkrank_google_connected') === '1' ||
		!!params.get('thinkrank_google_error')
	);
};

/**
 * Read the flow's outcome from the URL and scrub the params so a reload doesn't
 * replay the message.
 *
 * @return {{connected: boolean, error: string|null}} Result of the last flow.
 */
export const consumeOAuthResult = () => {
	if (typeof window === 'undefined') {
		return { connected: false, error: null };
	}

	const url = new URL(window.location.href);
	const connected =
		url.searchParams.get('thinkrank_google_connected') === '1';
	const error = url.searchParams.get('thinkrank_google_error');

	if (connected || error) {
		url.searchParams.delete('thinkrank_google_connected');
		url.searchParams.delete('thinkrank_google_error');
		window.history.replaceState({}, document.title, url.toString());
	}

	return { connected, error };
};
