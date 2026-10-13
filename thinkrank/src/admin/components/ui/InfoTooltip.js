import classnames from 'classnames';
import { Icon } from '@wordpress/components';
import { info } from '@wordpress/icons';
import Tooltip from './Tooltip';

export default function InfoTooltip({
	content,
	position = 'top',
	size = 16,
	className,
	iconClassName,
}) {
	if (!content) {
		return null;
	}

	return (
		<Tooltip
			content={content}
			position={position}
			className={classnames('tr-align-middle', className)}
		>
			<span
				className={classnames(
					'tr-inline-flex tr-items-center tr-justify-center tr-text-tertiary hover:tr-text-secondary tr-cursor-help',
					iconClassName
				)}
				aria-label="More info"
				tabIndex={0}
			>
				<Icon icon={info} size={size} />
			</span>
		</Tooltip>
	);
}
