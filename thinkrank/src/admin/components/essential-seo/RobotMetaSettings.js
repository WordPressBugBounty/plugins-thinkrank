/**
 * Robot Meta Settings Component
 *
 * Reusable component for robot meta settings (index, noindex, etc.)
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { CheckboxControl, Tooltip } from '@wordpress/components';

/**
 * Robot Meta Settings Component
 *
 * @param {Object}   props          Component props
 * @param {Object}   props.values   Current robots meta values
 * @param {Function} props.onChange Callback when a value changes
 */
const RobotMetaSettings = ({ values, onChange }) => {
	// Default values if not provided
	const settings = {
		index: true,
		noindex: false,
		nofollow: false,
		noarchive: false,
		noimageindex: false,
		nosnippet: false,
		...values,
	};

	const handleChange = (key, value) => {
		const newSettings = {
			...settings,
			[key]: value,
		};

		// Mutually exclusive logic for index/noindex
		if (key === 'index' && value) {
			newSettings.noindex = false;
		} else if (key === 'noindex' && value) {
			newSettings.index = false;
		}

		onChange(newSettings);
	};

	// Help icon component
	const HelpIcon = ({ text }) => (
		<Tooltip text={text}>
			<span
				style={{
					display: 'inline-flex',
					alignItems: 'center',
					justifyContent: 'center',
					width: '16px',
					height: '16px',
					borderRadius: '50%',
					background: '#ddd',
					color: 'var(--tr-text-secondary)',
					fontSize: '10px',
					fontWeight: 'bold',
					marginLeft: '4px',
					cursor: 'help',
				}}
			>
				?
			</span>
		</Tooltip>
	);

	return (
		<div className="thinkrank-robot-meta-settings">
			<div
				style={{
					display: 'grid',
					gridTemplateColumns: 'repeat(3, 1fr)',
					gap: '20px',
				}}
			>
				{/* Column 1 */}
				<div
					style={{
						display: 'flex',
						flexDirection: 'column',
						gap: '15px',
					}}
				>
					<CheckboxControl
						label={__('Index', 'thinkrank')}
						help={__(
							'Allow search engines to index this content.',
							'thinkrank'
						)}
						checked={settings.index}
						onChange={(val) => handleChange('index', val)}
						__nextHasNoMarginBottom
					/>
					<CheckboxControl
						label={__('No Follow', 'thinkrank')}
						help={__(
							'Do not follow links on this page.',
							'thinkrank'
						)}
						checked={settings.nofollow}
						onChange={(val) => handleChange('nofollow', val)}
						__nextHasNoMarginBottom
					/>
				</div>

				{/* Column 2 */}
				<div
					style={{
						display: 'flex',
						flexDirection: 'column',
						gap: '15px',
					}}
				>
					<CheckboxControl
						label={__('No Index', 'thinkrank')}
						help={__(
							'Do not allow search engines to index this content.',
							'thinkrank'
						)}
						checked={settings.noindex}
						onChange={(val) => handleChange('noindex', val)}
						__nextHasNoMarginBottom
					/>
					<CheckboxControl
						label={__('No Archive', 'thinkrank')}
						help={__(
							'Do not show a cached link in search results.',
							'thinkrank'
						)}
						checked={settings.noarchive}
						onChange={(val) => handleChange('noarchive', val)}
						__nextHasNoMarginBottom
					/>
				</div>

				{/* Column 3 */}
				<div
					style={{
						display: 'flex',
						flexDirection: 'column',
						gap: '15px',
					}}
				>
					<CheckboxControl
						label={__('No Snippet', 'thinkrank')}
						help={__(
							'Do not show a snippet in search results.',
							'thinkrank'
						)}
						checked={settings.nosnippet}
						onChange={(val) => handleChange('nosnippet', val)}
						__nextHasNoMarginBottom
					/>
					<CheckboxControl
						label={__('No Image Index', 'thinkrank')}
						help={__(
							'Do not index images on this page.',
							'thinkrank'
						)}
						checked={settings.noimageindex}
						onChange={(val) => handleChange('noimageindex', val)}
						__nextHasNoMarginBottom
					/>
				</div>
			</div>
		</div>
	);
};

export default RobotMetaSettings;
