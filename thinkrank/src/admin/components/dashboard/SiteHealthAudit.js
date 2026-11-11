/**
 * Dashboard — Site SEO Audit
 *
 * Renders the site-wide SEO Analyzer: one aggregate 0–100 score + letter grade,
 * per-category subtotals, and every check grouped by category with an
 * expandable "how to fix". Data comes from the backend analyzer engine via
 * GET /thinkrank/v1/seo-analyzer (cached) and POST /thinkrank/v1/seo-analyzer/run
 * (force a fresh run). No Google connection required.
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Input, SkeletonCard } from '../ui';
import { navigate, getPageUrl, isPlainLeftClick } from '../../utils/router';
import notify from '../../utils/notify';

// Deep link to the full analyzer page (Essential SEO › SEO Analyzer › Site Audit).
const DETAILS_PARAMS = { nav_section: 'seo-analyzer', nav_item: 'site-audit' };

// ── Icons ─────────────────────────────────────────────

const RefreshIcon = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M21 12a9 9 0 1 1-3-6.7M21 4v5h-5"
			stroke="currentColor"
			strokeWidth="1.7"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

const ArrowRightIcon = ({ size = 14 }) => (
	<svg
		width={size}
		height={size}
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

const ChevronIcon = ({ open }) => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 14 14"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
		style={{
			transition: 'transform 0.15s ease',
			transform: open ? 'rotate(90deg)' : 'none',
			flexShrink: 0,
		}}
	>
		<path
			d="M5 3l4 4-4 4"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

// ── Status → colors ───────────────────────────────────

const STATUS_STYLES = {
	failed: {
		label: __('Failed', 'thinkrank'),
		bg: '#fef2f2',
		fg: '#b42318',
		border: '#fecdca',
	},
	warning: {
		label: __('Warning', 'thinkrank'),
		bg: '#fff8e5',
		fg: '#b45309',
		border: '#fde68a',
	},
	passed: {
		label: __('Passed', 'thinkrank'),
		bg: '#ecfdf3',
		fg: '#027a48',
		border: '#b9f1cf',
	},
	// A check about something the site switched off: listed, never scored
	// (#1288), so it reads as neutral rather than as a warning.
	not_applicable: {
		label: __('Not scored', 'thinkrank'),
		bg: '#f3f4f6',
		fg: '#4b5563',
		border: '#e5e7eb',
	},
	// A check the site owner chose not to count (#1289). Its real status is
	// kept on the check, but the row is shown as left out of the score.
	ignored: {
		label: __('Ignored', 'thinkrank'),
		bg: '#f3f4f6',
		fg: '#4b5563',
		border: '#e5e7eb',
	},
};

const StatusPill = ({ status }) => {
	const style = STATUS_STYLES[status] || STATUS_STYLES.warning;
	return (
		<span
			style={{
				display: 'inline-flex',
				alignItems: 'center',
				fontSize: '11px',
				fontWeight: 700,
				padding: '3px 10px',
				borderRadius: '999px',
				background: style.bg,
				color: style.fg,
				border: '1px solid ' + style.border,
				whiteSpace: 'nowrap',
			}}
		>
			{style.label}
		</span>
	);
};

// Score band → gauge color.
const scoreColor = (score) => {
	if (score >= 80) return '#027a48';
	if (score >= 50) return '#b45309';
	return '#b42318';
};

// Score band → one-line headline.
const scoreHeadline = (score) => {
	if (score >= 80) {
		return __('Your site is in good SEO shape.', 'thinkrank');
	}
	if (score >= 50) {
		return __('Your site has some SEO issues to address.', 'thinkrank');
	}
	return __('Your site needs SEO attention.', 'thinkrank');
};

// "Last analyzed …" for the header. The analysis is cached, so saying when it
// was generated is the difference between a stale number and a silent lie.
const lastAnalyzedLabel = (generatedAt) => {
	if (!generatedAt) return '';
	const then = new Date(generatedAt);
	if (Number.isNaN(then.getTime())) return '';

	const seconds = Math.max(
		0,
		Math.round((Date.now() - then.getTime()) / 1000)
	);
	if (seconds < 60) return __('Last analyzed just now', 'thinkrank');

	const minutes = Math.round(seconds / 60);
	if (minutes < 60) {
		/* translators: %d: minutes since the analysis ran. */
		return sprintf(__('Last analyzed %d min ago', 'thinkrank'), minutes);
	}

	const hours = Math.round(minutes / 60);
	/* translators: %d: hours since the analysis ran. */
	return sprintf(__('Last analyzed %d h ago', 'thinkrank'), hours);
};

// ── Score gauge ───────────────────────────────────────

