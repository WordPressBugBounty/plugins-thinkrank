/**
 * SettingsCard Component
 *
 * Standardized settings card for Essential SEO tabs.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import classnames from 'classnames';
import { Toggle, Alert } from '../ui';

const SettingsCard = ({
	title,
	enabled = true,
	onToggle,
	toggleLabel,
	// True while the enable toggle is persisting. Feature switches autosave
	// (see hooks/useAutosaveSettings.js), so the card owes the user a visible
	// in-flight state and must refuse a second flip until the first lands.
	toggleBusy = false,
	optimizationButtons = [],
	notice,
	onNoticeRemove,
	children,
	className = '',
	showDisabledWarning = true,
	disabledWarningMessage,
	size,
	...rest
}) => {
	return (
		<div
			className={classnames(
				'tr-bg-white tr-rounded-lg tr-border tr-border-secondary tr-overflow-hidden',
				className
			)}
			{...rest}
		>
			{/* Header */}
			<div className="tr-flex tr-items-center tr-justify-between tr-gap-4 tr-px-6 tr-py-4 tr-border-b tr-border-secondary">
				<div className="tr-min-w-0 tr-flex-1">
					{typeof title === 'string' ? (
						<h2 className="tr-m-0 tr-text-sm tr-font-semibold tr-text-primary tr-leading-snug">
							{title}
						</h2>
					) : (
						title
					)}
				</div>

				{(onToggle || optimizationButtons.length > 0) && (
					<div className="tr-flex tr-shrink-0 tr-items-center tr-gap-3">
						{onToggle && (
							<div className="tr-flex tr-items-center tr-gap-2">
								{toggleBusy && (
									<span className="tr-text-xs tr-text-tertiary">
										{__('Saving…', 'thinkrank')}
									</span>
								)}
								<Toggle
									checked={enabled}
									onChange={onToggle}
									disabled={toggleBusy}
									label={
										toggleLabel || __('Enable', 'thinkrank')
									}
								/>
							</div>
						)}
						{optimizationButtons.map((btn, i) => (
							<div key={i}>{btn}</div>
						))}
					</div>
				)}
			</div>

			{/* Body */}
			<div className="tr-p-6 tr-flex tr-flex-col tr-gap-4">
				{!enabled && showDisabledWarning && (
					<Alert variant="warning">
						{disabledWarningMessage ||
							__(
								'This feature is disabled. Enable it to access configuration options.',
								'thinkrank'
							)}
					</Alert>
				)}

				{notice && (
					<Alert
						variant={
							notice.status === 'error'
								? 'error'
								: notice.status === 'success'
									? 'success'
									: notice.status === 'warning'
										? 'warning'
										: 'info'
						}
						onDismiss={onNoticeRemove}
					>
						{notice.message}
					</Alert>
				)}

				{children}
			</div>
		</div>
	);
};

export default SettingsCard;
