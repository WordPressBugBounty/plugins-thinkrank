/**
 * SEO Score Calculator Component
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { SkeletonCard, EmptyState } from '../ui';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Modal, Button } from '@wordpress/components';
import classnames from 'classnames';
import apiFetch from '@wordpress/api-fetch';

import ScoreBreakdown from './ScoreBreakdown';
import ImprovementSuggestions from './ImprovementSuggestions';
import SEOScoreSummary from './SEOScoreSummary';
import { scoreBadgeStyle } from '@utils/scoreScheme';
import linkifyMessage from '../../../shared/utils/linkifyMessage';
import { resolveEditorContent } from './utils/contentAnalysis';
import {
	beginScoreRequest,
	fieldsKeyOf,
	getLatestScoreRequest,
	getLiveContent,
	getSessionScore,
	requestLiveScore,
} from './liveScore';
import emptyStateIcon from '../../../shared/assets/empty-state.svg';
import logger from '../../../shared/utils/logger';

// Session-scoped record of suggestions hidden (applied/dismissed) per post.
// Kept at module scope so it survives the component unmounting/remounting when
// the user switches metabox tabs — without persisting to the server. Cleared
// for a post when its score is recalculated (see calculateScore), and gone
// entirely on a full page reload (a new editing session).
const sessionRemovedSuggestions = new Map();

const getSessionRemoved = (postId) =>
	sessionRemovedSuggestions.get(postId) || [];

/* Small inline icons (stroke = currentColor). */
const IconGauge = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		aria-hidden="true"
	>
		<path
			d="M4 19a8 8 0 1 1 16 0"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
		/>
		<path
			d="M12 19l4-5"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

const IconRefresh = ({ spin = false }) => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		aria-hidden="true"
		className={spin ? 'tr-animate-spin' : ''}
	>
		<path
			d="M21 12a9 9 0 1 1-2.64-6.36"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
		/>
		<path
			d="M21 4v5h-5"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

/**
 * Classify what pressing the action button on a suggestion will actually do.
 *
 * Single source of truth for two things that used to be decided separately and
 * could disagree: which branch `handleApplySuggestion` takes, and what the
 * button is labelled. Labelling every suggestion "Apply" when most of them only
 * open a guidance dialog is what made the feature look broken — the button
 * promised an edit and then nothing in the post changed.
 *
 * The score sends each suggestion's `action` with it, decided in PHP by key,
 * because the text is translated and its words are no longer English (#1298).
 * A suggestion without one (a score cached before that, or one a filter added)
 * falls back to matching the English text, as before.
 *
 * @param {string|Object} suggestion   Suggestion text, or the score's {text, action} object.
 * @param {Object}        capabilities What the host component can actually apply.
 * @return {'title'|'description'|'link'|'density'|'schema'|'guidance'} Action kind.
 */
export const classifySuggestion = (suggestion, capabilities = {}) => {
	const {
		canApplyTitle = false,
		canApplyDescription = false,
		canApplyContent = false,
		hasKeyword = false,
	} = capabilities;

	const action =
		suggestion && typeof suggestion === 'object' ? suggestion.action : null;
	if (action) {
		const allowed = {
			title: canApplyTitle,
			description: canApplyDescription,
			link: canApplyContent,
			density: hasKeyword && canApplyContent,
			schema: true,
		};
		return allowed[action] ? action : 'guidance';
	}

	const text =
		suggestion && typeof suggestion === 'object'
			? suggestion.text
			: suggestion;
	const lower = String(text || '').toLowerCase();

	// Order mirrors handleApplySuggestion: first match wins.
	if (lower.includes('title') && canApplyTitle) return 'title';
	if (lower.includes('meta description') && canApplyDescription) {
		return 'description';
	}
	if (
		(lower.includes('dofollow') ||
			lower.includes('authoritative external')) &&
		canApplyContent
	) {
		return 'link';
	}

	// Low keyword-density only. Excludes "density is high" (more usage hurts)
	// and the "set a focus keyword" prompt (no keyword to work with yet).
	const lowDensity =
		hasKeyword &&
		!lower.includes('set a focus keyword') &&
		((lower.includes('keyword density') && !lower.includes('high')) ||
			(lower.includes('keyword') &&
				lower.includes('content') &&
				!lower.includes('title')));
	if (lowDensity && canApplyContent) return 'density';

	if (lower.includes('schema')) return 'schema';

	return 'guidance';
};