const ScoreGauge = ({ score, grade }) => {
	const size = 108;
	const stroke = 10;
	const radius = (size - stroke) / 2;
	const circumference = 2 * Math.PI * radius;
	const clamped = Math.max(0, Math.min(100, score));
	const offset = circumference - (clamped / 100) * circumference;
	const color = scoreColor(clamped);
	// translators: %s: letter grade.
	const gradeLabel = sprintf(__('Grade %s', 'thinkrank'), grade);

	return (
		<div
			style={{
				position: 'relative',
				width: size,
				height: size,
				flexShrink: 0,
			}}
		>
			<svg width={size} height={size} aria-hidden="true">
				<circle
					cx={size / 2}
					cy={size / 2}
					r={radius}
					fill="none"
					stroke="#eef0f4"
					strokeWidth={stroke}
				/>
				<circle
					cx={size / 2}
					cy={size / 2}
					r={radius}
					fill="none"
					stroke={color}
					strokeWidth={stroke}
					strokeLinecap="round"
					strokeDasharray={circumference}
					strokeDashoffset={offset}
					transform={`rotate(-90 ${size / 2} ${size / 2})`}
					style={{ transition: 'stroke-dashoffset 0.5s ease' }}
				/>
			</svg>
			<div
				style={{
					position: 'absolute',
					inset: 0,
					display: 'flex',
					flexDirection: 'column',
					alignItems: 'center',
					justifyContent: 'center',
				}}
			>
				<span
					style={{
						fontSize: '26px',
						fontWeight: 800,
						color,
						lineHeight: 1,
					}}
				>
					{clamped}
				</span>
				<span
					style={{
						fontSize: '11px',
						fontWeight: 700,
						color: '#6b7280',
						marginTop: '2px',
					}}
				>
					{gradeLabel}
				</span>
			</div>
		</div>
	);
};

// ── Posts a finding is about ──────────────────────────

const AFFECTED_PER_PAGE = 10;

const pagerButtonStyle = (disabled) => ({
	background: '#fff',
	color: disabled ? '#9ca3af' : '#374151',
	border: '1px solid #e5e7eb',
	borderRadius: '6px',
	padding: '4px 10px',
	fontSize: '12px',
	fontWeight: 600,
	cursor: disabled ? 'default' : 'pointer',
});

// Small secondary action on a check row (Ignore, Include again, Cancel).
const textButtonStyle = (disabled) => ({
	background: 'none',
	border: 'none',
	padding: '4px 6px',
	font: 'inherit',
	fontSize: '12px',
	fontWeight: 600,
	color: disabled ? '#9ca3af' : '#6b7280',
	cursor: disabled ? 'default' : 'pointer',
	whiteSpace: 'nowrap',
});

// Keep a click or key press on a control inside a row from toggling the row.
const stopRowToggle = (event) => event.stopPropagation();

// Markup a rendered-page finding carries, per page. The agent-accessibility
// checks put the offending elements in `value` as
// `[{ url, unnamed: [{ tag, snippet }], hidden: [...], dangling: [...] }]`,
// and a page with a hundred links is no use named without them.
export const MARKUP_SNIPPETS_SHOWN = 3;

const MARKUP_BUCKETS = ['unnamed', 'hidden', 'dangling'];

const markupBucketLabel = (bucket) => {
	if ('hidden' === bucket) {
		return __('Inside aria-hidden', 'thinkrank');
	}
	if ('dangling' === bucket) {
		return __('aria-labelledby target missing', 'thinkrank');
	}
	return '';
};

/**
 * Index a check's `value` by page URL, keeping only snippet findings.
 *
 * @param {*} value A check's `value`; anything but the findings shape yields
 *                  an empty map.
 * @return {Map<string, Array<{bucket: string, tag: string, snippet: string}>>} Snippets by URL.
 */
export const markupByUrl = (value) => {
	const byUrl = new Map();

	if (!Array.isArray(value)) {
		return byUrl;
	}

	value.forEach((row) => {
		if (!row || 'object' !== typeof row || !row.url) {
			return;
		}

		const items = [];
		MARKUP_BUCKETS.forEach((bucket) => {
			(Array.isArray(row[bucket]) ? row[bucket] : []).forEach((item) => {
				if (item && 'string' === typeof item.snippet && item.snippet) {
					items.push({
						bucket,
						tag: String(item.tag || ''),
						snippet: item.snippet,
					});
				}
			});
		});

		if (items.length) {
			byUrl.set(row.url, [...(byUrl.get(row.url) || []), ...items]);
		}
	});

	return byUrl;
};

