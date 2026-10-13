import classnames from 'classnames';

// "Outlined modern" control style — no shadows, just border.
// See memory/feedback_form_control_style.md.

// A description is only help text if the control points at it. Rendered as a
// bare sibling <p> it is invisible to a screen reader, which is how three
// Advanced Robots Meta controls came to announce nothing but "-1" while the
// sentence explaining them sat next to them unread (#842). Giving it an id and
// wiring aria-describedby here fixes every screen that passes `description`,
// rather than one call site.
const describedBy = (ownId, callerValue) =>
	[callerValue, ownId].filter(Boolean).join(' ') || undefined;

export default function Select({
	label,
	description,
	error,
	options = [],
	className,
	id,
	hideLabelFromVision = false,
	...props
}) {
	const selectId = id || label?.toLowerCase().replace(/\s+/g, '-');
	const borderClass = error ? 'tr-border-danger' : 'tr-border-primary';
	const messageId = selectId ? `${selectId}-description` : undefined;
	const hasMessage = Boolean((description && !error) || error);

	return (
		<div
			className={classnames('tr-flex tr-flex-col tr-gap-1.5', className)}
		>
			{label && (
				// `hideLabelFromVision` keeps the real, associated <label> in the
				// DOM but out of the layout — for a control whose visible label
				// is a column heading shared by every row of a table. Uses WP's
				// own screen-reader-text, which wp-admin always loads, rather
				// than a tr-* utility that Tailwind may have purged.
				<label
					htmlFor={selectId}
					className={
						hideLabelFromVision
							? 'screen-reader-text'
							: 'tr-text-sm tr-font-medium tr-text-secondary'
					}
				>
					{label}
				</label>
			)}
			<div className="tr-relative">
				<select
					id={selectId}
					style={{
						backgroundImage: 'none',
						paddingRight: undefined,
					}}
					className={classnames(
						'tr-w-full tr-h-10 tr-rounded-md tr-bg-white tr-text-sm tr-text-primary',
						'tr-border-[1.5px] tr-pl-3 tr-pr-9 tr-appearance-none',
						'tr-transition-colors tr-duration-150',
						'hover:tr-border-[#A9AEC0]',
						'focus:tr-outline-none focus-visible:tr-border-brand',
						'disabled:tr-bg-white disabled:tr-text-tertiary disabled:tr-border-secondary disabled:tr-cursor-not-allowed',
						borderClass
					)}
					{...props}
					aria-describedby={
						hasMessage
							? describedBy(messageId, props['aria-describedby'])
							: props['aria-describedby']
					}
				>
					{options.map((opt) => (
						<option key={opt.value} value={opt.value}>
							{opt.label}
						</option>
					))}
				</select>
				<span className="tr-pointer-events-none tr-absolute tr-inset-y-0 tr-right-3 tr-flex tr-items-center tr-text-tertiary">
					<svg
						className="tr-h-4 tr-w-4"
						viewBox="0 0 16 16"
						fill="currentColor"
						aria-hidden="true"
					>
						<path
							fillRule="evenodd"
							d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z"
							clipRule="evenodd"
						/>
					</svg>
				</span>
			</div>
			{description && !error && (
				<p
					id={messageId}
					className="tr-text-xs tr-text-tertiary tr-m-0"
				>
					{description}
				</p>
			)}
			{error && (
				<p id={messageId} className="tr-text-xs tr-text-danger tr-m-0">
					{error}
				</p>
			)}
		</div>
	);
}
