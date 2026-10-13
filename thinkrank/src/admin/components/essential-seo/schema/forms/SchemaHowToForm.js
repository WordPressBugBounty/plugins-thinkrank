/**
 * Schema How-To Form Component
 *
 * Redesigned — uses plugin UI components and Tailwind classes.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Card, Input, Textarea, Select } from '../../../ui';
import MediaPicker from '../../../common/MediaPicker';

const IconList = () => (
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
		<line x1="8" y1="6" x2="21" y2="6" />
		<line x1="8" y1="12" x2="21" y2="12" />
		<line x1="8" y1="18" x2="21" y2="18" />
		<line x1="3" y1="6" x2="3.01" y2="6" />
		<line x1="3" y1="12" x2="3.01" y2="12" />
		<line x1="3" y1="18" x2="3.01" y2="18" />
	</svg>
);

const IconTool = () => (
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
		<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" />
	</svg>
);

const DIFFICULTY_OPTIONS = [
	{ value: '', label: __('Select Difficulty', 'thinkrank') },
	{ value: 'Beginner', label: __('Beginner', 'thinkrank') },
	{ value: 'Intermediate', label: __('Intermediate', 'thinkrank') },
	{ value: 'Advanced', label: __('Advanced', 'thinkrank') },
	{ value: 'Expert', label: __('Expert', 'thinkrank') },
];

const toLines = (arr) => (Array.isArray(arr) ? arr.join('\n') : arr || '');
const fromLines = (str) =>
	str
		.split('\n')
		.map((s) => s.trim())
		.filter(Boolean);

const SchemaHowToForm = ({ settings, onSettingChange, isLoading }) => {
	const disabled = !settings.enabled;

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{/* ── Guide Overview ────────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Guide Overview', 'thinkrank')}
					</h3>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Core information about the how-to guide',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Input
						label={__('How-To Title', 'thinkrank')}
						value={settings.howto_name || ''}
						onChange={(e) =>
							onSettingChange('howto_name', e.target.value)
						}
						description={__(
							'Clear, descriptive title, e.g., "How to Install WordPress"',
							'thinkrank'
						)}
						disabled={disabled}
					/>
					<Textarea
						label={__('Description', 'thinkrank')}
						value={settings.howto_description || ''}
						onChange={(e) =>
							onSettingChange('howto_description', e.target.value)
						}
						description={__(
							'Brief overview of what this guide teaches and the end result',
							'thinkrank'
						)}
						rows={3}
						disabled={disabled}
					/>
					<MediaPicker
						label={__('Guide Image', 'thinkrank')}
						value={settings.howto_image}
						onChange={(value) =>
							onSettingChange('howto_image', value)
						}
						help={__(
							'Main image for the how-to guide (recommended: 1200×630 px)',
							'thinkrank'
						)}
						allowedTypes={['image']}
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── Steps & Duration ──────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconList />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Steps & Duration', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Step-by-step instructions and time estimates',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Textarea
						label={__('Step-by-Step Instructions', 'thinkrank')}
						value={settings.howto_steps || ''}
						onChange={(e) =>
							onSettingChange('howto_steps', e.target.value)
						}
						description={__(
							'Enter each step on a new line, e.g., "1. Download WordPress  2. Upload files to server"',
							'thinkrank'
						)}
						rows={6}
						disabled={disabled}
					/>
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Input
							label={__('Total Time', 'thinkrank')}
							value={settings.howto_total_time || ''}
							onChange={(e) =>
								onSettingChange(
									'howto_total_time',
									e.target.value
								)
							}
							description={__(
								'ISO format: PT30M = 30 min, PT2H = 2 hrs',
								'thinkrank'
							)}
							placeholder="PT30M"
							disabled={disabled}
						/>
						<Input
							label={__('Prep Time', 'thinkrank')}
							value={settings.howto_prep_time || ''}
							onChange={(e) =>
								onSettingChange(
									'howto_prep_time',
									e.target.value
								)
							}
							description={__(
								'ISO format: PT10M = 10 min',
								'thinkrank'
							)}
							placeholder="PT10M"
							disabled={disabled}
						/>
					</div>
					<div className="tr-grid tr-grid-cols-2 tr-gap-4">
						<Select
							label={__('Difficulty Level', 'thinkrank')}
							value={settings.howto_difficulty || ''}
							options={DIFFICULTY_OPTIONS}
							onChange={(e) =>
								onSettingChange(
									'howto_difficulty',
									e.target.value
								)
							}
							disabled={disabled}
						/>
						<Input
							label={__('Estimated Cost', 'thinkrank')}
							value={settings.howto_estimated_cost || ''}
							onChange={(e) =>
								onSettingChange(
									'howto_estimated_cost',
									e.target.value
								)
							}
							description={__(
								'e.g., $50, Free, $10–$20',
								'thinkrank'
							)}
							disabled={disabled}
						/>
					</div>
				</div>
			</Card>

			{/* ── Requirements ──────────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<div className="tr-flex tr-items-center tr-gap-2">
						<span className="tr-text-tertiary">
							<IconTool />
						</span>
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{__('Requirements', 'thinkrank')}
						</h3>
					</div>
					<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
						{__(
							'Tools and materials needed, one item per line',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Textarea
						label={__('Required Tools', 'thinkrank')}
						value={toLines(settings.howto_tool)}
						onChange={(e) =>
							onSettingChange(
								'howto_tool',
								fromLines(e.target.value)
							)
						}
						description={__(
							'e.g., screwdriver / computer / text editor, one per line',
							'thinkrank'
						)}
						rows={4}
						disabled={disabled}
					/>
					<Textarea
						label={__('Materials & Supplies', 'thinkrank')}
						value={toLines(settings.howto_supply)}
						onChange={(e) =>
							onSettingChange(
								'howto_supply',
								fromLines(e.target.value)
							)
						}
						description={__(
							'e.g., wood / paint / software, one per line',
							'thinkrank'
						)}
						rows={4}
						disabled={disabled}
					/>
				</div>
			</Card>

			{/* ── Additional Details ────────────────────────────────────── */}
			<Card padding={false}>
				<div className="tr-px-5 tr-py-4 tr-border-b tr-border-secondary">
					<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{__('Additional Details', 'thinkrank')}
					</h3>
				</div>
				<div className="tr-p-5 tr-flex tr-flex-col tr-gap-4">
					<Input
						label={__('Guide URL', 'thinkrank')}
						type="url"
						value={settings.howto_url || ''}
						onChange={(e) =>
							onSettingChange('howto_url', e.target.value)
						}
						description={__(
							'Direct link to the how-to guide page',
							'thinkrank'
						)}
						placeholder="https://example.com/how-to-guide"
						disabled={disabled}
					/>
					<Input
						label={__('Yield / Result', 'thinkrank')}
						value={settings.howto_yield || ''}
						onChange={(e) =>
							onSettingChange('howto_yield', e.target.value)
						}
						description={__(
							'What the user will accomplish, e.g., "A working WordPress site"',
							'thinkrank'
						)}
						disabled={disabled}
					/>
				</div>
			</Card>
		</div>
	);
};

export default SchemaHowToForm;
