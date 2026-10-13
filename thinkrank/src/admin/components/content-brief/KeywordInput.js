/**
 * Keyword Input Component
 *
 * Reusable component for managing multiple keyword/URL inputs.
 * Uses ui/ components only (no @wordpress/components).
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Input } from '../ui';

const KeywordInput = ({
	keywords = [''],
	onChange,
	label = __('Keywords', 'thinkrank'),
	help = '',
	placeholder = __('Enter keyword…', 'thinkrank'),
	maxItems = 10,
}) => {
	const handleChange = (index, value) => {
		const updated = [...keywords];
		updated[index] = value;
		onChange(updated);
	};

	const addItem = () => {
		if (keywords.length < maxItems) {
			onChange([...keywords, '']);
		}
	};

	const handleKeyDown = (index, e) => {
		if (e.key === 'Enter') {
			e.preventDefault();
			// Commit the typed value by adding a new empty row,
			// but only when the current entry has content and there is room.
			if (keywords[index].trim() && keywords.length < maxItems) {
				addItem();
			}
		}
	};

	const removeItem = (index) => {
		if (keywords.length > 1) {
			onChange(keywords.filter((_, i) => i !== index));
		}
	};

	return (
		<div>
			<span className="tr-block tr-text-sm tr-font-medium tr-text-secondary tr-mb-1">
				{label}
			</span>

			{help && (
				<p className="tr-text-xs tr-text-tertiary tr-mb-3 tr-m-0 tr-mt-1">
					{help}
				</p>
			)}

			<div className="tr-flex tr-flex-col tr-gap-2 tr-mt-2">
				{keywords.map((keyword, index) => (
					<div
						key={index}
						className="tr-flex tr-items-center tr-gap-2"
					>
						<div className="tr-flex-1">
							<Input
								value={keyword}
								onChange={(e) =>
									handleChange(index, e.target.value)
								}
								onKeyDown={(e) => handleKeyDown(index, e)}
								placeholder={placeholder}
								aria-label={`${label} ${index + 1}`}
							/>
						</div>

						{keywords.length > 1 && (
							<button
								type="button"
								onClick={() => removeItem(index)}
								title={__('Remove', 'thinkrank')}
								className="tr-shrink-0 tr-flex tr-items-center tr-justify-center tr-w-10 tr-h-10 tr-rounded-md tr-border tr-border-danger tr-bg-white tr-text-danger hover:tr-bg-danger-bg tr-transition-colors"
							>
								<svg
									className="tr-w-4 tr-h-4"
									fill="none"
									stroke="currentColor"
									viewBox="0 0 24 24"
								>
									<path
										strokeLinecap="round"
										strokeLinejoin="round"
										strokeWidth={2}
										d="M6 18L18 6M6 6l12 12"
									/>
								</svg>
							</button>
						)}
					</div>
				))}
			</div>

			{keywords.length < maxItems && (
				<button
					type="button"
					onClick={addItem}
					className="tr-mt-2 tr-inline-flex tr-items-center tr-gap-1.5 tr-text-sm tr-font-medium tr-text-brand hover:tr-text-brand tr-transition-colors"
				>
					<svg
						className="tr-w-4 tr-h-4"
						fill="none"
						stroke="currentColor"
						viewBox="0 0 24 24"
					>
						<path
							strokeLinecap="round"
							strokeLinejoin="round"
							strokeWidth={2}
							d="M12 6v6m0 0v6m0-6h6m-6 0H6"
						/>
					</svg>
					{__('Add More', 'thinkrank')}
				</button>
			)}
		</div>
	);
};

export default KeywordInput;
