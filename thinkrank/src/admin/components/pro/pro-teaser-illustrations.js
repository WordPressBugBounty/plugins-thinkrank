/**
 * Pro teaser illustrations.
 *
 * One bespoke line-style SVG per Pro feature, drawn on the soft accent panel
 * of the ProTeaser card. Each is a self-contained, decorative
 * (aria-hidden) component sharing a common palette so every teaser reads as
 * part of the same family.
 *
 * Palette: accent #4451ff, mid #7b82ff, soft #a5adff, wash #eef0fd, white.
 *
 * @package
 * @since 1.18.0
 */

const A = '#4451ff'; // accent
const M = '#7b82ff'; // mid
const S = '#c3c8ff'; // soft
const W = '#ffffff'; // white

const frame = {
	width: '100%',
	height: '100%',
	maxWidth: '150px',
	maxHeight: '150px',
	display: 'block',
};

/* Redirections & 404 Monitor — OLD window redirecting to NEW window. */
export const RedirectionsIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<rect
			x="10"
			y="20"
			width="52"
			height="42"
			rx="6"
			fill={W}
			stroke={S}
			strokeWidth="2.5"
		/>
		<path d="M10 30h52" stroke={S} strokeWidth="2.5" />
		<circle cx="17" cy="25" r="1.6" fill={S} />
		<circle cx="23" cy="25" r="1.6" fill={S} />
		<rect x="18" y="38" width="36" height="16" rx="3" fill="#eef0fd" />
		<text
			x="36"
			y="49.5"
			fontSize="8"
			fontWeight="700"
			fill={M}
			textAnchor="middle"
			fontFamily="system-ui, sans-serif"
		>
			OLD
		</text>
		<rect
			x="58"
			y="58"
			width="52"
			height="42"
			rx="6"
			fill={W}
			stroke={A}
			strokeWidth="2.5"
		/>
		<path d="M58 68h52" stroke={A} strokeWidth="2.5" />
		<circle cx="65" cy="63" r="1.6" fill={A} />
		<circle cx="71" cy="63" r="1.6" fill={A} />
		<rect x="66" y="76" width="36" height="16" rx="3" fill={A} />
		<text
			x="84"
			y="87.5"
			fontSize="8"
			fontWeight="700"
			fill={W}
			textAnchor="middle"
			fontFamily="system-ui, sans-serif"
		>
			NEW
		</text>
		<path
			d="M66 40c14 2 22 10 22 22"
			stroke={A}
			strokeWidth="2.5"
			strokeLinecap="round"
		/>
		<path
			d="M83 56l5 6 6-4"
			stroke={A}
			strokeWidth="2.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

/* Broken Link Checker — a chain link snapped in two. */
export const BrokenLinksIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<circle cx="60" cy="60" r="46" fill="#eef0fd" />
		<path
			d="M50 44l-9 9a13 13 0 0 0 0 18 13 13 0 0 0 8 3.8"
			stroke={A}
			strokeWidth="4"
			strokeLinecap="round"
		/>
		<path
			d="M70 76l9-9a13 13 0 0 0 0-18 13 13 0 0 0-8-3.8"
			stroke={A}
			strokeWidth="4"
			strokeLinecap="round"
		/>
		<path
			d="M54 60h5M61 60h5"
			stroke={M}
			strokeWidth="4"
			strokeLinecap="round"
		/>
		<path
			d="M60 30v8M76 36l-5 6M44 36l5 6"
			stroke={S}
			strokeWidth="3"
			strokeLinecap="round"
		/>
	</svg>
);

/* AI Internal Links — a small node graph with an AI spark. */
export const InternalLinksIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<circle cx="60" cy="60" r="46" fill="#eef0fd" />
		<path
			d="M36 42l24 18M60 60l26-22M60 60l-18 30M60 60l26 24"
			stroke={S}
			strokeWidth="2.5"
		/>
		<circle cx="36" cy="42" r="8" fill={W} stroke={A} strokeWidth="3" />
		<circle cx="86" cy="38" r="7" fill={W} stroke={M} strokeWidth="3" />
		<circle cx="42" cy="90" r="7" fill={W} stroke={M} strokeWidth="3" />
		<circle cx="86" cy="84" r="7" fill={W} stroke={M} strokeWidth="3" />
		<circle cx="60" cy="60" r="11" fill={A} />
		<path
			d="M60 53l1.6 4L66 58l-3.4 2.4L64 65l-4-2.6L56 65l1.4-4.6L54 58l4.4-1z"
			fill={W}
		/>
	</svg>
);

