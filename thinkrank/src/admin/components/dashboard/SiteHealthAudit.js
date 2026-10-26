/**
 * Dashboard — Site SEO Audit
 *
 * Renders the site-wide SEO Analyzer: one aggregate 0–100 score + letter grade,
 * per-category subtotals, and every check grouped by category with an
 * expandable "how to fix". Data comes from the backend analyzer engine via
 * GET /thinkrank/v1/seo-analyzer (cached) and POST /thinkrank/v1/seo-analyzer/run
 * (force a fresh run). No Google connection required.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { SkeletonCard } from '../ui';
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

// A count ("7 of your 8 pages…") leaves the user to guess which pages it
// means. Name them, ten at a time, each linking to its editor. `total` can be
// larger than the list when the server capped it.
const AffectedPosts = ({ posts, total }) => {
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
						key={post.id}
						style={{
							display: 'flex',
							alignItems: 'center',
							gap: '10px',
							padding: '7px 10px',
							borderTop: i === 0 ? 'none' : '1px solid #eef0f4',
						}}
					>
						<a
							href={post.edit_url}
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
								style={{ fontSize: '11.5px', color: '#6b7280' }}
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

const CheckRow = ({ check, last, onFixed }) => {
	const [open, setOpen] = useState(false);
	const [fixing, setFixing] = useState(false);
	const hasFix = Boolean(check.how_to_fix);
	// A check that is not scored has nothing to fix, like a passing one.
	const passed =
		check.status === 'passed' || check.status === 'not_applicable';

	// The server decides what is safe to auto-apply — the UI never guesses.
	const canAutoFix = Boolean(check.can_auto_fix);

	const applyFix = async (event) => {
		// The row itself is a toggle; don't collapse it out from under the click.
		event.stopPropagation();
		setFixing(true);
		try {
			const res = await apiFetch({
				path: '/thinkrank/v1/seo-analyzer/fix',
				method: 'POST',
				data: { check_id: check.id },
			});
			if (!res.success) {
				throw new Error(res.message);
			}
			notify.success(res.message || __('Fixed.', 'thinkrank'));
			// The response carries a freshly re-run analysis, so the score and
			// this row update together — a fix you can't see land is
			// indistinguishable from one that silently failed.
			if (res.data?.analysis && onFixed) {
				onFixed(res.data.analysis);
			}
		} catch (err) {
			notify.error(
				err.message || __('Could not apply this fix.', 'thinkrank')
			);
		} finally {
			setFixing(false);
		}
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
				<StatusPill status={check.status} />
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
				</div>
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
								disabled={fixing}
								style={{
									background: '#4451FF',
									color: '#fff',
									border: 'none',
									borderRadius: '6px',
									padding: '7px 14px',
									fontSize: '12.5px',
									fontWeight: 600,
									cursor: fixing ? 'default' : 'pointer',
									opacity: fixing ? 0.6 : 1,
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
		</div>
	);
};

// ── Category tabs (full view) ─────────────────────────

// Worst status in a category → the color of its tab indicator dot.
const categoryDotColor = (category) => {
	if (category.failed > 0) return STATUS_STYLES.failed.fg;
	if (category.warning > 0) return STATUS_STYLES.warning.fg;
	return STATUS_STYLES.passed.fg;
};

const CategoryTabs = ({ categories, onFixed }) => {
	const [activeId, setActiveId] = useState(categories[0]?.id);
	const active = categories.find((c) => c.id === activeId) || categories[0];

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
									color: scoreColor(cat.score),
								}}
							>
								{cat.score}
							</span>
						</button>
					);
				})}
			</div>

			{/* Active panel */}
			<div role="tabpanel">
				{active.checks.map((check, i) => (
					<CheckRow
						key={check.id || i}
						check={check}
						last={i === active.checks.length - 1}
						onFixed={onFixed}
					/>
				))}
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

	const handleReanalyze = async () => {
		if (isRefreshing) return;
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
	};

	const summaryText = useMemo(() => {
		if (!data?.summary) return '';
		const {
			passed = 0,
			warning = 0,
			failed = 0,
			not_applicable: notScored = 0,
		} = data.summary;
		const text = sprintf(
			/* translators: 1: passed count, 2: warning count, 3: failed count. */
			__('%1$d passed · %2$d warnings · %3$d failed', 'thinkrank'),
			passed,
			warning,
			failed
		);
		return notScored > 0
			? sprintf(
					/* translators: 1: the passed/warnings/failed counts, 2: checks not scored. */
					__('%1$s · %2$d not scored', 'thinkrank'),
					text,
					notScored
				)
			: text;
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
						disabled={isRefreshing}
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
								onFixed={setData}
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
