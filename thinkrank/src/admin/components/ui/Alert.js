import classnames from 'classnames';

const VARIANT = {
	info: {
		wrapper: 'tr-bg-brand-bg tr-border-brand/30',
		icon: 'tr-text-brand',
		title: 'tr-text-brand',
		body: 'tr-text-brand/80',
	},
	success: {
		wrapper: 'tr-bg-success-bg tr-border-success/30',
		icon: 'tr-text-success',
		title: 'tr-text-success',
		body: 'tr-text-success/80',
	},
	warning: {
		wrapper: 'tr-bg-warning-bg tr-border-warning/30',
		icon: 'tr-text-warning',
		title: 'tr-text-warning',
		body: 'tr-text-warning/80',
	},
	error: {
		wrapper: 'tr-bg-danger-bg tr-border-danger/30',
		icon: 'tr-text-danger',
		title: 'tr-text-danger',
		body: 'tr-text-danger/80',
	},
};

const ICONS = {
	info: (
		<svg viewBox="0 0 20 20" fill="currentColor">
			<path
				fillRule="evenodd"
				d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z"
				clipRule="evenodd"
			/>
		</svg>
	),
	success: (
		<svg viewBox="0 0 20 20" fill="currentColor">
			<path
				fillRule="evenodd"
				d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z"
				clipRule="evenodd"
			/>
		</svg>
	),
	warning: (
		<svg viewBox="0 0 20 20" fill="currentColor">
			<path
				fillRule="evenodd"
				d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"
				clipRule="evenodd"
			/>
		</svg>
	),
	error: (
		<svg viewBox="0 0 20 20" fill="currentColor">
			<path
				fillRule="evenodd"
				d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM8.28 7.22a.75.75 0 0 0-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 1 0 1.06 1.06L10 11.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L11.06 10l1.72-1.72a.75.75 0 0 0-1.06-1.06L10 8.94 8.28 7.22Z"
				clipRule="evenodd"
			/>
		</svg>
	),
};

export default function Alert({
	variant = 'info',
	title,
	children,
	onDismiss,
	className,
}) {
	const styles = VARIANT[variant];

	return (
		<div
			role="alert"
			className={classnames(
				'tr-flex tr-gap-3 tr-p-4 tr-rounded-lg tr-border',
				styles.wrapper,
				className
			)}
		>
			<span
				className={classnames(
					'tr-shrink-0 tr-w-5 tr-h-5 tr-mt-0.5',
					styles.icon
				)}
			>
				{ICONS[variant]}
			</span>
			<div className="tr-flex-1 tr-min-w-0">
				{title && (
					<p
						className={classnames(
							'tr-text-sm tr-font-semibold tr-m-0',
							styles.title
						)}
					>
						{title}
					</p>
				)}
				{children && (
					<div
						className={classnames(
							'tr-text-sm tr-mt-0.5',
							styles.body
						)}
					>
						{children}
					</div>
				)}
			</div>
			{onDismiss && (
				<button
					onClick={onDismiss}
					aria-label="Dismiss"
					className={classnames(
						'tr-shrink-0 tr-p-0.5 tr-rounded tr-transition-opacity hover:tr-opacity-70',
						'focus:tr-outline-none focus:tr-ring-2 focus:tr-ring-current',
						styles.icon
					)}
				>
					<svg
						className="tr-w-4 tr-h-4"
						viewBox="0 0 16 16"
						fill="currentColor"
					>
						<path d="M5.28 4.22a.75.75 0 0 0-1.06 1.06L6.94 8l-2.72 2.72a.75.75 0 1 0 1.06 1.06L8 9.06l2.72 2.72a.75.75 0 1 0 1.06-1.06L9.06 8l2.72-2.72a.75.75 0 0 0-1.06-1.06L8 6.94 5.28 4.22Z" />
					</svg>
				</button>
			)}
		</div>
	);
}
