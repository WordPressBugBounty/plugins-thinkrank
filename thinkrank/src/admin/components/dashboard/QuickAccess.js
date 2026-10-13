/**
 * Dashboard — Quick Access sidebar widget
 *
 * Vertical list of utility links: Essential SEO, AI Tools, Usages, Settings,
 * docs and support. Lives in the right rail of the dashboard.
 */
import { __ } from '@wordpress/i18n';
import { navigate, getPageUrl, isPlainLeftClick } from '../../utils/router';

const RocketIcon = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M14 14l-4-4M8 21l3-3m5-5 3-3M14 6l4 4M5 19l1-3 3-1-2-2-3 1-1 3 2 2Z"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinejoin="round"
		/>
		<path
			d="M9 15c-1-3 0-6 3-9s6-4 9-3c1 3 0 6-3 9s-6 4-9 3Z"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinejoin="round"
		/>
	</svg>
);

const SparkleIcon = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M12 3l1.6 4.2L17.8 9l-4.2 1.6L12 15l-1.6-4.4L6.2 9l4.2-1.8L12 3Z"
			fill="currentColor"
		/>
		<path
			d="M19 14l.7 1.8 1.8.7-1.8.7L19 19l-.7-1.8-1.8-.7 1.8-.7.7-1.8Z"
			fill="currentColor"
			opacity="0.6"
		/>
	</svg>
);

const ChartIcon = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M4 20V10M10 20V4M16 20v-6M22 20H2"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinecap="round"
		/>
	</svg>
);

const TicketIcon = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4V8Z"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinejoin="round"
		/>
		<path
			d="M12 6v12"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeDasharray="2 3"
			strokeLinecap="round"
		/>
	</svg>
);

const DocIcon = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M6 3h7l5 5v12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinejoin="round"
		/>
		<path
			d="M13 3v5h5"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinejoin="round"
		/>
		<path
			d="M8.5 12.5h7M8.5 16h7M8.5 9h2.5"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinecap="round"
		/>
	</svg>
);

const StarIcon = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M12 3l2.6 5.5 6 .9-4.3 4.3 1 6.1L12 17l-5.3 2.8 1-6.1L3.4 9.4l6-.9L12 3Z"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinejoin="round"
		/>
	</svg>
);

const ArrowRightIcon = () => (
	<svg
		width="12"
		height="12"
		viewBox="0 0 14 14"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M3 7h8M8 4l3 3-3 3"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

const WizardIcon = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M15 4l1 2.5L18.5 8 16 9.5 15 12l-1-2.5L11.5 8 14 6.5 15 4Z"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinejoin="round"
		/>
		<path
			d="M6 12l4 4M4 21l9-9"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

const CalendarIcon = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<rect
			x="3"
			y="4.5"
			width="18"
			height="16"
			rx="2.5"
			stroke="currentColor"
			strokeWidth="1.6"
		/>
		<path
			d="M3 9h18M8 3v3M16 3v3"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinecap="round"
		/>
		<path
			d="M8.5 14.5l2.2 2.2 4.3-4.6"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

const ITEMS = [
	{
		label: __('Essential SEO', 'thinkrank'),
		page: 'essential-seo',
		Icon: RocketIcon,
		tone: { bg: '#eef0fd', fg: '#4451FF', border: '#dde0ff' },
	},
	{
		label: __('AI Tools', 'thinkrank'),
		page: 'ai-tools',
		Icon: SparkleIcon,
		tone: { bg: '#f5f0ff', fg: '#7c3aed', border: '#e5d8ff' },
	},
	{
		label: __('Usage Analytics', 'thinkrank'),
		page: 'analytics',
		Icon: ChartIcon,
		tone: { bg: '#ecfdf3', fg: '#027a48', border: '#b9f1cf' },
	},
	{
		label: __('Open Support Ticket', 'thinkrank'),
		href: 'https://wpdeveloper.com/support/new-ticket/',
		external: true,
		Icon: TicketIcon,
		tone: { bg: '#fff8e5', fg: '#b45309', border: '#fde68a' },
	},
	{
		label: __('Documentation', 'thinkrank'),
		href: 'https://thinkrank.ai/docs',
		external: true,
		Icon: DocIcon,
		tone: { bg: '#eff6ff', fg: '#1e40af', border: '#bfdbfe' },
	},
	{
		label: __('Book a Free Call', 'thinkrank'),
		href: 'https://calendly.com/thinkrank/onboarding',
		external: true,
		Icon: CalendarIcon,
		tone: { bg: '#effcf6', fg: '#0f766e', border: '#b8ece0' },
	},
	{
		label: __('Leave a Review', 'thinkrank'),
		href: 'https://wordpress.org/support/plugin/thinkrank/reviews/?rate=5#new-post',
		external: true,
		Icon: StarIcon,
		tone: { bg: '#fef3f2', fg: '#b42318', border: '#fda29b' },
	},
];

const QuickAccess = () => {
	// Surface a "Complete Setup" shortcut at the top of the list while the
	// onboarding Setup Wizard is unfinished. The wizard is a standalone admin
	// page (not an SPA route), so it links out via a plain href.
	const setupWizard = window.thinkrankAdmin?.setupWizard;
	const items =
		setupWizard && !setupWizard.completed && setupWizard.url
			? [
					{
						label: __('Quick Setup', 'thinkrank'),
						href: setupWizard.url,
						Icon: WizardIcon,
						tone: {
							bg: '#eef0fd',
							fg: '#4451FF',
							border: '#dde0ff',
						},
					},
					...ITEMS,
				]
			: ITEMS;

	return (
		<div
			style={{
				padding: '20px',
				borderRadius: '16px',
				background: '#fff',
				border: '1px solid var(--tr-border)',
			}}
		>
			<h3
				style={{
					fontSize: '15px',
					fontWeight: 700,
					color: '#1e1e1e',
					margin: '0 0 12px',
				}}
			>
				{__('Quick Access', 'thinkrank')}
			</h3>
			<div
				style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}
			>
				{items.map(({ label, href, page, external, Icon, tone }) => (
					<a
						key={label}
						href={page ? getPageUrl(page) : href}
						{...(external
							? { target: '_blank', rel: 'noreferrer' }
							: {})}
						onClick={(event) => {
							if (page && isPlainLeftClick(event)) {
								event.preventDefault();
								navigate(page);
							}
						}}
						style={{
							display: 'flex',
							alignItems: 'center',
							gap: '12px',
							padding: '10px 12px',
							borderRadius: '10px',
							background: '#fff',
							color: '#1e1e1e',
							textDecoration: 'none',
							border: '1px solid #eef0f4',
							transition: 'background-color 120ms ease',
						}}
					>
						<span
							style={{
								width: '32px',
								height: '32px',
								borderRadius: '8px',
								background: tone.bg,
								color: tone.fg,
								border: '1px solid ' + tone.border,
								display: 'inline-flex',
								alignItems: 'center',
								justifyContent: 'center',
								flexShrink: 0,
							}}
						>
							<Icon />
						</span>
						<span
							style={{
								flex: 1,
								minWidth: 0,
								fontSize: '13px',
								fontWeight: 600,
								color: '#1e1e1e',
							}}
						>
							{label}
						</span>
						<span
							style={{ color: '#9ca3af', display: 'inline-flex' }}
						>
							<ArrowRightIcon />
						</span>
					</a>
				))}
			</div>
		</div>
	);
};

export default QuickAccess;
