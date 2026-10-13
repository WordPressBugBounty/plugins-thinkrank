/**
 * ThinkRank Metabox Drawer
 *
 * UI shell that revamps the SEO metabox into a logo launcher + bottom drawer.
 *
 * - In the block editor the ThinkRank logo button is mounted into the editor
 *   header (`.edit-post-header` / `.editor-header` settings area), next to
 *   Save/Publish. In the classic editor (no such header) it falls back to a
 *   fixed button near the top of the page.
 * - Clicking it slides a full-width panel up from the bottom of the page.
 * - The launcher and panel are portaled (header slot / <body>) and wrapped in
 *   `.tr-root` so the Tailwind (`tr-*`) utilities — scoped to `.tr-root` — apply.
 *
 * This is purely presentational: all SEO settings/fields live in `children`
 * and remain unchanged. The actual hidden form inputs stay in the metabox
 * container (inside #post) and are intentionally NOT moved here.
 */

import { useEffect, useState, createPortal } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ScoreRing from '../../admin/components/ui/ScoreRing';
import { scoreBadgeStyle } from '@utils/scoreScheme';
import ThinkRankLogo from './ThinkRankLogo';

// Editor-header settings containers across WP versions (newest first).
// (Elementor has no stable, non-React header slot — its editor uses the fixed
// floating launcher below instead of injecting into Elementor chrome.)
const HEADER_SELECTORS = [
	'.editor-header__settings',
	'.edit-post-header__settings',
	'.editor-header',
	'.edit-post-header',
];

/**
 * Resolve the editor-header mount node. The header renders asynchronously, so
 * poll briefly until it appears; returns null in the classic editor.
 *
 * @param  enabled
 * @return {HTMLElement|null} A dedicated slot inside the header, or null.
 */
const useHeaderSlot = (enabled = true) => {
	const [slot, setSlot] = useState(null);

	useEffect(() => {
		if (!enabled) return undefined;
		let cancelled = false;
		let tries = 0;
		let timer = null;
		let slotEl = null;

		const findHeader = () => {
			for (const sel of HEADER_SELECTORS) {
				const node = document.querySelector(sel);
				if (node) return node;
			}
			return null;
		};

		const attach = () => {
			if (cancelled) return true;
			const header = findHeader();
			if (!header) return false;
			slotEl = document.createElement('div');
			slotEl.className = 'thinkrank-header-slot';
			// Place before the existing settings buttons (Save/Publish/options).
			header.insertBefore(slotEl, header.firstChild);
			setSlot(slotEl);
			return true;
		};

		if (!attach()) {
			timer = setInterval(() => {
				tries += 1;
				if (attach() || tries > 40) clearInterval(timer);
			}, 250);
		}

		return () => {
			cancelled = true;
			if (timer) clearInterval(timer);
			if (slotEl && slotEl.parentNode)
				slotEl.parentNode.removeChild(slotEl);
		};
	}, [enabled]);

	return slot;
};

