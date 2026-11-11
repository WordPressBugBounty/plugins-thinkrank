/**
 * AI Insights (#248 P1): first-party AI referral + crawler analytics.
 * WordPress serves its own pages, so the plugin reads referrers in the
 * request path — no pixel, no GA4 dependency (GA4 files most AI referrals
 * under "Direct").
 *
 * AI Revenue is a ThinkRank Pro slot (`thinkrank.aiInsights.revenueTab`,
 * thinkrank-pro#60); free shows the teaser.
 *
 * #734 adds, on the AI Traffic tab: platform rows that open onto the pages
 * that platform sent visitors to, three guidance cards stating the site's
 * current state on the controls that affect AI visibility, and inline Pro
 * slots for GA4 conversions (`thinkrank.aiInsights.conversions`,
 * `.platformConversions`, `.ga4OnlyPlatforms`, `.reconciliation`) and the
 * Markdown for AI card (`thinkrank.aiInsights.markdownCard`).
 *
 * Auto AI (metadata on publish) was a tab here until #707 moved it to
 * Bulk SEO Optimization, next to the rest of the AI-written metadata; see
 * `../AutoAiSection.js`.
 *
 * @package
 * @since 1.27.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { applyFilters } from '@wordpress/hooks';
import classnames from 'classnames';
import {
	Card,
	Badge,
	Alert,
	Tabs,
	TabPanel,
	EmptyState,
	Skeleton,
} from '../../ui';
import SectionHeader from '../../common/SectionHeader';
import ProTeaser from '../../pro/ProTeaser';
import ProGatedTab from '../../pro/ProGatedTab';
import { InternalLinksIllustration } from '../../pro/pro-teaser-illustrations';
import logger from '../../../../shared/utils/logger';
import { getPageUrl } from '../../../utils/router';
import { crawlerCard, llmsCard } from './guidance';

// --- Inline icons (currentColor → theme-aware, no icon-font dependency) ------

const Svg = (props) => (
	<svg
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.8"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
		{...props}
	/>
);
const BoltIcon = (p) => (
	<Svg {...p}>
		<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z" />
	</Svg>
);
const TrendIcon = (p) => (
	<Svg {...p}>
		<path d="M3 17l6-6 4 4 7-7" />
		<path d="M17 7h4v4" />
	</Svg>
);
const StarIcon = (p) => (
	<Svg {...p}>
		<path d="M12 3l2.6 5.5 6 .8-4.3 4.2 1 6-5.3-2.8L6.7 19.5l1-6L3.4 9.3l6-.8L12 3Z" />
	</Svg>
);
const GlobeIcon = (p) => (
	<Svg {...p}>
		<circle cx="12" cy="12" r="9" />
		<path d="M3 12h18M12 3c2.5 3 2.5 15 0 18M12 3c-2.5 3-2.5 15 0 18" />
	</Svg>
);
// --- Platform identity -------------------------------------------------------

// Display names for platform/crawler slugs coming from the API.
const SOURCE_LABELS = {
	chatgpt: 'ChatGPT',
	perplexity: 'Perplexity',
	gemini: 'Gemini',
	claude: 'Claude',
	copilot: 'Copilot',
	'meta-ai': 'Meta AI',
	you: 'You.com',
	poe: 'Poe',
	grok: 'Grok',
	mistral: 'Le Chat (Mistral)',
	deepseek: 'DeepSeek',
	kimi: 'Kimi',
	gptbot: 'GPTBot (OpenAI)',
	'oai-searchbot': 'OAI-SearchBot (OpenAI)',
	'chatgpt-user': 'ChatGPT-User (OpenAI)',
	perplexitybot: 'PerplexityBot',
	'perplexity-user': 'Perplexity-User',
	claudebot: 'ClaudeBot (Anthropic)',
	'claude-user': 'Claude-User (Anthropic)',
	'claude-searchbot': 'Claude-SearchBot (Anthropic)',
	'anthropic-ai': 'Anthropic-AI',
	'google-extended': 'Google-Extended',
	'applebot-extended': 'Applebot-Extended',
	'meta-externalagent': 'Meta External Agent',
	'meta-externalfetcher': 'Meta External Fetcher',
	bytespider: 'Bytespider (ByteDance)',
	amazonbot: 'Amazonbot',
	ccbot: 'CCBot (Common Crawl)',
	'cohere-ai': 'Cohere',
	'mistral-user': 'MistralAI-User',
};

const sourceLabel = (slug) => SOURCE_LABELS[slug] || slug;

// Recognisable accent per vendor. Crawler slugs map back to their parent brand
// so a vendor reads as one colour across the referral + crawler panels.
const PLATFORM_COLORS = {
	chatgpt: '#10a37f',
	gptbot: '#10a37f',
	'oai-searchbot': '#10a37f',
	'chatgpt-user': '#10a37f',
	perplexity: '#20808d',
	perplexitybot: '#20808d',
	'perplexity-user': '#20808d',
	gemini: '#4285f4',
	'google-extended': '#4285f4',
	claude: '#d97757',
	claudebot: '#d97757',
	'claude-user': '#d97757',
	'claude-searchbot': '#d97757',
	'anthropic-ai': '#d97757',
	copilot: '#0a6cd6',
	'meta-ai': '#0064e0',
	'meta-externalagent': '#0064e0',
	'meta-externalfetcher': '#0064e0',
	grok: '#6b7280',
	mistral: '#fa520f',
	'mistral-user': '#fa520f',
	deepseek: '#4d6bfe',
	kimi: '#6d28d9',
	you: '#5850ec',
	poe: '#5b2fd6',
	bytespider: '#325ab4',
	amazonbot: '#ff9900',
	ccbot: '#4b5563',
	'cohere-ai': '#39a0a5',
	'applebot-extended': '#6b7280',
};

const platformColor = (slug) =>
	PLATFORM_COLORS[slug] || 'var(--tr-brand-500, #6366f1)';

// --- Small building blocks ---------------------------------------------------

// Inline SVG area chart — no chart bundle, inherits brand colour via currentColor.
/**
 * Format a `YYYY-MM-DD` day key for display.
 *
 * Parsed AND formatted in UTC on purpose. `new Date('2026-09-03')` is
 * midnight UTC, which renders as 2 September for every viewer west of
 * Greenwich. These keys are calendar dates, not instants, so they must not
 * be shifted by whatever timezone the reader's browser is in.
 *
 * @param {string} ymd Day key.
 * @return {string} Short localized date, e.g. "3 Sep".
 */
