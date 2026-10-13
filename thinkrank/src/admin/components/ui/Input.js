import { useEffect, useRef } from '@wordpress/element';
import classnames from 'classnames';

// "Outlined modern" control style — no shadows, just border.
// See memory/feedback_form_control_style.md.
const CONTROL_BASE = [
	'tr-w-full tr-rounded-md tr-text-sm tr-text-primary',
	'tr-border-[1.5px] tr-transition-colors tr-duration-150',
	'placeholder:tr-text-tertiary',
	'focus:tr-outline-none focus-visible:tr-border-brand',
	'disabled:tr-text-tertiary disabled:tr-cursor-not-allowed',
];

// The outline is the control in a form, where a field has to advertise itself.
// In a dense table every cell is already a field, so the same outline repeated
// down every column is noise: `ghost` draws it only on hover and focus, and the
// value carries the row instead.
const VARIANT_CLASSES = {
	outlined: [
		'tr-bg-white hover:tr-border-[#A9AEC0]',
		'disabled:tr-bg-white disabled:tr-border-secondary',
	],
	ghost: [
		'tr-bg-transparent tr-border-transparent',
		'hover:tr-border-secondary focus-visible:tr-bg-white',
		'disabled:tr-bg-transparent disabled:tr-border-transparent',
	],
};

const borderStateClass = (error, variant) => {
	if (error) {
		return 'tr-border-danger';
	}
	return variant === 'ghost' ? '' : 'tr-border-primary';
};

// A description is only help text if the control points at it. Rendered as a
// bare sibling <p> it is invisible to a screen reader, which is how three
// Advanced Robots Meta controls came to announce nothing but "-1" while the
// sentence explaining them sat next to them unread (#842). Giving it an id and
// wiring aria-describedby here fixes every screen that passes `description`,
// rather than one call site.
const describedBy = (ownId, callerValue) =>
	[callerValue, ownId].filter(Boolean).join(' ') || undefined;

export default function Input({
	label,
	description,
	error,
	prefix,
	suffix,
	className,
	id,
	variant = 'outlined',
	hideLabelFromVision = false,
	...props
}) {
	const inputId = id || label?.toLowerCase().replace(/\s+/g, '-');
	const messageId = inputId ? `${inputId}-description` : undefined;
	const hasMessage = Boolean((description && !error) || error);

	return (
		<div
			className={classnames('tr-flex tr-flex-col tr-gap-1.5', className)}
		>
			{label && (
				// See the matching note in ui/Select.js: keeps the real,
				// associated <label> for assistive tech but out of the layout,
				// for a control whose visible label is a shared column heading.
				<label
					htmlFor={inputId}
					className={
						hideLabelFromVision
							? 'screen-reader-text'
							: 'tr-text-sm tr-font-medium tr-text-secondary'
					}
				>
					{label}
				</label>
			)}
			<div className="tr-relative tr-flex tr-items-center">
				{prefix && (
					<span className="tr-absolute tr-left-3 tr-text-tertiary tr-text-sm tr-pointer-events-none">
						{prefix}
					</span>
				)}
				<input
					id={inputId}
					className={classnames(
						CONTROL_BASE,
						VARIANT_CLASSES[variant] || VARIANT_CLASSES.outlined,
						'tr-h-10',
						borderStateClass(error, variant),
						prefix ? 'tr-pl-9' : 'tr-px-3',
						suffix ? 'tr-pr-9' : 'tr-pr-3'
					)}
					{...props}
					aria-describedby={
						hasMessage
							? describedBy(messageId, props['aria-describedby'])
							: props['aria-describedby']
					}
				/>
				{suffix && (
					<span className="tr-absolute tr-right-3 tr-text-tertiary tr-text-sm tr-pointer-events-none">
						{suffix}
					</span>
				)}
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

export function Textarea({
	label,
	description,
	error,
	className,
	id,
	value,
	variant = 'outlined',
	autoGrow = false,
	...props
}) {
	const inputId = id || label?.toLowerCase().replace(/\s+/g, '-');
	const messageId = inputId ? `${inputId}-description` : undefined;
	const hasMessage = Boolean((description && !error) || error);
	const ref = useRef(null);

	// A fixed height either clips the value or reserves room for one that is
	// not there. Growing to the content does neither, so a table of these
	// stays as short as its longest entry needs.
	useEffect(() => {
		if (!autoGrow || !ref.current) {
			return;
		}
		const el = ref.current;
		el.style.height = 'auto';
		// `height` is border-box here and `scrollHeight` is not, so the border
		// has to be added back or the last line is clipped by a hair.
		const border = el.offsetHeight - el.clientHeight;
		el.style.height = `${el.scrollHeight + border}px`;
	}, [autoGrow, value]);

	return (
		<div
			className={classnames('tr-flex tr-flex-col tr-gap-1.5', className)}
		>
			{label && (
				<label
					htmlFor={inputId}
					className="tr-text-sm tr-font-medium tr-text-secondary"
				>
					{label}
				</label>
			)}
			<textarea
				ref={ref}
				id={inputId}
				value={value}
				className={classnames(
					CONTROL_BASE,
					VARIANT_CLASSES[variant] || VARIANT_CLASSES.outlined,
					// A ghost field sits in a dense list, where the padding
					// and leading a standalone form control wants become the
					// height of every row.
					variant === 'ghost'
						? 'tr-px-2 tr-py-1.5 tr-leading-snug'
						: 'tr-px-3 tr-py-2.5 tr-leading-relaxed',
					autoGrow
						? 'tr-resize-none tr-overflow-hidden'
						: 'tr-resize-y',
					borderStateClass(error, variant)
				)}
				{...props}
				aria-describedby={
					hasMessage
						? describedBy(messageId, props['aria-describedby'])
						: props['aria-describedby']
				}
			/>
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
