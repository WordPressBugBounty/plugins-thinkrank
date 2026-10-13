import classnames from 'classnames';

export default function PageWrapper({
	children,
	className,
	noPadding = false,
}) {
	return (
		<main
			className={classnames(
				'tr-flex-1 tr-overflow-y-auto',
				!noPadding && 'tr-p-4 md:tr-p-6 lg:tr-p-8',
				className
			)}
		>
			{children}
		</main>
	);
}
