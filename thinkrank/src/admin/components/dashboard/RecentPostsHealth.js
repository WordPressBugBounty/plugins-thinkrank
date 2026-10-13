/**
 * Dashboard — Recent posts SEO health
 *
 * Lists the five most-recently-updated posts/pages with their ThinkRank
 * SEO score, so the dashboard surfaces what to fix next.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { SkeletonCard } from '../ui';

// ── Helpers ──────────────────────────────────────────

const formatRelativeDate = (iso) => {
	if (!iso) return '';
	const d = new Date(iso);
	if (Number.isNaN(d.getTime())) return '';
	const diff = (Date.now() - d.getTime()) / 1000;
	if (diff < 60) return __('just now', 'thinkrank');
	if (diff < 3600) {
		const m = Math.floor(diff / 60);
		/* translators: %d: minutes ago */
		return `${m} ${m === 1 ? __('min ago', 'thinkrank') : __('mins ago', 'thinkrank')}`;
	}
	if (diff < 86400) {
		const h = Math.floor(diff / 3600);
		return `${h} ${h === 1 ? __('hr ago', 'thinkrank') : __('hrs ago', 'thinkrank')}`;
	}
	if (diff < 86400 * 7) {
		const days = Math.floor(diff / 86400);
		return `${days}d ${__('ago', 'thinkrank')}`;
	}
	return d.toLocaleDateString();
};

const decodeHtml = (str) => {
	if (!str) return '';
	const ta = document.createElement('textarea');
	ta.innerHTML = str;
	return ta.value;
};

const getEditUrl = (id) => `/wp-admin/post.php?post=${id}&action=edit`;

const SCORE_TONES = {
	good: {
		bg: '#ecfdf3',
		fg: '#027a48',
		border: '#b9f1cf',
		label: __('Good', 'thinkrank'),
	},
	ok: {
		bg: '#fff8e5',
		fg: '#b45309',
		border: '#fde68a',
		label: __('Needs work', 'thinkrank'),
	},
	poor: {
		bg: '#fef3f2',
		fg: '#b42318',
		border: '#fda29b',
		label: __('Poor', 'thinkrank'),
	},
	none: {
		bg: '#f3f4f6',
		fg: '#6b7280',
		border: '#e5e7eb',
		label: __('Not scored', 'thinkrank'),
	},
};

const scoreTone = (score) => {
	if (score === null || score === undefined) return SCORE_TONES.none;
	const n = Number(score);
	if (!Number.isFinite(n)) return SCORE_TONES.none;
	if (n >= 80) return SCORE_TONES.good;
	if (n >= 50) return SCORE_TONES.ok;
	return SCORE_TONES.poor;
};

// ── Icons ────────────────────────────────────────────

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

const FileIcon = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<path
			d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6Z"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinejoin="round"
		/>
		<path
			d="M14 3v6h6"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinejoin="round"
		/>
	</svg>
);

// ── Score badge ──────────────────────────────────────

const ScoreBadge = ({ score }) => {
	const tone = scoreTone(score);
	const display =
		score === null || score === undefined ? '—' : Math.round(Number(score));
	return (
		<span
			style={{
				display: 'inline-flex',
				alignItems: 'center',
				gap: '8px',
				fontSize: '12.5px',
				fontWeight: 700,
				padding: '5px 10px',
				borderRadius: '999px',
				background: tone.bg,
				color: tone.fg,
				border: '1px solid ' + tone.border,
				whiteSpace: 'nowrap',
			}}
		>
			<span style={{ fontVariantNumeric: 'tabular-nums' }}>
				{display}
				{display !== '—' && '/100'}
			</span>
			<span style={{ opacity: 0.7, fontWeight: 600 }}>·</span>
			<span
				style={{
					textTransform: 'uppercase',
					letterSpacing: '0.04em',
					fontSize: '10.5px',
				}}
			>
				{tone.label}
			</span>
		</span>
	);
};

// ── Row ──────────────────────────────────────────────