export const formatDayKey = (ymd) => {
	const [year, month, day] = String(ymd).split('-').map(Number);
	if (!year || !month || !day) {
		return String(ymd);
	}
	return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString(
		undefined,
		{ month: 'short', day: 'numeric', timeZone: 'UTC' }
	);
};

/**
 * Single-series area chart for AI referrals per day.
 *
 * The series arrives zero-filled and contiguous from the server, so a point's
 * index is proportional to elapsed time and even horizontal spacing is
 * correct. It must stay that way: positioning by index over a SPARSE series
 * draws a three-week gap exactly like a one-day gap.
 *
 * Text is never placed inside the <svg>. The plot stretches horizontally via
 * preserveAspectRatio="none", which would distort any glyph drawn in that
 * coordinate space, so the labels, marker and tooltip are HTML siblings
 * positioned over it. The vertical axis is not stretched — the viewBox height
 * and the rendered height are both `height` — so a y in viewBox units is a y
 * in pixels.
 *
 * One series, so no legend: the panel title names it.
 *
 * @param {Object}   root0        Props.
 * @param {Object[]} root0.points Contiguous `{label, value}` series, one per day.
 * @param {number}   root0.height Plot height in pixels.
 * @return {JSX.Element|null} The chart, or null for a series too short to plot.
 */
