/**
 * SEO Goals: a section's checklist, above its settings (#728).
 *
 * The same goals the Goals screen lists, filtered to this section and shown
 * with a tick on the ones already done — "Crawling: sitemap on, llms.txt
 * published, AI crawlers allowed". Each item links to the screen where it is
 * set. Collapsed to one line; renders nothing for a section with no goals.
 *
 * @package
 * @since 2.22.0
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Icon,
	check,
	chevronDown,
	chevronUp,
	chevronRight,
} from '@wordpress/icons';

import { GOALS_PATH } from './useGoals';
import { WRITE_EVENT } from '../../utils/apiCache';
import { goalHref, followGoal } from './goalLink';
import { getPageUrl, navigate, isPlainLeftClick } from '../../utils/router';

const GOALS_PARAMS = { nav_section: 'seo-analyzer', nav_item: 'goals' };

const Tick = ({ done }) =>
	done ? (
		<span
			aria-hidden="true"
			className="tr-flex tr-shrink-0 tr-items-center tr-justify-center tr-w-5 tr-h-5 tr-rounded-full tr-bg-success tr-text-white"
		>
			<Icon icon={check} size={16} className="tr-fill-current" />
		</span>
	) : (
		<span
			aria-hidden="true"
			className="tr-block tr-shrink-0 tr-w-5 tr-h-5 tr-rounded-full tr-border-2 tr-border-primary tr-bg-white"
		/>
	);

const Ring = ({ done, total }) => {
	const radius = 15;
	const circumference = 2 * Math.PI * radius;
	const complete = done === total;

	return (
		<span className="tr-relative tr-flex tr-shrink-0 tr-items-center tr-justify-center tr-w-10 tr-h-10">
			<svg
				aria-hidden="true"
				viewBox="0 0 36 36"
				width="40"
				height="40"
				style={{ transform: 'rotate(-90deg)' }}
			>
				<circle
					cx="18"
					cy="18"
					r={radius}
					fill="none"
					strokeWidth="3"
					className="tr-stroke-line-2"
				/>
				<circle
					cx="18"
					cy="18"
					r={radius}
					fill="none"
					strokeWidth="3"
					strokeLinecap="round"
					strokeDasharray={circumference}
					strokeDashoffset={
						circumference -
						(total > 0 ? (done / total) * circumference : 0)
					}
					className={
						complete ? 'tr-stroke-success' : 'tr-stroke-brand'
					}
				/>
			</svg>
			<span
				className={`tr-absolute tr-text-[11px] tr-font-semibold tr-tabular-nums ${
					complete ? 'tr-text-success' : 'tr-text-primary'
				}`}
			>
				{complete ? (
					<Icon icon={check} size={18} className="tr-fill-current" />
				) : (
					`${done}/${total}`
				)}
			</span>
		</span>
	);
};

/**
 * @param {Object}        props            Component props.
 * @param {string}        props.section    Essential SEO section id.
 * @param {Function|null} props.onNavigate Essential SEO's (section, item) switcher.
 */