const MetaboxDrawer = ({
	open,
	onToggle,
	onClose,
	onSave = null,
	isSaving = false,
	score = null,
	title = __('ThinkRank SEO', 'thinkrank'),
	// When true, render no launcher at all — the host triggers open/close
	// externally (e.g. Elementor's top-bar action).
	hideLauncher = false,
	children,
}) => {
	const headerSlot = useHeaderSlot(!hideLauncher);
	// Block-editor screens get this body class. Used to avoid flashing the fixed
	// fallback launcher while we wait for the (async) editor header to render.
	const isBlockEditor =
		typeof document !== 'undefined' &&
		document.body.classList.contains('block-editor-page');
	const hasScore =
		score !== null && score !== undefined && !Number.isNaN(Number(score));
	const scoreLabel = hasScore ? Math.round(Number(score)) : null;

	// Close on Escape, and lock body scroll while the drawer is open.
	useEffect(() => {
		if (!open) return undefined;

		const onKeyDown = (e) => {
			if (e.key === 'Escape') onClose();
		};
		document.addEventListener('keydown', onKeyDown);

		const prevOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';

		return () => {
			document.removeEventListener('keydown', onKeyDown);
			document.body.style.overflow = prevOverflow;
		};
	}, [open, onClose]);

	// Only render a score badge once the post has actually been analyzed.
	const scoreBadge = (extra = '') =>
		hasScore ? (
			<span
				className={`tr-inline-flex tr-items-center tr-justify-center tr-min-w-[1.5rem] tr-h-5 tr-px-1.5 tr-rounded-full tr-text-xs tr-font-bold ${extra}`}
				style={scoreBadgeStyle(Number(score))}
			>
				{scoreLabel}
			</span>
		) : null;

	// Launcher button — compact, sits inside the editor header.
	const headerLauncher = (
		<div className="tr-root tr-font-sans">
			<button
				type="button"
				onClick={onToggle}
				aria-expanded={open}
				aria-label={__('ThinkRank SEO', 'thinkrank')}
				title={__('ThinkRank SEO', 'thinkrank')}
				className="thinkrank-header-launcher tr-inline-flex tr-items-center tr-gap-1.5 tr-h-9 tr-px-2 tr-rounded-md tr-bg-transparent hover:tr-bg-brand-bg tr-border-0 tr-cursor-pointer tr-transition-colors"
			>
				<ThinkRankLogo size={24} />
				<span className="tr-text-sm tr-font-semibold tr-text-primary">
					{__('ThinkRank SEO', 'thinkrank')}
				</span>
				{scoreBadge()}
			</button>
		</div>
	);

	// Fixed fallback launcher — used only when there's no editor header (classic).
	const fixedLauncher = (
		<div className="tr-root tr-font-sans">
			<button
				type="button"
				onClick={onToggle}
				aria-expanded={open}
				aria-label={__('Open ThinkRank SEO', 'thinkrank')}
				className={`thinkrank-metabox-launcher tr-fixed tr-z-[99990] tr-inline-flex tr-items-center tr-gap-2 tr-h-10 tr-pl-1.5 tr-pr-3 tr-rounded-full tr-bg-white tr-border tr-border-secondary tr-shadow-md hover:tr-shadow-lg tr-cursor-pointer tr-transition-all ${
					open
						? 'tr-opacity-0 tr-pointer-events-none'
						: 'tr-opacity-100'
				}`}
			>
				<ThinkRankLogo size={28} />
				<span className="tr-text-sm tr-font-semibold tr-text-primary">
					{__('ThinkRank SEO', 'thinkrank')}
				</span>
				{scoreBadge()}
			</button>
		</div>
	);

	// Bottom drawer + backdrop.
	const drawer = (
		<div className="tr-root tr-font-sans">
			{/* Backdrop */}
			<div
				onClick={onClose}
				className={`tr-fixed tr-inset-0 tr-z-[99998] tr-bg-black/40 tr-transition-opacity tr-duration-200 ${
					open
						? 'tr-opacity-100'
						: 'tr-opacity-0 tr-pointer-events-none'
				}`}
				aria-hidden="true"
			/>

			{/* Panel */}
			<div
				role="dialog"
				aria-modal="true"
				aria-label={title}
				className={`thinkrank-metabox-drawer tr-fixed tr-inset-x-0 tr-bottom-0 tr-z-[99999] tr-flex tr-flex-col tr-bg-white tr-shadow-2xl tr-transition-transform tr-duration-300 tr-ease-out ${
					open ? 'tr-translate-y-0' : 'tr-translate-y-full'
				}`}
			>
				{/* Header */}
				<div className="tr-flex tr-items-center tr-justify-between tr-gap-3 tr-px-5 tr-py-2.5 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2.5">
						<ThinkRankLogo size={28} />
						<h2 className="tr-text-base tr-font-semibold tr-text-primary tr-m-0">
							{title}
						</h2>
					</div>
					<div className="tr-flex tr-items-center tr-gap-3">
						{onSave && (
							<button
								type="button"
								onClick={onSave}
								disabled={isSaving}
								// Styled to mirror the native WP primary
								// Save/Publish button (accent bg, 2px radius,
								// 36px tall) using the same WP CSS variables.
								style={{
									display: 'inline-flex',
									alignItems: 'center',
									justifyContent: 'center',
									whiteSpace: 'nowrap',
									boxSizing: 'border-box',
									height: '36px',
									padding: '4px 12px',
									borderRadius: '2px',
									border: 0,
									outline: '1px solid transparent',
									background:
										'var(--wp-components-color-accent, var(--wp-admin-theme-color, #3858e9))',
									color: 'var(--wp-components-color-accent-inverted, #fff)',
									fontSize: '13px',
									fontWeight: 600,
									lineHeight: 1,
									textDecoration: 'none',
									textShadow: 'none',
									cursor: isSaving ? 'default' : 'pointer',
									opacity: isSaving ? 0.7 : 1,
								}}
							>
								{isSaving
									? __('Saving…', 'thinkrank')
									: __('Save', 'thinkrank')}
							</button>
						)}
						{hasScore && (
							<ScoreRing
								score={Math.round(Number(score))}
								size="sm"
							/>
						)}
						<button
							type="button"
							onClick={onClose}
							aria-label={__('Close', 'thinkrank')}
							className="tr-inline-flex tr-items-center tr-justify-center tr-w-8 tr-h-8 tr-rounded-full tr-border-0 tr-bg-transparent hover:tr-bg-brand-bg tr-text-tertiary hover:tr-text-primary tr-cursor-pointer tr-transition-colors"
						>
							<svg
								width="18"
								height="18"
								viewBox="0 0 24 24"
								fill="none"
								aria-hidden="true"
							>
								<path
									d="M6 6l12 12M18 6L6 18"
									stroke="currentColor"
									strokeWidth="2"
									strokeLinecap="round"
								/>
							</svg>
						</button>
					</div>
				</div>

				{/* Body — fills the remaining drawer height and does NOT scroll
				    itself. The child owns the layout and scrolls its own region
				    (e.g. a fixed left nav + a scrollable content column). */}
				<div className="tr-flex-1 tr-min-h-0 tr-overflow-hidden">
					{children}
				</div>
			</div>
		</div>
	);

	// Launcher placement:
	// - header slot ready  → mount in the editor header
	// - classic editor     → fixed fallback button
	// - block editor, slot not ready yet → render nothing (prevents the
	//   fixed-button flash/blink on reload before the header mounts)
	let launcher = null;
	if (hideLauncher) {
		// Host renders its own launcher (e.g. Elementor top bar) → none here.
		launcher = null;
	} else if (headerSlot) {
		launcher = createPortal(headerLauncher, headerSlot);
	} else if (!isBlockEditor) {
		// Classic editor and Elementor (no usable header slot) → fixed launcher.
		launcher = createPortal(fixedLauncher, document.body);
	}

	return (
		<>
			{launcher}
			{createPortal(drawer, document.body)}
		</>
	);
};

export default MetaboxDrawer;
