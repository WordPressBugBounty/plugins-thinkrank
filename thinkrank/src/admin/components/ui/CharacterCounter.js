import classnames from 'classnames';

export default function CharacterCounter({ value = '', max, className }) {
	const length = typeof value === 'string' ? value.length : 0;
	const over = max && length > max;

	return (
		<span
			className={classnames(
				'tr-text-xs tr-leading-4 tr-font-normal tr-text-tertiary',
				className
			)}
			aria-live="polite"
		>
			<span
				className={classnames({
					'tr-text-danger': over,
				})}
			>
				{length}
			</span>
			{max ? `/${max}` : null}
		</span>
	);
}