export const TrendArea = ({ points, height = 72 }) => {
	const [hovered, setHovered] = useState(null);

	if (!points || points.length < 2) {
		return null;
	}

	const width = 100;
	const pad = 5;
	const max = Math.max(1, ...points.map((p) => p.value));
	const stepX = width / (points.length - 1);
	const coords = points.map((p, i) => {
		const x = i * stepX;
		const y = height - pad - (p.value / max) * (height - pad * 2);
		return [x, y];
	});
	const line = coords
		.map(
			([x, y], i) =>
				`${i === 0 ? 'M' : 'L'}${x.toFixed(2)},${y.toFixed(2)}`
		)
		.join(' ');
	const area = `${line} L${width},${height} L0,${height} Z`;

	const first = points[0];
	const last = points[points.length - 1];
	const peakIndex = points.reduce(
		(best, p, i) => (p.value > points[best].value ? i : best),
		0
	);
	const peak = points[peakIndex];

	const total = points.reduce((sum, p) => sum + p.value, 0);

	// Carries the range and the peak, so a screen reader gets the same
	// summary a sighted reader takes off the labels — not just the title.
	const description = sprintf(
		/* translators: 1: start date, 2: end date, 3: total referrals, 4: peak referrals, 5: peak date. */
		__(
			'AI referrals from %1$s to %2$s. %3$d referrals in total, peaking at %4$d on %5$s.',
			'thinkrank'
		),
		formatDayKey(first.label),
		formatDayKey(last.label),
		total,
		peak.value,
		formatDayKey(peak.label)
	);

	const active = hovered === null ? null : points[hovered];
	const activeX = hovered === null ? 0 : (coords[hovered][0] / width) * 100;

	// Keep the tooltip inside the plot: anchor it left near the start, right
	// near the end, and centre it in between.
	let tooltipShiftX = '-50%';
	if (activeX > 70) {
		tooltipShiftX = '-100%';
	} else if (activeX < 30) {
		tooltipShiftX = '0';
	}

	const trackPointer = (event) => {
		const rect = event.currentTarget.getBoundingClientRect();
		if (!rect.width) {
			return;
		}
		const ratio = (event.clientX - rect.left) / rect.width;
		setHovered(
			Math.max(
				0,
				Math.min(
					points.length - 1,
					Math.round(ratio * (points.length - 1))
				)
			)
		);
	};

	return (
		<div>
			<div className="tr-flex tr-items-baseline tr-justify-between tr-gap-3 tr-mb-1">
				<span className="tr-text-xs tr-text-tertiary">
					{sprintf(
						/* translators: 1: peak referral count, 2: date of the peak. */
						__('Peak %1$d on %2$s', 'thinkrank'),
						peak.value,
						formatDayKey(peak.label)
					)}
				</span>
			</div>

			<div
				className="tr-relative"
				style={{ height }}
				onPointerMove={trackPointer}
				onPointerLeave={() => setHovered(null)}
			>
				<svg
					viewBox={`0 0 ${width} ${height}`}
					preserveAspectRatio="none"
					className="tr-w-full tr-text-brand"
					style={{ height }}
					role="img"
					aria-label={description}
				>
					<defs>
						<linearGradient
							id="tr-trend-fill"
							x1="0"
							y1="0"
							x2="0"
							y2="1"
						>
							<stop
								offset="0%"
								stopColor="currentColor"
								stopOpacity="0.22"
							/>
							<stop
								offset="100%"
								stopColor="currentColor"
								stopOpacity="0"
							/>
						</linearGradient>
					</defs>
					<path d={area} fill="url(#tr-trend-fill)" />
					<path
						d={line}
						fill="none"
						stroke="currentColor"
						strokeWidth="2"
						vectorEffect="non-scaling-stroke"
					/>
				</svg>

				{active !== null && (
					<>
						{/* Crosshair + marker, drawn in HTML so the horizontal
						    stretch cannot squash them into ellipses. */}
						<span
							aria-hidden="true"
							className="tr-absolute tr-bg-line"
							style={{
								left: `${activeX}%`,
								top: 0,
								width: 1,
								height,
								pointerEvents: 'none',
							}}
						/>
						<span
							aria-hidden="true"
							className="tr-absolute tr-bg-brand"
							style={{
								left: `${activeX}%`,
								top: coords[hovered][1],
								width: 8,
								height: 8,
								marginLeft: -4,
								marginTop: -4,
								borderRadius: '50%',
								boxShadow: '0 0 0 2px #fff',
								pointerEvents: 'none',
							}}
						/>
						{/* Visual affordance only. role="status" here would make
						    this an aria-live region and announce on every
						    pointer move; the per-day values reach assistive
						    tech through the table below instead. */}
						<span
							aria-hidden="true"
							data-tr-trend-tooltip=""
							className="tr-absolute tr-text-xs tr-text-primary tr-bg-white tr-border tr-border-secondary tr-rounded-lg"
							style={{
								left: `${activeX}%`,
								top: -8,
								transform: `translate(${tooltipShiftX}, -100%)`,
								padding: '4px 8px',
								whiteSpace: 'nowrap',
								pointerEvents: 'none',
								boxShadow: '0 2px 8px rgba(0,0,0,.08)',
							}}
						>
							{sprintf(
								/* translators: 1: date, 2: referral count. */
								_n(
									'%1$s: %2$d referral',
									'%1$s: %2$d referrals',
									active.value,
									'thinkrank'
								),
								formatDayKey(active.label),
								active.value
							)}
						</span>
					</>
				)}
			</div>

			<div className="tr-flex tr-items-center tr-justify-between tr-mt-1">
				<span className="tr-text-xs tr-text-tertiary">
					{formatDayKey(first.label)}
				</span>
				<span className="tr-text-xs tr-text-tertiary">
					{formatDayKey(last.label)}
				</span>
			</div>

			{/* The per-day numbers, for anyone who cannot read them off the
			    plot. The svg's aria-label carries the shape of the series;
			    only a table can carry its values. */}
			<table className="screen-reader-text">
				<caption>{__('AI referrals by day', 'thinkrank')}</caption>
				<thead>
					<tr>
						<th scope="col">{__('Date', 'thinkrank')}</th>
						<th scope="col">{__('AI referrals', 'thinkrank')}</th>
					</tr>
				</thead>
				<tbody>
					{points.map((point) => (
						<tr key={point.label}>
							<th scope="row">{formatDayKey(point.label)}</th>
							<td>{point.value}</td>
						</tr>
					))}
				</tbody>
			</table>
		</div>
	);
};

