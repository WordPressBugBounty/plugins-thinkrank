/**
 * SEO Score Summary Component
 *
 * Displays the main SEO score summary card with Content Quality,
 * Keyword Optimization, and Readability scores - restored from jQuery implementation
 *
 * @package
 * @since 1.0.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import ScoreRing from '../ui/ScoreRing';
import { scoreBadgeStyle } from '@utils/scoreScheme';
import {
	calculateWordCount,
	calculateKeywordDensity,
	calculateReadabilityScore,
	calculateContentQuality,
	calculateKeywordOptimization,
	analyzeHeadingStructure,
	resolveEditorContent,
} from './utils/contentAnalysis';
import KeywordGauges from './KeywordGauges';

/* Compact label/value stat tile. */
const Stat = ({ label, value }) => (
	<div className="tr-flex tr-flex-col tr-gap-0.5 tr-p-3 tr-rounded-lg tr-bg-white tr-border tr-border-secondary">
		<span className="tr-text-[11px] tr-font-medium tr-text-tertiary">
			{label}
		</span>
		<span className="tr-text-sm tr-font-semibold tr-text-primary tr-break-words tr-leading-tight">
			{value}
		</span>
	</div>
);

/* Hero badge tone + message for the overall score. */
// Grade-badge tone + message mirror the post-list SEO column scheme
// (scoreScheme): >= 90 green, >= 70 blue, >= 50 amber, else red.
const getHero = (score) => {
	const style = scoreBadgeStyle(score);
	if (score >= 90)
		return {
			style,
			msg: __('Excellent SEO optimization!', 'thinkrank'),
		};
	if (score >= 70)
		return {
			style,
			msg: __('Good: a little room to improve.', 'thinkrank'),
		};
	if (score >= 50)
		return {
			style,
			msg: __('Fair: needs attention.', 'thinkrank'),
		};
	return {
		style,
		msg: __('Needs significant work.', 'thinkrank'),
	};
};

/**
 * SEO Score Summary Component
 *
 * @param {Object} props                 Component props
 * @param {Object} props.scoreData       Complete scoring data from API
 * @param {string} props.targetKeyword   Focus keyword for analysis
 * @param {string} props.postTitle       Post title
 * @param {string} props.metaDescription Meta description
 * @param {string} [props.contentFull]   Server-resolved body, uncapped, used
 *                                       for measurement in builders where the
 *                                       editor itself has nothing to read.
 * @param {string} props.contentPreview  Server-resolved content, used when the
 *                                       editor holds none (page builders).
 * @return {JSX.Element} SEO score summary component
 */
/**
 * The figures the Analysis tiles show.
 *
 * The server's win, because they describe the content the score was actually
 * calculated from. `analyze_live_content()` resolves the editor's text AND
 * whatever `thinkrank_analyzable_content` contributes, so a post with 2 words in
 * the editor and 615 added by a filter is 617 words. The browser can only ever
 * see the editor, so the panel reported 2 and labelled the post "Too short"
 * beside a score built from 617, and that pair was persisted (#829).
 *
 * The browser's figures stay as the fallback, and that is what keeps typing
 * responsive: once the editor text differs from what the calculation measured,
 * the server's numbers describe something that is no longer on screen, so the
 * live ones are the honest answer until the next Recalculate.
 *
 * @param {Object}  args                 Inputs.
 * @param {Object}  [args.scoreData]     Score response from the API.
 * @param {Object}  args.contentAnalysis Browser-measured metrics.
 * @param {boolean} args.stale           Editor text has changed since the score.
 * @return {{wordCount: number, readability: string, contentQuality: string}} Tile values.
 */
export const displayMetrics = ({ scoreData, contentAnalysis, stale }) => {
	const fresh = !stale && scoreData;

	return {
		wordCount:
			fresh && typeof scoreData.word_count === 'number'
				? scoreData.word_count
				: contentAnalysis.wordCount,
		readability:
			fresh && scoreData.readability_score
				? String(scoreData.readability_score)
				: contentAnalysis.readabilityScore,
		contentQuality:
			fresh && scoreData.content_quality
				? String(scoreData.content_quality)
				: contentAnalysis.contentQuality,
	};
};

