import classnames from 'classnames';

const SIZE_CLASSES = {
	12: 'tr-text-xs',
	14: 'tr-text-sm',
	16: 'tr-text-base',
	18: 'tr-text-lg',
};

const WEIGHT_CLASSES = {
	400: 'tr-font-normal',
	500: 'tr-font-medium',
	600: 'tr-font-semibold',
	700: 'tr-font-bold',
};

const COLOR_CLASSES = {
	primary: 'tr-text-primary',
	secondary: 'tr-text-secondary',
	tertiary: 'tr-text-tertiary',
	help: 'tr-text-tertiary',
	muted: 'tr-text-tertiary',
	error: 'tr-text-danger',
	success: 'tr-text-success',
	warning: 'tr-text-warning',
	inherit: '',
};

export default function Text({
	as: Tag = 'p',
	size = 14,
	weight = 400,
	color = 'primary',
	className,
	children,
	...rest
}) {
	const sizeKey = typeof size === 'string' ? Number(size) : size;
	const weightKey = typeof weight === 'string' ? Number(weight) : weight;

	return (
		<Tag
			className={classnames(
				'tr-m-0',
				SIZE_CLASSES[sizeKey] || SIZE_CLASSES[14],
				WEIGHT_CLASSES[weightKey] || WEIGHT_CLASSES[400],
				COLOR_CLASSES[color] ?? COLOR_CLASSES.primary,
				className
			)}
			{...rest}
		>
			{children}
		</Tag>
	);
}
