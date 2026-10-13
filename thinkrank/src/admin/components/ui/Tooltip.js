import { useState, useRef } from '@wordpress/element';
import classnames from 'classnames';

const POSITION_CLASSES = {
	top: 'tr-bottom-full tr-left-1/2 tr--translate-x-1/2 tr-mb-2',
	bottom: 'tr-top-full tr-left-1/2 tr--translate-x-1/2 tr-mt-2',
	left: 'tr-right-full tr-top-1/2 tr--translate-y-1/2 tr-mr-2',
	right: 'tr-left-full tr-top-1/2 tr--translate-y-1/2 tr-ml-2',
};

let _counter = 0;

export default function Tooltip({
	content,
	position = 'top',
	children,
	className,
}) {
	const [visible, setVisible] = useState(false);
	// Stable unique ID per instance — allocated once on first render
	const tooltipId = useRef(`tr-tooltip-${++_counter}`).current;

	return (
		<span
			className={classnames('tr-relative tr-inline-flex', className)}
			onMouseEnter={() => setVisible(true)}
			onMouseLeave={() => setVisible(false)}
			onFocus={() => setVisible(true)}
			onBlur={() => setVisible(false)}
			aria-describedby={visible && content ? tooltipId : undefined}
		>
			{children}
			{visible && content && (
				<span
					id={tooltipId}
					role="tooltip"
					className={classnames(
						'tr-absolute tr-z-50 tr-w-max tr-max-w-xs',
						'tr-px-2.5 tr-py-1.5 tr-rounded',
						'tr-bg-ink-2 tr-text-white tr-text-xs',
						'tr-pointer-events-none tr-shadow-md',
						'tr-animate-[fadeIn_150ms_ease-out]',
						POSITION_CLASSES[position]
					)}
				>
					{content}
				</span>
			)}
		</span>
	);
}
