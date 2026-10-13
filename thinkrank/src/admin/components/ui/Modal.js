import { useEffect, useRef } from '@wordpress/element';
import classnames from 'classnames';

const FOCUSABLE = [
	'a[href]',
	'button:not([disabled])',
	'input:not([disabled])',
	'select:not([disabled])',
	'textarea:not([disabled])',
	'[tabindex]:not([tabindex="-1"])',
].join(', ');

const SIZE_CLASSES = {
	sm: 'tr-max-w-sm',
	md: 'tr-max-w-lg',
	lg: 'tr-max-w-2xl',
	xl: 'tr-max-w-4xl',
};

export default function Modal({
	isOpen,
	onClose,
	title,
	description,
	size = 'md',
	children,
	footer,
}) {
	const dialogRef = useRef(null);
	const previousFocusRef = useRef(null);
	const overlayRef = useRef(null);

	useEffect(() => {
		if (!isOpen) return;

		// Remember what had focus before the modal opened
		previousFocusRef.current = document.activeElement;

		// Move focus to the first focusable element inside the dialog
		const el = dialogRef.current;
		if (el) {
			const first = el.querySelector(FOCUSABLE);
			if (first) first.focus();
		}

		const handleKey = (e) => {
			if (e.key === 'Escape') {
				e.preventDefault();
				onClose();
				return;
			}

			if (e.key === 'Tab' && el) {
				const focusable = [...el.querySelectorAll(FOCUSABLE)];
				if (focusable.length === 0) {
					e.preventDefault();
					return;
				}

				const first = focusable[0];
				const last = focusable[focusable.length - 1];

				if (e.shiftKey) {
					if (document.activeElement === first) {
						e.preventDefault();
						last.focus();
					}
				} else if (document.activeElement === last) {
					e.preventDefault();
					first.focus();
				}
			}
		};

		document.addEventListener('keydown', handleKey);
		return () => {
			document.removeEventListener('keydown', handleKey);
			// Restore focus to the element that opened the modal
			if (previousFocusRef.current) {
				previousFocusRef.current.focus();
			}
		};
	}, [isOpen, onClose]);

	if (!isOpen) return null;

	return (
		<div
			ref={overlayRef}
			role="presentation"
			className="tr-fixed tr-inset-0 tr-z-50 tr-flex tr-items-center tr-justify-center tr-p-4"
			onClick={(e) => e.target === overlayRef.current && onClose()}
		>
			{/* Backdrop */}
			<div
				className="tr-fixed tr-inset-0 tr-bg-ink-2/40 tr-backdrop-blur-sm"
				aria-hidden="true"
			/>

			{/* Dialog */}
			<div
				ref={dialogRef}
				role="dialog"
				aria-modal="true"
				aria-labelledby="tr-modal-title"
				aria-describedby={description ? 'tr-modal-desc' : undefined}
				className={classnames(
					'tr-relative tr-w-full tr-bg-white tr-rounded-xl tr-shadow-lg',
					'tr-animate-[fadeIn_150ms_ease-out]',
					SIZE_CLASSES[size]
				)}
			>
				{/* Header */}
				<div className="tr-flex tr-items-start tr-justify-between tr-p-6 tr-border-b tr-border-secondary">
					<div>
						<h2
							id="tr-modal-title"
							className="tr-text-lg tr-font-semibold tr-text-primary tr-m-0"
						>
							{title}
						</h2>
						{description && (
							<p
								id="tr-modal-desc"
								className="tr-text-sm tr-text-tertiary tr-mt-1 tr-mb-0"
							>
								{description}
							</p>
						)}
					</div>
					<button
						onClick={onClose}
						aria-label="Close dialog"
						className="tr-ml-4 tr-shrink-0 tr-p-1 tr-rounded tr-text-tertiary hover:tr-text-secondary hover:tr-bg-white tr-transition-colors tr-duration-150 focus:tr-outline-none focus:tr-ring-2 focus:tr-ring-brand"
					>
						<svg
							className="tr-w-5 tr-h-5"
							viewBox="0 0 20 20"
							fill="currentColor"
							aria-hidden="true"
						>
							<path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
						</svg>
					</button>
				</div>

				{/* Body */}
				<div className="tr-p-6">{children}</div>

				{/* Footer */}
				{footer && (
					<div className="tr-flex tr-items-center tr-justify-end tr-gap-3 tr-px-6 tr-py-4 tr-border-t tr-border-secondary">
						{footer}
					</div>
				)}
			</div>
		</div>
	);
}
