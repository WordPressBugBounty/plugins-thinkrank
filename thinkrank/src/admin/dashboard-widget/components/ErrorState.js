/**
 * Error state — explains why Search Console data could not load.
 *
 * The REST layer sends a `reason` alongside the message (see
 * SEO_Analytics_Endpoint::google_error_to_wp_error) so the widget can show a
 * plain-language cause plus the one action that actually fixes it — picking a
 * property, reconnecting Google, or simply waiting out a rate limit. Raw Google
 * text (e.g. "'http://' is not a valid Search Console site URL") is kept behind
 * a "Technical details" disclosure instead of being the headline.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';

/**
 * Setup/config icon — a sliders glyph for "something needs configuring".
 */
function SetupIcon() {
	return (
		<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
			<path
				d="M4 7h10M18 7h2M4 17h2M10 17h10M16 4v6M8 14v6"
				fill="none"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
			/>
		</svg>
	);
}

/**
 * Warning icon — a triangle glyph for transient/remote failures.
 */
function WarningIcon() {
	return (
		<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
			<path
				d="M12 4.5 21 20H3L12 4.5Z"
				fill="none"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinejoin="round"
			/>
			<path
				d="M12 10v4.5"
				fill="none"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
			/>
			<circle cx="12" cy="17.5" r="1.1" fill="currentColor" />
		</svg>
	);
}

/**
 * Map a server-sent reason to a headline, tone, and call to action.
 *
 * @param {string} reason Reason code from the REST error payload.
 * @param {Object} config Localized widget config (connect/property URLs).
 * @return {Object} { title, tone, action }
 */
function resolveReason(reason, config) {
	switch (reason) {
		case 'not_connected':
			return {
				title: __('Google account not connected', 'thinkrank'),
				tone: 'setup',
				action: {
					label: __('Connect Google Search Console', 'thinkrank'),
					url: config.connectUrl || config.settingsUrl,
				},
			};
		case 'no_property':
			return {
				title: __('No Search Console property selected', 'thinkrank'),
				tone: 'setup',
				action: {
					label: __('Select your property', 'thinkrank'),
					url: config.propertyUrl || config.settingsUrl,
				},
			};
		case 'reconnect':
			return {
				title: __('Google connection expired', 'thinkrank'),
				tone: 'setup',
				action: {
					label: __('Reconnect Google', 'thinkrank'),
					url: config.connectUrl || config.settingsUrl,
				},
			};
		case 'permission':
			return {
				title: __('No access to this property', 'thinkrank'),
				tone: 'setup',
				action: {
					label: __('Choose another property', 'thinkrank'),
					url: config.propertyUrl || config.settingsUrl,
				},
			};
		case 'quota':
			return {
				title: __('Google rate limit reached', 'thinkrank'),
				tone: 'warning',
				action: null,
			};
		case 'google_down':
			return {
				title: __('Search Console is unavailable', 'thinkrank'),
				tone: 'warning',
				action: null,
			};
		default:
			return {
				title: __('Search data could not be loaded', 'thinkrank'),
				tone: 'warning',
				action: null,
			};
	}
}

export default function ErrorState({ error, config, onRetry }) {
	const { title, tone, action } = resolveReason(error?.reason, config);
	const message =
		error?.message ||
		__('Search data could not be loaded right now.', 'thinkrank');

	return (
		<div
			className={`thinkrank-dashboard-widget__error thinkrank-dashboard-widget__error--${tone}`}
			role="alert"
		>
			<span className="thinkrank-dashboard-widget__error-icon">
				{tone === 'setup' ? <SetupIcon /> : <WarningIcon />}
			</span>

			<div className="thinkrank-dashboard-widget__error-body">
				<p className="thinkrank-dashboard-widget__error-title">
					{title}
				</p>
				<p className="thinkrank-dashboard-widget__error-message">
					{message}
				</p>

				{/* Retry only helps when nothing needs configuring first — with a
				    fix-it link on screen, retrying just repeats the same error. */}
				<div className="thinkrank-dashboard-widget__error-actions">
					{action?.url ? (
						<a
							className="thinkrank-dashboard-widget__error-cta"
							href={action.url}
						>
							{action.label}
						</a>
					) : (
						<button
							type="button"
							className="thinkrank-dashboard-widget__error-cta"
							onClick={onRetry}
						>
							{__('Try again', 'thinkrank')}
						</button>
					)}
				</div>

				{error?.details && (
					<details className="thinkrank-dashboard-widget__error-details">
						<summary>
							{__('Technical details', 'thinkrank')}
						</summary>
						<code>{error.details}</code>
					</details>
				)}
			</div>
		</div>
	);
}
