/**
 * Variable-tag inserter + live preview.
 *
 * Mirrors the Bulk SEO Optimization UI: clickable variable chips that insert a
 * %tag% into the field, plus a live resolved preview of the current value. Lets
 * users customize the inherited variable-tag pattern per post.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { resolvePattern } from '../../utils/resolvePattern';

/**
 * @param {Object}   props
 * @param {Array}    props.variables [{ label, value }] tags to offer.
 * @param {Function} props.onInsert  Called with the %tag% to append.
 * @param {string}   props.value     Current field value (pattern or literal).
 * @param {Object}   props.vars      Token => value map for live preview.
 * @param {string}   props.fallback  Preview shown when the field is empty.
 * @param {boolean}  props.disabled  The field is locked: chips insert nothing.
 */
const VariableTags = ({
	variables = [],
	onInsert,
	value = '',
	vars = {},
	fallback = '',
	disabled = false,
}) => {
	const preview = value ? resolvePattern(value, vars) : fallback;

	return (
		<div className="tr-mt-1.5">
			<div className="tr-flex tr-flex-wrap tr-items-center tr-gap-1.5">
				<span className="tr-text-xs tr-text-tertiary">
					{__('Use variables:', 'thinkrank')}
				</span>
				{variables.map((variable) => (
					<button
						type="button"
						key={variable.value}
						disabled={disabled}
						onClick={() => !disabled && onInsert(variable.value)}
						title={__('Click to insert', 'thinkrank')}
						className="tr-inline-flex tr-items-center tr-rounded-full tr-border-0 tr-bg-[#EEF0F7] tr-px-2 tr-py-0.5 tr-text-xs tr-text-[#535353] tr-cursor-pointer hover:tr-bg-brand hover:tr-text-white tr-transition-colors disabled:tr-cursor-not-allowed disabled:tr-opacity-50 disabled:hover:tr-bg-[#EEF0F7] disabled:hover:tr-text-[#535353]"
					>
						{variable.label}
					</button>
				))}
			</div>
			{preview ? (
				<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-1">
					<span className="tr-font-medium">
						{__('Preview:', 'thinkrank')}
					</span>{' '}
					{preview}
				</p>
			) : null}
		</div>
	);
};

export default VariableTags;
