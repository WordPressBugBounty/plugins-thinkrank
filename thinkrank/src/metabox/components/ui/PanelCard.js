/**
 * PanelCard — a card with an icon header.
 *
 * Composes the shared ui `Card` (white, 1px border, rounded-xl) and adds an
 * icon + title + subtitle header plus an optional header action. Used by every
 * metabox panel for a consistent look. (The shared `CardHeader` has no icon
 * slot, hence this thin metabox-scoped wrapper.)
 */

import Card from '../../../admin/components/ui/Card';

const PanelCard = ({
	icon,
	title,
	subtitle,
	action,
	children,
	bodyClassName = '',
	className,
}) => (
	<Card padding={false} className={className}>
		{(title || icon || action) && (
			<div className="tr-flex tr-items-start tr-justify-between tr-gap-3 tr-px-4 tr-py-3 tr-border-b tr-border-secondary">
				<div className="tr-flex tr-items-start tr-gap-3 tr-min-w-0">
					{icon && (
						<span className="tr-flex tr-items-center tr-justify-center tr-w-8 tr-h-8 tr-rounded-lg tr-bg-brand-bg tr-text-brand tr-shrink-0">
							{icon}
						</span>
					)}
					<div className="tr-min-w-0">
						<h3 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
							{title}
						</h3>
						{subtitle && (
							<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
								{subtitle}
							</p>
						)}
					</div>
				</div>
				{action && <div className="tr-shrink-0">{action}</div>}
			</div>
		)}
		<div className={`tr-p-4 ${bodyClassName}`}>{children}</div>
	</Card>
);

export default PanelCard;
