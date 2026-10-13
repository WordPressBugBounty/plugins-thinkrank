/**
 * Horizontal step indicator.
 *
 * Completed and active steps are clickable so users can jump back to a
 * previously visited step. Future steps stay disabled — navigating forward
 * would skip each step's save/validation. Steps below `minStep` (e.g. the Start
 * step once dismissed by opting in to usage tracking) are also locked and
 * non-navigable.
 *
 * @package
 * @since 1.0.0
 */

export default function Stepper({
	steps,
	current,
	minStep = 1,
	locked = false,
	onStepClick,
}) {
	return (
		<ol className="tr-wizard__stepper" aria-label="Setup progress">
			{steps.map((step, position) => {
				// Display number is the position among *visible* steps (gap-free),
				// while state/navigation key off the step's stable flow index.
				const displayNumber = position + 1;
				const index = step.index ?? displayNumber;
				let state = 'future';
				if (index < current) {
					state = 'complete';
				} else if (index === current) {
					state = 'active';
				}

				const navigable =
					!locked && index <= current && index >= minStep;
				const marker = (
					<span className="tr-wizard__step-marker" aria-hidden="true">
						{state === 'complete' ? '✓' : displayNumber}
					</span>
				);
				const label = (
					<span className="tr-wizard__step-label">{step.label}</span>
				);

				return (
					<li
						key={step.slug}
						className={`tr-wizard__step is-${state}`}
						aria-current={state === 'active' ? 'step' : undefined}
					>
						{navigable && onStepClick ? (
							<button
								type="button"
								className="tr-wizard__step-button"
								onClick={() => onStepClick(index)}
								disabled={index === current}
							>
								{marker}
								{label}
							</button>
						) : (
							<>
								{marker}
								{label}
							</>
						)}
					</li>
				);
			})}
		</ol>
	);
}
