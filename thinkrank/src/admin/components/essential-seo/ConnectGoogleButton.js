/**
 * Connect to Google Button Component
 *
 * @package
 * @since 1.0.0
 */

import { Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import logger from '../../../shared/utils/logger';
import {
	consumeOAuthResult,
	getGoogleConnectUrl,
} from '../../utils/googleOAuth';

const GoogleGIcon = () => (
	<svg
		width="18"
		height="18"
		viewBox="0 0 18 18"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			fill="#4285F4"
			d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844a4.14 4.14 0 0 1-1.796 2.716v2.258h2.908c1.702-1.567 2.684-3.875 2.684-6.615z"
		/>
		<path
			fill="#34A853"
			d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z"
		/>
		<path
			fill="#FBBC05"
			d="M3.964 10.707A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.707V4.961H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.04l3.007-2.333z"
		/>
		<path
			fill="#EA4335"
			d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.962L3.964 7.29C4.672 5.166 6.656 3.58 9 3.58z"
		/>
	</svg>
);

const Spinner = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 16 16"
		className="tr-animate-spin"
		aria-hidden="true"
	>
		<circle
			cx="8"
			cy="8"
			r="6.5"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.75"
			strokeOpacity="0.25"
		/>
		<path
			d="M14.5 8a6.5 6.5 0 0 0-6.5-6.5"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.75"
			strokeLinecap="round"
		/>
	</svg>
);

const ERROR_MESSAGES = {
	access_denied: __('Google connection was cancelled.', 'thinkrank'),
	invalid_state: __(
		'The connection link expired. Please try connecting again.',
		'thinkrank'
	),
	forbidden: __(
		'You do not have permission to connect a Google account.',
		'thinkrank'
	),
};

const ConnectGoogleButton = ({
	onConnect,
	redirectTo,
	label,
	variant,
	icon,
	className = '',
}) => {
	// PHP completes the token exchange before redirecting back here, so the
	// component only has to report the outcome and start the next attempt.
	const [isConnecting, setIsConnecting] = useState(false);
	const [connectError, setConnectError] = useState(null);

	useEffect(() => {
		const { connected, error } = consumeOAuthResult();

		if (error) {
			logger.error('Google OAuth error:', error);
			setConnectError(
				ERROR_MESSAGES[error] ||
					__(
						'Google connection failed. Please try again.',
						'thinkrank'
					)
			);
			return;
		}

		if (connected) {
			if (onConnect) {
				onConnect();
			}
			if (redirectTo) {
				window.location.href = redirectTo;
			}
		}
	}, []);

	const handleConnect = () => {
		setConnectError(null);

		// The connect URL points at admin-post.php — PHP signs the request and
		// hands off to the OAuth proxy, which owns the Google credentials.
		const connectUrl = getGoogleConnectUrl(redirectTo);

		if (!connectUrl) {
			logger.error('Google connect URL is not available.');
			setConnectError(
				__(
					'Google connection is unavailable. Please reload the page.',
					'thinkrank'
				)
			);
			return;
		}

		setIsConnecting(true);
		window.location.href = connectUrl;
	};

	// When a `variant` is given (e.g. the Setup Wizard), render the native
	// WordPress Button so the styling matches the Figma design exactly — this is
	// the original setup-wizard implementation. Callers without a variant keep
	// the standalone custom button used elsewhere (e.g. Integrations tab).
	if (variant) {
		return (
			<Button
				variant={variant}
				onClick={handleConnect}
				className={className}
				isBusy={isConnecting}
				disabled={isConnecting}
			>
				{icon && !isConnecting && (
					<img
						src={icon}
						alt=""
						className="thinkrank-google-icon"
						aria-hidden="true"
					/>
				)}
				{isConnecting
					? __('Connecting…', 'thinkrank')
					: label || __('Connect with Google', 'thinkrank')}
			</Button>
		);
	}

	return (
		<>
			{connectError && (
				<p
					className="tr-text-xs tr-text-danger tr-m-0 tr-mb-2"
					role="alert"
				>
					{connectError}
				</p>
			)}
			<button
				type="button"
				onClick={handleConnect}
				disabled={isConnecting}
				className={
					'tr-inline-flex tr-items-center tr-gap-3 tr-h-11 tr-px-5 ' +
					'tr-rounded-md tr-bg-white tr-border-[1.5px] tr-border-primary ' +
					'tr-text-sm tr-font-semibold tr-text-secondary tr-cursor-pointer ' +
					'tr-transition-colors tr-duration-150 ' +
					'hover:tr-border-primary hover:tr-bg-white ' +
					'focus:tr-outline-none focus-visible:tr-border-brand focus-visible:tr-ring-2 focus-visible:tr-ring-brand ' +
					'disabled:tr-cursor-not-allowed disabled:tr-opacity-70' +
					(className ? ' ' + className : '')
				}
			>
				<span
					className="tr-flex tr-items-center tr-justify-center"
					aria-hidden="true"
				>
					{isConnecting ? (
						<Spinner />
					) : icon ? (
						<img
							src={icon}
							alt=""
							className="thinkrank-google-icon"
						/>
					) : (
						<GoogleGIcon />
					)}
				</span>
				<span>
					{isConnecting
						? __('Connecting…', 'thinkrank')
						: label || __('Connect with Google', 'thinkrank')}
				</span>
			</button>
		</>
	);
};

export default ConnectGoogleButton;
