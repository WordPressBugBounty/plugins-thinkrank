import classnames from 'classnames';
import PageHeader from './PageHeader';

export default function PageContentWrapper({
	children,
	title,
	description,
	icon,
	secondaryButton,
	infoTooltip = null,
	afterDescription = null,
	className,
}) {
	if (!children) {
		return null;
	}

	return (
		<div
			className={classnames(
				'tr-flex tr-flex-col tr-justify-start tr-gap-7 tr-w-full tr-h-full',
				className
			)}
		>
			<PageHeader
				title={title}
				description={description}
				icon={icon}
				secondaryButton={secondaryButton}
				infoTooltip={infoTooltip}
				afterDescription={afterDescription}
			/>
			{children}
		</div>
	);
}
