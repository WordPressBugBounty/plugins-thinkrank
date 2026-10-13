import classnames from 'classnames';

const SIZE_CLASSES = {
	xs: 'tr-text-sm tr-font-semibold tr-leading-snug',
	sm: 'tr-text-base tr-font-semibold tr-leading-snug',
	md: 'tr-text-lg tr-font-semibold tr-leading-snug',
	lg: 'tr-text-xl tr-font-semibold tr-leading-tight',
	xl: 'tr-text-2xl tr-font-semibold tr-leading-tight',
};

const TAG_BY_SIZE = {
	xs: 'h4',
	sm: 'h3',
	md: 'h2',
	lg: 'h2',
	xl: 'h1',
};

export default function Title({
	title,
	tag,
	size = 'md',
	icon = null,
	iconPosition = 'left',
	className,
	children,
	...rest
}) {
	const Tag = tag || TAG_BY_SIZE[size] || 'h2';
	const content = children ?? title;

	if (!icon) {
		return (
			<Tag
				className={classnames(
					'tr-m-0 tr-text-primary',
					SIZE_CLASSES[size] || SIZE_CLASSES.md,
					className
				)}
				{...rest}
			>
				{content}
			</Tag>
		);
	}

	return (
		<div
			className={classnames(
				'tr-flex tr-items-center tr-gap-2',
				className
			)}
		>
			{iconPosition === 'left' && icon}
			<Tag
				className={classnames(
					'tr-m-0 tr-text-primary',
					SIZE_CLASSES[size] || SIZE_CLASSES.md
				)}
				{...rest}
			>
				{content}
			</Tag>
			{iconPosition === 'right' && icon}
		</div>
	);
}
