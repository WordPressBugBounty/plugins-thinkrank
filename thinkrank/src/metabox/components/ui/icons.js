/**
 * Metabox icon set — small, consistent stroke icons (currentColor).
 *
 * Each accepts a `className` (default `tr-w-4 tr-h-4`) so callers control size
 * and colour via Tailwind utilities.
 */

const base = (className) => ({
	className,
	width: '1em',
	height: '1em',
	viewBox: '0 0 24 24',
	fill: 'none',
	'aria-hidden': 'true',
});

export const IconDocument = ({ className = 'tr-w-4 tr-h-4' }) => (
	<svg {...base(className)}>
		<path
			d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"
			stroke="currentColor"
			strokeWidth="1.7"
			strokeLinejoin="round"
		/>
		<path
			d="M14 3v5h5M8.5 13h7M8.5 16.5h7"
			stroke="currentColor"
			strokeWidth="1.7"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

export const IconSparkle = ({ className = 'tr-w-4 tr-h-4' }) => (
	<svg {...base(className)}>
		<path
			d="M12 3l1.7 4.8a2 2 0 0 0 1.2 1.2L19.7 11l-4.8 1.7a2 2 0 0 0-1.2 1.2L12 18.7l-1.7-4.8a2 2 0 0 0-1.2-1.2L4.3 11l4.8-1.7a2 2 0 0 0 1.2-1.2L12 3Z"
			fill="currentColor"
		/>
	</svg>
);

export const IconGauge = ({ className = 'tr-w-4 tr-h-4' }) => (
	<svg {...base(className)}>
		<path
			d="M4 19a8 8 0 1 1 16 0"
			stroke="currentColor"
			strokeWidth="1.7"
			strokeLinecap="round"
		/>
		<path
			d="M12 19l4-5"
			stroke="currentColor"
			strokeWidth="1.7"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

export const IconCode = ({ className = 'tr-w-4 tr-h-4' }) => (
	<svg {...base(className)}>
		<path
			d="M9 8l-4 4 4 4M15 8l4 4-4 4"
			stroke="currentColor"
			strokeWidth="1.7"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

export const IconShare = ({ className = 'tr-w-4 tr-h-4' }) => (
	<svg {...base(className)}>
		<circle
			cx="6"
			cy="12"
			r="2.5"
			stroke="currentColor"
			strokeWidth="1.7"
		/>
		<circle
			cx="18"
			cy="6"
			r="2.5"
			stroke="currentColor"
			strokeWidth="1.7"
		/>
		<circle
			cx="18"
			cy="18"
			r="2.5"
			stroke="currentColor"
			strokeWidth="1.7"
		/>
		<path
			d="M8.2 10.8l7.6-3.6M8.2 13.2l7.6 3.6"
			stroke="currentColor"
			strokeWidth="1.7"
			strokeLinecap="round"
		/>
	</svg>
);

export const IconSliders = ({ className = 'tr-w-4 tr-h-4' }) => (
	<svg {...base(className)}>
		<path
			d="M4 7h10M18 7h2M4 17h2M10 17h10"
			stroke="currentColor"
			strokeWidth="1.7"
			strokeLinecap="round"
		/>
		<circle cx="16" cy="7" r="2" stroke="currentColor" strokeWidth="1.7" />
		<circle cx="8" cy="17" r="2" stroke="currentColor" strokeWidth="1.7" />
	</svg>
);

export const IconLink = ({ className = 'tr-w-4 tr-h-4' }) => (
	<svg {...base(className)}>
		<path
			d="M10 13a3 3 0 0 0 4.5.4l2.5-2.5a3 3 0 0 0-4.3-4.3L11.5 8"
			stroke="currentColor"
			strokeWidth="1.7"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
		<path
			d="M14 11a3 3 0 0 0-4.5-.4L7 13.1a3 3 0 0 0 4.3 4.3l1.2-1.2"
			stroke="currentColor"
			strokeWidth="1.7"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

export const IconRobot = ({ className = 'tr-w-4 tr-h-4' }) => (
	<svg {...base(className)}>
		<rect
			x="4"
			y="8"
			width="16"
			height="11"
			rx="3"
			stroke="currentColor"
			strokeWidth="1.7"
		/>
		<path
			d="M12 5v3"
			stroke="currentColor"
			strokeWidth="1.7"
			strokeLinecap="round"
		/>
		<circle cx="12" cy="4" r="1.3" fill="currentColor" />
		<path
			d="M9 13h.01M15 13h.01"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
		/>
	</svg>
);

export const IconRefresh = ({ className = 'tr-w-4 tr-h-4' }) => (
	<svg {...base(className)}>
		<path
			d="M21 12a9 9 0 1 1-2.64-6.36"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
		/>
		<path
			d="M21 4v5h-5"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);