const MetricCard = ({ icon: Icon, label, value, hint }) => (
	<div className="tr-flex-1 tr-min-w-[170px] tr-rounded-xl tr-border tr-border-secondary tr-bg-white tr-p-4">
		<div className="tr-flex tr-items-center tr-gap-2 tr-mb-2.5">
			<span className="tr-flex tr-items-center tr-justify-center tr-w-8 tr-h-8 tr-rounded-lg tr-bg-brand-bg tr-text-brand">
				<Icon className="tr-w-[18px] tr-h-[18px]" />
			</span>
			<span className="tr-text-xs tr-font-medium tr-text-tertiary">
				{label}
			</span>
		</div>
		<div className="tr-text-2xl tr-font-semibold tr-text-primary tr-leading-tight tr-truncate">
			{value}
		</div>
		{hint && (
			<div className="tr-text-xs tr-text-secondary tr-mt-1">{hint}</div>
		)}
	</div>
);

// Labelled bar list with per-vendor accent colours.
const BarList = ({ entries, max }) => (
	<div className="tr-flex tr-flex-col tr-gap-2.5">
		{entries.map(([slug, hits]) => {
			const color = platformColor(slug);
			const pct = Math.max(3, Math.round((hits / max) * 100));
			return (
				<div key={slug} className="tr-flex tr-items-center tr-gap-3">
					<span className="tr-flex tr-items-center tr-gap-2 tr-w-48 tr-shrink-0 tr-min-w-0">
						<span
							className="tr-w-2.5 tr-h-2.5 tr-rounded-full tr-shrink-0"
							style={{ backgroundColor: color }}
						/>
						<span className="tr-text-sm tr-text-secondary tr-truncate">
							{sourceLabel(slug)}
						</span>
					</span>
					<span className="tr-flex-1 tr-h-2.5 tr-rounded-full tr-bg-line-2 tr-overflow-hidden">
						<span
							className="tr-block tr-h-full tr-rounded-full"
							style={{ width: `${pct}%`, backgroundColor: color }}
						/>
					</span>
					<span className="tr-w-12 tr-text-right tr-text-sm tr-font-semibold tr-text-primary tr-tabular-nums">
						{hits}
					</span>
				</div>
			);
		})}
	</div>
);

const PanelTitle = ({ children, trailing }) => (
	<div className="tr-flex tr-items-center tr-justify-between tr-gap-3 tr-mb-3">
		<h4 className="tr-m-0 tr-text-sm tr-font-semibold tr-text-primary">
			{children}
		</h4>
		{trailing}
	</div>
);

// An inline Pro slot: a tile, a column, a line inside a free panel. Rendered
// only for a licensed Pro. Unlike ProGatedTab it shows no teaser and no licence
// notice — a notice wedged into a row of metric tiles would crowd the data the
// row is for. The AI Revenue tab and the Markdown card on this same screen
// already carry the upsell and the licence state.
const ProInlineSlot = ({ filter, args = [] }) =>
	window.thinkrankAdmin?.isPro
		? (applyFilters(filter, null, ...args) ?? null)
		: null;

// Pro rows for platforms GA4 credited but the server never saw. Without them
// the conversions tile counts sessions no row accounts for. Pro gets the
// server's slugs to leave out, and the label and colour helpers so its rows
// read like these.
const Ga4OnlyPlatformsSlot = ({ platforms, days }) => (
	<ProInlineSlot
		filter="thinkrank.aiInsights.ga4OnlyPlatforms"
		args={[
			{
				platforms,
				days,
				label: sourceLabel,
				color: platformColor,
			},
		]}
	/>
);