/**
 * The server-resolved body to measure, or null to keep the editor's own text.
 *
 * Two rules live here, both of which were bugs while they were implicit.
 *
 * `contentFull` is preferred over `contentPreview` because the preview is a
 * 4,000-character AI prompt budget, and in a builder it is the only source the
 * panel has — measuring it froze Word Count at roughly 600-700 words however
 * long the page got (#778).
 *
 * And the choice is made on `undefined`, not on falsiness. `contentPreview` is
 * title + excerpt + body for the AI prompt, so treating an empty `contentFull`
 * as "missing" made an empty-bodied post report its title's word count, with
 * Readability and Content Quality derived from the title, while the server's own
 * Scoring Factors line said 0 words (#828). An empty string is an answer — this
 * post has no body — and has to be allowed to stand.
 *
 * @param {Object} args                  Resolution inputs.
 * @param {string} args.editorContent    Text the editor currently holds.
 * @param {string} [args.contentFull]    Server-resolved body; undefined when this
 *                                       bundle never localized it.
 * @param {string} [args.contentPreview] Server-resolved AI prompt text.
 * @return {string|null} Content to measure, or null to use the editor's text.
 */
export const resolveServerContent = ({
	editorContent,
	contentFull,
	contentPreview,
}) => {
	// The editor wins whenever it has anything, which is every ordinary post.
	if (String(editorContent || '').trim()) {
		return null;
	}

	const resolved = contentFull !== undefined ? contentFull : contentPreview;
	const trimmed = String(resolved ?? '').trim();

	// One representation of "nothing", so a whitespace-only resolution does not
	// reach the caller as an empty string that still looks like a value.
	return trimmed === '' ? null : trimmed;
};

