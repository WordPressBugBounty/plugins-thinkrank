import { __ } from '@wordpress/i18n';

import Alert from './Alert';
import Button from './Button';

/**
 * Shown in place of a settings form whose GET failed.
 *
 * The screens used to swallow a failed load and populate the form with
 * getDefaultSettings(), which looks identical to a freshly configured site. The
 * user would change one field, press Save, and POST the whole defaults object
 * over their stored title formats, breadcrumb config, business information and
 * robots.txt body (#390). Rendering this instead means there is nothing to save
 * from until a load succeeds.
 *
 * @param {Object}   props
 * @param {string}   [props.title]      Heading for the alert.
 * @param {string}   [props.message]    Body copy explaining what to do.
 * @param {Function} props.onRetry      Re-runs the load.
 * @param {boolean}  [props.isRetrying] Whether a retry is in flight.
 * @return {JSX.Element} The error state.
 */
export default function SettingsLoadError({
	title,
	message,
	onRetry,
	isRetrying = false,
}) {
	return (
		<Alert
			variant="error"
			title={title || __('Couldn’t load your settings', 'thinkrank')}
		>
			<p className="tr-m-0">
				{message ||
					__(
						'Your saved settings could not be read, so this screen has nothing to edit yet. Nothing has changed. Retry to load them.',
						'thinkrank'
					)}
			</p>
			<Button
				variant="secondary"
				size="sm"
				className="tr-mt-3"
				onClick={onRetry}
				loading={isRetrying}
			>
				{__('Retry', 'thinkrank')}
			</Button>
		</Alert>
	);
}
