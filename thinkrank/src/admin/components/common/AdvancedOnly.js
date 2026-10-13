/**
 * AdvancedOnly (#730): settings a site owner rarely needs, folded away in
 * Basic mode behind a "Show advanced settings" link. Advanced mode renders
 * them as always.
 *
 * Folding only hides the controls; the settings keep working, and opening
 * the fold shows them as they are.
 *
 * @package
 * @since 2.11.0
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { SIMPLE, useUiMode } from '../../utils/uiMode';

/**
 * @param {Object}      props             Props.
 * @param {string}      [props.label]     What is folded away, e.g. "Embed cards".
 * @param {string}      [props.className] Extra classes for the link.
 * @param {JSX.Element} props.children    Advanced settings.
 * @return {JSX.Element} The settings, or the link that shows them.
 */
const AdvancedOnly = ({ label, className = '', children }) => {
	const { mode } = useUiMode();
	const [shown, setShown] = useState(false);

	if (SIMPLE !== mode || shown) {
		return children;
	}

	return (
		<button
			type="button"
			onClick={() => setShown(true)}
			className={`tr-self-start tr-inline-flex tr-items-center tr-gap-1 tr-bg-transparent tr-border-0 tr-p-0 tr-text-sm tr-font-medium tr-text-brand tr-cursor-pointer hover:tr-underline ${className}`}
		>
			{label
				? sprintf(
						/* translators: %s: what the advanced settings cover, e.g. "Embed cards". */
						__('Show advanced settings: %s', 'thinkrank'),
						label
					)
				: __('Show advanced settings', 'thinkrank')}
		</button>
	);
};

export default AdvancedOnly;