// The offending markup under a page row, as code. React escapes the text, so
// a snippet is shown as written and never rendered as HTML.
const MarkupSnippets = ({ items }) => {
	const shown = items.slice(0, MARKUP_SNIPPETS_SHOWN);
	const more = items.length - shown.length;

	return (
		<ul
			className="thinkrank-markup-snippets"
			style={{ listStyle: 'none', margin: '6px 0 0', padding: 0 }}
		>
			{shown.map((item, i) => (
				<li
					key={i}
					style={{
						display: 'flex',
						alignItems: 'baseline',
						gap: '6px',
						marginTop: i === 0 ? 0 : '4px',
						minWidth: 0,
					}}
				>
					{markupBucketLabel(item.bucket) && (
						<span
							style={{
								flexShrink: 0,
								fontSize: '11px',
								color: '#92400e',
							}}
						>
							{markupBucketLabel(item.bucket)}
						</span>
					)}
					<code
						style={{
							display: 'block',
							minWidth: 0,
							fontSize: '11.5px',
							color: '#374151',
							background: '#f3f4f6',
							borderRadius: '4px',
							padding: '2px 6px',
							overflowWrap: 'anywhere',
							whiteSpace: 'pre-wrap',
						}}
					>
						{item.snippet}
					</code>
				</li>
			))}
			{more > 0 && (
				<li
					style={{
						marginTop: '4px',
						fontSize: '11.5px',
						color: '#6b7280',
					}}
				>
					{sprintf(
						/* translators: %d: number of further elements not shown. */
						__('+%d more', 'thinkrank'),
						more
					)}
				</li>
			)}
		</ul>
	);
};

// A count ("7 of your 8 pages…") leaves the user to guess which pages it
// means. Name them, ten at a time, each linking to its editor. `total` can be
// larger than the list when the server capped it. `findings` is the check's
// `value`; when it carries markup for a listed page, that markup is shown
// under the page.
const AffectedPosts = ({ posts, total, findings }) => {
	const markup = useMemo(() => markupByUrl(findings), [findings]);
	const count = Math.max(posts.length, total || 0);
	const [page, setPage] = useState(1);
	const pageCount = Math.max(1, Math.ceil(posts.length / AFFECTED_PER_PAGE));
	// A re-analysis can shrink the list under the page the user was on.
	const current = Math.min(page, pageCount);
	const start = (current - 1) * AFFECTED_PER_PAGE;
	const visible = posts.slice(start, start + AFFECTED_PER_PAGE);

	return (
		<div style={{ marginTop: '12px' }}>
			<strong style={{ fontWeight: 700 }}>
				{sprintf(
					/* translators: %d: number of items that fail this check. */
					_n(
						'%d item to fix:',
						'%d items to fix:',
						count,
						'thinkrank'
					),
					count
				)}
			</strong>
			{count > posts.length && (
				<span style={{ marginLeft: '6px', color: '#6b7280' }}>
					{sprintf(
						/* translators: %d: number of items listed. */
						__('The most recent %d are listed.', 'thinkrank'),
						posts.length
					)}
				</span>
			)}
			<ul
				style={{
					listStyle: 'none',
					margin: '6px 0 0',
					padding: 0,
					background: '#fff',
					border: '1px solid #eef0f4',
					borderRadius: '6px',
				}}
			>
				{visible.map((post, i) => (
					<li
						// A finding about a rendered URL has no post behind it,
						// so the id is 0 for every one of them and would not be
						// a key at all.
						key={post.id || post.url || i}
						style={{
							padding: '7px 10px',
							borderTop: i === 0 ? 'none' : '1px solid #eef0f4',
						}}
					>
						<div
							style={{
								display: 'flex',
								alignItems: 'center',
								gap: '10px',
							}}
						>
							<a
								// Same reason: with no post there is no editor to
								// open, so the page itself is the useful link.
								href={post.edit_url || post.url}
								target="_blank"
								rel="noopener noreferrer"
								style={{
									flex: 1,
									minWidth: 0,
									color: '#4451FF',
									fontWeight: 600,
									textDecoration: 'none',
									overflow: 'hidden',
									textOverflow: 'ellipsis',
									whiteSpace: 'nowrap',
								}}
							>
								{post.title}
							</a>
							{post.type && (
								<span
									style={{
										fontSize: '11.5px',
										color: '#6b7280',
									}}
								>
									{post.type}
								</span>
							)}
							{post.url && (
								<a
									href={post.url}
									target="_blank"
									rel="noopener noreferrer"
									style={{
										fontSize: '11.5px',
										color: '#6b7280',
										textDecoration: 'none',
									}}
								>
									{__('View', 'thinkrank')}
								</a>
							)}
						</div>
						{post.url && markup.has(post.url) && (
							<MarkupSnippets items={markup.get(post.url)} />
						)}
					</li>
				))}
			</ul>
			{pageCount > 1 && (
				<div
					style={{
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'space-between',
						gap: '8px',
						flexWrap: 'wrap',
						marginTop: '8px',
						fontSize: '12px',
						color: '#6b7280',
					}}
				>
					<span>
						{sprintf(
							/* translators: 1: first item shown, 2: last item shown, 3: total items. */
							__('Showing %1$d–%2$d of %3$d', 'thinkrank'),
							start + 1,
							start + visible.length,
							posts.length
						)}
					</span>
					<span
						style={{
							display: 'inline-flex',
							alignItems: 'center',
							gap: '6px',
						}}
					>
						<button
							type="button"
							onClick={() => setPage(current - 1)}
							disabled={current <= 1}
							style={pagerButtonStyle(current <= 1)}
						>
							{__('Previous', 'thinkrank')}
						</button>
						<span>
							{sprintf(
								/* translators: 1: current page, 2: total pages. */
								__('Page %1$d of %2$d', 'thinkrank'),
								current,
								pageCount
							)}
						</span>
						<button
							type="button"
							onClick={() => setPage(current + 1)}
							disabled={current >= pageCount}
							style={pagerButtonStyle(current >= pageCount)}
						>
							{__('Next', 'thinkrank')}
						</button>
					</span>
				</div>
			)}
		</div>
	);
};