// Platform rows that open onto the pages each platform sent visitors to.
export const PlatformList = ({ entries, max, pagesByPlatform = {}, days }) => {
	const [open, setOpen] = useState(null);

	return (
		<div className="tr-flex tr-flex-col tr-gap-1">
			{entries.map(([slug, hits]) => {
				const color = platformColor(slug);
				const pct = Math.max(3, Math.round((hits / max) * 100));
				const pages = Object.entries(pagesByPlatform[slug] || {});
				const isOpen = open === slug;
				const panelId = `tr-ai-platform-${slug}`;

				return (
					<div key={slug} className="tr-rounded-lg">
						<button
							type="button"
							aria-expanded={isOpen}
							aria-controls={panelId}
							onClick={() => setOpen(isOpen ? null : slug)}
							className="tr-flex tr-w-full tr-items-center tr-gap-3 tr-rounded-lg tr-border-0 tr-bg-transparent tr-px-2 tr-py-1.5 tr-text-left tr-cursor-pointer hover:tr-bg-line-2 tr-transition-colors"
						>
							<span
								aria-hidden="true"
								className={classnames(
									'tr-w-3 tr-text-xs tr-text-tertiary tr-transition-transform',
									isOpen && 'tr-rotate-90'
								)}
							>
								›
							</span>
							<span className="tr-flex tr-items-center tr-gap-2 tr-w-44 tr-shrink-0 tr-min-w-0">
								<span
									className="tr-w-2.5 tr-h-2.5 tr-rounded-full tr-shrink-0"
									style={{ backgroundColor: color }}
								/>
								<span className="tr-text-sm tr-text-secondary tr-truncate">
									{sourceLabel(slug)}
								</span>
							</span>
							<span className="tr-flex-1 tr-h-2.5 tr-rounded-full tr-bg-line-2 tr-overflow-hidden">
								<span
									className="tr-block tr-h-full tr-rounded-full"
									style={{
										width: `${pct}%`,
										backgroundColor: color,
									}}
								/>
							</span>
							<span className="tr-w-12 tr-text-right tr-text-sm tr-font-semibold tr-text-primary tr-tabular-nums">
								{hits}
							</span>
						</button>

						{isOpen && (
							<div
								id={panelId}
								className="tr-ml-8 tr-mr-2 tr-mb-2 tr-mt-1 tr-flex tr-flex-col tr-gap-2"
							>
								<ProInlineSlot
									filter="thinkrank.aiInsights.platformConversions"
									args={[{ platform: slug, days }]}
								/>
								{pages.length > 0 ? (
									<div className="tr-flex tr-flex-col tr-gap-0.5">
										<span className="tr-text-xs tr-font-medium tr-text-tertiary">
											{sprintf(
												/* translators: %s: AI platform name. */
												__(
													'Pages %s sent visitors to',
													'thinkrank'
												),
												sourceLabel(slug)
											)}
										</span>
										{pages.map(([path, pageHits]) => (
											<div
												key={path}
												className="tr-flex tr-items-center tr-justify-between tr-gap-4 tr-rounded tr-px-2 tr-py-1 tr-text-sm"
											>
												<code className="tr-truncate tr-text-secondary tr-bg-transparent tr-p-0">
													{path}
												</code>
												<span className="tr-font-semibold tr-text-primary tr-tabular-nums">
													{pageHits}
												</span>
											</div>
										))}
									</div>
								) : (
									<p className="tr-m-0 tr-text-sm tr-text-tertiary">
										{__(
											'No landing page was recorded for these visits.',
											'thinkrank'
										)}
									</p>
								)}
							</div>
						)}
					</div>
				);
			})}
			<Ga4OnlyPlatformsSlot
				platforms={entries.map(([slug]) => slug)}
				days={days}
			/>
		</div>
	);
};

const TONE_DOT = {
	good: 'tr-bg-success',
	warning: 'tr-bg-warning',
	poor: 'tr-bg-danger',
	neutral: 'tr-bg-line-1',
};

export const GuidanceCard = ({ title, tone, status, detail, href, action }) => (
	<div className="tr-flex tr-flex-col tr-gap-2 tr-rounded-xl tr-border tr-border-secondary tr-bg-white tr-p-4">
		<span className="tr-text-xs tr-font-medium tr-text-tertiary">
			{title}
		</span>
		<span className="tr-flex tr-items-center tr-gap-2 tr-text-sm tr-font-semibold tr-text-primary">
			<span
				className={classnames(
					'tr-w-2 tr-h-2 tr-rounded-full tr-shrink-0',
					TONE_DOT[tone] || TONE_DOT.neutral
				)}
				aria-hidden="true"
			/>
			{status}
		</span>
		<span className="tr-text-xs tr-text-secondary tr-leading-snug">
			{detail}
		</span>
		{href && (
			<a
				href={href}
				className="tr-mt-auto tr-text-xs tr-font-medium tr-text-brand tr-no-underline hover:tr-underline"
			>
				{action}
			</a>
		)}
	</div>
);

