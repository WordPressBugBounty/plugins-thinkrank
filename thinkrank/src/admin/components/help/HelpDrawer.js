/**
 * Help drawer: the documentation for the screen you are on, without leaving it.
 *
 * Everything it shows comes from `utils/docsMap.js`, which ships with the
 * bundle. Three consequences worth knowing:
 *
 * - It opens instantly, with no spinner and no request.
 * - It works identically when thinkrank.ai is unreachable, because there is
 *   nothing to be unreachable.
 * - Nothing about this site, this screen or the reader's query leaves the
 *   browser until they click through to an article.
 *
 * The search is over article titles, summaries and paths, not full text. For
 * full text the drawer hands off to the docs site, which indexes it with
 * Pagefind.
 *
 * @since 2.19.0
 */

import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import classnames from 'classnames';

import {
	DOC_ARTICLES,
	DOC_SECTIONS,
	DOCS_BASE,
	docsForScreen,
	docsUrl,
	screenKey,
	searchDocs,
} from '../../utils/docsMap';

const FOCUSABLE = [
	'a[href]',
	'button:not([disabled])',
	'input:not([disabled])',
	'[tabindex]:not([tabindex="-1"])',
].join(', ');

const SUPPORT_URL = 'https://wpdeveloper.com/support/new-ticket/';

const IconHelp = () => (
	<svg
		className="tr-w-4 tr-h-4"
		viewBox="0 0 20 20"
		fill="currentColor"
		aria-hidden="true"
	>
		<path
			fillRule="evenodd"
			d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16ZM8.2 7.3c.3-.6.9-1 1.8-1 1.1 0 1.9.7 1.9 1.6 0 .7-.4 1.1-1.1 1.6-.7.5-1.3 1-1.3 2v.3h1.5v-.2c0-.6.3-.9 1-1.4.9-.6 1.5-1.3 1.5-2.4 0-1.6-1.4-2.8-3.4-2.8-1.6 0-2.9.8-3.3 2.1l1.4.6ZM10 15.3a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"
			clipRule="evenodd"
		/>
	</svg>
);

const IconExternal = () => (
	<svg
		className="tr-w-3.5 tr-h-3.5 tr-shrink-0"
		viewBox="0 0 20 20"
		fill="currentColor"
		aria-hidden="true"
	>
		<path d="M11 3h6v6h-1.5V5.56l-7.72 7.72-1.06-1.06L14.44 4.5H11V3Z" />
		<path d="M4 6h4v1.5H5.5v7h7V12H14v4H4V6Z" />
	</svg>
);

const IconClose = () => (
	<svg
		className="tr-w-5 tr-h-5"
		viewBox="0 0 20 20"
		fill="currentColor"
		aria-hidden="true"
	>
		<path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
	</svg>
);

/**
 * One article row.
 *
 * @param {Object} props         Component props.
 * @param {Object} props.article Article from the docs map.
 * @return {JSX.Element} The row.
 */
export const ArticleLink = ({ article }) => (
	<a
		href={docsUrl(article.path)}
		target="_blank"
		rel="noopener noreferrer"
		className="tr-flex tr-flex-col tr-gap-1 tr-px-3 tr-py-2.5 tr-rounded-md tr-no-underline tr-border tr-border-transparent hover:tr-border-secondary hover:tr-bg-line-2 tr-transition-colors"
	>
		<span className="tr-flex tr-items-center tr-gap-1.5 tr-text-sm tr-font-medium tr-text-primary">
			{article.title}
			<IconExternal />
		</span>
		{article.summary && (
			<span className="tr-text-xs tr-text-tertiary tr-leading-relaxed">
				{article.summary}
			</span>
		)}
	</a>
);

/**
 * The drawer itself.
 *
 * @param {Object}   props             Component props.
 * @param {boolean}  props.isOpen      Whether the drawer is open.
 * @param {Function} props.onClose     Close handler.
 * @param {string}   props.screen      Key from screenKey().
 * @param {string}   props.screenLabel Human name of the current screen.
 * @return {JSX.Element|null} The drawer, or null when closed.
 */
