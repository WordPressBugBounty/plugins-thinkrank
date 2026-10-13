/**
 * Basic / Advanced switch (#730).
 *
 * Lives in the Essential SEO page header, beside the title, because that is
 * the screen it governs: the section menu, the search over those sections,
 * and the advanced settings folded away inside Sitemap, Schema and Social
 * Media. A control that changes one screen belongs on that screen, not in the
 * top nav above screens it does not touch.
 *
 * The stored value is still `simple` — user meta and the REST enum both keep
 * it, so a user who already chose keeps their choice. Only the label reads
 * "Basic".
 *
 * @package
 * @since 2.11.0
 */

import { __ } from '@wordpress/i18n';
import classnames from 'classnames';

import { SIMPLE, ADVANCED, useUiMode } from '../../utils/uiMode';

/**
 * @return {JSX.Element} The switch.
 */
const ModeSwitch = () => {
	const { mode, setMode, isSaving } = useUiMode();
	const options = [
		{
			value: SIMPLE,
			label: __('Basic', 'thinkrank'),
			title: __(
				'Show the sections most sites need; advanced ones stay one click away.',
				'thinkrank'
			),
		},
		{
			value: ADVANCED,
			label: __('Advanced', 'thinkrank'),
			title: __('Show every section.', 'thinkrank'),
		},
	];

	return (
		<div
			role="group"
			aria-label={__('Menu detail', 'thinkrank')}
			className="tr-inline-flex tr-items-center tr-p-0.5 tr-rounded-md tr-bg-line-2 tr-shrink-0"
		>
			{options.map((option) => {
				const active = mode === option.value;
				return (
					<button
						key={option.value}
						type="button"
						aria-pressed={active}
						title={option.title}
						disabled={isSaving}
						onClick={() => setMode(option.value)}
						className={classnames(
							'tr-px-2.5 tr-py-1 tr-rounded tr-border-0 tr-text-xs tr-font-semibold tr-cursor-pointer tr-transition-colors',
							'focus:tr-outline-none focus-visible:tr-ring-2 focus-visible:tr-ring-brand',
							'disabled:tr-cursor-wait',
							active
								? 'tr-bg-white tr-text-primary tr-shadow-sm'
								: 'tr-bg-transparent tr-text-tertiary hover:tr-text-secondary'
						)}
					>
						{option.label}
					</button>
				);
			})}
		</div>
	);
};

export default ModeSwitch;
