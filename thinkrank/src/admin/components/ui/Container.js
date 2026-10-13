import classnames from 'classnames';

const DIRECTION_CLASSES = {
	row: 'tr-flex-row',
	column: 'tr-flex-col',
};

const GAP_CLASSES = {
	0: 'tr-gap-0',
	0.5: 'tr-gap-0.5',
	1: 'tr-gap-1',
	1.5: 'tr-gap-1.5',
	2: 'tr-gap-2',
	2.5: 'tr-gap-2.5',
	3: 'tr-gap-3',
	4: 'tr-gap-4',
	5: 'tr-gap-5',
	6: 'tr-gap-6',
	7: 'tr-gap-7',
	8: 'tr-gap-8',
	10: 'tr-gap-10',
};

const ALIGN_CLASSES = {
	start: 'tr-items-start',
	center: 'tr-items-center',
	end: 'tr-items-end',
	stretch: 'tr-items-stretch',
	baseline: 'tr-items-baseline',
};

const JUSTIFY_CLASSES = {
	start: 'tr-justify-start',
	center: 'tr-justify-center',
	end: 'tr-justify-end',
	between: 'tr-justify-between',
	around: 'tr-justify-around',
};

const resolveGap = (gap) => {
	if (gap === undefined || gap === null || gap === '') {
		return '';
	}
	const key = typeof gap === 'string' ? Number(gap) : gap;
	return GAP_CLASSES[key] ?? '';
};

export default function Container({
	as: Tag = 'div',
	direction = 'row',
	gap,
	align,
	justify,
	className,
	children,
	...rest
}) {
	return (
		<Tag
			className={classnames(
				'tr-flex',
				DIRECTION_CLASSES[direction] || DIRECTION_CLASSES.row,
				resolveGap(gap),
				align && ALIGN_CLASSES[align],
				justify && JUSTIFY_CLASSES[justify],
				className
			)}
			{...rest}
		>
			{children}
		</Tag>
	);
}
