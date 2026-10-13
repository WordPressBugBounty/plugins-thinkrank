import classnames from 'classnames';
import { useState, useRef, useEffect, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

// Combobox sibling of Select — same "outlined modern" control style, but the
// field is a text input that filters the option list as you type. With an
// empty query it lists every option, so it degrades to a plain dropdown.
// Use it where the list can be long (Search Console sites, GA4 properties);
// keep plain Select for short, fixed lists.
export default function SearchableSelect({
	label,
	description,
	error,
	options = [],
	value = '',
	onChange,
	disabled = false,
	placeholder = '',
	clearable = true,
	className,
	id,
}) {
	const selectId = id || label?.toLowerCase().replace(/\s+/g, '-');
	const borderClass = error ? 'tr-border-danger' : 'tr-border-primary';
	const isClearable = clearable && !disabled && !!value;

	const [isOpen, setIsOpen] = useState(false);
	const [query, setQuery] = useState('');
	const [activeIndex, setActiveIndex] = useState(-1);
	const wrapperRef = useRef(null);

	const selectedLabel =
		options.find((opt) => opt.value === value)?.label || '';

	const filtered = useMemo(() => {
		const needle = query.trim().toLowerCase();
		if (!needle) {
			return options;
		}
		return options.filter((opt) =>
			String(opt.label).toLowerCase().includes(needle)
		);
	}, [options, query]);

	// Close on outside click.
	useEffect(() => {
		if (!isOpen) {
			return;
		}
		const onDocMouseDown = (event) => {
			if (!wrapperRef.current?.contains(event.target)) {
				setIsOpen(false);
				setQuery('');
			}
		};
		document.addEventListener('mousedown', onDocMouseDown);
		return () => document.removeEventListener('mousedown', onDocMouseDown);
	}, [isOpen]);

	const open = () => {
		if (disabled) {
			return;
		}
		setIsOpen(true);
		setActiveIndex(-1);
	};

	const pick = (opt) => {
		onChange?.(opt.value);
		setQuery('');
		setIsOpen(false);
		setActiveIndex(-1);
	};

	const clear = () => {
		onChange?.('');
		setQuery('');
		setIsOpen(false);
		setActiveIndex(-1);
	};

	const onKeyDown = (event) => {
		if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
			event.preventDefault();
			if (!isOpen) {
				open();
				return;
			}
			const step = event.key === 'ArrowDown' ? 1 : -1;
			setActiveIndex((prev) => {
				if (!filtered.length) {
					return -1;
				}
				const next = prev + step;
				if (next < 0) {
					return filtered.length - 1;
				}
				if (next >= filtered.length) {
					return 0;
				}
				return next;
			});
		} else if (event.key === 'Enter') {
			if (isOpen && filtered[activeIndex]) {
				event.preventDefault();
				pick(filtered[activeIndex]);
			}
		} else if (event.key === 'Escape') {
			setIsOpen(false);
			setQuery('');
		}
	};

	return (
		<div
			className={classnames('tr-flex tr-flex-col tr-gap-1.5', className)}
			ref={wrapperRef}
		>
			{label && (
				<label
					htmlFor={selectId}
					className="tr-text-sm tr-font-medium tr-text-secondary"
				>
					{label}
				</label>
			)}
			<div className="tr-relative">
				<input
					id={selectId}
					type="text"
					role="combobox"
					aria-expanded={isOpen}
					aria-autocomplete="list"
					className={classnames(
						'tr-w-full tr-h-10 tr-rounded-md tr-bg-white tr-text-sm tr-text-primary',
						'tr-border-[1.5px] tr-pl-3',
						isClearable ? 'tr-pr-16' : 'tr-pr-9',
						'tr-transition-colors tr-duration-150',
						'hover:tr-border-[#A9AEC0]',
						'focus:tr-outline-none focus-visible:tr-border-brand',
						'disabled:tr-bg-white disabled:tr-text-tertiary disabled:tr-border-secondary disabled:tr-cursor-not-allowed',
						borderClass
					)}
					value={isOpen ? query : selectedLabel}
					placeholder={selectedLabel || placeholder}
					disabled={disabled}
					onFocus={open}
					onClick={open}
					onChange={(e) => {
						setQuery(e.target.value);
						setIsOpen(true);
						setActiveIndex(-1);
					}}
					onKeyDown={onKeyDown}
				/>
				{isClearable && (
					<button
						type="button"
						onMouseDown={(e) => e.preventDefault()}
						onClick={clear}
						aria-label={__('Clear selection', 'thinkrank')}
						className="tr-absolute tr-inset-y-0 tr-right-8 tr-flex tr-items-center tr-text-tertiary hover:tr-text-primary"
						style={{
							background: 'none',
							border: 0,
							padding: '0 4px',
							cursor: 'pointer',
						}}
					>
						<svg
							className="tr-h-4 tr-w-4"
							viewBox="0 0 16 16"
							fill="currentColor"
							aria-hidden="true"
						>
							<path d="M4.28 3.22a.75.75 0 0 0-1.06 1.06L6.94 8l-3.72 3.72a.75.75 0 1 0 1.06 1.06L8 9.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L9.06 8l3.72-3.72a.75.75 0 0 0-1.06-1.06L8 6.94 4.28 3.22Z" />
						</svg>
					</button>
				)}
				<span className="tr-pointer-events-none tr-absolute tr-inset-y-0 tr-right-3 tr-flex tr-items-center tr-text-tertiary">
					<svg
						className="tr-h-4 tr-w-4"
						viewBox="0 0 16 16"
						fill="currentColor"
						aria-hidden="true"
					>
						<path
							fillRule="evenodd"
							d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z"
							clipRule="evenodd"
						/>
					</svg>
				</span>
				{isOpen && (
					<ul
						role="listbox"
						style={{
							position: 'absolute',
							zIndex: 20,
							top: 'calc(100% + 4px)',
							left: 0,
							right: 0,
							maxHeight: '240px',
							overflowY: 'auto',
							margin: 0,
							padding: '4px',
							listStyle: 'none',
							background: '#fff',
							border: '1px solid var(--tr-border-card)',
							borderRadius: '8px',
							boxShadow: '0 8px 20px rgba(0,0,0,.10)',
						}}
					>
						{filtered.length === 0 && (
							<li
								style={{
									padding: '8px 10px',
									fontSize: '13px',
									color: 'var(--tr-text-subtle)',
								}}
							>
								{__('No matches found', 'thinkrank')}
							</li>
						)}
						{filtered.map((opt, index) => {
							const isActive = index === activeIndex;
							const isSelected = opt.value === value;
							return (
								/* eslint-disable-next-line jsx-a11y/click-events-have-key-events */
								<li
									key={opt.value}
									role="option"
									aria-selected={isSelected}
									onMouseEnter={() => setActiveIndex(index)}
									onMouseDown={(e) => e.preventDefault()}
									onClick={() => pick(opt)}
									style={{
										padding: '8px 10px',
										margin: 0,
										fontSize: '13px',
										borderRadius: '6px',
										cursor: 'pointer',
										color: 'var(--tr-text-heading)',
										fontWeight: isSelected ? 600 : 400,
										background: isActive
											? 'var(--tr-accent-bg)'
											: 'transparent',
									}}
								>
									{opt.label}
								</li>
							);
						})}
					</ul>
				)}
			</div>
			{description && !error && (
				<p className="tr-text-xs tr-text-tertiary tr-m-0">
					{description}
				</p>
			)}
			{error && (
				<p className="tr-text-xs tr-text-danger tr-m-0">{error}</p>
			)}
		</div>
	);
}
