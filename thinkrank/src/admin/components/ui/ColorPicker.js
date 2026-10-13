import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import classnames from 'classnames';

/**
 * Whether a string is a hex colour this control will hand back.
 *
 * @param {string} value Candidate.
 * @return {boolean} True for #rgb or #rrggbb.
 */
const isHex = (value) => /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(value);

/**
 * Normalize typed input to the stored form: lowercase, #rrggbb, or ''.
 *
 * Empty is a real value here and means "leave the stock colour alone", so it
 * survives normalization rather than being turned into a colour.
 *
 * @param {string} raw What the field holds.
 * @return {string|null} Storable value, or null when it is not one yet.
 */
const normalize = (raw) => {
	const trimmed = String(raw || '').trim();

	if (trimmed === '') {
		return '';
	}

	const hashed = trimmed.startsWith('#') ? trimmed : `#${trimmed}`;

	if (!isHex(hashed)) {
		return null;
	}

	const bare = hashed.slice(1).toLowerCase();

	return bare.length === 3
		? `#${bare[0]}${bare[0]}${bare[1]}${bare[1]}${bare[2]}${bare[2]}`
		: `#${bare}`;
};

/**
 * Colour field: a swatch, a hex box, and a way back to the default.
 *
 * Built rather than borrowed because the control has to express a state a
 * native colour input cannot: unset. `<input type="color">` always holds a
 * colour, so on its own it can only ever say "the owner chose this" and never
 * "the owner has not chosen" — and it is that second state which keeps an
 * untouched sitemap rendering as it always did. The hex box is the real value;
 * the swatch is a way to fill it in.
 *
 * @param {Object}   root0             Props.
 * @param {string}   root0.value       Stored colour, or '' when unset.
 * @param {Function} root0.onChange    Called with the new stored value.
 * @param {string}   root0.label       Field label.
 * @param {string}   root0.description Help text.
 * @param {string}   root0.fallback    Colour shown, greyed, while unset.
 * @param {boolean}  root0.disabled    Disable the control.
 * @param {string}   root0.id          Field id.
 */
export default function ColorPicker({
	value = '',
	onChange,
	label,
	description,
	fallback = '#000000',
	disabled = false,
	id,
}) {
	const fieldId =
		id || `tr-color-${label?.toLowerCase().replace(/\s+/g, '-')}`;

	// Typing "#1a2" passes through states that are not colours yet, so the box
	// keeps its own text and only reports upward once it reads as one.
	const [draft, setDraft] = useState(value);

	useEffect(() => {
		setDraft(value);
	}, [value]);

	const invalid = normalize(draft) === null;
	const swatch = isHex(value) ? value : fallback;

	const commit = (raw) => {
		setDraft(raw);

		const next = normalize(raw);

		if (next !== null && next !== value) {
			onChange(next);
		}
	};

	return (
		<div className="tr-flex tr-flex-col tr-gap-1.5">
			{label && (
				<label
					htmlFor={fieldId}
					className="tr-text-sm tr-font-medium tr-text-secondary"
				>
					{label}
				</label>
			)}

			<div className="tr-flex tr-items-center tr-gap-2">
				<input
					type="color"
					value={swatch}
					disabled={disabled}
					onChange={(e) => commit(e.target.value)}
					className={classnames(
						'tr-h-10 tr-w-12 tr-shrink-0 tr-cursor-pointer tr-rounded-md tr-bg-white tr-p-1',
						'tr-border-[1.5px] tr-border-primary tr-transition-colors tr-duration-150',
						'hover:tr-border-[#A9AEC0] focus:tr-outline-none focus-visible:tr-border-brand',
						'disabled:tr-cursor-not-allowed disabled:tr-border-secondary'
					)}
					aria-label={sprintf(
						/* translators: %s: field label, e.g. "Main Color". */
						__('Pick a colour for %s', 'thinkrank'),
						label || __('this field', 'thinkrank')
					)}
				/>

				<input
					id={fieldId}
					type="text"
					value={draft}
					disabled={disabled}
					spellCheck="false"
					placeholder={fallback}
					onChange={(e) => commit(e.target.value)}
					onBlur={() => setDraft(value)}
					className={classnames(
						'tr-h-10 tr-w-32 tr-rounded-md tr-bg-white tr-px-3 tr-text-sm tr-text-primary',
						'tr-border-[1.5px] tr-transition-colors tr-duration-150',
						'placeholder:tr-text-tertiary',
						'focus:tr-outline-none focus-visible:tr-border-brand',
						'disabled:tr-bg-white disabled:tr-text-tertiary disabled:tr-border-secondary disabled:tr-cursor-not-allowed',
						invalid
							? 'tr-border-danger'
							: 'tr-border-primary hover:tr-border-[#A9AEC0]'
					)}
				/>

				{value !== '' && !disabled && (
					<button
						type="button"
						onClick={() => onChange('')}
						className="tr-text-xs tr-text-brand tr-bg-transparent tr-border-0 tr-cursor-pointer tr-p-0 hover:tr-underline"
					>
						{__('Reset', 'thinkrank')}
					</button>
				)}
			</div>

			{invalid ? (
				<p className="tr-text-xs tr-text-danger tr-m-0">
					{__(
						'Enter a hex colour such as #1565c0, or clear the field to keep the default.',
						'thinkrank'
					)}
				</p>
			) : (
				description && (
					<p className="tr-text-xs tr-text-tertiary tr-m-0">
						{description}
					</p>
				)
			)}
		</div>
	);
}
