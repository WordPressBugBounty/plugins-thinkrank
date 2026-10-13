/**
 * PerformanceErrorNotice — the one error state the Performance panels share.
 *
 * The three panels each grew their own. Opportunities printed the bare message
 * and stopped; Diagnostics printed it and appended a sentence of advice;
 * Historical printed it with a Retry button and no advice. Same failure, three
 * different amounts of help — and the one panel that offered a way to fix the
 * usual cause (add a credential) offered it only from its *empty* state, never
 * from its error state.
 *
 * What the user reads now comes classified from the server rather than being
 * Google's raw wording, so this component's job is only to present it and to
 * offer the two actions that can resolve it: go add a credential, or retry.
 *
 * @package
 * @since 2.1.1
 */

import { __ } from '@wordpress/i18n';
import { Notice, Button } from '@wordpress/components';

/**
 * Failure codes whose remedy is a credential the user can go and configure.
 * Anything else (a burst rate limit, an unreachable URL, a Google outage) is
 * not fixed on the Integrations screen, so sending them there would be a
 * dead end — those get Retry alone.
 */
const CREDENTIAL_CODES = [
	'pagespeed_not_configured',
	'pagespeed_quota_exhausted',
	'pagespeed_credential_rejected',
];

/**
 * @param {Object}        props
 * @param {Object|string} props.error        `{ message, code }` from the endpoint. A bare
 *                                           string is accepted too, for a caller that has
 *                                           no code to pass.
 * @param {Function}      [props.onNavigate] SPA navigation, used for the Integrations link.
 * @param {Function}      [props.onRetry]    Re-runs the request.
 * @param {boolean}       [props.isRetrying] Whether a retry is in flight.
 * @return {JSX.Element|null} The error state, or null when there is no error.
 */
const PerformanceErrorNotice = ({
	error,
	onNavigate,
	onRetry,
	isRetrying = false,
}) => {
	if (!error) {
		return null;
	}

	const message = typeof error === 'string' ? error : error.message;
	const code = typeof error === 'string' ? '' : error.code;

	if (!message) {
		return null;
	}

	const offerCredential = CREDENTIAL_CODES.includes(code) && !!onNavigate;

	return (
		<div className="tr-flex tr-flex-col tr-gap-3">
			<Notice status="error" isDismissible={false}>
				{message}
			</Notice>

			{(offerCredential || onRetry) && (
				<div className="tr-flex tr-gap-3">
					{offerCredential && (
						<Button
							variant="primary"
							onClick={() =>
								onNavigate('integrations', 'google-services')
							}
						>
							{__('Add PageSpeed API Key', 'thinkrank')}
						</Button>
					)}
					{onRetry && (
						<Button
							variant="secondary"
							onClick={onRetry}
							disabled={isRetrying}
						>
							{isRetrying
								? __('Retrying…', 'thinkrank')
								: __('Retry', 'thinkrank')}
						</Button>
					)}
				</div>
			)}
		</div>
	);
};

export default PerformanceErrorNotice;