const Row = ({ post, score, last }) => (
	<a
		href={getEditUrl(post.id)}
		style={{
			display: 'flex',
			alignItems: 'center',
			gap: '14px',
			padding: '12px 0',
			borderBottom: last ? 'none' : '1px solid #eef0f4',
			textDecoration: 'none',
			color: 'inherit',
		}}
	>
		<span
			style={{
				width: '32px',
				height: '32px',
				borderRadius: '8px',
				background: '#fafbfc',
				color: '#6b7280',
				border: '1px solid #eef0f4',
				display: 'inline-flex',
				alignItems: 'center',
				justifyContent: 'center',
				flexShrink: 0,
			}}
			aria-hidden="true"
		>
			<FileIcon />
		</span>
		<div style={{ flex: 1, minWidth: 0 }}>
			<div
				style={{
					fontSize: '13.5px',
					fontWeight: 600,
					color: '#1e1e1e',
					overflow: 'hidden',
					textOverflow: 'ellipsis',
					whiteSpace: 'nowrap',
				}}
			>
				{decodeHtml(post.title?.rendered) ||
					__('(Untitled)', 'thinkrank')}
			</div>
			<div
				style={{
					fontSize: '11.5px',
					color: '#9ca3af',
					marginTop: '2px',
					textTransform: 'capitalize',
				}}
			>
				{post.type === 'page'
					? __('Page', 'thinkrank')
					: __('Post', 'thinkrank')}
				{' · '}
				{__('Updated', 'thinkrank')}{' '}
				{formatRelativeDate(
					post.modified_gmt ? `${post.modified_gmt}Z` : post.modified
				)}
			</div>
		</div>
		<ScoreBadge score={score} />
		<span
			style={{ color: '#cbd5e1', display: 'inline-flex', flexShrink: 0 }}
			aria-hidden="true"
		>
			<ArrowRightIcon />
		</span>
	</a>
);

// ── Main component ───────────────────────────────────

const RecentPostsHealth = () => {
	const [isLoading, setIsLoading] = useState(true);
	const [rows, setRows] = useState([]);
	const [error, setError] = useState(false);

	useEffect(() => {
		let cancelled = false;

		const fetchScore = async (postId) => {
			try {
				const res = await apiFetch({
					path: `/thinkrank/v1/seo-score/latest?post_id=${postId}`,
				});
				const raw = res?.data;
				if (!raw) return null;
				if (typeof raw === 'number') return raw;
				// The seo-score/latest row stores the value in `overall_score`
				// (thinkrank_seo_scores table); keep the other keys as fallbacks.
				const s =
					raw.overall_score ?? raw.score ?? raw.total ?? raw.value;
				return s === null || s === undefined ? null : Number(s);
			} catch (e) {
				return null;
			}
		};

		async function load() {
			try {
				const posts = await apiFetch({
					path: '/wp/v2/posts?per_page=5&orderby=modified&order=desc&status=publish,draft,private&_fields=id,title,link,modified,modified_gmt,type',
				}).catch(() => []);

				if (cancelled) return;
				if (!Array.isArray(posts) || posts.length === 0) {
					setRows([]);
					return;
				}

				const scores = await Promise.all(
					posts.map((p) => fetchScore(p.id))
				);
				if (cancelled) return;
				setRows(posts.map((post, i) => ({ post, score: scores[i] })));
			} catch (e) {
				if (!cancelled) setError(true);
			} finally {
				if (!cancelled) setIsLoading(false);
			}
		}

		load();
		return () => {
			cancelled = true;
		};
	}, []);

	if (isLoading) {
		return (
			<SkeletonCard
				lines={4}
				label={__('Loading recent posts…', 'thinkrank')}
			/>
		);
	}

	if (error || rows.length === 0) {
		return (
			<div
				style={{
					padding: '20px 24px',
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
						margin: 0,
					}}
				>
					{__('Recent posts', 'thinkrank')}
				</h3>
				<p
					style={{
						fontSize: '13px',
						color: '#6b7280',
						margin: '8px 0 0',
						lineHeight: 1.5,
					}}
				>
					{__(
						'No posts to show yet. Publish or update a post and it will appear here with its latest SEO score.',
						'thinkrank'
					)}
				</p>
			</div>
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
			<div
				style={{
					display: 'flex',
					alignItems: 'center',
					justifyContent: 'space-between',
					gap: '12px',
					marginBottom: '8px',
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
						{__('Recent posts SEO health', 'thinkrank')}
					</h3>
					<p
						style={{
							fontSize: '12.5px',
							color: '#6b7280',
							margin: '4px 0 0',
							lineHeight: 1.5,
						}}
					>
						{__(
							'Your most recently updated content. Click a row to open the editor and improve its score.',
							'thinkrank'
						)}
					</p>
				</div>
				<a
					href="/wp-admin/edit.php"
					style={{
						fontSize: '12.5px',
						fontWeight: 600,
						color: '#4451FF',
						textDecoration: 'none',
						display: 'inline-flex',
						alignItems: 'center',
					}}
				>
					{__('View all posts', 'thinkrank')}
					<ArrowRightIcon />
				</a>
			</div>

			<div>
				{rows.map(({ post, score }, i) => (
					<Row
						key={post.id}
						post={post}
						score={score}
						last={i === rows.length - 1}
					/>
				))}
			</div>
		</div>
	);
};

export default RecentPostsHealth;