const SEOScoreSummary = ({
	scoreData,
	targetKeyword = '',
	targetKeywords = [],
	postTitle = '',
	metaDescription = '',
	contentPreview = '',
	// Deliberately no default. An empty string means "the server resolved this
	// post's body and it is empty"; undefined means "this bundle never sent it".
	// Defaulting to '' collapsed the two, so an empty body fell through to
	// contentPreview and the panel measured the title (#828).
	contentFull,
}) => {
	const [contentAnalysis, setContentAnalysis] = useState({
		wordCount: 0,
		contentStructure: 'No content',
		keywordDensity: '0%',
		contentQuality: 'No content',
		keywordOptimization: 'No focus keyword',
		readabilityScore: 'No content',
	});

	/**
	 * Extract content from WordPress editor
	 */
	const getPostContent = () => {
		let content = '';
		let htmlContent = '';

		// Try to get content from Block Editor first
		if (
			typeof wp !== 'undefined' &&
			wp.data &&
			wp.data.select('core/editor')
		) {
			try {
				const blockContent = wp.data
					.select('core/editor')
					.getEditedPostContent();
				if (blockContent) {
					htmlContent = blockContent;
				}
			} catch (e) {
				// Block editor not available, try classic editor
			}
		}

		// Fallback to TinyMCE (Classic Editor)
		if (!htmlContent && typeof tinymce !== 'undefined') {
			const editor = tinymce.get('content');
			if (editor && !editor.isHidden()) {
				htmlContent = editor.getContent();
			}
		}

		// Fallback to textarea
		if (!htmlContent) {
			const contentTextarea = document.getElementById('content');
			if (contentTextarea) {
				htmlContent = contentTextarea.value || '';
			}
		}

		// Page-builder markup keeps its words inside block-attribute JSON, which
		// lives in an HTML comment, so parsing it as HTML yields nothing. The
		// resolver reaches for those attributes only when the markup itself has
		// no text: on an ordinary post, one block that stores content in its
		// attributes (a ThinkRank FAQ) must not replace the article.
		if (htmlContent) {
			const resolvedEditor = resolveEditorContent(htmlContent);
			content = resolvedEditor.text;
			htmlContent = resolvedEditor.html;
		}

		// Builders that empty post_content (Oxygen keeps the page in postmeta)
		// leave the editor with nothing to read, and the browser cannot reach
		// postmeta. The server already resolved it for us — use that rather
		// than reporting "No content" next to a valid saved score.
		//
		// `contentFull` before `contentPreview`: the preview is a 4,000-char AI
		// prompt budget, and in a builder it is the only source the panel has,
		// so measuring it froze Word Count at roughly 600-700 words no matter
		// how long the page got (#778). contentPreview stays as the fallback
		// for an editor bundle localized before contentFull existed.
		const resolved = resolveServerContent({
			editorContent: content,
			contentFull,
			contentPreview,
		});

		if (resolved !== null) {
			return { content: resolved, htmlContent };
		}

		return { content: content.trim(), htmlContent };
	};

	/**
	 * Update content analysis metrics
	 */
	// The content the newest calculation measured, so the tiles can tell "these
	// server figures still describe what is on screen" from "the user has typed
	// since". Keyed on calculated_at: a fresh calculation is the only thing that
	// makes the server's figures current again.
	const scoredContentRef = useRef(null);
	const scoredAtRef = useRef(null);
	const [contentChangedSinceScore, setContentChangedSinceScore] =
		useState(false);

	const updateContentAnalysis = () => {
		const { content, htmlContent } = getPostContent();

		// A new calculation arrived: whatever is on screen now is what it
		// measured. Otherwise, compare against that snapshot.
		const calculatedAt = scoreData?.calculated_at ?? null;
		if (calculatedAt !== scoredAtRef.current) {
			scoredAtRef.current = calculatedAt;
			scoredContentRef.current = content;
			setContentChangedSinceScore(false);
		} else if (scoredContentRef.current !== null) {
			setContentChangedSinceScore(content !== scoredContentRef.current);
		}

		if (!content) {
			setContentAnalysis({
				wordCount: 0,
				contentStructure: 'No content',
				keywordDensity: 'No keyword set',
				contentQuality: 'No content',
				keywordOptimization: 'No content',
				readabilityScore: 'No content',
			});
			return;
		}

		// Calculate metrics using the same logic as jQuery implementation
		const wordCount = calculateWordCount(content);
		const headingAnalysis = analyzeHeadingStructure(htmlContent, postTitle);
		const readabilityScore = calculateReadabilityScore(content);
		const contentQuality = calculateContentQuality(content, htmlContent);

		let keywordDensity = 'No keyword set';
		let keywordOptimization = 'No focus keyword';

		if (targetKeyword && targetKeyword.trim()) {
			const density = calculateKeywordDensity(content, targetKeyword);
			keywordDensity = `${density}% (${targetKeyword})`;
			keywordOptimization = calculateKeywordOptimization(
				content,
				htmlContent,
				postTitle,
				targetKeyword,
				metaDescription
			);
		}

		setContentAnalysis({
			wordCount,
			contentStructure: headingAnalysis.structure,
			keywordDensity,
			contentQuality,
			keywordOptimization,
			readabilityScore,
		});
	};

	// Update analysis when dependencies change
	useEffect(() => {
		updateContentAnalysis();
	}, [targetKeyword, postTitle, metaDescription]);

	// Update analysis periodically to catch content changes
	useEffect(() => {
		const interval = setInterval(updateContentAnalysis, 2000);
		return () => clearInterval(interval);
	}, [targetKeyword, postTitle, metaDescription]);

	if (!scoreData) {
		return null;
	}

	const keywordList =
		Array.isArray(targetKeywords) && targetKeywords.length
			? targetKeywords
			: targetKeyword
				? [targetKeyword]
				: [];

	const { overall_score = 0, grade = 'F' } = scoreData;

	const display = displayMetrics({
		scoreData,
		contentAnalysis,
		stale: contentChangedSinceScore,
	});

	const hero = getHero(overall_score);

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{/* Hero score */}
			<div className="tr-flex tr-items-center tr-gap-4 tr-p-4 tr-rounded-xl tr-border tr-border-secondary tr-bg-brand-bg/40">
				<ScoreRing score={overall_score} size="lg" />
				<div className="tr-min-w-0">
					<div className="tr-flex tr-items-center tr-gap-2 tr-flex-wrap">
						<span className="tr-text-sm tr-font-semibold tr-text-primary">
							{__('SEO Score', 'thinkrank')}
						</span>
						<span
							className="tr-inline-flex tr-items-center tr-h-5 tr-px-2 tr-rounded-full tr-text-[11px] tr-font-bold"
							style={hero.style}
						>
							{__('Grade', 'thinkrank')} {grade}
						</span>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-1">
						{hero.msg}
					</p>
				</div>
			</div>

			{/* Sub-scores */}
			<div className="tr-grid tr-grid-cols-3 tr-gap-2">
				<Stat
					label={__('Content Quality', 'thinkrank')}
					value={display.contentQuality}
				/>
				<Stat
					label={__('Keyword Opt.', 'thinkrank')}
					value={contentAnalysis.keywordOptimization}
				/>
				<Stat
					label={__('Readability', 'thinkrank')}
					value={display.readability}
				/>
			</div>

			{/* Content analysis */}
			<div>
				<h5 className="tr-text-[11px] tr-font-semibold tr-uppercase tr-tracking-wide tr-text-tertiary tr-m-0 tr-mb-2">
					{__('Content Analysis', 'thinkrank')}
				</h5>
				<div className="tr-grid tr-grid-cols-3 tr-gap-2">
					<Stat
						label={__('Word Count', 'thinkrank')}
						value={display.wordCount.toLocaleString()}
					/>
					<Stat
						label={__('Structure', 'thinkrank')}
						value={contentAnalysis.contentStructure}
					/>
					<Stat
						label={__('Keyword Density', 'thinkrank')}
						value={contentAnalysis.keywordDensity}
					/>
				</div>
			</div>

			{renderKeywordBreakdown(scoreData, keywordList)}
		</div>
	);
};

