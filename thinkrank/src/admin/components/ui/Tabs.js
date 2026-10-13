import { useRef } from '@wordpress/element';
import classnames from 'classnames';

/**
 * TabPanel — wrap each tab's content with this to get correct ARIA roles.
 * Usage:  <TabPanel tabId="my-tab">...</TabPanel>
 * @param root0
 * @param root0.tabId
 * @param root0.activeTab
 * @param root0.children
 * @param root0.className
 */
export function TabPanel({ tabId, activeTab, children, className }) {
	const isActive = activeTab === tabId;
	return (
		<div
			id={`tr-panel-${tabId}`}
			role="tabpanel"
			aria-labelledby={`tr-tab-${tabId}`}
			tabIndex={isActive ? 0 : -1}
			hidden={!isActive}
			className={classnames('focus:tr-outline-none', className)}
		>
			{children}
		</div>
	);
}

export default function Tabs({
	tabs,
	activeTab,
	onTabChange,
	className,
	disabled = false,
}) {
	const tabRefs = useRef([]);

	const handleKeyDown = (e, index) => {
		if (disabled) {
			return;
		}
		const count = tabs.length;
		let nextIndex = index;

		if (e.key === 'ArrowRight') nextIndex = (index + 1) % count;
		else if (e.key === 'ArrowLeft') nextIndex = (index - 1 + count) % count;
		else if (e.key === 'Home') nextIndex = 0;
		else if (e.key === 'End') nextIndex = count - 1;
		else return;

		e.preventDefault();
		onTabChange(tabs[nextIndex].id);
		tabRefs.current[nextIndex]?.focus();
	};

	return (
		<div
			role="tablist"
			aria-label="Page tabs"
			className={classnames(
				'tr-flex tr-border-b tr-border-secondary',
				className
			)}
		>
			{tabs.map((tab, index) => {
				const isActive = activeTab === tab.id;
				const panelId = `tr-panel-${tab.id}`;
				const tabId = `tr-tab-${tab.id}`;

				return (
					<button
						key={tab.id}
						id={tabId}
						ref={(el) => {
							tabRefs.current[index] = el;
						}}
						role="tab"
						aria-selected={isActive}
						aria-controls={panelId}
						tabIndex={isActive ? 0 : -1}
						disabled={disabled}
						onClick={() => !disabled && onTabChange(tab.id)}
						onKeyDown={(e) => handleKeyDown(e, index)}
						className={classnames(
							'tr-relative tr-px-4 tr-py-3 tr-text-sm tr-font-medium tr-whitespace-nowrap',
							'tr-transition-colors tr-duration-150',
							'focus:tr-outline-none',
							disabled && 'tr-opacity-50 tr-cursor-not-allowed',
							isActive
								? "tr-text-brand after:tr-absolute after:tr-bottom-0 after:tr-left-0 after:tr-right-0 after:tr-h-0.5 after:tr-bg-brand after:tr-content-['']"
								: 'tr-text-tertiary hover:tr-text-secondary'
						)}
					>
						{tab.icon && (
							<tab.icon
								className="tr-inline-block tr-w-4 tr-h-4 tr-mr-1.5 tr--mt-0.5"
								aria-hidden="true"
							/>
						)}
						{tab.label}
						{tab.badge !== undefined && (
							<span
								className={classnames(
									'tr-ml-2 tr-inline-flex tr-items-center tr-justify-center',
									'tr-min-w-[18px] tr-h-[18px] tr-px-1 tr-rounded-full tr-text-xs tr-font-semibold',
									isActive
										? 'tr-bg-brand-bg tr-text-brand'
										: 'tr-bg-white tr-text-tertiary'
								)}
								aria-label={`${tab.badge} items`}
							>
								{tab.badge}
							</span>
						)}
						{tab.tag && (
							<span className="tr-absolute -tr-top-1 -tr-right-2 tr-inline-flex tr-items-center tr-px-1.5 tr-py-0.5 tr-rounded-full tr-text-[9px] tr-font-bold tr-uppercase tr-tracking-wide tr-leading-none tr-bg-brand tr-text-white tr-shadow-md tr-ring-2 tr-ring-white">
								{tab.tag}
							</span>
						)}
					</button>
				);
			})}
		</div>
	);
}