export const HelpDrawer = ({ isOpen, onClose, screen, screenLabel }) => {
	const [query, setQuery] = useState('');
	const panelRef = useRef(null);
	const searchRef = useRef(null);
	const restoreFocusRef = useRef(null);

	const contextual = useMemo(() => docsForScreen(screen), [screen]);
	// Named from the docs section the matched articles belong to, not from the
	// top-level page: on Schema Settings the page is "Essential SEO", which is
	// true and useless. Derived rather than duplicated, so a remapped screen
	// renames its own heading.
	const contextLabel =
		DOC_SECTIONS[contextual[0]?.section] || screenLabel || '';
	const results = useMemo(() => searchDocs(query), [query]);
	const searching = query.trim().length > 0;

	useEffect(() => {
		if (!isOpen) {
			return undefined;
		}

		restoreFocusRef.current = document.activeElement;
		// The search field, not the close button: the reader opened this to
		// look something up, and one keystroke should start that.
		if (searchRef.current) {
			searchRef.current.focus();
		}

		const onKeyDown = (event) => {
			if (event.key === 'Escape') {
				event.preventDefault();
				onClose();
				return;
			}

			if (event.key !== 'Tab' || !panelRef.current) {
				return;
			}

			const focusable = [...panelRef.current.querySelectorAll(FOCUSABLE)];
			if (!focusable.length) {
				return;
			}

			const first = focusable[0];
			const last = focusable[focusable.length - 1];

			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		};

		document.addEventListener('keydown', onKeyDown);

		return () => {
			document.removeEventListener('keydown', onKeyDown);
			if (restoreFocusRef.current) {
				restoreFocusRef.current.focus();
			}
		};
	}, [isOpen, onClose]);

	// A fresh query each time it opens: the previous screen's search is not
	// what the reader is asking about now.
	useEffect(() => {
		if (isOpen) {
			setQuery('');
		}
	}, [isOpen]);

	if (!isOpen) {
		return null;
	}

	const ticketUrl = `${SUPPORT_URL}?${new URLSearchParams({
		// Context the first support reply always has to ask for.
		thinkrank_screen: screenLabel || screen || 'unknown',
		thinkrank_version: window.thinkrankAdmin?.version || '',
		thinkrank_pro: window.thinkrankAdmin?.isPro ? 'yes' : 'no',
	}).toString()}`;

	return (
		<div
			className="tr-fixed tr-inset-0 tr-z-[100000] tr-flex tr-justify-end"
			role="presentation"
		>
			<div
				className="tr-absolute tr-inset-0 tr-bg-ink-2/30"
				aria-hidden="true"
				onClick={onClose}
			/>

			<div
				ref={panelRef}
				role="dialog"
				aria-modal="true"
				aria-labelledby="tr-help-drawer-title"
				className="tr-relative tr-flex tr-flex-col tr-w-full sm:tr-w-[420px] tr-h-full tr-bg-white tr-shadow-lg"
			>
				<div className="tr-flex tr-items-start tr-justify-between tr-gap-3 tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div>
						<h2
							id="tr-help-drawer-title"
							className="tr-text-base tr-font-semibold tr-text-primary tr-m-0"
						>
							{__('Help', 'thinkrank')}
						</h2>
						<p className="tr-text-xs tr-text-tertiary tr-mt-1 tr-mb-0">
							{__(
								'Documentation for this screen. Nothing you type here leaves your site.',
								'thinkrank'
							)}
						</p>
					</div>
					<button
						type="button"
						onClick={onClose}
						aria-label={__('Close help', 'thinkrank')}
						className="tr-shrink-0 tr-p-1 tr-rounded tr-bg-transparent tr-border-0 tr-cursor-pointer tr-text-tertiary hover:tr-text-secondary focus:tr-outline-none focus-visible:tr-ring-2 focus-visible:tr-ring-brand"
					>
						<IconClose />
					</button>
				</div>

				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<label
						htmlFor="tr-help-search"
						className="screen-reader-text"
					>
						{__('Search the documentation', 'thinkrank')}
					</label>
					<input
						ref={searchRef}
						id="tr-help-search"
						type="search"
						value={query}
						onChange={(event) => setQuery(event.target.value)}
						placeholder={__(
							'Search the documentation',
							'thinkrank'
						)}
						className="tr-w-full tr-h-10 tr-rounded-md tr-bg-white tr-text-sm tr-text-primary tr-border-[1.5px] tr-border-primary tr-px-3 focus:tr-outline-none focus-visible:tr-border-brand"
					/>
				</div>

				<div className="tr-flex-1 tr-overflow-y-auto tr-px-2 tr-py-3">
					{searching ? (
						<section aria-labelledby="tr-help-results-heading">
							<h3
								id="tr-help-results-heading"
								className="tr-px-3 tr-text-xs tr-font-semibold tr-uppercase tr-tracking-wide tr-text-secondary tr-mt-0 tr-mb-2"
							>
								{sprintf(
									/* translators: %d: number of matching documentation articles. */
									__('%d matching articles', 'thinkrank'),
									results.length
								)}
							</h3>
							{results.length ? (
								<div className="tr-flex tr-flex-col">
									{results.map((article) => (
										<ArticleLink
											key={article.path}
											article={article}
										/>
									))}
								</div>
							) : (
								<p className="tr-px-3 tr-text-sm tr-text-tertiary">
									{__(
										'No article title or summary matches that. The full text of every page is searchable on the docs site.',
										'thinkrank'
									)}
								</p>
							)}
						</section>
					) : (
						<>
							{contextual.length > 0 && (
								<section
									aria-labelledby="tr-help-screen-heading"
									className="tr-mb-4"
								>
									<h3
										id="tr-help-screen-heading"
										className="tr-px-3 tr-text-xs tr-font-semibold tr-uppercase tr-tracking-wide tr-text-secondary tr-mt-0 tr-mb-2"
									>
										{contextLabel
											? sprintf(
													/* translators: %s: name of the documentation section covering the current screen, e.g. "Schema". */
													__(
														'Docs for %s',
														'thinkrank'
													),
													contextLabel
												)
											: __(
													'Docs for this screen',
													'thinkrank'
												)}
									</h3>
									<div className="tr-flex tr-flex-col">
										{contextual.map((article) => (
											<ArticleLink
												key={article.path}
												article={article}
											/>
										))}
									</div>
								</section>
							)}

							<section aria-labelledby="tr-help-browse-heading">
								<h3
									id="tr-help-browse-heading"
									className="tr-px-3 tr-text-xs tr-font-semibold tr-uppercase tr-tracking-wide tr-text-secondary tr-mt-0 tr-mb-2"
								>
									{__('Browse all docs', 'thinkrank')}
								</h3>
								<div className="tr-flex tr-flex-wrap tr-gap-1.5 tr-px-3">
									{Object.entries(DOC_SECTIONS)
										.filter(([slug]) => slug)
										.map(([slug, title]) => (
											<a
												key={slug}
												href={docsUrl(`/docs/${slug}/`)}
												target="_blank"
												rel="noopener noreferrer"
												className="tr-inline-flex tr-items-center tr-px-2.5 tr-py-1 tr-rounded-full tr-border tr-border-secondary tr-bg-white tr-text-xs tr-text-secondary tr-no-underline hover:tr-bg-line-2 tr-transition-colors"
											>
												{title}
											</a>
										))}
								</div>
							</section>
						</>
					)}
				</div>

				<div className="tr-flex tr-items-center tr-justify-between tr-gap-3 tr-px-5 tr-py-4 tr-border-t tr-border-secondary">
					<a
						href={`${DOCS_BASE}/docs/`}
						target="_blank"
						rel="noopener noreferrer"
						className="tr-inline-flex tr-items-center tr-gap-1.5 tr-text-xs tr-font-semibold tr-text-brand tr-no-underline"
					>
						{sprintf(
							/* translators: %d: total number of documentation articles. */
							__('Search all %d articles', 'thinkrank'),
							DOC_ARTICLES.length
						)}
						<IconExternal />
					</a>
					<a
						href={ticketUrl}
						target="_blank"
						rel="noopener noreferrer"
						className="tr-inline-flex tr-items-center tr-gap-1.5 tr-text-xs tr-text-secondary tr-no-underline hover:tr-text-primary"
					>
						{__('Still stuck? Open a ticket', 'thinkrank')}
						<IconExternal />
					</a>
				</div>
			</div>
		</div>
	);
};