// Where the site stands on each control that affects AI visibility, with a
// link to it. States, never promises: no citations, no exposure estimates.
const GuidanceCards = ({ guidance }) => {
	const now = Math.floor(Date.now() / 1000);
	const crawlers = crawlerCard(guidance?.crawlers);
	const llms = llmsCard(guidance?.llms_txt, now);
	const crawlingLink = (item) =>
		getPageUrl('essential-seo', {
			nav_section: 'crawling-ai-indexing',
			nav_item: item,
		});

	return (
		<div>
			<PanelTitle>{__('AI visibility controls', 'thinkrank')}</PanelTitle>
			<div className="tr-grid tr-grid-cols-1 md:tr-grid-cols-3 tr-gap-3">
				<GuidanceCard
					title={__('AI crawler access', 'thinkrank')}
					{...crawlers}
					href={crawlingLink('robots-management')}
					action={__('Review crawler rules →', 'thinkrank')}
				/>
				<GuidanceCard
					title={__('llms.txt', 'thinkrank')}
					{...llms}
					href={crawlingLink('llms-ai-indexing')}
					action={
						guidance?.llms_txt?.published
							? __('Edit llms.txt →', 'thinkrank')
							: __('Generate llms.txt →', 'thinkrank')
					}
				/>
				<ProGatedTab
					filter="thinkrank.aiInsights.markdownCard"
					args={[
						{
							href: crawlingLink('markdown-for-ai'),
						},
					]}
					teaser={
						<GuidanceCard
							title={__('Markdown for AI', 'thinkrank')}
							tone="neutral"
							status={__(
								'Available in ThinkRank Pro',
								'thinkrank'
							)}
							detail={__(
								'Serves AI agents a clean Markdown copy of each page instead of the full HTML.',
								'thinkrank'
							)}
							href={crawlingLink('markdown-for-ai')}
							action={__('Learn more →', 'thinkrank')}
						/>
					}
				/>
			</div>
		</div>
	);
};

// --- Tab: AI Traffic ---------------------------------------------------------

const RANGES = [7, 30, 90];