const SectionChecklist = ({ section, onNavigate = null }) => {
	const [items, setItems] = useState(null);
	const [open, setOpen] = useState(false);

	const load = useCallback(
		(isCurrent) =>
			apiFetch({ path: GOALS_PATH })
				.then((response) => {
					if (isCurrent()) {
						setItems(response?.data?.checklists?.[section] || []);
					}
				})
				// A checklist that cannot load is simply not shown; the
				// settings below it are what the reader came for. A failed
				// refresh keeps the list already on screen.
				.catch(() => isCurrent() && setItems((prev) => prev || [])),
		[section]
	);

	useEffect(() => {
		let current = true;
		const isCurrent = () => current;
		setItems(null);
		setOpen(false);
		load(isCurrent);

		// Saving a setting on this screen can complete an item: refetch once
		// the write settles rather than waiting for a reload. Marks made on
		// the Goals screen are skipped, they do not change a checklist here.
		let timer = null;
		const onWrite = (event) => {
			if (String(event?.detail?.path || '').startsWith(GOALS_PATH)) {
				return;
			}
			clearTimeout(timer);
			timer = setTimeout(() => load(isCurrent), 400);
		};
		window.addEventListener(WRITE_EVENT, onWrite);

		return () => {
			current = false;
			clearTimeout(timer);
			window.removeEventListener(WRITE_EVENT, onWrite);
		};
	}, [load]);

	if (!items || 0 === items.length) {
		return null;
	}

	const done = items.filter((item) => item.done).length;
	const left = items.length - done;
	const goalsHref = getPageUrl('essential-seo', GOALS_PARAMS);

	return (
		<div className="tr-mb-4 tr-bg-white tr-border tr-border-secondary tr-rounded-lg tr-overflow-hidden">
			<button
				type="button"
				aria-expanded={open}
				onClick={() => setOpen(!open)}
				className="tr-w-full tr-flex tr-items-center tr-justify-between tr-gap-3 tr-px-4 tr-py-3 tr-bg-transparent tr-border-0 tr-cursor-pointer tr-text-left hover:tr-bg-line-2/30"
			>
				<span className="tr-flex tr-items-center tr-gap-3 tr-min-w-0">
					<Ring done={done} total={items.length} />
					<span className="tr-min-w-0">
						<span className="tr-block tr-text-sm tr-font-semibold tr-text-primary">
							{__('Section checklist', 'thinkrank')}
						</span>
						<span className="tr-block tr-text-xs tr-text-secondary">
							{0 === left
								? __('Everything here is done.', 'thinkrank')
								: sprintf(
										/* translators: 1: items done, 2: items in the checklist, 3: items left. */
										__(
											'%1$d of %2$d done, %3$d left',
											'thinkrank'
										),
										done,
										items.length,
										left
									)}
						</span>
					</span>
				</span>
				<span className="tr-inline-flex tr-items-center tr-text-sm tr-font-medium tr-text-brand">
					{open ? __('Hide', 'thinkrank') : __('Show', 'thinkrank')}
					<Icon
						icon={open ? chevronUp : chevronDown}
						size={18}
						className="tr-fill-current"
					/>
				</span>
			</button>

			{open && (
				<>
					<ul className="tr-m-0 tr-p-0 tr-list-none tr-border-t tr-border-secondary tr-divide-y tr-divide-secondary">
						{items.map((item) => {
							const linked = Boolean(
								item.link?.section ||
									item.link?.page ||
									item.link?.screen
							);
							const body = (
								<>
									<Tick done={item.done} />
									<span
										className={`tr-flex-1 tr-min-w-0 tr-text-sm ${
											item.done
												? 'tr-text-secondary'
												: 'tr-text-primary tr-font-medium'
										}`}
									>
										{item.title}
									</span>
									{linked && (
										<span
											className={`tr-inline-flex tr-items-center tr-shrink-0 tr-text-xs tr-font-medium ${
												item.done
													? 'tr-text-tertiary group-hover:tr-text-brand'
													: 'tr-text-brand'
											}`}
										>
											{item.done
												? __('View', 'thinkrank')
												: __('Fix it', 'thinkrank')}
											<Icon
												icon={chevronRight}
												size={18}
												className="tr-fill-current"
											/>
										</span>
									)}
								</>
							);

							return (
								<li key={item.id} className="tr-m-0">
									{linked ? (
										<a
											href={goalHref(item.link)}
											onClick={(event) =>
												followGoal(
													event,
													item.link,
													onNavigate
												)
											}
											className="tr-group tr-flex tr-items-center tr-gap-3 tr-px-4 tr-py-2.5 tr-no-underline tr-transition-colors hover:tr-bg-line-2/40"
										>
											{body}
										</a>
									) : (
										<div className="tr-flex tr-items-center tr-gap-3 tr-px-4 tr-py-2.5">
											{body}
										</div>
									)}
								</li>
							);
						})}
					</ul>
					<div className="tr-px-4 tr-py-2.5 tr-border-t tr-border-secondary tr-bg-line-2/30">
						<a
							href={goalsHref}
							onClick={(event) => {
								if (!isPlainLeftClick(event)) {
									return;
								}
								event.preventDefault();
								if (onNavigate) {
									onNavigate('seo-analyzer', 'goals');
									window.scrollTo({
										top: 0,
										behavior: 'smooth',
									});
									return;
								}
								navigate('essential-seo', GOALS_PARAMS);
							}}
							className="tr-inline-flex tr-items-center tr-gap-1 tr-text-xs tr-font-semibold tr-text-brand tr-no-underline"
						>
							{__('See every goal for this site', 'thinkrank')}
							<Icon
								icon={chevronRight}
								size={16}
								className="tr-fill-current"
							/>
						</a>
					</div>
				</>
			)}
		</div>
	);
};

export default SectionChecklist;
