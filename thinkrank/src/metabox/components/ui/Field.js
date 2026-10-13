/**
 * Field — a labelled form row.
 *
 * Renders a label row (shared ui `Label` + an optional counter node, e.g.
 * `CharacterCounter`), the control passed as `children` (a ui `Input` /
 * `Textarea` / `Select`), and optional help or error text below.
 */

import Label from '../../../admin/components/ui/Label';

const Field = ({
	label,
	htmlFor,
	counter,
	help,
	error,
	required = false,
	children,
}) => (
	<div className="tr-flex tr-flex-col tr-gap-1.5">
		{(label || counter) && (
			<div className="tr-flex tr-items-center tr-justify-between tr-gap-2">
				{label ? (
					<Label tag="label" htmlFor={htmlFor} required={required}>
						{label}
					</Label>
				) : (
					<span />
				)}
				{counter}
			</div>
		)}
		{children}
		{error ? (
			<p className="tr-text-xs tr-text-danger tr-m-0">{error}</p>
		) : (
			help && <p className="tr-text-xs tr-text-tertiary tr-m-0">{help}</p>
		)}
	</div>
);

export default Field;
