import classnames from 'classnames';
import { Label, Text } from '../ui';

// Two-column form field row.
//
// Layout on lg+:
//   ┌──────────────────────────┬──────────────────────────────┐
//   │ Label              ⓘ     │ [control]                    │
//   │ Description text         │ help text          counter   │
//   └──────────────────────────┴──────────────────────────────┘
//
// Stacks to single column below lg. Counter sits in the help row on the
// right; pass it via the `counter` prop. Pass label-side help (a short
// sentence under the label) via `description`, and control-side help
// (microcopy under the input) via `help`.
export default function FormField({
	id,
	label,
	description,
	help,
	counter,
	required = false,
	disabled = false,
	tooltip,
	className,
	children,
}) {
	return (
		<div
			className={classnames(
				'tr-grid tr-grid-cols-1 lg:tr-grid-cols-[minmax(0,1fr)_minmax(0,2fr)]',
				'tr-gap-2 lg:tr-gap-8 tr-items-start',
				className
			)}
		>
			<div
				className={classnames(
					'tr-flex tr-flex-col tr-gap-1 tr-min-w-0',
					'lg:tr-pt-2.5'
				)}
			>
				<div className="tr-flex tr-items-center tr-gap-1.5">
					<Label
						htmlFor={id}
						required={required}
						className={disabled ? 'tr-opacity-60' : ''}
					>
						{label}
					</Label>
					{tooltip}
				</div>
				{description && (
					<Text size={12} color="help">
						{description}
					</Text>
				)}
			</div>

			<div className="tr-flex tr-flex-col tr-gap-1.5 tr-min-w-0">
				{children}
				{(help || counter) && (
					<div className="tr-flex tr-items-start tr-justify-between tr-gap-3">
						<div className="tr-min-w-0 tr-flex-1">
							{help && (
								<Text size={12} color="help">
									{help}
								</Text>
							)}
						</div>
						{counter && (
							<div className="tr-shrink-0">{counter}</div>
						)}
					</div>
				)}
			</div>
		</div>
	);
}