/* WooCommerce SEO — a shopping tag with a rank star. */
export const WooCommerceIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<circle cx="60" cy="60" r="46" fill="#eef0fd" />
		<path
			d="M40 34h20l30 30-26 26-30-30V34z"
			fill={W}
			stroke={A}
			strokeWidth="3"
			strokeLinejoin="round"
		/>
		<circle cx="50" cy="46" r="4.5" fill={A} />
		<path
			d="M69 66l3 6 6.6.8-4.8 4.6L75 84l-6-3.2L63 84l1.2-6.6-4.8-4.6 6.6-.8 3-6z"
			fill={M}
		/>
	</svg>
);

/* Publisher Sitemaps — newspaper + video play badge. */
export const PublisherSitemapsIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<rect
			x="20"
			y="28"
			width="58"
			height="64"
			rx="6"
			fill={W}
			stroke={A}
			strokeWidth="3"
		/>
		<path
			d="M30 42h24M30 52h38M30 62h38M30 72h26"
			stroke={S}
			strokeWidth="3"
			strokeLinecap="round"
		/>
		<rect x="30" y="40" width="16" height="16" rx="2" fill="#eef0fd" />
		<circle cx="82" cy="78" r="18" fill={A} />
		<path d="M78 71l10 7-10 7V71z" fill={W} />
	</svg>
);

/* Markdown for AI — a document whose lines resolve into markdown syntax. */
export const MarkdownForAiIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<rect
			x="24"
			y="20"
			width="62"
			height="76"
			rx="6"
			fill={W}
			stroke={A}
			strokeWidth="3"
		/>
		<path
			d="M34 36h16M34 60h30M34 70h24M34 80h30"
			stroke={S}
			strokeWidth="3"
			strokeLinecap="round"
		/>
		<path
			d="M34 46h42"
			stroke={M}
			strokeWidth="3"
			strokeLinecap="round"
			strokeDasharray="4 5"
		/>
		<rect x="66" y="60" width="42" height="30" rx="5" fill={A} />
		<path
			d="M73 82V68l7 8 7-8v14M95 68v10m0 0l-4-4m4 4l4-4"
			stroke={W}
			strokeWidth="3"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

/* AI Usage Policy — a document stamped with a policy shield. */
export const AiUsagePolicyIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<rect
			x="20"
			y="18"
			width="60"
			height="80"
			rx="6"
			fill={W}
			stroke={A}
			strokeWidth="3"
		/>
		<path
			d="M30 34h34M30 46h40M30 58h26"
			stroke={S}
			strokeWidth="3"
			strokeLinecap="round"
		/>
		<path
			d="M30 70h20"
			stroke={M}
			strokeWidth="3"
			strokeLinecap="round"
			strokeDasharray="4 5"
		/>
		<path
			d="M84 50l18 7v14c0 11-8 18-18 22-10-4-18-11-18-22V57l18-7z"
			fill={A}
		/>
		<path
			d="M77 71l5 5 11-11"
			stroke={W}
			strokeWidth="3"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

/* Audit history — a rising score line over two stacked run cards. */
export const AuditHistoryIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<rect
			x="14"
			y="66"
			width="42"
			height="34"
			rx="5"
			fill={W}
			stroke={S}
			strokeWidth="3"
		/>
		<rect
			x="64"
			y="66"
			width="42"
			height="34"
			rx="5"
			fill={W}
			stroke={A}
			strokeWidth="3"
		/>
		<path
			d="M22 78h26M22 88h18M72 78h26M72 88h18"
			stroke={S}
			strokeWidth="3"
			strokeLinecap="round"
		/>
		<path
			d="M18 52l20-14 16 12 24-28"
			stroke={A}
			strokeWidth="3.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
		<circle cx="78" cy="22" r="6" fill={A} />
		<circle cx="38" cy="38" r="4.5" fill={M} />
		<circle cx="54" cy="50" r="4.5" fill={M} />
	</svg>
);

