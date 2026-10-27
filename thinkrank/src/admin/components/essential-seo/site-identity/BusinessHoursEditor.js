/**
 * Business Hours editor: one or more ranges per day.
 *
 * Each open day lists its ranges, and "+ Add hours" adds another, so a
 * business that closes for lunch can enter 09:00–13:00 and 14:00–18:00
 * (#1119). Up to four ranges a day; the button hides at the limit.
 *
 * @package
 * @since 2.19.0
 */

import { __, sprintf } from '@wordpress/i18n';

import { Button, Toggle } from '../../ui';
import {
	MAX_SLOTS,
	rangesOf,
	withRanges,
	nextRange,
	hasOverlap,
} from '../../../utils/businessHours';

/**
 * What a day starts as before the admin sets it.
 */
const DEFAULT_HOURS = {
	monday: { open: '09:00', close: '17:00', closed: true },
	tuesday: { open: '09:00', close: '17:00', closed: true },
	wednesday: { open: '09:00', close: '17:00', closed: true },
	thursday: { open: '09:00', close: '17:00', closed: true },
	friday: { open: '09:00', close: '17:00', closed: true },
	saturday: { open: '10:00', close: '16:00', closed: true },
	sunday: { open: '09:00', close: '17:00', closed: true },
};

const DAY_LABELS = {
	monday: __('Monday', 'thinkrank'),
	tuesday: __('Tuesday', 'thinkrank'),
	wednesday: __('Wednesday', 'thinkrank'),
	thursday: __('Thursday', 'thinkrank'),
	friday: __('Friday', 'thinkrank'),
	saturday: __('Saturday', 'thinkrank'),
	sunday: __('Sunday', 'thinkrank'),
};

const TIME_INPUT_STYLE = {
	border: 'none',
	background: 'transparent',
	boxShadow: 'none',
	padding: '0',
	margin: '0',
	color: 'var(--tr-text-heading)',
	fontWeight: '500',
	fontSize: '14px',
	outline: 'none',
	textAlign: 'right',
	cursor: 'pointer',
};

/**
 * One time field, labelled From or To.
 *
 * @param {Object}   props
 * @param {string}   props.id       Input id.
 * @param {string}   props.label    Visible label.
 * @param {string}   props.value    HH:MM.
 * @param {Function} props.onChange Called with the new HH:MM.
 * @param {boolean}  props.disabled Whether the field is disabled.
 */
const TimeField = ({ id, label, value, onChange, disabled }) => (
	<label
		htmlFor={id}
		className="tr-flex tr-flex-1 tr-items-center tr-h-11 tr-border tr-border-primary tr-rounded tr-px-4 tr-bg-white"
	>
		<span className="tr-text-sm tr-text-tertiary tr-mr-auto">{label}</span>
		<input
			id={id}
			type="time"
			value={value}
			onChange={(e) => onChange(e.target.value)}
			disabled={disabled}
			style={TIME_INPUT_STYLE}
		/>
	</label>
);

/**
 * @param {Object}   props
 * @param {Object}   props.value    Stored business_hours, or undefined.
 * @param {Function} props.onChange Called with the whole updated week.
 * @param {boolean}  props.disabled Whether the editor is disabled.
 */
const BusinessHoursEditor = ({ value, onChange, disabled }) => {
	const week =
		value && typeof value === 'object'
			? { ...DEFAULT_HOURS, ...value }
			: DEFAULT_HOURS;

	const updateDay = (day, next) => onChange({ ...week, [day]: next });

	const setRanges = (day, ranges) =>
		updateDay(day, withRanges(week[day], ranges));

	return Object.entries(week).map(([day, hours]) => {
		const label = DAY_LABELS[day] || day;
		const ranges = rangesOf(hours);

		return (
			<div
				key={day}
				className="tr-flex tr-items-start tr-gap-4 tr-mb-3"
				data-testid={`business-hours-${day}`}
			>
				<div
					className="tr-flex tr-items-center tr-shrink-0 tr-h-11"
					style={{ width: '140px' }}
				>
					<Toggle
						id={`business-hours-${day}`}
						label={label}
						checked={!hours?.closed}
						onChange={(open) =>
							updateDay(day, { ...hours, closed: !open })
						}
						disabled={disabled}
					/>
				</div>

				{hours?.closed ? (
					<div className="tr-flex tr-flex-1 tr-items-center tr-h-11 tr-bg-white tr-rounded tr-px-4 tr-gap-2">
						<svg
							width="16"
							height="16"
							viewBox="0 0 24 24"
							fill="none"
							stroke="#8c9bb7"
							strokeWidth="2"
							strokeLinecap="round"
							strokeLinejoin="round"
							aria-hidden="true"
						>
							<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
						</svg>
						<span className="tr-text-sm tr-text-tertiary">
							{__('Closed', 'thinkrank')}
						</span>
					</div>
				) : (
					<div className="tr-flex tr-flex-col tr-flex-1 tr-gap-2 tr-min-w-0">
						{ranges.map((range, index) => (
							<div
								key={index}
								className="tr-flex tr-flex-wrap tr-items-center tr-gap-3"
							>
								<TimeField
									id={`business-hours-${day}-${index}-open`}
									label={__('From', 'thinkrank')}
									value={range.open || '09:00'}
									onChange={(time) =>
										setRanges(
											day,
											ranges.map((r, i) =>
												i === index
													? { ...r, open: time }
													: r
											)
										)
									}
									disabled={disabled}
								/>
								<TimeField
									id={`business-hours-${day}-${index}-close`}
									label={__('To', 'thinkrank')}
									value={range.close || '17:00'}
									onChange={(time) =>
										setRanges(
											day,
											ranges.map((r, i) =>
												i === index
													? { ...r, close: time }
													: r
											)
										)
									}
									disabled={disabled}
								/>
								{ranges.length > 1 && (
									<Button
										type="button"
										variant="ghost"
										size="sm"
										onClick={() =>
											setRanges(
												day,
												ranges.filter(
													(r, i) => i !== index
												)
											)
										}
										disabled={disabled}
										aria-label={sprintf(
											/* translators: 1: day of the week, 2: opening time, 3: closing time. */
											__(
												'Remove %1$s %2$s–%3$s',
												'thinkrank'
											),
											label,
											range.open,
											range.close
										)}
									>
										{__('Remove', 'thinkrank')}
									</Button>
								)}
							</div>
						))}

						{hasOverlap(ranges) && (
							<p
								className="tr-text-xs tr-text-warning tr-m-0"
								role="status"
							>
								{sprintf(
									/* translators: %s: day of the week. */
									__(
										'These hours overlap on %s.',
										'thinkrank'
									),
									label
								)}
							</p>
						)}

						{ranges.length < MAX_SLOTS && (
							<div>
								<Button
									type="button"
									variant="ghost"
									size="sm"
									onClick={() =>
										setRanges(day, [
											...ranges,
											nextRange(ranges),
										])
									}
									disabled={disabled}
									aria-label={sprintf(
										/* translators: %s: day of the week. */
										__('Add hours on %s', 'thinkrank'),
										label
									)}
								>
									{__('+ Add hours', 'thinkrank')}
								</Button>
							</div>
						)}
					</div>
				)}
			</div>
		);
	});
};

export default BusinessHoursEditor;
