import { memo } from '@wordpress/element';
import spinIcon from '../assets/spin.svg';

const Spinner = ({ className = '', style = {}, ...props }) => {
	const mergedClassName = [
		'components-spinner',
		'thinkrank-svg-spinner thinkrank-animate-spin',
		className,
	]
		.filter(Boolean)
		.join(' ');

	const mergedStyle = {
		width: '30px',
		height: '30px',
		display: 'inline-block',
		verticalAlign: 'middle',
		...style,
	};

	return (
		<img
			src={spinIcon}
			alt=""
			aria-hidden="true"
			className={mergedClassName}
			style={mergedStyle}
			{...props}
		/>
	);
};

export default memo(Spinner);