/**
 * The header button, the `?` shortcut, and the drawer they open.
 *
 * @param {Object} props             Component props.
 * @param {string} props.currentPage SPA page id.
 * @param {string} props.screenLabel Human name of the current screen.
 * @return {JSX.Element} Button plus drawer.
 */
export default function HelpLauncher({ currentPage, screenLabel }) {
	const [isOpen, setIsOpen] = useState(false);
	const [screen, setScreen] = useState(() =>
		screenKey(
			typeof window === 'undefined' ? '' : window.location.search,
			currentPage
		)
	);

	const open = useCallback(() => {
		// Read the sub-section at open time rather than tracking it: the SPA
		// rewrites nav_section and nav_item as the reader moves around, and the
		// only moment the answer matters is now.
		setScreen(screenKey(window.location.search, currentPage));
		setIsOpen(true);
	}, [currentPage]);

	const close = useCallback(() => setIsOpen(false), []);

	useEffect(() => {
		const onKeyDown = (event) => {
			if (
				event.key !== '?' ||
				event.metaKey ||
				event.ctrlKey ||
				event.altKey
			) {
				return;
			}

			// Not while the reader is typing: `?` is a character in a meta
			// description as much as it is a shortcut.
			const target = event.target;
			const tag = target?.tagName;
			if (
				tag === 'INPUT' ||
				tag === 'TEXTAREA' ||
				tag === 'SELECT' ||
				target?.isContentEditable
			) {
				return;
			}

			event.preventDefault();
			open();
		};

		document.addEventListener('keydown', onKeyDown);
		return () => document.removeEventListener('keydown', onKeyDown);
	}, [open]);

	return (
		<>
			<button
				type="button"
				onClick={open}
				title={__('Help (?)', 'thinkrank')}
				aria-label={__('Help', 'thinkrank')}
				aria-expanded={isOpen}
				className={classnames(
					'tr-flex tr-items-center tr-justify-center tr-w-7 tr-h-7 tr-rounded-md tr-border-0 tr-bg-transparent tr-cursor-pointer',
					'tr-text-tertiary hover:tr-text-secondary hover:tr-bg-white tr-transition-colors',
					'focus:tr-outline-none focus-visible:tr-ring-2 focus-visible:tr-ring-brand'
				)}
			>
				<IconHelp />
			</button>
			<HelpDrawer
				isOpen={isOpen}
				onClose={close}
				screen={screen}
				screenLabel={screenLabel}
			/>
		</>
	);
}