// ── Check row (expandable when it has a fix) ──────────

const CheckRow = ({ check, last, onFix, onIgnore, busy = false }) => {
	const [open, setOpen] = useState(false);
	const [fixing, setFixing] = useState(false);
	// The "why are you ignoring it?" form under the row, and its reason.
	const [confirming, setConfirming] = useState(false);
	const [reason, setReason] = useState('');
	const [saving, setSaving] = useState(false);
	// Any change to the analysis in flight, from any row or Re-analyze, holds
	// every row's actions (review on #1591).
	const ignoreLocked = saving || busy;
	const ignored = Boolean(check.ignored);
	// An ignored row is not expandable: it is out of the score, so a fix or a
	// how-to-fix would read as still being asked to act on it.
	const hasFix = Boolean(check.how_to_fix) && !ignored;
	// A check that is not scored has nothing to fix, like a passing one.
	const passed =
		check.status === 'passed' || check.status === 'not_applicable';
	// A check that does not apply is already out of the score.
	const canIgnore =
		!ignored &&
		check.status !== 'not_applicable' &&
		typeof onIgnore === 'function';

	const changeIgnored = async (ignore) => {
		setSaving(true);
		const done = await onIgnore(check.id, ignore, ignore ? reason : '');
		setSaving(false);
		if (done && ignore) {
			setConfirming(false);
			setReason('');
		}
	};

	// The server decides what is safe to auto-apply — the UI never guesses.
	const canAutoFix = Boolean(check.can_auto_fix);

	const applyFix = async (event) => {
		// The row itself is a toggle; don't collapse it out from under the click.
		event.stopPropagation();
		setFixing(true);
		await onFix(check.id);
		setFixing(false);
	};

	return (
		<div style={{ borderBottom: last ? 'none' : '1px solid #eef0f4' }}>
			<div
				onClick={hasFix ? () => setOpen((v) => !v) : undefined}
				role={hasFix ? 'button' : undefined}
				tabIndex={hasFix ? 0 : undefined}
				onKeyDown={
					hasFix
						? (e) => {
								if (e.key === 'Enter' || e.key === ' ') {
									e.preventDefault();
									setOpen((v) => !v);
								}
							}
						: undefined
				}
				style={{
					display: 'flex',
					alignItems: 'center',
					gap: '12px',
					padding: '12px 0',
					cursor: hasFix ? 'pointer' : 'default',
				}}
			>
				<StatusPill status={ignored ? 'ignored' : check.status} />
				<div style={{ flex: 1, minWidth: 0 }}>
					<div
						style={{
							fontSize: '13px',
							fontWeight: 600,
							color: '#1e1e1e',
							lineHeight: 1.4,
						}}
					>
						{check.label}
					</div>
					<div
						style={{
							fontSize: '12.5px',
							color: '#6b7280',
							lineHeight: 1.5,
							marginTop: '2px',
						}}
					>
						{check.message}
					</div>
					{ignored && check.ignore_reason && (
						<div
							style={{
								fontSize: '12px',
								color: '#6b7280',
								lineHeight: 1.5,
								marginTop: '4px',
								fontStyle: 'italic',
							}}
						>
							{sprintf(
								/* translators: %s: why the site owner ignored this check. */
								__('Reason: %s', 'thinkrank'),
								check.ignore_reason
							)}
						</div>
					)}
				</div>
				{canIgnore && !confirming && (
					<button
						type="button"
						onClick={(event) => {
							stopRowToggle(event);
							setConfirming(true);
						}}
						onKeyDown={stopRowToggle}
						disabled={busy}
						style={textButtonStyle(busy)}
					>
						{__('Ignore', 'thinkrank')}
					</button>
				)}
				{ignored && (
					<button
						type="button"
						onClick={(event) => {
							stopRowToggle(event);
							changeIgnored(false);
						}}
						onKeyDown={stopRowToggle}
						disabled={ignoreLocked}
						style={textButtonStyle(ignoreLocked)}
					>
						{saving
							? __('Including…', 'thinkrank')
							: __('Include again', 'thinkrank')}
					</button>
				)}
				{hasFix && !passed && (
					<span style={{ color: '#9ca3af' }}>
						<ChevronIcon open={open} />
					</span>
				)}
			</div>
			{hasFix && open && (
				<div
					style={{
						fontSize: '12.5px',
						color: '#374151',
						lineHeight: 1.6,
						background: '#f9fafb',
						border: '1px solid #eef0f4',
						borderRadius: '8px',
						padding: '10px 12px',
						margin: '0 0 12px 0',
					}}
				>
					<strong style={{ fontWeight: 700 }}>
						{__('How to fix: ', 'thinkrank')}
					</strong>
					{check.how_to_fix}

					{Array.isArray(check.affected_posts) &&
						check.affected_posts.length > 0 && (
							<AffectedPosts
								posts={check.affected_posts}
								total={check.affected_total}
								findings={check.value}
							/>
						)}

					{canAutoFix && (
						<div style={{ marginTop: '10px' }}>
							{check.fix_warning && (
								<p
									style={{
										margin: '0 0 8px 0',
										fontSize: '12px',
										color: '#92400e',
									}}
								>
									{check.fix_warning}
								</p>
							)}
							<button
								type="button"
								onClick={applyFix}
								disabled={fixing || busy}
								style={{
									background: '#4451FF',
									color: '#fff',
									border: 'none',
									borderRadius: '6px',
									padding: '7px 14px',
									fontSize: '12.5px',
									fontWeight: 600,
									cursor:
										fixing || busy ? 'default' : 'pointer',
									opacity: fixing || busy ? 0.6 : 1,
								}}
							>
								{fixing
									? __('Fixing…', 'thinkrank')
									: check.fix_label ||
										__('Fix this for me', 'thinkrank')}
							</button>
						</div>
					)}
				</div>
			)}
			{confirming && (
				<div
					style={{
						background: '#f9fafb',
						border: '1px solid #eef0f4',
						borderRadius: '8px',
						padding: '10px 12px',
						margin: '0 0 12px 0',
					}}
				>
					{/* The help text sits under the whole row, not inside the
					    Input, so the row's bottom edge is the input's and the
					    buttons line up with it at any width. */}
					<div
						style={{
							display: 'flex',
							alignItems: 'flex-end',
							gap: '10px',
							flexWrap: 'wrap',
						}}
					>
						<Input
							label={__('Reason (optional)', 'thinkrank')}
							aria-describedby={`tr-ignore-help-${check.id}`}
							value={reason}
							maxLength={200}
							onChange={(event) => setReason(event.target.value)}
							placeholder={__(
								'e.g. We do not publish llms.txt',
								'thinkrank'
							)}
							className="tr-flex-1"
							style={{ minWidth: '220px' }}
							disabled={saving}
						/>
						<div
							style={{
								display: 'flex',
								alignItems: 'center',
								gap: '6px',
							}}
						>
							<button
								type="button"
								onClick={() => changeIgnored(true)}
								disabled={ignoreLocked}
								style={{
									background: '#4451FF',
									color: '#fff',
									border: 'none',
									borderRadius: '8px',
									// Same 40px as the Reason input beside it.
									height: '40px',
									boxSizing: 'border-box',
									padding: '0 14px',
									fontSize: '12.5px',
									fontWeight: 600,
									cursor: ignoreLocked
										? 'default'
										: 'pointer',
									opacity: ignoreLocked ? 0.6 : 1,
									whiteSpace: 'nowrap',
								}}
							>
								{saving
									? __('Saving…', 'thinkrank')
									: __("Don't count this check", 'thinkrank')}
							</button>
							<button
								type="button"
								onClick={() => {
									setConfirming(false);
									setReason('');
								}}
								disabled={saving}
								style={textButtonStyle(saving)}
							>
								{__('Cancel', 'thinkrank')}
							</button>
						</div>
					</div>
					<p
						id={`tr-ignore-help-${check.id}`}
						className="tr-text-xs tr-text-tertiary"
						style={{ margin: '6px 0 0' }}
					>
						{__(
							'An ignored check stays listed but no longer counts towards your score.',
							'thinkrank'
						)}
					</p>
				</div>
			)}
		</div>
	);
};

