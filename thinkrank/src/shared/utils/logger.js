/**
 * ThinkRank Logger Utility
 *
 * Centralized logging that can be silenced in production.
 * Outputs are gated behind a debug flag to keep the browser
 * console clean for end users.
 *
 * Enable debug output by setting: window.thinkrankDebug = true
 * in the browser console, or by having WP_DEBUG enabled
 * (the admin localization sets window.thinkrankAdmin.debug).
 *
 * @package
 * @since 1.10.0
 */

const isDebug = () => {
	return (
		window.thinkrankDebug === true || window.thinkrankAdmin?.debug === true
	);
};

const logger = {
	/**
	 * Log an error message (only when debug mode is enabled)
	 *
	 * @param {...any} args - Arguments to pass to console.error
	 */
	error: (...args) => {
		if (isDebug()) {
			console.error('[ThinkRank]', ...args);
		}
	},

	/**
	 * Log a warning message (only when debug mode is enabled)
	 *
	 * @param {...any} args - Arguments to pass to console.warn
	 */
	warn: (...args) => {
		if (isDebug()) {
			console.warn('[ThinkRank]', ...args);
		}
	},

	/**
	 * Log an info message (only when debug mode is enabled)
	 *
	 * @param {...any} args - Arguments to pass to console.info
	 */
	info: (...args) => {
		if (isDebug()) {
			console.info('[ThinkRank]', ...args);
		}
	},
};

export default logger;