/* Focus Pages — one page card with a diagnosis column beside it. */
export const FocusPagesIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<rect
			x="16"
			y="20"
			width="50"
			height="80"
			rx="6"
			fill={W}
			stroke={A}
			strokeWidth="3"
		/>
		<path
			d="M26 36h30M26 48h22M26 60h30M26 72h18"
			stroke={S}
			strokeWidth="3"
			strokeLinecap="round"
		/>
		<circle cx="86" cy="34" r="7" fill={A} />
		<circle cx="86" cy="58" r="7" fill={M} />
		<circle cx="86" cy="82" r="7" fill={S} />
		<path
			d="M96 34h10M96 58h10M96 82h10"
			stroke={S}
			strokeWidth="3"
			strokeLinecap="round"
		/>
		<path
			d="M83 34l2.5 2.5L90 32"
			stroke={W}
			strokeWidth="2.4"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

/* Rank Tracker — bar chart trending up with a magnifier. */
export const RankTrackerIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<circle cx="60" cy="60" r="46" fill="#eef0fd" />
		<rect x="34" y="66" width="10" height="20" rx="2" fill={S} />
		<rect x="50" y="54" width="10" height="32" rx="2" fill={M} />
		<rect x="66" y="42" width="10" height="44" rx="2" fill={A} />
		<path
			d="M34 60l16-10 16-8 18-14"
			stroke={A}
			strokeWidth="3"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
		<path
			d="M84 22l6 1-1 6"
			stroke={A}
			strokeWidth="3"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

/* Index Status — stacked pages with a check. */
export const IndexStatusIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<circle cx="60" cy="60" r="46" fill="#eef0fd" />
		<rect
			x="36"
			y="30"
			width="40"
			height="52"
			rx="5"
			fill={W}
			stroke={M}
			strokeWidth="3"
			transform="rotate(-8 56 56)"
		/>
		<rect
			x="44"
			y="38"
			width="40"
			height="52"
			rx="5"
			fill={W}
			stroke={A}
			strokeWidth="3"
		/>
		<path
			d="M52 50h24M52 60h24M52 70h14"
			stroke={S}
			strokeWidth="3"
			strokeLinecap="round"
		/>
		<circle cx="82" cy="82" r="13" fill={A} />
		<path
			d="M76 82l4 4 8-8"
			stroke={W}
			strokeWidth="3"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

/* Google Analytics — donut chart beside rising bars. */
export const GoogleAnalyticsIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<circle cx="60" cy="60" r="46" fill="#eef0fd" />
		<circle cx="50" cy="56" r="18" fill={W} stroke={S} strokeWidth="6" />
		<path
			d="M50 38a18 18 0 0 1 15 27"
			fill="none"
			stroke={A}
			strokeWidth="6"
			strokeLinecap="round"
		/>
		<circle cx="50" cy="56" r="5" fill={M} />
		<rect x="72" y="62" width="7" height="16" rx="2" fill={S} />
		<rect x="82" y="52" width="7" height="26" rx="2" fill={A} />
	</svg>
);

/* Traffic Overview — a rising area/line chart of sessions. */
export const TrafficOverviewIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<circle cx="60" cy="60" r="46" fill="#eef0fd" />
		<path
			d="M32 78 L44 66 L56 72 L68 54 L80 60 L88 46 L88 82 L32 82 Z"
			fill={S}
			opacity="0.55"
		/>
		<path
			d="M32 78 L44 66 L56 72 L68 54 L80 60 L88 46"
			fill="none"
			stroke={A}
			strokeWidth="3"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
		<circle cx="88" cy="46" r="4" fill={W} stroke={A} strokeWidth="3" />
		<path d="M30 82h60" stroke={M} strokeWidth="3" strokeLinecap="round" />
	</svg>
);