// ── Category tabs (full view) ─────────────────────────

// Whether a category has any check that counts. One whose checks are all
// ignored or not applicable has no score to show, rather than a red 0.
const isScored = (category) =>
	(category.passed || 0) + (category.warning || 0) + (category.failed || 0) >
	0;

// Worst status in a category → the color of its tab indicator dot.
const categoryDotColor = (category) => {
	if (category.failed > 0) return STATUS_STYLES.failed.fg;
	if (category.warning > 0) return STATUS_STYLES.warning.fg;
	if (!isScored(category)) return '#9ca3af';
	return STATUS_STYLES.passed.fg;
};

const CategoryTabs = ({ categories, onFix, onIgnore, busy }) => {
	const [activeId, setActiveId] = useState(categories[0]?.id);
	const active = categories.find((c) => c.id === activeId) || categories[0];
	// Ignored checks sit in their own group under the ones that count.
	const counted = active.checks.filter((check) => !check.ignored);
	const ignored = active.checks.filter((check) => check.ignored);

	return (
		<div style={{ marginTop: '20px' }}>
			{/* Tab bar */}
			<div
				role="tablist"
				aria-label={__('SEO audit categories', 'thinkrank')}
				style={{
					display: 'flex',
					gap: '4px',
					flexWrap: 'wrap',
					borderBottom: '1px solid #eef0f4',
				}}
			>
				{categories.map((cat) => {
					const isActive = cat.id === active.id;
					return (
						<button
							key={cat.id}
							type="button"
							role="tab"
							aria-selected={isActive}
							onClick={() => setActiveId(cat.id)}
							style={{
								display: 'inline-flex',
								alignItems: 'center',
								gap: '7px',
								padding: '9px 14px',
								background: 'none',
								border: 'none',
								borderBottom: isActive
									? '2px solid #4451ff'
									: '2px solid transparent',
								marginBottom: '-1px',
								cursor: 'pointer',
								font: 'inherit',
								fontSize: '13px',
								fontWeight: 600,
								color: isActive ? '#4451ff' : '#6b7280',
								whiteSpace: 'nowrap',
							}}
						>
							<span
								aria-hidden="true"
								style={{
									width: '7px',
									height: '7px',
									borderRadius: '999px',
									background: categoryDotColor(cat),
									flexShrink: 0,
								}}
							/>
							{cat.label}
							<span
								style={{
									fontSize: '11px',
									fontWeight: 700,
									color: isScored(cat)
										? scoreColor(cat.score)
										: '#9ca3af',
								}}
							>
								{isScored(cat) ? cat.score : '–'}
							</span>
						</button>
					);
				})}
			</div>

			{/* Active panel */}
			<div role="tabpanel">
				{counted.map((check, i) => (
					<CheckRow
						key={check.id || i}
						check={check}
						last={i === counted.length - 1}
						onFix={onFix}
						onIgnore={onIgnore}
						busy={busy}
					/>
				))}
				{ignored.length > 0 && (
					<div style={{ marginTop: counted.length ? '8px' : 0 }}>
						<div
							style={{
								fontSize: '12px',
								fontWeight: 700,
								color: '#6b7280',
								textTransform: 'uppercase',
								letterSpacing: '0.04em',
								padding: '12px 0 0',
								borderTop: counted.length
									? '1px solid #eef0f4'
									: 'none',
							}}
						>
							{sprintf(
								/* translators: %d: number of ignored checks. */
								__('Ignored (%d)', 'thinkrank'),
								ignored.length
							)}
						</div>
						<div
							style={{
								fontSize: '12px',
								color: '#6b7280',
								marginTop: '2px',
							}}
						>
							{__(
								'These checks still run but do not count towards your score.',
								'thinkrank'
							)}
						</div>
						{ignored.map((check, i) => (
							<CheckRow
								key={check.id || i}
								check={check}
								last={i === ignored.length - 1}
								onFix={onFix}
								onIgnore={onIgnore}
								busy={busy}
							/>
						))}
					</div>
				)}
			</div>
		</div>
	);
};

