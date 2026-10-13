import classnames from 'classnames';
import EmptyStateIllustration from './EmptyStateIllustration';

export default function EmptyState({
	icon: Icon,
	illustration = true,
	title,
	description,
	action,
	size = 'md',
	className,
}) {
	const SIZE = {
		sm: { wrapper: 'tr-p-6', illo: 100 },
		md: { wrapper: 'tr-p-10', illo: 150 },
		lg: { wrapper: 'tr-p-16', illo: 180 },
	};

	const showIllustration = !Icon && illustration;

	return (
		<div
			className={classnames(
				'tr-flex tr-flex-col tr-items-center tr-justify-center tr-text-center',
				SIZE[size].wrapper,
				className
			)}
		>
			{Icon && (
				<div className="tr-flex tr-items-center tr-justify-center tr-w-16 tr-h-16 tr-rounded-full tr-bg-brand-bg tr-mb-4">
					<Icon className="tr-w-7 tr-h-7 tr-text-brand" />
				</div>
			)}
			{showIllustration && (
				<EmptyStateIllustration
					width={SIZE[size].illo}
					height={SIZE[size].illo}
				/>
			)}
			{title && (
				<h3 className="tr-text-base tr-font-semibold tr-text-primary tr-m-0 tr-mt-4">
					{title}
				</h3>
			)}
			{description && (
				<p className="tr-text-sm tr-text-tertiary tr-mt-1.5 tr-mb-0 tr-max-w-sm">
					{description}
				</p>
			)}
			{action && <div className="tr-mt-5">{action}</div>}
		</div>
	);
}
