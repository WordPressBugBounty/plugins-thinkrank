/**
 * NavSearch — settings search for the top navigation bar.
 *
 * When on the Essential SEO page, dispatches a CustomEvent so EssentialSEO
 * can switch sections in place. On any other page, SPA-routes to Essential
 * SEO with nav_section/nav_item params — no reload either way.
 *
 * Keyboard: ⌘/ / Ctrl+/ to focus · ↑↓ to move · Enter to go · Esc to close.
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect, useRef, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { getNavigationStructure } from '../essential-seo/SidebarNavigation';
import { navigate } from '../../utils/router';
import { isVisibleInMode, useUiMode } from '../../utils/uiMode';

const IconSearch = () => (
	<svg
		width="13"
		height="13"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2.5"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<circle cx="11" cy="11" r="8" />
		<line x1="21" y1="21" x2="16.65" y2="16.65" />
	</svg>
);

const IconArrow = () => (
	<svg
		width="12"
		height="12"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<polyline points="9 18 15 12 9 6" />
	</svg>
);

/**
 * Wrap the first occurrence of `query` in `text` with a highlighted mark.
 * @param root0
 * @param root0.text
 * @param root0.query
 */
const Highlight = ({ text, query }) => {
	if (!query.trim()) return text;
	const idx = text.toLowerCase().indexOf(query.toLowerCase());
	if (idx === -1) return text;
	return (
		<>
			{text.slice(0, idx)}
			<mark
				style={{
					background: 'rgba(251,191,36,0.35)',
					borderRadius: '2px',
					padding: '0 1px',
					fontWeight: 600,
					color: 'inherit',
				}}
			>
				{text.slice(idx, idx + query.length)}
			</mark>
			{text.slice(idx + query.length)}
		</>
	);
};

const KBD = ({ children }) => (
	<kbd
		style={{
			fontSize: '10px',
			fontFamily: 'sans-serif',
			color: '#94a3b8',
			background: '#f1f5f9',
			border: '1px solid #e2e8f0',
			borderRadius: '3px',
			padding: '1px 5px',
			lineHeight: 1,
		}}
	>
		{children}
	</kbd>
);

const isMac = () => {
	try {
		return navigator.platform.toUpperCase().includes('MAC');
	} catch {
		return false;
	}
};