/**
 * Render the per-location keyword checks, and — when more than one focus
 * keyword is in play — the per-keyword score comparison above them.
 *
 * The two used to share one gate, so a single-keyword post computed the whole
 * check matrix on every scoring run and then threw it away: the panel bailed
 * out before rendering. Single keyword is the default configuration, so the
 * most common case was the one that saw nothing (#413). The comparison list
 * still needs two keywords to compare — one row saying "best" against itself
 * is noise — but the checks stand on their own.
 *
 * The final SEO score above is the highest individual keyword score; each
 * check passes if ANY keyword matches.
 *
 * @param {Object}   scoreData   Scoring data from the API.
 * @param {string[]} keywordList Focus keywords.
 * @return {JSX.Element|null} Breakdown section or null.
 */
/* Row state icons. Partial has to look unlike a pass — that is the whole point. */
const ROW_ICON = { all: '✓', partial: '◑', none: '✕' };

const ROW_CLASS = {
	all: 'tr-text-success',
	partial: 'tr-text-warning',
	none: 'tr-text-tertiary',
};

/**
 * Pair every focus keyword with whether this check found it.
 *
 * The API returns the winners only, so the losers have to be reconstructed
 * from the full keyword list — otherwise a row where one of three keywords
 * matched looks identical to one where all three did (#414).
 *
 * @param {Object}   check       One entry from keyword_checks.
 * @param {string[]} allKeywords Every focus keyword.
 * @return {Array<{keyword: string, matched: boolean}>} Per-keyword state.
 */
const keywordStates = (check, allKeywords) => {
	const matched = new Set(
		(check.matched_keywords || []).map((k) => String(k).toLowerCase())
	);

	return allKeywords.map((keyword) => ({
		keyword,
		matched: matched.has(String(keyword).toLowerCase()),
	}));
};

/**
 * Whether a row matched all of its keywords, some, or none.
 *
 * The API's `passed` is OR-combined across keywords, so it cannot tell "all"
 * from "some" — which is what made a partial row read as a clean pass. It is
 * still the fallback when there is no keyword list to count against.
 *
 * @param {Object} check      One entry from keyword_checks.
 * @param {Array}  perKeyword Output of keywordStates().
 * @param {number} hits       How many keywords matched.
 * @return {string} 'all', 'partial' or 'none'.
 */
const rowState = (check, perKeyword, hits) => {
	if (perKeyword.length === 0) {
		return check.passed ? 'all' : 'none';
	}
	if (hits === 0) {
		return 'none';
	}

	return hits === perKeyword.length ? 'all' : 'partial';
};

/**
 * What a row says on the right-hand side.
 *
 * @param {string} state 'all', 'partial' or 'none'.
 * @param {number} hits  How many keywords matched.
 * @param {number} total How many focus keywords there are.
 * @return {string} Row label.
 */
const rowLabel = (state, hits, total) => {
	if (state === 'all') {
		return __('Matched', 'thinkrank');
	}
	if (state === 'none') {
		return __('Unmatched', 'thinkrank');
	}

	return sprintf(
		/* translators: 1: number of keywords found in this location, 2: total focus keywords. */
		__('%1$d of %2$d matched', 'thinkrank'),
		hits,
		total
	);
};

