/**
 * Dashboard Pro slot — sidebar widget.
 *
 * Toggles between an upsell teaser and the license activation/status card
 * based on whether the Pro plugin is active (`window.thinkrankAdmin.isPro`).
 */
import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';

import proTeaserPlaceholder from '../../../shared/assets/dashboard-pro-teaser-placeholder.svg';
import { isProInstalled } from '../pro/proPlan';

// ── Icons ─────────────────────────────────────────────

const CheckIcon = () => (
	<svg
		width="13"
		height="13"
		viewBox="0 0 14 14"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M3 7.5l3 3 5-6"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

const LockTileIcon = () => (
	<svg
		width="24"
		height="24"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<rect
			x="4"
			y="10"
			width="16"
			height="11"
			rx="2"
			stroke="currentColor"
			strokeWidth="1.8"
		/>
		<path
			d="M8 10V7a4 4 0 0 1 8 0v3"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
		/>
		<rect
			x="11.2"
			y="14"
			width="1.6"
			height="3.5"
			rx="0.8"
			fill="currentColor"
		/>
	</svg>
);

const KeyIcon = () => (
	<svg
		width="18"
		height="18"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<circle cx="8" cy="14" r="4" stroke="currentColor" strokeWidth="1.8" />
		<path
			d="M10.8 11.2L20 2M16 6l3 3M18 4l3 3"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

// ── Pro teaser (sidebar) ──────────────────────────────

const PRO_FEATURES = [
	__('Rank Tracker daily keyword positions from Search Console', 'thinkrank'),
	__('AI Internal Links with one-click insertion', 'thinkrank'),
	__('Custom Schema with display conditions', 'thinkrank'),
	__('Publisher Sitemaps for News & Video', 'thinkrank'),
	__('Branded email reports for your team', 'thinkrank'),
];

const ProTeaserCard = () => (
	<div
		style={{
			borderRadius: '16px',
			overflow: 'hidden',
			background: '#fff',
			border: '1px solid var(--tr-border)',
		}}
	>
		{/* Top illustration band — placeholder, replace proTeaserPlaceholder import to swap. */}
		<img
			src={proTeaserPlaceholder}
			alt=""
			style={{
				display: 'block',
				width: '100%',
				height: '140px',
				objectFit: 'cover',
			}}
		/>

		<div style={{ padding: '20px' }}>
			<h3
				style={{
					fontSize: '16px',
					fontWeight: 700,
					color: '#1e1e1e',
					margin: 0,
					lineHeight: 1.25,
				}}
			>
				{__('Unlock the full ThinkRank toolkit', 'thinkrank')}
			</h3>
			<p
				style={{
					fontSize: '13px',
					color: '#6b7280',
					margin: '6px 0 14px',
					lineHeight: 1.5,
				}}
			>
				{__(
					'Track rankings, build internal links with AI, ship custom schema with display conditions, and white-label your reports.',
					'thinkrank'
				)}
			</p>

			<ul
				style={{
					listStyle: 'none',
					padding: 0,
					margin: '0 0 16px',
					display: 'flex',
					flexDirection: 'column',
					gap: '8px',
				}}
			>
				{PRO_FEATURES.map((feature) => (
					<li
						key={feature}
						style={{
							display: 'flex',
							alignItems: 'flex-start',
							gap: '8px',
							fontSize: '12.5px',
							color: '#374151',
						}}
					>
						<span
							style={{
								width: '18px',
								height: '18px',
								borderRadius: '999px',
								background: '#ecfdf3',
								color: '#027a48',
								display: 'inline-flex',
								alignItems: 'center',
								justifyContent: 'center',
								flexShrink: 0,
								marginTop: '1px',
								border: '1px solid #b9f1cf',
							}}
						>
							<CheckIcon />
						</span>
						<span style={{ lineHeight: 1.45 }}>{feature}</span>
					</li>
				))}
			</ul>

			<a
				href="https://thinkrank.ai/#pricing"
				target="_blank"
				rel="noreferrer"
				style={{
					display: 'flex',
					justifyContent: 'center',
					alignItems: 'center',
					gap: '6px',
					padding: '11px 16px',
					borderRadius: '8px',
					background:
						'linear-gradient(90deg, #4c4cff 58%, #3535b0 118%)',
					boxShadow: '0 1px 4px rgba(68,81,255,0.3)',
					color: '#fff',
					fontSize: '13px',
					fontWeight: 600,
					textDecoration: 'none',
				}}
			>
				{__('Upgrade Now', 'thinkrank')}
			</a>
		</div>
	</div>
);

// ── License card (Pro active) ─────────────────────────

const LICENSE_MASK_LENGTH = 11; // 4 + 11 + 2 = 17 visible characters total.

// ── License activation prompt (license NOT active) ────

const LicenseActivatePrompt = () => {
	const licenseUrl = `${window.location.pathname}?page=thinkrank-license`;
	return (
		<div
			style={{
				padding: '20px',
				borderRadius: '16px',
				background: '#fff',
				border: '1px solid var(--tr-border)',
			}}
		>
			<div
				style={{
					display: 'flex',
					gap: '12px',
					alignItems: 'flex-start',
				}}
			>
				<div
					style={{
						width: '40px',
						height: '40px',
						borderRadius: '10px',
						background: '#fff8e5',
						color: '#b45309',
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center',
						flexShrink: 0,
						border: '1px solid #fde68a',
					}}
				>
					<KeyIcon />
				</div>
				<div style={{ minWidth: 0, flex: 1 }}>
					<h3
						style={{
							fontSize: '15px',
							fontWeight: 700,
							color: '#1e1e1e',
							margin: 0,
							lineHeight: 1.3,
						}}
					>
						{__('Activate your ThinkRank Pro license', 'thinkrank')}
					</h3>
					<p
						style={{
							fontSize: '12.5px',
							color: '#6b7280',
							margin: '6px 0 12px',
							lineHeight: 1.5,
						}}
					>
						{__(
							'Add your license key to unlock automated rank tracking, AI internal links, custom schema controls, and branded reports.',
							'thinkrank'
						)}
					</p>
					<span
						style={{
							display: 'inline-flex',
							alignItems: 'center',
							gap: '4px',
							padding: '4px 10px',
							borderRadius: '999px',
							background: '#fff8e5',
							color: '#b45309',
							border: '1px solid #fde68a',
							fontSize: '11.5px',
							fontWeight: 700,
							whiteSpace: 'nowrap',
						}}
					>
						<span
							aria-hidden="true"
							style={{
								width: '6px',
								height: '6px',
								borderRadius: '999px',
								background: '#b45309',
								display: 'inline-block',
							}}
						/>
						{__('Not activated', 'thinkrank')}
					</span>
				</div>
			</div>

			<div
				aria-hidden="true"
				style={{
					height: '1px',
					background: '#eef0f4',
					margin: '18px 0 16px',
				}}
			/>

			<a
				href={licenseUrl}
				style={{
					display: 'flex',
					justifyContent: 'center',
					alignItems: 'center',
					gap: '6px',
					padding: '11px 16px',
					borderRadius: '8px',
					background: '#1e1e1e',
					color: '#fff',
					fontSize: '13px',
					fontWeight: 600,
					textDecoration: 'none',
				}}
			>
				{__('Activate license', 'thinkrank')}
			</a>
		</div>
	);
};

// ── License unlocked card (license active) ────────────

const LicenseActiveCard = () => {
	const licenseUrl = `${window.location.pathname}?page=thinkrank-license`;
	const keyPrefix = (window.thinkrankProLicense?.keyPrefix || '').trim();
	const keySuffix = (window.thinkrankProLicense?.keySuffix || '').trim();
	const maskDots = '•'.repeat(LICENSE_MASK_LENGTH);

	return (
		<div
			style={{
				padding: '20px',
				borderRadius: '16px',
				background: '#fff',
				border: '1px solid var(--tr-border)',
			}}
		>
			{/* Top: lock tile + title + description (Activated chip below) */}
			<div
				style={{
					display: 'flex',
					gap: '12px',
					alignItems: 'flex-start',
				}}
			>
				<div
					style={{
						width: '40px',
						height: '40px',
						borderRadius: '10px',
						background: '#d1fadf',
						color: '#027a48',
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center',
						flexShrink: 0,
						border: '1px solid #a7f3cf',
					}}
				>
					<LockTileIcon />
				</div>
				<div style={{ minWidth: 0, flex: 1 }}>
					<h3
						style={{
							fontSize: '15px',
							fontWeight: 700,
							color: '#1e1e1e',
							margin: 0,
							lineHeight: 1.3,
						}}
					>
						{__("You've unlocked ThinkRank Pro", 'thinkrank')}
					</h3>
					<p
						style={{
							fontSize: '12.5px',
							color: '#6b7280',
							margin: '6px 0 12px',
							lineHeight: 1.5,
						}}
					>
						{__(
							'All Pro modules are running on this site. Manage your license anytime from the License page.',
							'thinkrank'
						)}
					</p>
					<span
						style={{
							display: 'inline-flex',
							alignItems: 'center',
							gap: '4px',
							padding: '4px 10px',
							borderRadius: '999px',
							background: '#ecfdf3',
							color: '#027a48',
							border: '1px solid #b9f1cf',
							fontSize: '11.5px',
							fontWeight: 700,
							whiteSpace: 'nowrap',
						}}
					>
						<svg
							width="12"
							height="12"
							viewBox="0 0 14 14"
							fill="none"
							xmlns="http://www.w3.org/2000/svg"
							aria-hidden="true"
						>
							<path
								d="M3 7.5L6 10.5L11 4.5"
								stroke="currentColor"
								strokeWidth="2"
								strokeLinecap="round"
								strokeLinejoin="round"
							/>
						</svg>
						{__('Activated', 'thinkrank')}
					</span>
				</div>
			</div>

			{/* Divider */}
			<div
				aria-hidden="true"
				style={{
					height: '1px',
					background: '#eef0f4',
					margin: '18px 0 16px',
				}}
			/>

			{/* License key panel — borderless inner row */}
			<div
				style={{
					padding: '10px',
					borderRadius: '12px',
					background: '#f5f0ff',
					border: '1px solid #e5d8ff',
				}}
			>
				<div
					style={{
						display: 'flex',
						alignItems: 'center',
						gap: '10px',
						padding: '10px 12px',
						borderRadius: '8px',
						background: '#fff',
					}}
				>
					<span
						style={{
							color: '#7c3aed',
							display: 'inline-flex',
							flexShrink: 0,
						}}
					>
						<KeyIcon />
					</span>
					<span
						style={{
							flex: 1,
							minWidth: 0,
							fontSize: '13px',
							color: '#1e1e1e',
							fontFamily:
								'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
							letterSpacing: '0.04em',
							overflow: 'hidden',
							textOverflow: 'ellipsis',
							whiteSpace: 'nowrap',
						}}
						aria-label={__('Masked license key', 'thinkrank')}
					>
						<span style={{ fontWeight: 700 }}>
							{keyPrefix || '••••'}
						</span>
						<span style={{ color: '#9ca3af' }}>{maskDots}</span>
						<span style={{ fontWeight: 700 }}>
							{keySuffix || '••'}
						</span>
					</span>
					<a
						href={licenseUrl}
						style={{
							display: 'inline-flex',
							alignItems: 'center',
							padding: '7px 14px',
							borderRadius: '6px',
							background: '#4451FF',
							color: '#fff',
							fontSize: '12.5px',
							fontWeight: 600,
							textDecoration: 'none',
							whiteSpace: 'nowrap',
						}}
					>
						{__('Manage', 'thinkrank')}
					</a>
				</div>
			</div>
		</div>
	);
};

// ── License card (Pro plugin active) ─────────────────

const LicenseCard = () => {
	// Pro fills this slot with the inline activate/deactivate panel, so a key
	// can be pasted (or released) without leaving the dashboard. The link-based
	// cards below remain the fallback for Pro builds that predate the slot —
	// those still register their own License page for the link to land on.
	const inlineCard = applyFilters('thinkrank.dashboard.licenseCard', null);
	if (inlineCard) {
		return inlineCard;
	}

	// `window.thinkrankProLicense` is localized by the Pro plugin. Note that
	// `wp_localize_script` casts PHP booleans to the strings "1" / "" — so we
	// have to accept either form here. When the data is missing entirely we
	// default to "active" rather than nagging a paying customer (older Pro
	// builds, conditional enqueue mismatches).
	const data = window.thinkrankProLicense;
	const truthy = (v) => v === true || v === 1 || v === '1' || v === 'true';
	const falsy = (v) =>
		v === false || v === 0 || v === '0' || v === '' || v === 'false';
	const isActive = data
		? truthy(data.isActive)
			? true
			: falsy(data.isActive)
				? false
				: true
		: true;
	return isActive ? <LicenseActiveCard /> : <LicenseActivatePrompt />;
};

// ── Export ────────────────────────────────────────────

// Deliberately NOT `thinkrankAdmin.isPro`. That flag means "active AND
// licensed", so on an activated-but-unlicensed install it is false, and gating
// this slot on it showed the upsell teaser to someone who already owns the
// plugin, with nowhere to paste their key (#512). The rule now lives in
// components/pro/proPlan.js so the Pro-gated section tabs ask it the same way
// (#533) rather than each keeping their own copy.

const ProSlot = () => (isProInstalled() ? <LicenseCard /> : <ProTeaserCard />);

export default ProSlot;
