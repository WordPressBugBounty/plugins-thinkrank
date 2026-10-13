/**
 * SEO Improvement Suggestions Component
 *
 * Displays actionable SEO improvement recommendations
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import Button from '../ui/Button';

/**
 * Improvement Suggestions Component
 *
 * @param {Object}   props                       Component props
 * @param {Array}    props.suggestions           Array of suggestion strings
 * @param {Function} props.onApplySuggestion     Callback for applying suggestions
 * @param {Function} props.resolveSuggestionKind Returns what the action button will do for a suggestion
 * @param {Function} props.onExplainSuggestion   Async callback that returns an AI explanation for a suggestion
 * @param {boolean}  props.aiConfigured          Whether an AI provider key is configured
 * @param {Function} props.onDismissSuggestion   Callback for dismissing suggestions
 * @return {JSX.Element} Improvement suggestions component
 */
const ImprovementSuggestions = ({
	suggestions = [],
	onApplySuggestion,
	resolveSuggestionKind = null,
	onExplainSuggestion,
	aiConfigured = true,
	onDismissSuggestion,
}) => {
	const [dismissedSuggestions, setDismissedSuggestions] = useState(new Set());
	const [applyingIndex, setApplyingIndex] = useState(null);
	// Per-suggestion AI explanation state, keyed by normalized suggestion text
	// (stable across list re-filtering): { [text]: { loading, text, error } }.
	const [explanations, setExplanations] = useState({});

	// Normalize a suggestion (string or { text }) to display text.
	const suggestionToText = (suggestion) => {
		if (typeof suggestion === 'string') return suggestion;
		if (suggestion && suggestion.text) return suggestion.text;
		return String(suggestion);
	};

	/**
	 * Handle applying a suggestion
	 * @param suggestion
	 * @param index
	 */
	const handleApplySuggestion = async (suggestion, index) => {
		if (!onApplySuggestion) return;

		setApplyingIndex(index);
		try {
			await onApplySuggestion(suggestion, index);
		} finally {
			setApplyingIndex(null);
		}
	};

	/**
	 * Fetch (or toggle) an AI explanation for a suggestion. Re-clicking while an
	 * explanation is shown collapses it; re-clicking after an error retries.
	 *
	 * Keyed by the normalized suggestion text (a stable identity) rather than the
	 * list index, so an explanation stays attached to its suggestion even after
	 * the list is re-filtered by an apply/dismiss elsewhere.
	 *
	 * @param {string|Object} suggestion The suggestion being explained.
	 */
	const handleExplainSuggestion = async (suggestion) => {
		if (!onExplainSuggestion) return;

		const key = suggestionToText(suggestion);
		const current = explanations[key];
		// Collapse if we already have a successful explanation shown.
		if (current && current.text && !current.loading) {
			setExplanations((prev) => {
				const next = { ...prev };
				delete next[key];
				return next;
			});
			return;
		}
		if (current && current.loading) return;

		setExplanations((prev) => ({
			...prev,
			[key]: { loading: true, text: '', error: '' },
		}));

		const result = await onExplainSuggestion(suggestion);
		setExplanations((prev) => ({
			...prev,
			[key]: {
				loading: false,
				text: result && result.ok ? result.explanation : '',
				error: result && result.ok ? '' : result?.message || '',
			},
		}));
	};

	/**
	 * Handle dismissing a suggestion
	 * @param suggestion
	 * @param index
	 */
	const handleDismissSuggestion = (suggestion, index) => {
		// Call parent handler first (it may show confirmation)
		let shouldDismiss = true;
		if (onDismissSuggestion) {
			shouldDismiss = onDismissSuggestion(suggestion, index);
		}

		// Only dismiss if parent handler allows it
		if (shouldDismiss !== false) {
			setDismissedSuggestions((prev) => new Set([...prev, index]));
		}
	};

	/**
	 * Get suggestion priority level - Enhanced to match jQuery implementation
	 * @param suggestion
	 */
	const getSuggestionPriority = (suggestion) => {
		// Handle both string and object formats (matching jQuery implementation)
		let suggestionText = '';
		let explicitPriority = null;

		if (typeof suggestion === 'string') {
			suggestionText = suggestion;
		} else if (suggestion && suggestion.text) {
			suggestionText = suggestion.text;
			explicitPriority = suggestion.priority;
		} else {
			suggestionText = String(suggestion);
		}

		// If explicit priority is set, use it
		if (explicitPriority) {
			return explicitPriority.toLowerCase();
		}

		// Enhanced keyword detection for better priority classification
		const suggestionLower = suggestionText.toLowerCase();

		// High priority indicators
		const highPriorityKeywords = [
			'missing',
			'required',
			'must',
			'critical',
			'important',
			'essential',
			'no h1',
			'no title',
			'no meta description',
			'too short',
			'too long',
			'duplicate',
			'broken',
			'error',
			'invalid',
			'empty',
		];

		// Low priority indicators
		const lowPriorityKeywords = [
			'consider',
			'might',
			'could',
			'optional',
			'suggestion',
			'recommend',
			'try',
			'perhaps',
			'maybe',
			'potentially',
			'enhance',
			'improve slightly',
		];

		// Medium priority indicators
		const mediumPriorityKeywords = [
			'should',
			'add',
			'include',
			'optimize',
			'update',
			'modify',
			'adjust',
			'review',
			'check',
			'ensure',
			'verify',
		];

		if (
			highPriorityKeywords.some((keyword) =>
				suggestionLower.includes(keyword)
			)
		) {
			return 'high';
		}

		if (
			lowPriorityKeywords.some((keyword) =>
				suggestionLower.includes(keyword)
			)
		) {
			return 'low';
		}

		if (
			mediumPriorityKeywords.some((keyword) =>
				suggestionLower.includes(keyword)
			)
		) {
			return 'medium';
		}

		// Default to medium priority
		return 'medium';
	};

	/**
	 * Get suggestion category based on content - Enhanced categorization
	 * @param suggestion
	 */
	const getSuggestionCategory = (suggestion) => {
		// Handle both string and object formats
		let suggestionText = '';
		if (typeof suggestion === 'string') {
			suggestionText = suggestion;
		} else if (suggestion && suggestion.text) {
			suggestionText = suggestion.text;
		} else {
			suggestionText = String(suggestion);
		}

		const suggestionLower = suggestionText.toLowerCase();

		// Enhanced category detection
		if (suggestionLower.includes('title') || suggestionLower.includes('h1'))
			return 'title';
		if (
			suggestionLower.includes('meta description') ||
			suggestionLower.includes('description')
		)
			return 'meta';
		if (
			suggestionLower.includes('heading') ||
			suggestionLower.includes('h2') ||
			suggestionLower.includes('h3') ||
			suggestionLower.includes('subheading')
		)
			return 'headings';
		if (
			suggestionLower.includes('keyword') ||
			suggestionLower.includes('focus keyword') ||
			suggestionLower.includes('density')
		)
			return 'keywords';
		if (
			suggestionLower.includes('content') ||
			suggestionLower.includes('word count') ||
			suggestionLower.includes('paragraph')
		)
			return 'content';
		if (
			suggestionLower.includes('readability') ||
			suggestionLower.includes('flesch') ||
			suggestionLower.includes('reading')
		)
			return 'readability';
		if (
			suggestionLower.includes('link') ||
			suggestionLower.includes('internal') ||
			suggestionLower.includes('external')
		)
			return 'links';
		if (
			suggestionLower.includes('image') ||
			suggestionLower.includes('alt') ||
			suggestionLower.includes('media')
		)
			return 'images';
		if (
			suggestionLower.includes('url') ||
			suggestionLower.includes('permalink') ||
			suggestionLower.includes('slug')
		)
			return 'url';
		if (
			suggestionLower.includes('mobile') ||
			suggestionLower.includes('responsive')
		)
			return 'mobile';
		if (
			suggestionLower.includes('speed') ||
			suggestionLower.includes('performance') ||
			suggestionLower.includes('loading')
		)
			return 'performance';

		return 'general';
	};

	// Filter out dismissed suggestions
	const activeSuggestions = suggestions.filter(
		(_, index) => !dismissedSuggestions.has(index)
	);

	if (activeSuggestions.length === 0) {
		return (
			<div className="tr-py-8 tr-text-center">
				<div className="tr-text-3xl tr-mb-2">✅</div>
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
		);
	}

	// Group suggestions by priority
	const groupedSuggestions = activeSuggestions.reduce(
		(groups, suggestion, originalIndex) => {
			const priority = getSuggestionPriority(suggestion);
			if (!groups[priority]) groups[priority] = [];
			groups[priority].push({ suggestion, originalIndex });
			return groups;
		},
		{}
	);

	const priorityOrder = ['high', 'medium', 'low'];
	const PRIORITY = {
		high: {
			label: __('High priority', 'thinkrank'),
			badge: 'tr-bg-danger-bg tr-text-danger',
			dot: 'tr-bg-danger',
		},
		medium: {
			label: __('Medium priority', 'thinkrank'),
			badge: 'tr-bg-warning-bg tr-text-warning',
			dot: 'tr-bg-warning',
		},
		low: {
			label: __('Low priority', 'thinkrank'),
			badge: 'tr-bg-success-bg tr-text-success',
			dot: 'tr-bg-success',
		},
	};

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{/* Header */}
			<div className="tr-flex tr-items-center tr-justify-between tr-gap-2">
				<div className="tr-flex tr-items-center tr-gap-2">
					<span className="tr-text-base">💡</span>
					<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Improvement Suggestions', 'thinkrank')}
					</h4>
				</div>
				<span className="tr-text-xs tr-text-tertiary">
					{activeSuggestions.length}{' '}
					{activeSuggestions.length === 1
						? __('suggestion', 'thinkrank')
						: __('suggestions', 'thinkrank')}
				</span>
			</div>

			{/* Priority groups */}
			{priorityOrder.map((priority) => {
				if (!groupedSuggestions[priority]) return null;
				const cfg = PRIORITY[priority];
				return (
					<div
						key={priority}
						className="tr-flex tr-flex-col tr-gap-2"
					>
						<div className="tr-flex tr-items-center tr-gap-2">
							<span
								className={`tr-w-2 tr-h-2 tr-rounded-full ${cfg.dot}`}
							/>
							<span className="tr-text-[11px] tr-font-semibold tr-uppercase tr-tracking-wide tr-text-tertiary">
								{cfg.label}
							</span>
							<span className="tr-text-[11px] tr-text-tertiary">
								({groupedSuggestions[priority].length})
							</span>
						</div>

						{groupedSuggestions[priority].map(
							({ suggestion, originalIndex }) => {
								const category =
									getSuggestionCategory(suggestion);
								const isApplying =
									applyingIndex === originalIndex;
								const suggestionText =
									suggestionToText(suggestion);
								const explain = explanations[suggestionText];
								const isExplaining = Boolean(explain?.loading);
								// Most suggestions can't be auto-applied: they
								// open step-by-step guidance instead. Labelling
								// those "Apply" promised an edit that never
								// arrived, which is what made the whole feature
								// read as broken.
								const isGuidanceOnly = resolveSuggestionKind
									? resolveSuggestionKind(suggestion) ===
										'guidance'
									: false;

								return (
									<div
										key={suggestionText}
										className="tr-flex tr-flex-col tr-gap-2 tr-p-3 tr-rounded-lg tr-bg-white tr-border tr-border-secondary"
									>
										<div className="tr-flex tr-items-center tr-gap-1.5 tr-flex-wrap">
											<span className="tr-inline-flex tr-items-center tr-h-5 tr-px-2 tr-rounded-full tr-text-[10px] tr-font-medium tr-bg-line-2 tr-text-secondary tr-capitalize">
												{category}
											</span>
											<span
												className={`tr-inline-flex tr-items-center tr-h-5 tr-px-2 tr-rounded-full tr-text-[10px] tr-font-semibold ${cfg.badge}`}
											>
												{cfg.label}
											</span>
										</div>
										<p className="tr-text-sm tr-text-secondary tr-m-0 tr-leading-relaxed">
											{suggestionText}
										</p>
										{(isExplaining ||
											explain?.text ||
											explain?.error) && (
											<div
												className={`tr-p-3 tr-rounded-lg tr-text-sm tr-leading-7 ${
													explain?.error
														? 'tr-bg-danger-bg tr-text-danger'
														: 'tr-bg-brand-bg tr-text-primary'
												}`}
											>
												{isExplaining
													? __(
															'Asking AI why this matters…',
															'thinkrank'
														)
													: explain?.error ||
														explain?.text}
											</div>
										)}
										<div className="tr-flex tr-gap-2">
											{onApplySuggestion && (
												<Button
													variant={
														isGuidanceOnly
															? 'secondary'
															: 'primary'
													}
													size="sm"
													loading={isApplying}
													title={
														isGuidanceOnly
															? __(
																	'Shows step-by-step guidance, so you make this change yourself',
																	'thinkrank'
																)
															: __(
																	'ThinkRank makes this change for you',
																	'thinkrank'
																)
													}
													onClick={() =>
														handleApplySuggestion(
															suggestion,
															originalIndex
														)
													}
												>
													{isApplying
														? __(
																'Applying…',
																'thinkrank'
															)
														: isGuidanceOnly
															? __(
																	'How to fix',
																	'thinkrank'
																)
															: __(
																	'Apply with AI',
																	'thinkrank'
																)}
												</Button>
											)}
											{onExplainSuggestion && (
												<Button
													variant="secondary"
													size="sm"
													disabled={
														isExplaining ||
														!aiConfigured
													}
													title={
														!aiConfigured
															? __(
																	'Connect an AI provider in ThinkRank → Settings to enable AI explanations',
																	'thinkrank'
																)
															: undefined
													}
													onClick={() =>
														handleExplainSuggestion(
															suggestion
														)
													}
												>
													{explain?.text &&
													!isExplaining
														? __(
																'Hide',
																'thinkrank'
															)
														: __(
																'Explain with AI',
																'thinkrank'
															)}
												</Button>
											)}
											<Button
												variant="secondary"
												size="sm"
												onClick={() =>
													handleDismissSuggestion(
														suggestion,
														originalIndex
													)
												}
											>
												{__('Dismiss', 'thinkrank')}
											</Button>
										</div>
									</div>
								);
							}
						)}
					</div>
				);
			})}

			{dismissedSuggestions.size > 0 && (
				<div className="tr-text-xs tr-text-tertiary tr-text-center">
					{dismissedSuggestions.size}{' '}
					{dismissedSuggestions.size === 1
						? __('suggestion', 'thinkrank')
						: __('suggestions', 'thinkrank')}{' '}
					{__('dismissed', 'thinkrank')}
				</div>
			)}
		</div>
	);
};

export default ImprovementSuggestions;