const TrafficPanel = () => {
	const [days, setDays] = useState(30);
	const [view, setView] = useState('platforms');
	const [data, setData] = useState(null);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState('');

	useEffect(() => {
		let live = true;
		setLoading(true);
		apiFetch({ path: `/thinkrank/v1/ai-insights/traffic?days=${days}` })
			.then((res) => {
				if (!live) return;
				setData(res.data);
				setError('');
			})
			.catch((err) => {
				if (!live) return;
				logger.error('ThinkRank: AI traffic load failed', err);
				setError(
					__(
						'Couldn’t load AI traffic data. Reload the page and try again.',
						'thinkrank'
					)
				);
			})
			.finally(() => live && setLoading(false));
		return () => {
			live = false;
		};
	}, [days]);

	const rangeSelector = (
		<div className="tr-inline-flex tr-rounded-lg tr-border tr-border-secondary tr-bg-white tr-p-0.5">
			{RANGES.map((r) => (
				<button
					key={r}
					type="button"
					onClick={() => setDays(r)}
					className={classnames(
						'tr-px-2.5 tr-py-1 tr-rounded-md tr-text-xs tr-font-medium tr-border-0 tr-cursor-pointer tr-transition-colors',
						days === r
							? 'tr-bg-brand tr-text-white'
							: 'tr-bg-transparent tr-text-tertiary hover:tr-text-secondary'
					)}
				>
					{sprintf(
						/* translators: %d: number of days. */
						__('%dd', 'thinkrank'),
						r
					)}
				</button>
			))}
		</div>
	);

	if (error) {
		return <Alert variant="error">{error}</Alert>;
	}

	if (loading && !data) {
		return (
			<div className="tr-flex tr-flex-col tr-gap-5">
				<div className="tr-flex tr-flex-wrap tr-gap-3">
					{[0, 1, 2].map((i) => (
						<div
							key={i}
							className="tr-flex-1 tr-min-w-[170px] tr-rounded-xl tr-border tr-border-secondary tr-p-4"
						>
							<Skeleton className="tr-h-4 tr-w-24 tr-mb-3" />
							<Skeleton className="tr-h-7 tr-w-16" />
						</div>
					))}
				</div>
				<Skeleton className="tr-h-[72px] tr-w-full tr-rounded-lg" />
				<div className="tr-flex tr-flex-col tr-gap-2.5">
					{[0, 1, 2, 3].map((i) => (
						<Skeleton key={i} className="tr-h-3 tr-w-full" />
					))}
				</div>
			</div>
		);
	}

	const platforms = Object.entries(data.platforms || {});
	const crawlers = Object.entries(data.crawlers || {});
	const pages = Object.entries(data.top_pages || {});
	const trendPoints = Object.entries(data.trend || {}).map(
		([day, value]) => ({
			label: day,
			value,
		})
	);
	// The series is zero-filled server-side, so its length is the window size,
	// not the amount of traffic — a site with one active day now yields 31
	// points rather than 1. Gate the chart on days that actually had a
	// referral, so a single spike still shows as a stat rather than a lone
	// blip on a flat line of zeroes.
	const trendDaysWithData = trendPoints.filter((p) => p.value > 0).length;
	const maxPlatform = Math.max(1, ...platforms.map(([, h]) => h));
	const maxCrawler = Math.max(1, ...crawlers.map(([, h]) => h));
	const topPlatform = platforms.length ? sourceLabel(platforms[0][0]) : '—';
	// Markdown serves count as recorded AI traffic too — "nothing yet" while
	// Pro is actively handing pages to agents would be wrong.
	const nothingYet =
		platforms.length === 0 &&
		crawlers.length === 0 &&
		!(data.markdown_served > 0);

	return (
		<div
			className={classnames(
				'tr-flex tr-flex-col tr-gap-5',
				loading && 'tr-opacity-60'
			)}
		>
			{!nothingYet && (
				<>
					<div className="tr-flex tr-items-center tr-justify-between tr-gap-3">
						<h4 className="tr-m-0 tr-text-sm tr-font-semibold tr-text-primary">
							{__('Overview', 'thinkrank')}
						</h4>
						{rangeSelector}
					</div>

					<div className="tr-flex tr-flex-wrap tr-gap-3">
						<MetricCard
							icon={BoltIcon}
							label={sprintf(
								/* translators: %d: number of days. */
								__('AI referral visits (%dd)', 'thinkrank'),
								data.days
							)}
							value={Number(data.ai_sessions).toLocaleString()}
						/>
						<MetricCard
							icon={TrendIcon}
							label={__('Share of all visits', 'thinkrank')}
							value={`${data.ai_share}%`}
							hint={sprintf(
								/* translators: %s: total tracked pageviews. */
								__('of %s tracked pageviews', 'thinkrank'),
								Number(data.baseline).toLocaleString()
							)}
						/>
						<MetricCard
							icon={StarIcon}
							label={__('Top AI platform', 'thinkrank')}
							value={topPlatform}
						/>
						<ProInlineSlot
							filter="thinkrank.aiInsights.conversions"
							args={[{ days: data.days }]}
						/>
					</div>
					<ProInlineSlot
						filter="thinkrank.aiInsights.reconciliation"
						args={[
							{
								days: data.days,
								// GA4's window: complete days to yesterday.
								// ai_sessions also holds today.
								serverSessions:
									data.ai_sessions_to_yesterday ??
									data.ai_sessions,
							},
						]}
					/>
				</>
			)}

			{nothingYet ? (
				<EmptyState
					icon={GlobeIcon}
					illustration={false}
					title={__('No AI traffic recorded yet', 'thinkrank')}
					description={__(
						'Tracking started with this update. As AI platforms send visitors and AI crawlers fetch your pages, they’ll appear here no setup needed.',
						'thinkrank'
					)}
				/>
			) : (
				<>
					{trendDaysWithData > 1 && (
						<div className="tr-rounded-xl tr-border tr-border-secondary tr-bg-white tr-p-4">
							<PanelTitle>
								{__('AI referrals over time', 'thinkrank')}
							</PanelTitle>
							<TrendArea points={trendPoints} />
						</div>
					)}

					<div>
						<Tabs
							tabs={[
								{
									id: 'platforms',
									label: __('Platforms', 'thinkrank'),
									badge: platforms.length,
								},
								{
									id: 'pages',
									label: __('Top Pages', 'thinkrank'),
									badge: pages.length,
								},
								{
									id: 'crawlers',
									label: __('Crawlers', 'thinkrank'),
									badge: crawlers.length,
								},
							]}
							activeTab={view}
							onTabChange={setView}
						/>

						<div className="tr-pt-4">
							{view === 'platforms' &&
								(platforms.length > 0 ? (
									<PlatformList
										entries={platforms}
										max={maxPlatform}
										pagesByPlatform={data.pages_by_platform}
										days={data.days}
									/>
								) : (
									<>
										<p className="tr-m-0 tr-text-sm tr-text-secondary">
											{__(
												'No AI platform referrals recorded yet.',
												'thinkrank'
											)}
										</p>
										<Ga4OnlyPlatformsSlot
											platforms={[]}
											days={data.days}
										/>
									</>
								))}

							{view === 'pages' &&
								(pages.length > 0 ? (
									<div className="tr-flex tr-flex-col tr-gap-1">
										{pages.map(([path, hits]) => (
											<div
												key={path}
												className="tr-flex tr-items-center tr-justify-between tr-gap-4 tr-rounded-lg tr-px-3 tr-py-2 tr-text-sm hover:tr-bg-line-2 tr-transition-colors"
											>
												<code className="tr-truncate tr-text-secondary tr-bg-transparent tr-p-0">
													{path}
												</code>
												<span className="tr-font-semibold tr-text-primary tr-tabular-nums">
													{hits}
												</span>
											</div>
										))}
									</div>
								) : (
									<p className="tr-m-0 tr-text-sm tr-text-secondary">
										{__(
											'No landing pages recorded yet.',
											'thinkrank'
										)}
									</p>
								))}

							{view === 'crawlers' && (
								<>
									<div className="tr-flex tr-justify-end tr-gap-2 tr-flex-wrap tr-mb-3">
										{(data.markdown_served || 0) > 0 && (
											<Badge variant="good">
												{sprintf(
													/* translators: %d: number of Markdown responses served to AI agents. */
													_n(
														'%d Markdown page served to AI',
														'%d Markdown pages served to AI',
														data.markdown_served,
														'thinkrank'
													),
													data.markdown_served
												)}
											</Badge>
										)}
										<Badge
											variant={
												data.llms_txt
													? 'good'
													: 'warning'
											}
										>
											{data.llms_txt
												? __(
														'llms.txt is live',
														'thinkrank'
													)
												: __(
														'No llms.txt',
														'thinkrank'
													)}
										</Badge>
									</div>
									{crawlers.length > 0 ? (
										<BarList
											entries={crawlers}
											max={maxCrawler}
										/>
									) : (
										<p className="tr-m-0 tr-text-sm tr-text-secondary">
											{__(
												'No AI crawler hits recorded yet.',
												'thinkrank'
											)}
										</p>
									)}
								</>
							)}
						</div>
					</div>
				</>
			)}

			{/* Shown even before any traffic: where the site stands on these
			    controls is useful before the first AI visit, not after. */}
			{data.guidance && <GuidanceCards guidance={data.guidance} />}
		</div>
	);
};