// ── Main component ────────────────────────────────────

// `compact` (default) is the dashboard summary — score, headline, counts, and a
// "See details" link. Pass compact={false} for the full breakdown (the
// Essential SEO › SEO Analyzer › Site Audit tab).
const SiteHealthAudit = ({ onRefresh, compact = true }) => {
	const [isLoading, setIsLoading] = useState(true);
	const [isRefreshing, setIsRefreshing] = useState(false);
	const [error, setError] = useState(false);
	const [data, setData] = useState(null);
	// One change to the analysis at a time (review on #1591). An ignore, an
	// include, a fix and Re-analyze each answer with the whole analysis, so two
	// in flight could land out of order and the older one would undo the newer
	// on screen. Each runs, and applies its answer, inside runExclusive(); the
	// ref refuses a second call in the same tick, before the state re-renders.
	const mutationInFlight = useRef(false);
	const [mutationPending, setMutationPending] = useState(false);

	const runExclusive = async (work) => {
		if (mutationInFlight.current) {
			return false;
		}
		mutationInFlight.current = true;
		setMutationPending(true);
		try {
			return await work();
		} finally {
			mutationInFlight.current = false;
			setMutationPending(false);
		}
	};

	useEffect(() => {
		let cancelled = false;

		async function load() {
			try {
				const res = await apiFetch({
					path: '/thinkrank/v1/seo-analyzer',
				});
				if (!cancelled) {
					setData(res?.data || null);
					setError(!res?.data);
					if (!res?.data) {
						notify.error(
							__(
								'We could not load the site analysis. Please try again.',
								'thinkrank'
							)
						);
					}
				}
			} catch (e) {
				if (!cancelled) {
					setError(true);
					notify.error(
						__(
							'We could not load the site analysis. Please try again.',
							'thinkrank'
						)
					);
				}
			} finally {
				if (!cancelled) setIsLoading(false);
			}
		}

		load();
		return () => {
			cancelled = true;
		};
	}, []);

	const handleReanalyze = () =>
		runExclusive(async () => {
			setIsRefreshing(true);
			setError(false);
			try {
				const res = await apiFetch({
					path: '/thinkrank/v1/seo-analyzer/run',
					method: 'POST',
				});
				setData(res?.data || null);
				if (res?.data) {
					notify.success(__('Site analysis complete.', 'thinkrank'));
				} else {
					setError(true);
					notify.error(
						__(
							'We could not run the site analysis right now. Please try again.',
							'thinkrank'
						)
					);
				}
				// Keep the rest of the dashboard in sync with any settings it caches.
				if (typeof onRefresh === 'function') {
					await onRefresh();
				}
			} catch (e) {
				setError(true);
				notify.error(
					__(
						'We could not run the site analysis right now. Please try again.',
						'thinkrank'
					)
				);
			} finally {
				setIsRefreshing(false);
			}
		});

	// Apply a check's automatic fix. The answer carries a freshly re-run
	// analysis, so the score and the row update together.
	const handleFix = (checkId) =>
		runExclusive(async () => {
			try {
				const res = await apiFetch({
					path: '/thinkrank/v1/seo-analyzer/fix',
					method: 'POST',
					data: { check_id: checkId },
				});
				if (!res.success) {
					throw new Error(res.message);
				}
				notify.success(res.message || __('Fixed.', 'thinkrank'));
				if (res.data?.analysis) {
					setData(res.data.analysis);
				}
			} catch (err) {
				notify.error(
					err.message || __('Could not apply this fix.', 'thinkrank')
				);
			}
		});

	// Ignore a check, or count it again (#1289). Resolves true when saved, so
	// the row can close its form only on success.
	const handleIgnore = (checkId, ignore, reason) =>
		runExclusive(async () => {
			try {
				const res = await apiFetch({
					path: '/thinkrank/v1/seo-analyzer/ignore',
					method: 'POST',
					data: { check_id: checkId, ignore, reason },
				});
				if (!res?.success) {
					throw new Error(res?.message);
				}
				if (res.data?.analysis) {
					setData(res.data.analysis);
				}
				notify.success(
					res.message ||
						(ignore
							? __(
									'This check no longer counts towards your score.',
									'thinkrank'
								)
							: __(
									'This check counts towards your score again.',
									'thinkrank'
								))
				);
				return true;
			} catch (err) {
				notify.error(
					err?.message ||
						__(
							'Could not update this check. Please try again.',
							'thinkrank'
						)
				);
				return false;
			}
		});

	const summaryText = useMemo(() => {
		if (!data?.summary) return '';
		const {
			passed = 0,
			warning = 0,
			failed = 0,
			not_applicable: notScored = 0,
			ignored = 0,
		} = data.summary;
		let text = sprintf(
			/* translators: 1: passed count, 2: warning count, 3: failed count. */
			__('%1$d passed · %2$d warnings · %3$d failed', 'thinkrank'),
			passed,
			warning,
			failed
		);
		if (notScored > 0) {
			text = sprintf(
				/* translators: 1: the passed/warnings/failed counts, 2: checks not scored. */
				__('%1$s · %2$d not scored', 'thinkrank'),
				text,
				notScored
			);
		}
		if (ignored > 0) {
			text = sprintf(
				/* translators: 1: the counts so far, 2: checks the site owner chose not to count. */
				__('%1$s · %2$d ignored', 'thinkrank'),
				text,
				ignored
			);
		}
		return text;
	}, [data]);

	const detailsHref = getPageUrl('essential-seo', DETAILS_PARAMS);
	const goToDetails = (event) => {
		if (isPlainLeftClick(event)) {
			event.preventDefault();
			navigate('essential-seo', DETAILS_PARAMS);
		}
	};

	if (isLoading) {
		return (
			<SkeletonCard
				lines={5}
				label={__('Analyzing your site…', 'thinkrank')}
			/>
		);
	}

	return (
		<div
			style={{
				padding: '20px 24px',
				borderRadius: '16px',
				background: '#fff',
				border: '1px solid var(--tr-border)',
			}}
		>
			{/* Header */}
			<div
				style={{
					display: 'flex',
					alignItems: 'center',
					justifyContent: 'space-between',
					gap: '12px',
					marginBottom: '4px',
					flexWrap: 'wrap',
				}}
			>
				<div>
					<h3
						style={{
							fontSize: '15px',
							fontWeight: 700,
							color: '#1e1e1e',
							margin: 0,
						}}
					>
						{__('Site SEO Audit', 'thinkrank')}
					</h3>
					{!compact && (
						<p
							style={{
								fontSize: '12.5px',
								color: '#6b7280',
								margin: '4px 0 0',
								lineHeight: 1.5,
							}}
						>
							{__(
								'A whole-site audit of your SEO setup, content, performance and security. No Google connection needed.',
								'thinkrank'
							)}
						</p>
					)}
				</div>
				<div
					style={{
						display: 'flex',
						alignItems: 'center',
						gap: '10px',
						flexWrap: 'wrap',
					}}
				>
					{data?.generated_at && (
						<span
							style={{
								fontSize: '12px',
								color: '#6b7280',
								whiteSpace: 'nowrap',
							}}
						>
							{lastAnalyzedLabel(data.generated_at)}
						</span>
					)}
					<button
						type="button"
						onClick={handleReanalyze}
						disabled={isRefreshing || mutationPending}
						style={{
							display: 'inline-flex',
							alignItems: 'center',
							gap: '6px',
							padding: '7px 14px',
							borderRadius: '8px',
							background: '#fff',
							color: '#374151',
							fontSize: '12.5px',
							fontWeight: 600,
							border: '1.5px solid #d1d5db',
							cursor: isRefreshing ? 'wait' : 'pointer',
							opacity: isRefreshing ? 0.7 : 1,
							font: 'inherit',
						}}
					>
						<RefreshIcon />
						{isRefreshing
							? __('Analyzing…', 'thinkrank')
							: __('Re-analyze', 'thinkrank')}
					</button>
				</div>
			</div>

			{error || !data ? (
				<div
					style={{
						fontSize: '13px',
						color: '#6b7280',
						padding: '18px 0 4px',
						lineHeight: 1.6,
					}}
				>
					{__(
						'We could not run the site analysis right now. Please try again.',
						'thinkrank'
					)}
				</div>
			) : (
				<>
					{/* Score + summary */}
					<div
						style={{
							display: 'flex',
							alignItems: 'center',
							gap: '18px',
							padding: '16px 0 4px',
							flexWrap: 'wrap',
						}}
					>
						<ScoreGauge
							score={data.overall_score}
							grade={data.grade}
						/>
						<div style={{ flex: 1, minWidth: '160px' }}>
							<div
								style={{
									fontSize: '13.5px',
									fontWeight: 600,
									color: '#1e1e1e',
									lineHeight: 1.5,
								}}
							>
								{scoreHeadline(data.overall_score)}
							</div>
							<div
								style={{
									fontSize: '12.5px',
									color: '#6b7280',
									marginTop: '4px',
								}}
							>
								{summaryText}
							</div>
						</div>
					</div>

					{/* Full breakdown — tabbed by category */}
					{!compact &&
						Array.isArray(data.categories) &&
						data.categories.length > 0 && (
							<CategoryTabs
								categories={data.categories}
								onFix={handleFix}
								onIgnore={handleIgnore}
								busy={mutationPending}
							/>
						)}

					{/* Compact (dashboard) — link to the full report */}
					{compact && (
						<a
							href={detailsHref}
							onClick={goToDetails}
							style={{
								display: 'flex',
								alignItems: 'center',
								justifyContent: 'center',
								gap: '6px',
								marginTop: '16px',
								padding: '11px 16px',
								borderRadius: '10px',
								background: '#eef0fd',
								color: '#4451ff',
								fontSize: '13px',
								fontWeight: 600,
								textDecoration: 'none',
							}}
						>
							{__('See details', 'thinkrank')}
							<ArrowRightIcon />
						</a>
					)}
				</>
			)}
		</div>
	);
};

export default SiteHealthAudit;
