/**
 * Sticky footer with Skip / Back / primary actions.
 *
 * @package
 * @since 1.0.0
 */

import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function StepFooter({
	primaryLabel,
	onPrimary,
	showSkip = false,
	onSkip,
	showBack = false,
	onBack,
	isSaving = false,
	locked = false,
}) {
	// `locked` (a step is running a long operation) disables every action but,
	// unlike `isSaving`, does not spin the primary button — progress is shown in
	// the step body instead.
	const disabled = isSaving || locked;

	return (
		<div className="tr-wizard__footer">
			<div className="tr-wizard__footer-left">
				{showSkip && (
					<Button
						variant="link"
						className="tr-wizard__skip"
						onClick={onSkip}
						disabled={disabled}
					>
						{__('Skip', 'thinkrank')}
					</Button>
				)}
			</div>
			<div className="tr-wizard__footer-right">
				{showBack && (
					<Button
						variant="secondary"
						onClick={onBack}
						disabled={disabled}
					>
						{__('Back', 'thinkrank')}
					</Button>
				)}
				<Button
					variant="primary"
					onClick={onPrimary}
					isBusy={isSaving}
					disabled={disabled}
				>
					{primaryLabel}
				</Button>
			</div>
		</div>
	);
}
