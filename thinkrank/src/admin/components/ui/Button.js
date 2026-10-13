import classnames from 'classnames';

const VARIANT_CLASSES = {
	primary:
		'tr-bg-brand tr-text-white hover:tr-bg-brand tr-border-transparent',
	secondary:
		'tr-bg-white tr-text-secondary tr-border-primary hover:tr-bg-white',
	ghost: 'tr-bg-transparent tr-text-brand tr-border-transparent hover:tr-bg-brand-bg',
	danger: 'tr-bg-danger tr-text-white hover:tr-bg-danger tr-border-transparent',
	ai: 'tr-bg-brand tr-text-white hover:tr-bg-brand tr-border-transparent',
};

const SIZE_CLASSES = {
	sm: 'tr-h-8 tr-px-3 tr-text-xs',
	md: 'tr-h-10 tr-px-4 tr-text-sm',
	lg: 'tr-h-11 tr-px-5 tr-text-base',
};

export default function Button({
	variant = 'primary',
	size = 'md',
	disabled = false,
	loading = false,
	icon: Icon,
	iconPosition = 'left',
	className,
	children,
	...props
}) {
	return (
		<button
			className={classnames(
				'tr-inline-flex tr-items-center tr-justify-center tr-gap-2',
				'tr-rounded-md tr-border tr-font-medium',
				'tr-transition-colors tr-duration-150',
				'focus:tr-outline-none focus:tr-ring-2 focus:tr-ring-brand focus:tr-ring-offset-1',
				'disabled:tr-opacity-50 disabled:tr-cursor-not-allowed',
				VARIANT_CLASSES[variant],
				SIZE_CLASSES[size],
				className
			)}
			disabled={disabled || loading}
			aria-busy={loading || undefined}
			aria-disabled={disabled || loading || undefined}
			{...props}
		>
			{loading && (
				<span
					className="tr-w-4 tr-h-4 tr-shrink-0 tr-rounded-full tr-border-2 tr-animate-spin"
					style={{
						borderColor: 'currentColor',
						borderTopColor: 'transparent',
					}}
					aria-hidden="true"
				/>
			)}
			{!loading && Icon && iconPosition === 'left' && (
				<Icon className="tr-w-4 tr-h-4 tr-shrink-0" />
			)}
			{children}
			{!loading && Icon && iconPosition === 'right' && (
				<Icon className="tr-w-4 tr-h-4 tr-shrink-0" />
			)}
		</button>
	);
}
