/**
 * Shared pieces of the WooCommerce › Product SEO cards.
 *
 * The cards on this screen sit above Pro's panel, which opens with an icon,
 * title, description and a saving hint and lays each setting out with its
 * switch on the right. These give the free cards the same shape, so the screen
 * reads as one page.
 *
 * @package
 * @since 2.21.0
 */

import { __ } from '@wordpress/i18n';
import { Icon } from '@wordpress/icons';

import { Toggle } from '../../ui';

/**
 * A card's single header.
 *
 * @param {Object}  props             Props.
 * @param {Object}  props.icon        Icon from @wordpress/icons.
 * @param {string}  props.title       Card title.
 * @param {string}  props.description One line about the card.
 * @param {boolean} props.isSaving    Whether a change is being saved.
 * @return {JSX.Element} The header.
 */
export const ProductSeoCardHeader = ({
	icon,
	title,
	description,
	isSaving = false,
}) => (
	<div className="tr-flex tr-items-start tr-gap-3 tr-border-b tr-border-secondary tr-px-6 tr-py-5">
		<div className="tr-flex tr-h-11 tr-w-11 tr-shrink-0 tr-items-center tr-justify-center tr-rounded-lg tr-bg-brand-bg tr-text-brand">
			<Icon icon={icon} size={22} fill="currentColor" />
		</div>
		<div className="tr-min-w-0 tr-flex-1">
			<h3 className="tr-m-0 tr-text-base tr-font-semibold tr-text-primary">
				{title}
			</h3>
			<p className="tr-m-0 tr-mt-1 tr-text-sm tr-text-secondary">
				{description}
			</p>
		</div>
		{isSaving && (
			<span className="tr-shrink-0 tr-text-xs tr-text-tertiary">
				{__('Saving…', 'thinkrank')}
			</span>
		)}
	</div>
);

/**
 * One setting with its switch on the right.
 *
 * The label is rendered here rather than by Toggle so that it can sit apart
 * from the switch; `htmlFor` keeps it the switch's accessible name.
 *
 * @param {Object}      props             Props.
 * @param {string}      props.id          Switch id.
 * @param {boolean}     props.checked     Current value.
 * @param {boolean}     props.disabled    Whether the switch is disabled.
 * @param {string}      props.label       Setting name.
 * @param {string}      props.description What it does.
 * @param {Function}    props.onChange    Receives the new value.
 * @param {JSX.Element} props.children    Extra content under the description.
 * @return {JSX.Element} The row.
 */
export const ToggleRow = ({
	id,
	checked,
	disabled = false,
	label,
	description,
	onChange,
	children,
}) => (
	<div className="tr-flex tr-items-start tr-justify-between tr-gap-6 tr-py-4 first:tr-pt-0 last:tr-pb-0">
		<div className="tr-min-w-0 tr-flex-1">
			<label
				htmlFor={id}
				className="tr-block tr-cursor-pointer tr-text-sm tr-font-medium tr-text-primary"
			>
				{label}
			</label>
			<p className="tr-m-0 tr-mt-1 tr-text-sm tr-text-tertiary">
				{description}
			</p>
			{children}
		</div>
		<div className="tr-shrink-0 tr-pt-0.5">
			<Toggle
				id={id}
				checked={checked}
				disabled={disabled}
				onChange={onChange}
			/>
		</div>
	</div>
);