const renderKeywordBreakdown = (scoreData, keywordList) => {
	const results = scoreData?.keyword_results || [];
	// One gauge per keyword (#729) replaces the per-location rows; a score
	// from before the gauges existed still gets the rows.
	const gauges = Array.isArray(scoreData?.keywords) ? scoreData.keywords : [];
	const checks = gauges.length ? null : scoreData?.keyword_checks || null;

	// Two or more keywords: the score comparison is meaningful.
	const showComparison = keywordList.length > 1 || results.length > 1;

	// Nothing to say at all — no checks computed and nothing to compare.
	if (!checks && !gauges.length && !showComparison) {
		return null;
	}

	const checkLabels = {
		title: __('Title', 'thinkrank'),
		meta_description: __('Meta description', 'thinkrank'),
		content: __('Content', 'thinkrank'),
		image_alt: __('Image alt', 'thinkrank'),
		slug: __('Slug', 'thinkrank'),
	};

	const bestScore = Math.max(...results.map((r) => r.overall_score || 0), 0);

	// Every focus keyword, so a row can say what happened to each of them —
	// not just name the winners. Falls back to the scored results when the
	// caller passes no list (the panel scores unsaved edits that way).
	const allKeywords = keywordList?.length
		? keywordList
		: results.map((r) => r.keyword).filter(Boolean);

	return (
		<div>
			<h5 className="tr-text-[11px] tr-font-semibold tr-uppercase tr-tracking-wide tr-text-tertiary tr-m-0 tr-mb-2">
				{showComparison
					? __('Per-Keyword Analysis', 'thinkrank')
					: __('Keyword Analysis', 'thinkrank')}
			</h5>

			{showComparison && (
				<div className="tr-flex tr-flex-col tr-gap-1.5 tr-mb-3">
					{results.map((r) => (
						<div
							key={r.keyword}
							className="tr-flex tr-items-center tr-justify-between tr-gap-2 tr-text-xs"
						>
							<span className="tr-flex tr-items-center tr-gap-1.5 tr-text-secondary">
								{r.keyword}
								{r.overall_score === bestScore && (
									<span className="tr-inline-flex tr-items-center tr-rounded tr-px-1.5 tr-py-0.5 tr-text-[10px] tr-font-semibold tr-bg-success-bg tr-text-success">
										{__('Best', 'thinkrank')}
									</span>
								)}
							</span>
							<span className="tr-font-semibold tr-text-primary">
								{r.overall_score} ({r.grade})
							</span>
						</div>
					))}
				</div>
			)}

			<KeywordGauges keywords={gauges} />

			{checks && (
				<div className="tr-grid tr-grid-cols-1 tr-gap-1">
					{Object.keys(checkLabels).map((key) => {
						const check = checks[key];
						if (!check) return null;

						const perKeyword = keywordStates(check, allKeywords);
						const hits = perKeyword.filter((k) => k.matched).length;
						const state = rowState(check, perKeyword, hits);

						return (
							<div key={key} className="tr-text-xs">
								<div className="tr-flex tr-items-center tr-justify-between tr-gap-2">
									<span className="tr-text-tertiary">
										{ROW_ICON[state]} {checkLabels[key]}
									</span>
									<span className={ROW_CLASS[state]}>
										{rowLabel(
											state,
											hits,
											perKeyword.length
										)}
									</span>
								</div>

								{/* Per-keyword state. Only worth the space
								    with more than one keyword — with a single
								    one the row above already says it, and a
								    chip repeating it reads as "1 of 1" noise. */}
								{perKeyword.length > 1 && (
									<div className="tr-flex tr-flex-wrap tr-gap-1 tr-mt-1 tr-mb-0.5 tr-pl-4">
										{perKeyword.map(
											({ keyword, matched }) => (
												<span
													key={keyword}
													className={`tr-inline-flex tr-items-center tr-gap-1 tr-rounded tr-px-1.5 tr-py-0.5 tr-text-[10px] ${
														matched
															? 'tr-bg-success-bg tr-text-success'
															: 'tr-text-tertiary tr-border tr-border-secondary'
													}`}
												>
													{matched ? '✓' : '✕'}{' '}
													{keyword}
												</span>
											)
										)}
									</div>
								)}
							</div>
						);
					})}
				</div>
			)}
		</div>
	);
};

export default SEOScoreSummary;
