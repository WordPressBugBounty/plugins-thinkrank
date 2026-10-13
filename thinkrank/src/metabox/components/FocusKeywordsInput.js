/**
 * Focus Keywords Tag Input
 *
 * Tag-style input for managing multiple focus keywords (up to `max`; 0 = no
 * cap). Keywords
 * are added on Enter or comma, shown as removable chips, and deduped
 * case-insensitively. The first keyword is treated as the primary.
 *
 * @package
 * @since 1.0.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';

const FocusKeywordsInput = ({
	keywords = [],
	onChange,
	onDraftChange,
	max = 5,
	primaryKeyword = '',
}) => {
	const [draft, setDraft] = useState('');

	// Update the local draft and mirror it to the parent so a Save that happens
	// before the draft is committed (Enter/comma/blur) can still flush it. See #230.
	const updateDraft = (value) => {
		setDraft(value);
		onDraftChange?.(value);
	};

	// When Save folds an uncommitted draft into the keyword list (#230), the draft
	// text would otherwise linger in the input next to its new chip. Clear it once
	// it has become a committed keyword.
	useEffect(() => {
		const trimmed = draft.trim();
		if (
			trimmed &&
			keywords.some((k) => k.toLowerCase() === trimmed.toLowerCase())
		) {
			updateDraft('');
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [keywords]);

	// max <= 0 means no cap. Use Infinity so nothing blocks.
	const effectiveMax = max > 0 ? max : Infinity;
	const atMax = keywords.length >= effectiveMax;

	const commitKeyword = (value) => {
		const trimmed = value.trim().replace(/,+$/, '').trim();
		if (!trimmed) {
			return;
		}
		// Case-insensitive dedupe.
		const exists = keywords.some(
			(k) => k.toLowerCase() === trimmed.toLowerCase()
		);
		if (exists || keywords.length >= effectiveMax) {
			updateDraft('');
			return;
		}
		onChange([...keywords, trimmed]);
		updateDraft('');
	};

	const removeKeyword = (index) => {
		onChange(keywords.filter((_, i) => i !== index));
	};

	const handleKeyDown = (e) => {
		if (e.key === 'Enter' || e.key === ',') {
			e.preventDefault();
			commitKeyword(draft);
		} else if (e.key === 'Backspace' && draft === '' && keywords.length) {
			// Quick-delete the last chip when the field is empty.
			removeKeyword(keywords.length - 1);
		}
	};

	const handleChange = (e) => {
		const { value } = e.target;
		// Support paste of a comma-separated list.
		if (value.includes(',')) {
			value
				.split(',')
				.map((v) => v.trim())
				.filter(Boolean)
				.forEach((v) => commitKeyword(v));
			return;
		}
		updateDraft(value);
	};

	return (
		<div>
			<label
				htmlFor="thinkrank_focus_keyword_input"
				className="tr-text-sm tr-font-medium tr-text-secondary tr-block tr-mb-1"
			>
				{__('Focus Keywords', 'thinkrank')}
			</label>

			<div className="tr-w-full tr-min-h-10 tr-rounded-md tr-border-[1.5px] tr-border-primary tr-bg-white tr-px-2 tr-py-1.5 tr-flex tr-flex-wrap tr-items-center tr-gap-1.5 focus-within:tr-border-brand tr-transition-colors">
				{keywords.map((keyword, index) => {
					const isPrimary =
						index === 0 ||
						keyword.toLowerCase() ===
							(primaryKeyword || '').toLowerCase();
					return (
						<span
							key={`${keyword}-${index}`}
							className={`tr-inline-flex tr-items-center tr-gap-1 tr-rounded tr-px-2 tr-py-0.5 tr-text-xs tr-font-medium ${
								isPrimary && index === 0
									? 'tr-bg-brand tr-text-white'
									: 'tr-bg-line-2 tr-text-secondary'
							}`}
							title={
								index === 0
									? __('Primary keyword', 'thinkrank')
									: undefined
							}
						>
							{keyword}
							<button
								type="button"
								onClick={() => removeKeyword(index)}
								aria-label={sprintf(
									/* translators: %s: keyword */
									__('Remove %s', 'thinkrank'),
									keyword
								)}
								className="tr-leading-none tr-border-0 tr-bg-transparent tr-cursor-pointer tr-p-0 tr-text-current tr-opacity-70 hover:tr-opacity-100"
							>
								×
							</button>
						</span>
					);
				})}

				{!atMax && (
					<input
						type="text"
						id="thinkrank_focus_keyword_input"
						value={draft}
						onChange={handleChange}
						onKeyDown={handleKeyDown}
						onBlur={() => commitKeyword(draft)}
						className="tr-flex-1 tr-min-w-[120px] tr-border-0 tr-outline-none tr-bg-transparent tr-text-sm tr-text-primary tr-py-0.5"
						placeholder={
							keywords.length
								? __('Add another keyword…', 'thinkrank')
								: __('e.g., WordPress SEO', 'thinkrank')
						}
					/>
				)}
			</div>

			<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-1">
				{atMax
					? sprintf(
							/* translators: %d: maximum number of focus keywords */
							__(
								'Up to %d focus keywords. The first keyword is primary.',
								'thinkrank'
							),
							max
						)
					: __(
							'Press Enter or comma to add. The first keyword is primary.',
							'thinkrank'
						)}
			</p>
		</div>
	);
};

export default FocusKeywordsInput;
