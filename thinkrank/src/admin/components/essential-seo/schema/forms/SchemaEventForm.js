/**
 * Schema Event Form Component
 *
 * Redesigned — uses plugin UI components and Tailwind classes.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Card, Input, Textarea, Select } from '../../../ui';
import MediaPicker from '../../../common/MediaPicker';

const IconCalendar = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<rect x="3" y="4" width="18" height="18" rx="2" />
		<line x1="16" y1="2" x2="16" y2="6" />
		<line x1="8" y1="2" x2="8" y2="6" />
		<line x1="3" y1="10" x2="21" y2="10" />
	</svg>
);

const IconMapPin = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
		<circle cx="12" cy="10" r="3" />
	</svg>
);

const IconTicket = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v2z" />
	</svg>
);

const EVENT_TYPE_OPTIONS = [
	{ value: 'Event', label: __('General Event', 'thinkrank') },
	{ value: 'BusinessEvent', label: __('Business Event', 'thinkrank') },
	{ value: 'ChildrensEvent', label: __("Children's Event", 'thinkrank') },
	{ value: 'ComedyEvent', label: __('Comedy Event', 'thinkrank') },
	{ value: 'CourseInstance', label: __('Course / Training', 'thinkrank') },
	{ value: 'DanceEvent', label: __('Dance Event', 'thinkrank') },
	{ value: 'DeliveryEvent', label: __('Delivery Event', 'thinkrank') },
	{ value: 'EducationEvent', label: __('Education Event', 'thinkrank') },
	{ value: 'ExhibitionEvent', label: __('Exhibition', 'thinkrank') },
	{ value: 'Festival', label: __('Festival', 'thinkrank') },
	{ value: 'FoodEvent', label: __('Food Event', 'thinkrank') },
	{ value: 'LiteraryEvent', label: __('Literary Event', 'thinkrank') },
	{ value: 'MusicEvent', label: __('Music Event', 'thinkrank') },
	{ value: 'PublicationEvent', label: __('Publication Event', 'thinkrank') },
	{ value: 'SaleEvent', label: __('Sale Event', 'thinkrank') },
	{ value: 'ScreeningEvent', label: __('Screening Event', 'thinkrank') },
	{ value: 'SocialEvent', label: __('Social Event', 'thinkrank') },
	{ value: 'SportsEvent', label: __('Sports Event', 'thinkrank') },
	{ value: 'TheaterEvent', label: __('Theater Event', 'thinkrank') },
	{ value: 'VisualArtsEvent', label: __('Visual Arts Event', 'thinkrank') },
];

const EVENT_STATUS_OPTIONS = [
	{ value: 'EventScheduled', label: __('Scheduled', 'thinkrank') },
	{ value: 'EventCancelled', label: __('Cancelled', 'thinkrank') },
	{ value: 'EventMovedOnline', label: __('Moved Online', 'thinkrank') },
	{ value: 'EventPostponed', label: __('Postponed', 'thinkrank') },
	{ value: 'EventRescheduled', label: __('Rescheduled', 'thinkrank') },
];

const ATTENDANCE_OPTIONS = [
	{
		value: 'OfflineEventAttendanceMode',
		label: __('In-Person', 'thinkrank'),
	},
	{
		value: 'OnlineEventAttendanceMode',
		label: __('Online Only', 'thinkrank'),
	},
	{
		value: 'MixedEventAttendanceMode',
		label: __('Hybrid (In-Person + Online)', 'thinkrank'),
	},
];

const CURRENCY_OPTIONS = [
	{ value: 'USD', label: 'USD ($)' },
	{ value: 'EUR', label: 'EUR (€)' },
	{ value: 'GBP', label: 'GBP (£)' },
	{ value: 'CAD', label: 'CAD (C$)' },
	{ value: 'AUD', label: 'AUD (A$)' },
	{ value: 'JPY', label: 'JPY (¥)' },
];

const SchemaEventForm = ({ settings, onSettingChange, isLoading }) => {
	const disabled = !settings.enabled;

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{/* ── Event Details ─────────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconCalendar />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Event Details', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Core event information for schema markup',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Input
						label={__('Event Name', 'thinkrank')}
						value={settings.event_name || ''}
						onChange={(e) =>
							onSettingChange('event_name', e.target.value)
						}
						description={__('Full name of the event', 'thinkrank')}
						disabled={disabled}
					/>
					<Select
						label={__('Event Type', 'thinkrank')}
						value={settings.event_type || 'Event'}
						options={EVENT_TYPE_OPTIONS}
						onChange={(e) =>
							onSettingChange('event_type', e.target.value)
						}
						description={__(
							'Specific type of event for better categorization',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Start Date & Time', 'thinkrank')}
							type="datetime-local"
							value={settings.event_start_date || ''}
							onChange={(e) =>
								onSettingChange(
									'event_start_date',
									e.target.value
								)
							}
							description={__(
								'When the event starts',
								'thinkrank'
							)}
							disabled={disabled}
						/>
						<Input
							label={__('End Date & Time', 'thinkrank')}
							type="datetime-local"
							value={settings.event_end_date || ''}
							onChange={(e) =>
								onSettingChange(
									'event_end_date',
									e.target.value
								)
							}
							description={__('When the event ends', 'thinkrank')}
							disabled={disabled}
						/>
					</div>
					<Select
						label={__('Event Status', 'thinkrank')}
						value={settings.event_status || 'EventScheduled'}
						options={EVENT_STATUS_OPTIONS}
						onChange={(e) =>
							onSettingChange('event_status', e.target.value)
						}
						description={__(
							'Current status of the event',
							'thinkrank'
						)}
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── Location & People ─────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconMapPin />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Location & People', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Venue, organizer, and performer details',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Location / Venue', 'thinkrank')}
							value={settings.event_location || ''}
							onChange={(e) =>
								onSettingChange(
									'event_location',
									e.target.value
								)
							}
							description={__(
								'Venue name or address',
								'thinkrank'
							)}
							disabled={disabled}
						/>
						<Select
							label={__('Attendance Mode', 'thinkrank')}
							value={
								settings.event_attendance_mode ||
								'OfflineEventAttendanceMode'
							}
							options={ATTENDANCE_OPTIONS}
							onChange={(e) =>
								onSettingChange(
									'event_attendance_mode',
									e.target.value
								)
							}
							disabled={disabled}
						/>
					</div>
					<Input
						label={__('Organizer', 'thinkrank')}
						value={settings.event_organizer || ''}
						onChange={(e) =>
							onSettingChange('event_organizer', e.target.value)
						}
						description={__(
							'Event organizer name or organization',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<Input
						label={__('Performer', 'thinkrank')}
						value={settings.event_performer || ''}
						onChange={(e) =>
							onSettingChange('event_performer', e.target.value)
						}
						description={__(
							'Main performer, speaker, or presenter',
							'thinkrank'
						)}
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── Media & Tickets ───────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconTicket />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Media & Tickets', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Event image, pricing, and description',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<MediaPicker
						label={__('Event Image', 'thinkrank')}
						value={settings.event_image}
						onChange={(value) =>
							onSettingChange('event_image', value)
						}
						help={__(
							'Event promotional image (recommended: 1200×630 px)',
							'thinkrank'
						)}
						allowedTypes={['image']}
						disabled={disabled}
					/>
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Ticket Price', 'thinkrank')}
							type="number"
							step="0.01"
							min="0"
							value={settings.event_price || ''}
							onChange={(e) =>
								onSettingChange('event_price', e.target.value)
							}
							description={__(
								'Leave empty for free events',
								'thinkrank'
							)}
							disabled={disabled}
						/>
						<Select
							label={__('Currency', 'thinkrank')}
							value={settings.event_currency || 'USD'}
							options={CURRENCY_OPTIONS}
							onChange={(e) =>
								onSettingChange(
									'event_currency',
									e.target.value
								)
							}
							disabled={disabled}
						/>
					</div>
					<Textarea
						label={__('Description', 'thinkrank')}
						value={settings.event_description || ''}
						onChange={(e) =>
							onSettingChange('event_description', e.target.value)
						}
						description={__(
							'Detailed event description for search engines',
							'thinkrank'
						)}
						rows={3}
						disabled={disabled}
					/>
				</div>
			</Card>
		</div>
	);
};

export default SchemaEventForm;
