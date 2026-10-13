import classnames from 'classnames';

export default function Toggle({
	checked,
	onChange,
	label,
	description,
	disabled = false,
	id,
}) {
	const toggleId =
		id ||
		(typeof label === 'string'
			? label.toLowerCase().replace(/\s+/g, '-')
			: undefined);

	return (
		<div className="tr-flex tr-items-start tr-gap-3">
			<button
				id={toggleId}
				role="switch"
				aria-checked={checked}
				onClick={() => !disabled && onChange(!checked)}
				disabled={disabled}
				className={classnames(
					'tr-relative tr-inline-flex tr-h-6 tr-w-11 tr-shrink-0 tr-items-center',
					'tr-rounded-full tr-border-2 tr-border-transparent',
					'tr-transition-colors tr-duration-200',
					'focus:tr-outline-none focus:tr-ring-2 focus:tr-ring-brand focus:tr-ring-offset-2',
					'disabled:tr-opacity-50 disabled:tr-cursor-not-allowed',
					checked ? 'tr-bg-brand' : 'tr-bg-line-2'
				)}
			>
				<span
					className={classnames(
						'tr-inline-block tr-h-4 tr-w-4 tr-rounded-full tr-bg-white tr-shadow',
						'tr-transition-transform tr-duration-200',
						checked ? 'tr-translate-x-5' : 'tr-translate-x-0'
					)}
				/>
			</button>
			{(label || description) && (
				<label
					htmlFor={toggleId}
					className="tr-flex tr-flex-col tr-cursor-pointer"
				>
					{label && (
						<span className="tr-text-sm tr-font-medium tr-text-secondary">
							{label}
						</span>
					)}
					{description && (
						<span className="tr-text-xs tr-text-tertiary tr-mt-0.5">
							{description}
						</span>
					)}
				</label>
			)}
		</div>
	);
}
