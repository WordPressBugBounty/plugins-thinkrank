/**
 * Dashboard — Welcome card
 *
 * Greets the user and links to learning resources. Sits at the top of the
 * dashboard's main column.
 */
import { __ } from '@wordpress/i18n';

// Animated hero (Remotion render, 760x540 animated WebP, ~360 KB): an assistant
// asks for SEO fixes, the request travels over MCP, and the SEO score climbs.
// Shared with the setup wizard's first step.
import heroAnimation from '../../../shared/assets/thinkrank-hero.webp';

const ArrowRightIcon = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 14 14"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M3 7h8M8 4l3 3-3 3"
			stroke="currentColor"
			strokeWidth="1.7"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

const WelcomeCard = () => {
	const displayName = window.thinkrankAdmin?.displayName || '';

	return (
		<div
			style={{
				display: 'flex',
				alignItems: 'center',
				justifyContent: 'space-between',
				gap: '24px',
				padding: '28px 32px',
				borderRadius: '16px',
				background: '#fff',
				border: '1px solid var(--tr-border)',
				flexWrap: 'wrap',
			}}
		>
			<div style={{ flex: 1, minWidth: '280px' }}>
				<h2
					style={{
						fontSize: '22px',
						fontWeight: 700,
						color: '#1e1e1e',
						margin: 0,
						lineHeight: 1.25,
					}}
				>
					{displayName
						? `${__('Welcome back,', 'thinkrank')} ${displayName}!`
						: __('Welcome to ThinkRank!', 'thinkrank')}
				</h2>
				<p
					style={{
						fontSize: '14px',
						color: '#6b7280',
						margin: '8px 0 20px',
						lineHeight: 1.55,
						maxWidth: '560px',
					}}
				>
					{__(
						"Do your SEO right here, or let Claude or ChatGPT do it for you through ThinkRank's MCP server. Generate metadata with your own AI key, plan content, add schema and watch your SEO score update as you work.",
						'thinkrank'
					)}
				</p>
				<a
					href="https://thinkrank.ai/docs"
					target="_blank"
					rel="noreferrer"
					style={{
						display: 'inline-flex',
						alignItems: 'center',
						gap: '8px',
						padding: '10px 18px',
						borderRadius: '8px',
						background: '#1e1e1e',
						color: '#fff',
						fontSize: '13px',
						fontWeight: 600,
						textDecoration: 'none',
					}}
				>
					{__('Learn More', 'thinkrank')}
					<ArrowRightIcon />
				</a>
			</div>

			<img
				src={heroAnimation}
				alt=""
				width="228"
				height="162"
				style={{
					width: '228px',
					height: '162px',
					objectFit: 'contain',
					borderRadius: '12px',
					border: '1px solid #e0e7ff',
					flexShrink: 0,
					display: 'block',
				}}
			/>
		</div>
	);
};

export default WelcomeCard;
