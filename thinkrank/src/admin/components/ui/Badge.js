import classnames from 'classnames';

const VARIANT_CLASSES = {
	good: 'tr-bg-success-bg tr-text-success',
	warning: 'tr-bg-warning-bg tr-text-warning',
	poor: 'tr-bg-danger-bg tr-text-danger',
	pro: 'tr-bg-brand-bg tr-text-brand',
	info: 'tr-bg-brand-bg tr-text-brand',
	neutral: 'tr-bg-white tr-text-secondary',
};

export default function Badge({ variant = 'neutral', className, children }) {
	return (
		<span
			className={classnames(
				'tr-inline-flex tr-items-center tr-px-2 tr-py-0.5',
				'tr-rounded-full tr-text-xs tr-font-medium',
				VARIANT_CLASSES[variant],
				className
			)}
		>
			{children}
		</span>
	);
}