/* Search Queries — search bar with result rows and a magnifier. */
export const SearchQueriesIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<circle cx="60" cy="60" r="46" fill="#eef0fd" />
		<rect
			x="30"
			y="40"
			width="46"
			height="16"
			rx="8"
			fill={W}
			stroke={M}
			strokeWidth="3"
		/>
		<circle cx="45" cy="48" r="4" fill={S} />
		<rect x="30" y="66" width="42" height="6" rx="3" fill={S} />
		<rect x="30" y="78" width="30" height="6" rx="3" fill={S} />
		<circle cx="80" cy="74" r="12" fill={W} stroke={A} strokeWidth="4" />
		<path d="M89 83l5 5" stroke={A} strokeWidth="4" strokeLinecap="round" />
	</svg>
);

/* Top Content — a page with a rising-trend badge. */
export const TopContentIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<circle cx="60" cy="60" r="46" fill="#eef0fd" />
		<rect
			x="38"
			y="32"
			width="44"
			height="54"
			rx="5"
			fill={W}
			stroke={M}
			strokeWidth="3"
		/>
		<path
			d="M48 46h24M48 56h24M48 66h14"
			stroke={S}
			strokeWidth="3"
			strokeLinecap="round"
		/>
		<circle cx="80" cy="80" r="13" fill={A} />
		<path
			d="M74 83l4-5 3 3 5-7"
			stroke={W}
			strokeWidth="2.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

/* Custom Schema — code braces around structured nodes. */
export const CustomSchemaIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<circle cx="60" cy="60" r="46" fill="#eef0fd" />
		<path
			d="M44 38c-8 0-8 16-14 22 6 6 6 22 14 22"
			stroke={A}
			strokeWidth="3.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
		<path
			d="M76 38c8 0 8 16 14 22-6 6-6 22-14 22"
			stroke={A}
			strokeWidth="3.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
		<circle cx="60" cy="48" r="5" fill={M} />
		<circle cx="52" cy="62" r="5" fill={A} />
		<circle cx="68" cy="62" r="5" fill={A} />
		<circle cx="60" cy="76" r="5" fill={M} />
		<path d="M60 53v18M56 59l8 6M64 59l-8 6" stroke={S} strokeWidth="2.5" />
	</svg>
);

/* Custom Field Mapping — meta field rows wired across to a schema node. */
export const FieldMappingIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<circle cx="60" cy="60" r="46" fill="#eef0fd" />
		<rect
			x="22"
			y="36"
			width="34"
			height="48"
			rx="4"
			fill={W}
			stroke={S}
			strokeWidth="2.5"
		/>
		<path
			d="M28 46h22M28 56h22M28 66h22M28 76h14"
			stroke={M}
			strokeWidth="2.5"
			strokeLinecap="round"
		/>
		<path
			d="M58 48h14a6 6 0 0 1 6 6v6M58 72h14a6 6 0 0 0 6-6v-6"
			stroke={A}
			strokeWidth="2.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
		<circle cx="78" cy="60" r="9" fill={A} />
		<circle cx="94" cy="40" r="6" fill={M} />
		<circle cx="94" cy="80" r="6" fill={M} />
		<path
			d="M85 55l5-10M85 65l5 10"
			stroke={S}
			strokeWidth="2.5"
			strokeLinecap="round"
		/>
	</svg>
);

/* Local SEO — map with multiple location pins. */
export const LocalSeoIllustration = () => (
	<svg
		viewBox="0 0 120 120"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		style={frame}
		aria-hidden="true"
	>
		<circle cx="60" cy="60" r="46" fill="#eef0fd" />
		<path
			d="M30 40l20-8 20 8 20-8v40l-20 8-20-8-20 8V40z"
			fill={W}
			stroke={S}
			strokeWidth="2.5"
			strokeLinejoin="round"
		/>
		<path d="M50 32v40M70 40v40" stroke={S} strokeWidth="2.5" />
		<path
			d="M52 46a10 10 0 0 0-20 0c0 7 10 16 10 16s10-9 10-16z"
			fill={M}
		/>
		<circle cx="42" cy="46" r="4" fill={W} />
		<path
			d="M84 58a9 9 0 0 0-18 0c0 6.5 9 14.5 9 14.5S84 64.5 84 58z"
			fill={A}
		/>
		<circle cx="75" cy="58" r="3.5" fill={W} />
	</svg>
);
