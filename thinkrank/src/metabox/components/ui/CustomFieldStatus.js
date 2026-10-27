/**
 * What the editor preview is doing with a `%customfield(key)%` it does not
 * have a value for yet (#1277 review): looking it up, or failed to. Nothing
 * when every field the fields name is known.
 *
 * @package
 * @since 2.19.0
 */

import { __ } from '@wordpress/i18n';

import Button from '../../../admin/components/ui/Button';

/**
 * @param {Object}   props
 * @param {string}   props.status  'idle', 'loading' or 'error'.
 * @param {string}   props.error   The lookup's error message.
 * @param {Function} props.onRetry Asks for the values again.
 */
const CustomFieldStatus = ({ status, error, onRetry }) => {
	if ('loading' === status) {
		return (
			<p
				className="tr-m-0 tr-text-xs tr-text-tertiary"
				role="status"
				data-testid="custom-field-status"
			>
				{__(
					'Looking up the custom field values for the preview…',
					'thinkrank'
				)}
			</p>
		);
	}

	if ('error' === status) {
		return (
			<div
				className="tr-flex tr-flex-wrap tr-items-center tr-gap-2"
				data-testid="custom-field-status"
			>
				<p className="tr-m-0 tr-text-xs tr-text-danger" role="alert">
					{error}{' '}
					{__(
						'The preview shows those fields as typed; the page still uses their values.',
						'thinkrank'
					)}
				</p>
				{onRetry && (
					<Button
						type="button"
						variant="secondary"
						size="sm"
						onClick={onRetry}
					>
						{__('Try again', 'thinkrank')}
					</Button>
				)}
			</div>
		);
	}

	return null;
};

export default CustomFieldStatus;
