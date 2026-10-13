import classnames from 'classnames';

const SIZE_CLASSES = {
	xs: 'tr-text-xs',
	sm: 'tr-text-sm',
	md: 'tr-text-base',
};

export default function Label({
	tag: Tag = 'label',
	size = 'sm',
	required = false,
	className,
	children,
	...rest
}) {
	return (
		<Tag
			className={classnames(
				SIZE_CLASSES[size] || SIZE_CLASSES.sm,
				'tr-font-medium tr-text-primary tr-leading-snug',
				className
			)}
			{...rest}
		>
			{children}
			{required && (
				<span className="tr-text-danger tr-ml-0.5" aria-hidden="true">
					*
				</span>
			)}
		</Tag>
	);
}