export default function NavSearch({ currentPage }) {
	const [query, setQuery] = useState('');
	const [results, setResults] = useState([]);
	const [isOpen, setIsOpen] = useState(false);
	const [cursor, setCursor] = useState(-1);

	const inputRef = useRef(null);
	const containerRef = useRef(null);

	const { mode } = useUiMode();

	/* Flatten the navigation, without what Simple mode hides from the menu (#730). */
	const allItems = useMemo(() => {
		const flat = [];
		getNavigationStructure().forEach((section) => {
			section.items.forEach((item) => {
				if (!isVisibleInMode(item, mode)) {
					return;
				}
				flat.push({
					section: section.id,
					sectionTitle: section.title,
					item: item.id,
					itemTitle: item.title,
					pro: item.pro || false,
				});
			});
		});
		return flat;
	}, [mode]);

	/* Filter results whenever the query changes. */
	useEffect(() => {
		const q = query.trim().toLowerCase();
		if (!q) {
			setResults([]);
			setIsOpen(false);
			return;
		}
		const filtered = allItems.filter(
			(r) =>
				r.sectionTitle.toLowerCase().includes(q) ||
				r.itemTitle.toLowerCase().includes(q)
		);
		setResults(filtered.slice(0, 8));
		setIsOpen(filtered.length > 0);
		setCursor(-1);
	}, [query, allItems]);

	/* Close on click outside. */
	useEffect(() => {
		const handler = (e) => {
			if (
				containerRef.current &&
				!containerRef.current.contains(e.target)
			) {
				dismiss();
			}
		};
		document.addEventListener('mousedown', handler);
		return () => document.removeEventListener('mousedown', handler);
	}, []); // eslint-disable-line react-hooks/exhaustive-deps

	/* ⌘/ / Ctrl+/ global shortcut to focus the input. */
	useEffect(() => {
		const handler = (e) => {
			if ((e.metaKey || e.ctrlKey) && e.key === '/') {
				e.preventDefault();
				inputRef.current?.focus();
				inputRef.current?.select();
			}
		};
		document.addEventListener('keydown', handler);
		return () => document.removeEventListener('keydown', handler);
	}, []);

	const dismiss = () => {
		setQuery('');
		setIsOpen(false);
		setCursor(-1);
	};

	const goTo = (result) => {
		dismiss();
		if (currentPage === 'essential-seo') {
			window.dispatchEvent(
				new CustomEvent('thinkrank:nav-search', {
					detail: { section: result.section, item: result.item },
				})
			);
		} else {
			// SPA route to Essential SEO; the page reads nav_section/nav_item
			// from the URL on mount.
			navigate('essential-seo', {
				nav_section: result.section,
				nav_item: result.item,
			});
		}
	};

	const handleKeyDown = (e) => {
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			setCursor((prev) => Math.min(prev + 1, results.length - 1));
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			setCursor((prev) => Math.max(prev - 1, 0));
		} else if (e.key === 'Enter' && cursor >= 0 && results[cursor]) {
			e.preventDefault();
			goTo(results[cursor]);
		} else if (e.key === 'Escape') {
			dismiss();
			inputRef.current?.blur();
		}
	};

	const isPro = window.thinkrankAdmin?.isPro;

	return (
		<div
			ref={containerRef}
			className="tr-relative tr-hidden sm:tr-block tr-shrink-0"
		>
			{/* ── Input ──────────────────────────────────────────────────── */}
			<div className="tr-relative">
				<span
					className="tr-absolute tr-left-2.5 tr-top-1/2 -tr-translate-y-1/2 tr-pointer-events-none tr-text-tertiary"
					aria-hidden="true"
				>
					<IconSearch />
				</span>

				<input
					ref={inputRef}
					type="text"
					role="combobox"
					aria-expanded={isOpen}
					aria-autocomplete="list"
					aria-label={__('Search settings', 'thinkrank')}
					value={query}
					onChange={(e) => setQuery(e.target.value)}
					onKeyDown={handleKeyDown}
					onFocus={() => {
						if (query.trim()) setIsOpen(results.length > 0);
					}}
					placeholder={__('Search settings…', 'thinkrank')}
					className="tr-w-44 lg:tr-w-56 tr-h-7 tr-pl-8 tr-pr-14 tr-text-xs tr-rounded-md tr-bg-white tr-text-secondary placeholder:tr-text-tertiary focus:tr-bg-white tr-transition-all tr-duration-150"
					style={{
						margin: 0,
						border: '1px solid #e2e8f0',
						outline: 'none',
						boxShadow: 'none',
					}}
				/>

				{/* ⌘/ hint — hidden while typing */}
				{!query && (
					<span className="tr-absolute tr-right-2 tr-top-1/2 -tr-translate-y-1/2 tr-pointer-events-none tr-hidden lg:tr-flex tr-items-center tr-gap-0.5">
						<KBD>{isMac() ? '⌘' : 'Ctrl'}</KBD>
						<KBD>/</KBD>
					</span>
				)}
			</div>

			{/* ── Dropdown ────────────────────────────────────────────────── */}
			{isOpen && results.length > 0 && (
				<div
					role="listbox"
					aria-label={__('Search results', 'thinkrank')}
					className="tr-absolute tr-top-full tr-left-0 tr-mt-1.5 tr-w-72 tr-bg-white tr-border tr-border-secondary tr-rounded-lg tr-shadow-lg tr-overflow-hidden"
					style={{ zIndex: 99999 }}
				>
					<ul className="tr-list-none tr-m-0 tr-p-1 tr-flex tr-flex-col tr-gap-px">
						{results.map((result, i) => {
							const isActive = i === cursor;
							return (
								<li
									key={`${result.section}-${result.item}`}
									role="option"
									aria-selected={isActive}
								>
									<button
										/* Use mousedown so blur on the input doesn't close the
                                           dropdown before the click registers. */
										onMouseDown={(e) => {
											e.preventDefault();
											goTo(result);
										}}
										onMouseEnter={() => setCursor(i)}
										className="tr-w-full tr-text-left tr-px-3 tr-py-2 tr-flex tr-items-center tr-gap-2.5 tr-rounded-md tr-border-0 tr-cursor-pointer tr-transition-colors tr-duration-100"
										style={{
											background: isActive
												? '#ECFDF3'
												: 'transparent',
											outline: 'none',
										}}
									>
										<span className="tr-text-tertiary tr-shrink-0">
											<IconArrow />
										</span>
										<div className="tr-min-w-0 tr-flex-1">
											<p className="tr-m-0 tr-text-[11px] tr-text-tertiary tr-truncate tr-leading-none tr-mb-0.5">
												<Highlight
													text={result.sectionTitle}
													query={query}
												/>
											</p>
											<p className="tr-m-0 tr-text-sm tr-font-medium tr-text-secondary tr-truncate tr-leading-snug">
												<Highlight
													text={result.itemTitle}
													query={query}
												/>
											</p>
										</div>
										{result.pro && !isPro && (
											<span className="tr-inline-flex tr-items-center tr-px-1.5 tr-py-0.5 tr-rounded tr-text-[10px] tr-font-semibold tr-bg-brand-bg tr-text-brand tr-shrink-0">
												PRO
											</span>
										)}
									</button>
								</li>
							);
						})}
					</ul>

					{/* Footer hint bar */}
					<div className="tr-px-3 tr-py-2 tr-border-t tr-border-secondary tr-flex tr-items-center tr-gap-3">
						<span className="tr-text-[10px] tr-text-tertiary tr-flex tr-items-center tr-gap-1">
							<KBD>↑↓</KBD> {__('navigate', 'thinkrank')}
						</span>
						<span className="tr-text-[10px] tr-text-tertiary tr-flex tr-items-center tr-gap-1">
							<KBD>↵</KBD> {__('go', 'thinkrank')}
						</span>
						<span className="tr-text-[10px] tr-text-tertiary tr-flex tr-items-center tr-gap-1">
							<KBD>Esc</KBD> {__('close', 'thinkrank')}
						</span>
					</div>
				</div>
			)}
		</div>
	);
}