// --- Section shell -----------------------------------------------------------

const TABS = [
	{ id: 'traffic', label: __('AI Traffic', 'thinkrank'), icon: BoltIcon },
	{ id: 'revenue', label: __('AI Revenue', 'thinkrank'), icon: TrendIcon },
];

const AIInsightsTab = () => {
	const [active, setActive] = useState('traffic');

	return (
		<Card padding={false}>
			<SectionHeader
				title={__('AI Insights', 'thinkrank')}
				description={__(
					'How AI platforms send you visitors, and which AI crawlers read your site.',
					'thinkrank'
				)}
			/>
			<div className="tr-px-6 tr-pt-4">
				<Tabs tabs={TABS} activeTab={active} onTabChange={setActive} />
			</div>
			<div className="tr-p-6">
				<TabPanel tabId="traffic" activeTab={active}>
					{active === 'traffic' && <TrafficPanel />}
				</TabPanel>
				<TabPanel tabId="revenue" activeTab={active}>
					{active === 'revenue' && (
						<ProGatedTab
							filter="thinkrank.aiInsights.revenueTab"
							teaser={
								<ProTeaser
									illustration={<InternalLinksIllustration />}
									title={__(
										'AI referral revenue',
										'thinkrank'
									)}
									description={__(
										'AI Traffic shows how many people ChatGPT, Perplexity and other assistants send you. ThinkRank Pro shows what they spend: WooCommerce orders and revenue credited to the AI platform that brought the customer.',
										'thinkrank'
									)}
									features={[
										__(
											'Revenue, orders and average order value per AI platform',
											'thinkrank'
										),
										__(
											'The landing pages that earn the most from AI visitors',
											'thinkrank'
										),
										__(
											'A configurable attribution window, stated plainly',
											'thinkrank'
										),
									]}
								/>
							}
						/>
					)}
				</TabPanel>
			</div>
		</Card>
	);
};

export default AIInsightsTab;
