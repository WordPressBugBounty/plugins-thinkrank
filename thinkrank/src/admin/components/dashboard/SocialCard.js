/**
 * Dashboard — Follow Us / social links card
 *
 * Compact row of branded icon buttons linking out to ThinkRank's social
 * channels. Lives in the right rail of the dashboard, below Quick Access.
 */
import { __ } from '@wordpress/i18n';

const CommunityIcon = () => (
	<svg
		width="18"
		height="18"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<circle cx="9" cy="8" r="3" stroke="currentColor" strokeWidth="1.6" />
		<path
			d="M3.5 19c0-2.8 2.4-4.5 5.5-4.5s5.5 1.7 5.5 4.5"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinecap="round"
		/>
		<path
			d="M16 5.2a2.6 2.6 0 010 5.1M17.5 19c0-2.3-1.2-3.8-3-4.4"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinecap="round"
		/>
	</svg>
);

const FacebookIcon = () => (
	<svg
		width="18"
		height="18"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M14 8.5V6.8c0-.7.2-1.3 1.3-1.3H16V3.1C15.7 3 14.9 3 14 3c-1.9 0-3.2 1.2-3.2 3.4v2.1H8.5V11h2.3v8h2.9v-8h2.1l.4-2.5H13.7z"
			fill="currentColor"
		/>
	</svg>
);

const InstagramIcon = () => (
	<svg
		width="18"
		height="18"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<rect
			x="3.5"
			y="3.5"
			width="17"
			height="17"
			rx="5"
			stroke="currentColor"
			strokeWidth="1.6"
		/>
		<circle cx="12" cy="12" r="4" stroke="currentColor" strokeWidth="1.6" />
		<circle cx="17" cy="7" r="1.1" fill="currentColor" />
	</svg>
);

const XIcon = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24h-6.66l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231 5.451-6.231zm-1.161 17.52h1.833L7.084 4.126H5.117l11.966 15.644z"
			fill="currentColor"
		/>
	</svg>
);

const LinkedInIcon = () => (
	<svg
		width="18"
		height="18"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M6.94 5.5a1.94 1.94 0 11-3.88 0 1.94 1.94 0 013.88 0zM3.3 8.98h3.35V21H3.3V8.98zm5.48 0h3.21v1.64h.05c.45-.85 1.54-1.75 3.17-1.75 3.39 0 4.02 2.23 4.02 5.13V21h-3.35v-5.33c0-1.27-.02-2.9-1.77-2.9-1.77 0-2.04 1.38-2.04 2.81V21H8.78V8.98z"
			fill="currentColor"
		/>
	</svg>
);

const CHANNELS = [
	{
		label: __('Join our Community', 'thinkrank'),
		href: 'https://www.facebook.com/groups/thinkrankcommunity',
		Icon: CommunityIcon,
		tone: { bg: '#eef0fd', fg: '#4451FF', border: '#dde0ff' },
	},
	{
		label: __('Follow us on Facebook', 'thinkrank'),
		href: 'https://www.facebook.com/thinkrank',
		Icon: FacebookIcon,
		tone: { bg: '#eff4ff', fg: '#1877f2', border: '#c9dcff' },
	},
	{
		label: __('Follow us on Instagram', 'thinkrank'),
		href: 'https://www.instagram.com/thinkrank/',
		Icon: InstagramIcon,
		tone: { bg: '#fdf2f8', fg: '#c13584', border: '#fbcfe8' },
	},
	{
		label: __('Follow us on X', 'thinkrank'),
		href: 'https://x.com/ThinkRankSEO',
		Icon: XIcon,
		tone: { bg: '#f3f4f6', fg: '#111827', border: '#d1d5db' },
	},
	{
		label: __('Follow us on LinkedIn', 'thinkrank'),
		href: 'https://www.linkedin.com/company/thinkrank/',
		Icon: LinkedInIcon,
		tone: { bg: '#eef6fc', fg: '#0a66c2', border: '#c7e0f4' },
	},
];

const SocialCard = () => (
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
				margin: '0 0 4px',
			}}
		>
			{__('Follow Us', 'thinkrank')}
		</h3>
		<p
			style={{
				fontSize: '13px',
				color: '#6b7280',
				margin: '0 0 14px',
			}}
		>
			{__('Join the community and stay in the loop.', 'thinkrank')}
		</p>
		<div style={{ display: 'flex', flexWrap: 'wrap', gap: '10px' }}>
			{CHANNELS.map(({ label, href, Icon, tone }) => (
				<a
					key={label}
					href={href}
					target="_blank"
					rel="noreferrer"
					title={label}
					aria-label={label}
					style={{
						width: '44px',
						height: '44px',
						borderRadius: '10px',
						background: tone.bg,
						color: tone.fg,
						border: '1px solid ' + tone.border,
						display: 'inline-flex',
						alignItems: 'center',
						justifyContent: 'center',
						textDecoration: 'none',
						transition: 'transform 120ms ease',
					}}
				>
					<Icon />
				</a>
			))}
		</div>
	</div>
);

export default SocialCard;