const SEOScoreCalculator = ({
	postId,
	targetKeyword = '',
	targetKeywords = null,
	postTitle = '',
	metaDescription = '',
	contentPreview = '',
	contentFull = '',
	scoreData: externalScoreData = null,
	onRefresh: externalOnRefresh = null,
	onScoreChange = null,
	seoTitle = '',
	contentType = 'blog_post',
	tone = 'professional',
	onApplyTitle = null,
	onApplyDescription = null,
	onApplyContent = null,
}) => {
	// Normalize the keyword list (multi-keyword aware, back-compat with single).
	const keywordList =
		Array.isArray(targetKeywords) && targetKeywords.length
			? targetKeywords
			: targetKeyword
				? [targetKeyword]
				: [];
	const keywordKey = keywordList.join('|');
	const [scoreData, setScoreData] = useState(null);
	const [isCalculating, setIsCalculating] = useState(false);
	const [error, setError] = useState(null);
	// { label, value } describing the field an Apply action just changed.
	const [applied, setApplied] = useState(null);
	// Suggestion texts removed from the visible list this session (applied or
	// dismissed). Seeded from (and mirrored to) a module-level store keyed by
	// post so the hidden state survives tab switches that remount this
	// component. Not persisted to the server — Recalculate clears it and
	// regenerates the list, so any issue that still exists reappears.
	const [removed, setRemoved] = useState(() => getSessionRemoved(postId));
	const [lastCalculated, setLastCalculated] = useState(null);
	const [modalState, setModalState] = useState({
		isOpen: false,
		type: null,
		data: null,
	});
	const [activeTab, setActiveTab] = useState('overview');

	// Whether an AI provider key is configured. This component renders in both
	// the editor metabox and the admin app, which expose the flag on different
	// globals — accept either. Gates the "Explain with AI" action so it degrades
	// to a helpful hint instead of erroring when no provider is connected.
	const aiConfigured = Boolean(
		window.thinkrankMetabox?.aiConfigured ||
			window.thinkrankAdmin?.aiConfigured
	);

	// Mirror the removed set into the module store so it survives remounts.
	useEffect(() => {
		sessionRemovedSuggestions.set(postId, removed);
	}, [postId, removed]);

	const calculateScore = useCallback(
		async (saveScore = true) => {
			if (!postId) {
				setError(__('No post ID provided', 'thinkrank'));
				return;
			}
			const isSuperseded = beginScoreRequest(postId);
			setIsCalculating(true);
			setError(null);
			try {
				const response = await requestLiveScore({
					postId,
					keywords: keywordList,
					seoTitle,
					metaDescription,
					saveScore,
					isSuperseded,
				});
				if (isSuperseded()) {
					return;
				}
				if (response.success) {
					setScoreData(response.data);
					// Only a deliberate recalculation brings back suggestions
					// hidden this session; a live re-score while typing must
					// not.
					if (saveScore) {
						setRemoved([]);
					}
					setLastCalculated(new Date());
				} else {
					setError(
						response.message ||
							__('Failed to calculate SEO score', 'thinkrank')
					);
				}
			} catch (err) {
				if (isSuperseded()) {
					return;
				}
				logger.error('SEO Score calculation error:', err);
				setError(
					err.message ||
						__(
							'An error occurred while calculating SEO score',
							'thinkrank'
						)
				);
			} finally {
				// The newer request clears it when it lands.
				if (!isSuperseded()) {
					setIsCalculating(false);
				}
			}
		},
		[postId, keywordKey, seoTitle, metaDescription]
	);

	const getLatestScore = async () => {
		if (!postId) return;
		// A live score from earlier this session is newer than the stored one.
		const session = getSessionScore(postId);
		if (session) {
			setScoreData(session.data);
			return;
		}
		// A score calculated while this read was in flight is newer.
		const newestBefore = getLatestScoreRequest(postId);
		try {
			const response = await apiFetch({
				path: `/thinkrank/v1/seo-score/get?post_id=${postId}`,
				method: 'GET',
			});
			if (getLatestScoreRequest(postId) !== newestBefore) {
				return;
			}
			if (response.success && response.data) {
				setScoreData(response.data);
				setLastCalculated(new Date(response.data.calculated_at));
			}
		} catch (err) {}
	};

	// Normalize a suggestion (string or {text}) to its display text.
	const toText = (suggestion) =>
		typeof suggestion === 'string'
			? suggestion
			: suggestion.text || String(suggestion);

	// Hide a suggestion from the visible list (applied or dismissed).
	const removeSuggestion = (text) =>
		setRemoved((prev) => (prev.includes(text) ? prev : [...prev, text]));

	// The haystack the AI suggestion fixes are given. Same resolver as the
	// score labels: on a builder page raw markup has no readable text, so this
	// handed the AI an empty body and it answered about nothing.
	const getPlainTextContent = () =>
		resolveEditorContent(getLiveContent()).text;

	// Generate an AI fix for a suggestion and apply it to the relevant field.
	// `field` is the response.data key to apply; `notice` optionally maps the
	// response to a friendlier banner string (e.g. for raw HTML fragments).
	const applyWithAI = async ({
		path,
		data,
		field,
		apply,
		label,
		fail,
		notice,
	}) => {
		setError(null);
		setApplied(null);
		try {
			const response = await apiFetch({ path, method: 'POST', data });
			const value = response?.data?.[field];
			if (response.success && value) {
				apply(value);
				setApplied({
					label,
					value: notice ? notice(response.data) : value,
				});
				return response.data || true;
			}
			setError(response.message || fail);
			return false;
		} catch (err) {
			logger.error('SEO suggestion apply error:', err);
			setError(err.message || fail);
			return false;
		}
	};

	// Read-only copilot action: fetch a plain-language, post-specific AI
	// explanation of why a suggestion matters and how to resolve it. Returns the
	// result to the caller so it can be shown inline on the suggestion (this does
	// not modify any content, so it deliberately avoids the top-level banners).
	const explainSuggestion = async (suggestion) => {
		const suggestionText = toText(suggestion);
		const content = getPlainTextContent().slice(0, 5000);
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/ai/explain-suggestion',
				method: 'POST',
				data: {
					content,
					suggestion: suggestionText,
					title: seoTitle || postTitle || '',
					target_keyword: keywordList[0] || targetKeyword,
					content_type: contentType,
				},
			});
			if (response.success && response?.data?.explanation) {
				return { ok: true, explanation: response.data.explanation };
			}
			return {
				ok: false,
				message:
					response.message ||
					__('Failed to generate an explanation', 'thinkrank'),
			};
		} catch (err) {
			logger.error('SEO suggestion explain error:', err);
			return {
				ok: false,
				message:
					err.message ||
					__('Failed to generate an explanation', 'thinkrank'),
			};
		}
	};

	/**
	 * What the action button on a suggestion will do, given what this instance
	 * can actually apply. Shared with the button label so the two never
	 * disagree about whether a suggestion is auto-appliable.
	 *
	 * @param {string|Object} suggestion The suggestion.
	 * @return {string} Action kind (see classifySuggestion).
	 */
	const resolveSuggestionKind = (suggestion) =>
		classifySuggestion(suggestion, {
			canApplyTitle: !!onApplyTitle,
			canApplyDescription: !!onApplyDescription,
			canApplyContent: !!onApplyContent,
			hasKeyword: !!(keywordList[0] || targetKeyword),
		});

	const handleApplySuggestion = async (suggestion, index) => {
		const suggestionText = toText(suggestion);
		const content = getPlainTextContent().slice(0, 5000);
		const kind = resolveSuggestionKind(suggestion);

		// Title suggestions → generate and apply an improved SEO title.
		if (kind === 'title') {
			const ok = await applyWithAI({
				path: '/thinkrank/v1/ai/improve-title',
				data: {
					content,
					current_title: seoTitle || postTitle || '',
					target_keyword: keywordList[0] || targetKeyword,
					content_type: contentType,
					tone,
					suggestion: suggestionText,
					suggestion_key: suggestion?.key || '',
				},
				field: 'title',
				apply: onApplyTitle,
				label: __('Applied a new SEO title:', 'thinkrank'),
				fail: __('Failed to generate an improved title', 'thinkrank'),
			});
			if (ok) removeSuggestion(suggestionText);
			return;
		}

		// Meta description suggestions → generate and apply a meta description.
		if (kind === 'description') {
			const ok = await applyWithAI({
				path: '/thinkrank/v1/ai/improve-meta-description',
				data: {
					content,
					current_description: metaDescription || '',
					target_keyword: keywordList[0] || targetKeyword,
					content_type: contentType,
					tone,
					suggestion: suggestionText,
				},
				field: 'description',
				apply: onApplyDescription,
				label: __('Applied a new meta description:', 'thinkrank'),
				fail: __('Failed to generate a meta description', 'thinkrank'),
			});
			if (ok) removeSuggestion(suggestionText);
			return;
		}

		// External-link suggestions → append an authoritative source link.
		// Covers both "add a dofollow link" and "cite authoritative external
		// sources"; a single dofollow link resolves either.
		if (kind === 'link') {
			const ok = await applyWithAI({
				path: '/thinkrank/v1/ai/add-dofollow-link',
				data: {
					content,
					target_keyword: keywordList[0] || targetKeyword,
					content_type: contentType,
				},
				field: 'html',
				apply: onApplyContent,
				label: __('Added an authoritative source link:', 'thinkrank'),
				fail: __('Failed to add a source link', 'thinkrank'),
				notice: (d) => d.anchor || d.url,
			});
			if (ok) removeSuggestion(suggestionText);
			return;
		}

		// Low keyword-density suggestions → append a keyword-rich paragraph.
		if (kind === 'density') {
			let densityResult = null;
			const fullText = getPlainTextContent();
			const kw = (keywordList[0] || targetKeyword || '').toLowerCase();
			const wordCount = (fullText.trim().match(/\S+/g) || []).length;
			const keywordCount = kw
				? fullText.toLowerCase().split(kw).length - 1
				: 0;
			densityResult = await applyWithAI({
				path: '/thinkrank/v1/ai/add-keyword-paragraph',
				data: {
					content,
					target_keyword: keywordList[0] || targetKeyword,
					content_type: contentType,
					tone,
					word_count: wordCount,
					keyword_count: keywordCount,
				},
				field: 'html',
				apply: onApplyContent,
				label: __(
					'Added a keyword-rich closing paragraph:',
					'thinkrank'
				),
				fail: __('Failed to add a paragraph', 'thinkrank'),
				notice: (d) =>
					d.resolves
						? `${__('keyword density is now', 'thinkrank')} ${d.new_density}% (${__('within range', 'thinkrank')})`
						: `${__('keyword density is now', 'thinkrank')} ${d.new_density}% (${__('apply again or weave the keyword into the body to reach 0.5%', 'thinkrank')})`,
			});
			// Only hide once density is actually in range; otherwise keep the
			// suggestion so the user can apply again.
			if (densityResult && densityResult.resolves)
				removeSuggestion(suggestionText);
			return;
		}

		// Schema suggestion → enable ThinkRank's Global SEO schema output.
		if (kind === 'schema') {
			setError(null);
			setApplied(null);
			try {
				const response = await apiFetch({
					path: '/thinkrank/v1/schema/enable-for-post',
					method: 'POST',
					data: { post_id: postId },
				});
				if (response.success) {
					setApplied({
						label: __(
							'Enabled ThinkRank structured data:',
							'thinkrank'
						),
						value: response.data.already_enabled
							? __(
									'already on; recalculate the score',
									'thinkrank'
								)
							: `${response.data.schema_type} ${__('schema; recalculate the score', 'thinkrank')}`,
						noSave: true,
					});
					removeSuggestion(suggestionText);
				} else {
					setError(
						response.message ||
							__('Failed to enable schema', 'thinkrank')
					);
				}
			} catch (err) {
				logger.error('SEO schema enable error:', err);
				setError(
					err.message || __('Failed to enable schema', 'thinkrank')
				);
			}
			return;
		}

		// Other suggestions → show manual guidance (existing behavior).
		const guidance = getSuggestionGuidance(suggestionText);
		setModalState({
			isOpen: true,
			type: 'apply',
			data: { suggestion: suggestionText, guidance, index },
		});
		return Promise.resolve();
	};

	const getSuggestionGuidance = (suggestion) => {
		const lower = suggestion.toLowerCase();

		// Non-actionable here: these are server/theme/performance signals that
		// no AI-generated text can change. Explain honestly what the user must do
		// rather than pretend to "apply" a fix.
		if (lower.includes('https') || lower.includes('ssl'))
			return __(
				'🔒 This is a server setting, not content. Install an SSL certificate with your host (most offer free Let’s Encrypt SSL) and serve the whole site over HTTPS. It can’t be fixed from the editor.',
				'thinkrank'
			);
		if (lower.includes('mobile'))
			return __(
				'📱 Mobile-friendliness comes from your theme, not this post. Use a responsive theme and test with Google’s Mobile-Friendly Test. There’s nothing to auto-apply to the content here.',
				'thinkrank'
			);
		if (lower.includes('core web vitals') || lower.includes('lcp'))
			return __(
				'⚡ Core Web Vitals (LCP, INP, CLS) are runtime performance metrics driven by hosting, theme, images and scripts. Measure with PageSpeed Insights and optimize there. They can’t be set from post content.',
				'thinkrank'
			);
		if (
			lower.includes('author credentials') ||
			lower.includes('trustworthiness')
		)
			return __(
				'👤 Reinforce E-E-A-T by adding an author bio with credentials, citing reputable sources in the article, and providing contact/about info on your site. This is editorial work, not an auto-applied change.',
				'thinkrank'
			);
		if (lower.includes('readability') || lower.includes('structure'))
			return __(
				'📝 Improve readability manually: split long paragraphs, shorten sentences, and add H2/H3 subheadings to break up the content into scannable sections.',
				'thinkrank'
			);

		if (lower.includes('meta description'))
			return __(
				'💡 Scroll down to the meta description field and update it with compelling, keyword-rich content.',
				'thinkrank'
			);
		if (lower.includes('title') || lower.includes('heading'))
			return __(
				'💡 Update your post title or add relevant headings (H2, H3) to your content.',
				'thinkrank'
			);
		if (lower.includes('keyword'))
			return __(
				'💡 Review your content and naturally incorporate your target keyword in key locations.',
				'thinkrank'
			);
		if (lower.includes('content') || lower.includes('words'))
			return __(
				'💡 Expand your content with more detailed, valuable information for your readers.',
				'thinkrank'
			);
		if (lower.includes('link'))
			return __(
				'💡 Add relevant internal links to other pages on your site or quality external resources.',
				'thinkrank'
			);
		if (lower.includes('image'))
			return __(
				'💡 Add relevant images with descriptive alt text to enhance your content.',
				'thinkrank'
			);
		return __(
			'💡 Review the suggestion and make the recommended changes to improve your SEO.',
			'thinkrank'
		);
	};

	const handleDismissSuggestion = (suggestion, index) => {
		const suggestionText = toText(suggestion);
		setModalState({
			isOpen: true,
			type: 'dismiss',
			data: { suggestion: suggestionText, index },
		});
		return false;
	};

	const handleModalClose = () =>
		setModalState({ isOpen: false, type: null, data: null });

	const handleDismissConfirm = () => {
		const { data } = modalState;
		// Hide locally only — not persisted. Recalculate regenerates the full
		// list, so the suggestion returns if the issue still exists.
		removeSuggestion(data.suggestion);
		setModalState({ isOpen: false, type: null, data: null });
	};

	const effectiveScoreData = externalScoreData || scoreData;
	const effectiveOnRefresh =
		externalOnRefresh || (() => calculateScore(true));

	useEffect(() => {
		if (!externalScoreData) getLatestScore();
	}, [postId, externalScoreData]);

	useEffect(() => {
		if (!externalScoreData && effectiveScoreData) {
			const prevKey = Array.isArray(effectiveScoreData.target_keywords)
				? effectiveScoreData.target_keywords.join('|')
				: effectiveScoreData.target_keyword || '';
			if (keywordKey !== prevKey) {
				const timer = setTimeout(() => calculateScore(false), 1000);
				return () => clearTimeout(timer);
			}
		}
	}, [keywordKey, externalScoreData]);

	// Keep the score live (#729): re-score when the SEO title or meta
	// description differs from what this session last scored, so a keyword
	// gauge turns green while the writer types. The first time the panel
	// opens, the stored score came from the saved post and the editor may
	// hold unsaved edits (a restored draft among them), so it is re-scored
	// once against the editor. Not while a parent owns the data.
	const fieldsKey = fieldsKeyOf(seoTitle, metaDescription);
	const hasScoreData = Boolean(effectiveScoreData);
	useEffect(() => {
		if (
			externalScoreData ||
			!hasScoreData ||
			getSessionScore(postId)?.key === fieldsKey
		) {
			return undefined;
		}
		const timer = setTimeout(() => calculateScore(false), 1500);
		return () => clearTimeout(timer);
	}, [postId, fieldsKey, hasScoreData, externalScoreData]);

	// Suggestions minus any applied/dismissed this session.
	const visibleSuggestions = (effectiveScoreData?.suggestions || []).filter(
		(s) => !removed.includes(toText(s))
	);
	const suggestionCount = visibleSuggestions.length;
	const overallScore = Number(effectiveScoreData?.overall_score);
	const rawScore = effectiveScoreData?.overall_score;
	const hasScore =
		rawScore !== undefined &&
		rawScore !== null &&
		!Number.isNaN(overallScore);

	// Report the current score upward (e.g. for the drawer header ring).
	useEffect(() => {
		if (typeof onScoreChange === 'function') {
			onScoreChange(hasScore ? overallScore : null);
		}
	}, [overallScore, hasScore, onScoreChange]);

	// Recalculate button label (avoids a nested ternary in JSX).
	let calcLabel = __('Calculate Score', 'thinkrank');
	if (isCalculating) {
		calcLabel = __('Calculating…', 'thinkrank');
	} else if (hasScore) {
		calcLabel = __('Recalculate', 'thinkrank');
	}

	const TABS = [
		{ id: 'overview', label: __('Overview', 'thinkrank') },
		{ id: 'factors', label: __('Scoring Factors', 'thinkrank') },
		{
			id: 'suggestions',
			label: __('Suggestions', 'thinkrank'),
			badge: suggestionCount,
		},
	];

	return (
		<div className="tr-bg-white tr-rounded-xl tr-border tr-border-secondary">
			{/* Header */}
			<div className="tr-flex tr-items-center tr-justify-between tr-gap-3 tr-px-4 tr-py-3 tr-border-b tr-border-secondary">
				<div className="tr-flex tr-items-center tr-gap-3 tr-min-w-0">
					<span className="tr-flex tr-items-center tr-justify-center tr-w-8 tr-h-8 tr-rounded-lg tr-bg-brand-bg tr-text-brand tr-shrink-0">
						<IconGauge />
					</span>
					<div className="tr-min-w-0">
						<div className="tr-flex tr-items-center tr-gap-2">
							<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
								{__('SEO Score Analysis', 'thinkrank')}
							</h4>
							{hasScore && (
								<span
									className="tr-inline-flex tr-items-center tr-justify-center tr-min-w-[1.75rem] tr-h-5 tr-px-1.5 tr-rounded-full tr-text-xs tr-font-bold"
									style={scoreBadgeStyle(overallScore)}
								>
									{Math.round(overallScore)}
								</span>
							)}
						</div>
						<p className="tr-flex tr-items-center tr-gap-1 tr-text-[11px] tr-text-tertiary tr-m-0 tr-mt-0.5">
							{lastCalculated
								? `${__('Last calculated', 'thinkrank')} · ${lastCalculated.toLocaleString()}`
								: __('Not analyzed yet', 'thinkrank')}
						</p>
					</div>
				</div>
				<button
					type="button"
					onClick={() => calculateScore(true)}
					disabled={isCalculating || !postTitle?.trim()}
					title={
						!postTitle?.trim()
							? __(
									'Add a post title before calculating the SEO score',
									'thinkrank'
								)
							: undefined
					}
					className="tr-inline-flex tr-items-center tr-gap-1.5 tr-h-9 tr-px-3 tr-rounded-lg tr-border-[1.5px] tr-border-line tr-bg-white tr-text-xs tr-font-semibold tr-text-secondary hover:tr-bg-brand-bg hover:tr-text-brand disabled:tr-opacity-50 disabled:tr-cursor-not-allowed tr-transition-colors tr-cursor-pointer tr-shrink-0"
				>
					<IconRefresh spin={isCalculating} />
					{calcLabel}
				</button>
			</div>

			{/* Error */}
			{error && (
				<div className="tr-mx-4 tr-mt-3 tr-flex tr-items-start tr-gap-2 tr-rounded tr-p-3 tr-text-xs tr-bg-danger-bg tr-text-danger tr-border tr-border-danger">
					<span className="tr-flex-1">{linkifyMessage(error)}</span>
					<button
						type="button"
						onClick={() => setError(null)}
						className="tr-text-danger hover:tr-opacity-70 tr-leading-none tr-border-0 tr-bg-transparent tr-cursor-pointer tr-p-0 tr-shrink-0"
					>
						✕
					</button>
				</div>
			)}

			{/* Applied-fix success */}
			{applied && (
				<div className="tr-mx-4 tr-mt-3 tr-flex tr-items-start tr-gap-2 tr-rounded tr-p-3 tr-text-xs tr-bg-success-bg tr-text-success tr-border tr-border-success">
					<span className="tr-flex-1">
						{applied.label} <strong>“{applied.value}”</strong>{' '}
						{!applied.noSave &&
							__(
								'Remember to save the post to keep it.',
								'thinkrank'
							)}
					</span>
					<button
						type="button"
						onClick={() => setApplied(null)}
						className="tr-text-success hover:tr-opacity-70 tr-leading-none tr-border-0 tr-bg-transparent tr-cursor-pointer tr-p-0 tr-shrink-0"
					>
						✕
					</button>
				</div>
			)}

			{/* Loading */}
			{isCalculating && (
				<div className="tr-p-4">
					<SkeletonCard
						lines={3}
						label={__(
							'Analyzing your content for SEO optimization…',
							'thinkrank'
						)}
					/>
				</div>
			)}

			{/* Empty state */}
			{!effectiveScoreData && !isCalculating && !error && (
				<EmptyState
					size="sm"
					title={__('No SEO Analysis Yet', 'thinkrank')}
					description={__(
						'Click "Calculate SEO Score" to analyze your content and get optimization recommendations.',
						'thinkrank'
					)}
				/>
			)}

			{/* Results */}
			{effectiveScoreData && (
				<>
					{/* Tab bar — segmented pill control */}
					<div className="tr-px-4 tr-pt-4">
						<div
							role="tablist"
							className="tr-flex tr-gap-1 tr-p-1 tr-rounded-lg tr-bg-line-2"
						>
							{TABS.map((tab) => {
								const isActive = activeTab === tab.id;
								return (
									<button
										key={tab.id}
										type="button"
										role="tab"
										aria-selected={isActive}
										onClick={() => setActiveTab(tab.id)}
										className={classnames(
											'tr-flex-1 tr-inline-flex tr-items-center tr-justify-center tr-gap-1.5',
											'tr-h-8 tr-px-2 tr-rounded-md tr-text-xs tr-font-semibold tr-whitespace-nowrap',
											'tr-border-0 tr-cursor-pointer tr-transition-colors focus:tr-outline-none',
											isActive
												? 'tr-bg-white tr-text-brand tr-shadow-sm'
												: 'tr-bg-transparent tr-text-tertiary hover:tr-text-secondary'
										)}
									>
										{tab.label}
										{tab.badge > 0 && (
											<span
												className={classnames(
													'tr-inline-flex tr-items-center tr-justify-center tr-min-w-[16px] tr-h-4 tr-px-1 tr-rounded-full tr-text-[10px] tr-font-bold',
													isActive
														? 'tr-bg-danger-bg tr-text-danger'
														: 'tr-bg-white tr-text-tertiary'
												)}
											>
												{tab.badge}
											</span>
										)}
									</button>
								);
							})}
						</div>
					</div>

					{/* Tab content — all panels stay mounted; we toggle
					    visibility so switching tabs doesn't remount/re-animate
					    (which caused the blink). */}
					<div className="tr-p-4">
						<div
							className={
								activeTab === 'overview' ? '' : 'tr-hidden'
							}
						>
							<SEOScoreSummary
								scoreData={effectiveScoreData}
								targetKeyword={keywordList[0] || targetKeyword}
								targetKeywords={keywordList}
								postTitle={postTitle}
								metaDescription={metaDescription}
								contentPreview={contentPreview}
								contentFull={contentFull}
							/>
						</div>
						<div
							className={
								activeTab === 'factors' ? '' : 'tr-hidden'
							}
						>
							<ScoreBreakdown
								scoreData={effectiveScoreData}
								showOverview={false}
								onRefresh={null}
							/>
						</div>
						<div
							className={
								activeTab === 'suggestions' ? '' : 'tr-hidden'
							}
						>
							{visibleSuggestions.length > 0 ? (
								<ImprovementSuggestions
									suggestions={visibleSuggestions}
									onApplySuggestion={handleApplySuggestion}
									resolveSuggestionKind={
										resolveSuggestionKind
									}
									onExplainSuggestion={explainSuggestion}
									aiConfigured={aiConfigured}
									onDismissSuggestion={
										handleDismissSuggestion
									}
								/>
							) : (
								<div className="tr-py-8 tr-text-center">
									<div className="tr-text-4xl tr-mb-3">
										✅
									</div>
									<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0 tr-mb-1">
										{__('Excellent Work!', 'thinkrank')}
									</h4>
									<p className="tr-text-xs tr-text-tertiary tr-m-0">
										{__(
											'No immediate SEO improvements needed. Your content is well optimized!',
											'thinkrank'
										)}
									</p>
								</div>
							)}
						</div>
					</div>
				</>
			)}

			{/* Guidance / Dismiss Modal */}
			{modalState.isOpen && (
				<Modal
					title={
						modalState.type === 'apply'
							? __('SEO Improvement Guidance', 'thinkrank')
							: __('Dismiss SEO Suggestion', 'thinkrank')
					}
					onRequestClose={handleModalClose}
					className="thinkrank-suggestion-modal"
				>
					<div className="modal-content">
						{modalState.type === 'apply' ? (
							<>
								<blockquote className="suggestion-quote">
									"{modalState.data?.suggestion}"
								</blockquote>
								<div className="guidance-section">
									<p>{modalState.data?.guidance}</p>
								</div>
								<p>
									{__(
										'Use this guidance to manually improve your content. The suggestions are recommendations to help you optimize your SEO.',
										'thinkrank'
									)}
								</p>
							</>
						) : (
							<>
								<p>
									<strong>
										{__(
											'Dismiss this suggestion?',
											'thinkrank'
										)}
									</strong>
								</p>
								<blockquote className="suggestion-quote">
									"{modalState.data?.suggestion}"
								</blockquote>
								<p>
									{__(
										'You can always recalculate your SEO score to see suggestions again.',
										'thinkrank'
									)}
								</p>
							</>
						)}
					</div>
					<div className="modal-actions">
						{modalState.type === 'apply' ? (
							<Button
								variant="primary"
								onClick={handleModalClose}
								__next40pxDefaultSize
							>
								{__('Got it', 'thinkrank')}
							</Button>
						) : (
							<>
								<Button
									variant="tertiary"
									onClick={handleModalClose}
									__next40pxDefaultSize
								>
									{__('Cancel', 'thinkrank')}
								</Button>
								<Button
									variant="primary"
									isDestructive
									onClick={handleDismissConfirm}
									__next40pxDefaultSize
								>
									{__('Dismiss', 'thinkrank')}
								</Button>
							</>
						)}
					</div>
				</Modal>
			)}
		</div>
	);
};

export default SEOScoreCalculator;
