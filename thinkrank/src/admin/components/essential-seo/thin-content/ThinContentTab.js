/**
 * Thin Content
 *
 * Which published pages are shorter than they should be, grouped by post type,
 * thinnest first, with the threshold for each type editable in place (#565).
 *
 * The length is what a visitor reads: the server resolves page builder content
 * before counting, so an Elementor or Bricks page is not reported as empty.
 * That resolution is expensive, which is why the scan arrives a batch at a
 * time and this screen keeps asking until it is done.
 *
 * The server owns every judgement, as it does on Bulk Snippets. This renders
 * the report and edits the thresholds.
 *
 * @package
 * @since 2.10.0
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { Card, Button, Input, Alert, SkeletonCard, EmptyState } from '../../ui';
import SectionHeader from '../../common/SectionHeader';
import notify from '../../../utils/notify';
import logger from '../../../../shared/utils/logger';

const PATH = '/thinkrank/v1/global-seo/thin-content';

/**
 * Describe a length in the unit the site counts in.
 *
 * A number with no unit is a trap on a Japanese or Thai site, where WordPress
 * counts characters rather than words and 300 means something entirely
 * different.
 *
 * @param {number} count Length.
 * @param {string} unit  Unit from the server.
 * @return {string} Human-readable length.
 */
export function formatLength(count, unit) {
	const value = Math.max(0, Math.floor(Number(count)) || 0);

	if (unit === 'words') {
		return sprintf(
			/* translators: %d: number of words. */
			_n('%d word', '%d words', value, 'thinkrank'),
			value
		);
	}

	return sprintf(
		/* translators: %d: number of characters. */
		_n('%d character', '%d characters', value, 'thinkrank'),
		value
	);
}

/**
 * A threshold input that commits on blur or Enter.
 *
 * The value shown is always one the server holds. A save that is refused (a
 * number outside the limits, or a request that fails) puts the field back to
 * the value in force: before, a rejected 0 or 20000 stayed in the box after
 * the error, and read as if it had been saved.
 *
 * @param {Object}   props             Component props.
 * @param {string}   props.id          Field id. ui/Input derives one from the
 *                                     label otherwise, and labels repeat.
 * @param {string}   props.label       Field label.
 * @param {string}   [props.ariaLabel] Accessible name, when the visible label is
 *                                     shared by every post type's field and so does
 *                                     not say which one this is (#840).
 * @param {number}   props.value       Threshold in force.
 * @param {Object}   props.limits      Min and max threshold.
 * @param {boolean}  props.disabled    Whether editing is blocked.
 * @param {Function} props.onSave      Commit a new threshold. Returns a promise
 *                                     resolving to whether it was saved.
 */
export const ThresholdInput = ({
	id,
	label,
	ariaLabel,
	value,
	limits,
	disabled,
	onSave,
}) => {
	const [draft, setDraft] = useState(String(value));

	// The report reloads after every save, so the value from the server is the
	// truth; a draft left over from a previous render would show a number that
	// is not in force.
	useEffect(() => {
		setDraft(String(value));
	}, [value]);

	const commit = () => {
		const next = parseInt(draft, 10);
		if (!Number.isFinite(next) || next === value) {
			setDraft(String(value));
			return;
		}

		if (next < limits.min_threshold || next > limits.max_threshold) {
			setDraft(String(value));
			notify.error(
				sprintf(
					/* translators: 1: smallest threshold, 2: largest threshold. */
					__('Enter a number from %1$d to %2$d.', 'thinkrank'),
					limits.min_threshold,
					limits.max_threshold
				)
			);
			return;
		}

		Promise.resolve(onSave(next)).then((saved) => {
			if (saved === false) {
				setDraft(String(value));
			}
		});
	};

	return (
		<Input
			id={id}
			label={label}
			aria-label={ariaLabel}
			type="number"
			min={limits.min_threshold}
			max={limits.max_threshold}
			value={draft}
			disabled={disabled}
			onChange={(e) => setDraft(e.target.value)}
			onBlur={commit}
			onKeyDown={(e) => {
				if (e.key === 'Enter') {
					e.preventDefault();
					commit();
				}
			}}
			className="tr-w-40"
		/>
	);
};

/**
 * The threshold control for one post type.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.type     Post type row from the report.
 * @param {string}   props.unit     Counting unit.
 * @param {number}   props.fallback The site default.
 * @param {Object}   props.limits   Min and max threshold.
 * @param {boolean}  props.disabled Whether editing is blocked.
 * @param {Function} props.onSave   Commit a new threshold (null clears it).
 */
const ThresholdField = ({ type, unit, fallback, limits, disabled, onSave }) => {
	// One string, used both as the visible label and inside the accessible name.
	// An aria-label replaces the label for a screen reader, so the name has to
	// contain the visible text verbatim or what is seen and what is heard stop
	// matching (WCAG 2.5.3 Label in Name). Deriving both from this avoids the
	// two drifting, and avoids relying on a case-insensitive comparison.
	const visibleLabel =
		unit === 'words'
			? __('Thin below (words)', 'thinkrank')
			: __('Thin below (characters)', 'thinkrank');

	return (
		<div className="tr-flex tr-flex-wrap tr-items-end tr-gap-2">
			<ThresholdInput
				// ui/Input derives its id from the label when it is given none,
				// and every post type's label is the same string — so all five
				// fields shared one id and every <label for> pointed at the
				// first. Clicking "Thin below" under Products focused the field
				// under Posts.
				id={`tr-thin-threshold-${type.post_type}`}
				label={visibleLabel}
				// Five of these fields can be on screen and the visible label is
				// the same string in all of them: the post type appeared only as
				// unassociated text above, so assistive tech announced five
				// identical controls (#840).
				ariaLabel={sprintf(
					/* translators: 1: post type label, 2: the field's visible label. */
					__('%1$s: %2$s', 'thinkrank'),
					type.label,
					visibleLabel
				)}
				value={type.threshold}
				limits={limits}
				disabled={disabled}
				onSave={onSave}
			/>
			{type.overridden && (
				<Button
					variant="link"
					disabled={disabled}
					onClick={() => onSave(null)}
					// Same problem as the field beside it: one of these per
					// overridden post type, all reading identically.
					aria-label={sprintf(
						/* translators: 1: post type label, 2: the site-wide default threshold. */
						__('%1$s: use the default (%2$d)', 'thinkrank'),
						type.label,
						fallback
					)}
				>
					{sprintf(
						/* translators: %d: the site-wide default threshold. */
						__('Use the default (%d)', 'thinkrank'),
						fallback
					)}
				</Button>
			)}
		</div>
	);
};

/**
 * One post type: its numbers, its threshold and its thinnest pages.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.type     Post type row from the report.
 * @param {string}   props.unit     Counting unit.
 * @param {number}   props.fallback The site default threshold.
 * @param {Object}   props.limits   Min and max threshold.
 * @param {boolean}  props.disabled Whether editing is blocked.
 * @param {Function} props.onSave   Commit a new threshold.
 */
export const PostTypeSection = ({
	type,
	unit,
	fallback,
	limits,
	disabled,
	onSave,
}) => {
	const clean = type.thin === 0;

	return (
		<div className="tr-rounded-lg tr-border tr-border-secondary">
			<div className="tr-flex tr-flex-wrap tr-items-end tr-justify-between tr-gap-4 tr-px-4 tr-py-3">
				<div>
					{/* A real heading, so the per-type blocks can be navigated
					    by heading rather than only seen. h4 because
					    SectionHeader renders the screen's "Thin Content" title
					    as h3 and these sit under it; the level was read off the
					    rendered outline rather than picked. Classes unchanged,
					    plus tr-m-0 to drop the margin a browser gives an h4. */}
					<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
						{type.label}
					</h4>
					<div className="tr-text-xs tr-text-secondary tr-mt-0.5">
						{clean
							? sprintf(
									/* translators: %d: pages checked. */
									_n(
										'%d published page, none thin.',
										'%d published pages, none thin.',
										type.counted,
										'thinkrank'
									),
									type.counted
								)
							: sprintf(
									/* translators: 1: thin pages, 2: pages checked. */
									__(
										'%1$d of %2$d published pages are under the threshold.',
										'thinkrank'
									),
									type.thin,
									type.counted
								)}
					</div>
				</div>
				<ThresholdField
					type={type}
					unit={unit}
					fallback={fallback}
					limits={limits}
					disabled={disabled}
					onSave={onSave}
				/>
			</div>

			{!clean && (
				<ul className="tr-list-none tr-m-0 tr-p-0">
					{type.posts.map((post) => (
						<li
							key={post.post_id}
							className="tr-flex tr-flex-wrap tr-items-baseline tr-justify-between tr-gap-3 tr-px-4 tr-py-2 tr-border-t tr-border-secondary"
						>
							<a
								// can_edit is the server's answer, so ask it
								// rather than reading an empty edit_url as one.
								// The two agree today only because
								// get_edit_post_link() returns nothing to a user
								// who cannot edit; a row is a thin page either
								// way, and it must stay a link to somewhere.
								href={
									(post.can_edit && post.edit_url) ||
									post.permalink
								}
								className="tr-text-sm tr-text-brand tr-break-words"
								target="_blank"
								rel="noreferrer"
							>
								{post.post_title ||
									sprintf(
										/* translators: %d: post ID. */
										__('(no title) #%d', 'thinkrank'),
										post.post_id
									)}
							</a>
							<span className="tr-text-xs tr-text-secondary">
								{formatLength(post.count, unit)}
							</span>
						</li>
					))}
					{type.thin > type.posts.length && (
						<li className="tr-px-4 tr-py-2 tr-text-xs tr-text-secondary tr-border-t tr-border-secondary">
							{sprintf(
								/* translators: 1: pages listed, 2: thin pages in total. */
								__(
									'Showing the %1$d thinnest of %2$d.',
									'thinkrank'
								),
								// What is actually on screen, not the server's
								// ceiling: a page deleted between the count and
								// the render leaves fewer rows than the limit,
								// and the sentence would then name a number the
								// reader could not find.
								type.posts.length,
								type.thin
							)}
						</li>
					)}
				</ul>
			)}
		</div>
	);
};

const ThinContentTab = () => {
	const [report, setReport] = useState(null);
	const [isLoading, setIsLoading] = useState(true);
	const [isRescanning, setIsRescanning] = useState(false);
	const [isSaving, setIsSaving] = useState(false);
	const [error, setError] = useState(null);
	// Responses received, so the polling effect runs once per response even
	// when two in a row carry the same `pending`. Keyed on `pending` alone, a
	// response that made no progress never re-ran the effect: polling stopped,
	// the stall check below never counted it, and Rescan stayed disabled.
	const [responses, setResponses] = useState(0);
	const [isStalled, setIsStalled] = useState(false);

	const load = useCallback((refresh = false) => {
		return apiFetch({ path: refresh ? `${PATH}?refresh=1` : PATH })
			.then((response) => {
				setReport(response);
				setResponses((n) => n + 1);
				setError(null);
			})
			.catch((e) => {
				logger.error('Thin content: load failed', e);
				setError(
					e?.message ||
						__(
							'Could not read the thin content report.',
							'thinkrank'
						)
				);
			})
			.finally(() => setIsLoading(false));
	}, []);

	useEffect(() => {
		load();
	}, [load]);

	// Each request counts another bounded batch, so keep asking until none
	// remain. Resolving builder content is what makes this take more than one
	// request on a large site.
	const pending = report?.pending ?? 0;
	// Polls in a row that made no progress. The server always counts a batch,
	// so this only trips if something prevents that (a cached response did,
	// before this path was exempted from the browser cache), and then polling
	// stops rather than hammering the server for the life of the tab. Rescan
	// is enabled again at that point, so the screen is recoverable without a
	// reload.
	const stalled = useRef({ last: null, count: 0, seen: null });
	useEffect(() => {
		if (pending <= 0 || isRescanning || isSaving || isStalled) {
			return undefined;
		}
		const tracker = stalled.current;
		// Only a new response is evidence. The effect also re-runs when a save
		// or a rescan finishes, and counting those would call a healthy scan
		// stalled.
		if (tracker.seen !== responses) {
			tracker.seen = responses;
			tracker.count =
				tracker.last !== null && pending >= tracker.last
					? tracker.count + 1
					: 0;
			tracker.last = pending;
		}
		if (tracker.count >= 3) {
			logger.error('Thin content: scan made no progress', pending);
			setIsStalled(true);
			return undefined;
		}
		const timer = setTimeout(load, 600);
		return () => clearTimeout(timer);
	}, [responses, pending, isRescanning, isSaving, isStalled, load]);

	const rescan = () => {
		setIsRescanning(true);
		setIsStalled(false);
		stalled.current = { last: null, count: 0, seen: null };
		load(true).finally(() => setIsRescanning(false));
	};

	/**
	 * Save thresholds and reload the report.
	 *
	 * @param {Object} data    Settings to send: `default` and/or `overrides`.
	 * @param {string} message Success notice.
	 * @return {Promise<boolean>} Whether the save went through.
	 */
	const saveSettings = (data, message) => {
		setIsSaving(true);

		return apiFetch({
			path: `${PATH}/settings`,
			method: 'POST',
			data,
		})
			.then(() => load())
			.then(() => {
				notify.success(message);
				return true;
			})
			.catch((e) => {
				logger.error('Thin content: threshold save failed', e);
				notify.error(
					e?.message ||
						__('Could not save the threshold.', 'thinkrank')
				);
				return false;
			})
			.finally(() => setIsSaving(false));
	};

	const saveThreshold = (postType, value) =>
		saveSettings(
			{ overrides: { [postType]: value } },
			value === null
				? __('Threshold reset to the default.', 'thinkrank')
				: __('Threshold saved.', 'thinkrank')
		);

	const saveDefault = (value) =>
		saveSettings({ default: value }, __('Default saved.', 'thinkrank'));

	if (isLoading) {
		return (
			<div className="tr-flex tr-flex-col tr-gap-5">
				<SkeletonCard lines={2} />
				<SkeletonCard lines={6} />
			</div>
		);
	}

	if (error) {
		return (
			<Card>
				<Alert variant="error">
					{error}{' '}
					<Button variant="link" onClick={() => load()}>
						{__('Try again', 'thinkrank')}
					</Button>
				</Alert>
			</Card>
		);
	}

	const unit = report?.unit || 'words';
	const types = report?.types || [];
	const limits = report?.limits || {
		min_threshold: 1,
		max_threshold: 10000,
		max_listed: 25,
	};
	const busy = isSaving || isRescanning;
	const defaultThreshold = report?.default_threshold ?? 300;

	return (
		<Card padding={false}>
			<SectionHeader
				title={__('Thin Content', 'thinkrank')}
				description={__(
					'Published pages with too little content to rank or to be worth citing. The length counted is what a visitor reads, so a page built in Elementor, Divi, Oxygen, Beaver or Bricks reports its real length rather than an empty one.',
					'thinkrank'
				)}
			/>

			<div className="tr-p-6 tr-flex tr-flex-col tr-gap-4">
				<div className="tr-flex tr-flex-wrap tr-items-baseline tr-gap-x-4 tr-gap-y-2 tr-px-4 tr-py-3 tr-rounded-lg tr-border tr-border-brand/30 tr-bg-brand-bg">
					<span className="tr-text-2xl tr-font-semibold tr-text-primary">
						{pending > 0
							? `${report?.thin ?? 0}+`
							: (report?.thin ?? 0)}
					</span>
					<span className="tr-text-sm tr-text-secondary">
						{sprintf(
							/* translators: %d: published pages checked. */
							_n(
								'thin of %d published page checked',
								'thin of %d published pages checked',
								report?.counted ?? 0,
								'thinkrank'
							),
							report?.counted ?? 0
						)}
					</span>
				</div>

				{pending > 0 && isStalled && (
					<Alert variant="warning">
						{sprintf(
							/* translators: %d: pages not yet counted. */
							_n(
								'Counting stopped with %d page left. Press Rescan to try again.',
								'Counting stopped with %d pages left. Press Rescan to try again.',
								pending,
								'thinkrank'
							),
							pending
						)}
					</Alert>
				)}

				{pending > 0 && !isStalled && (
					<Alert variant="info">
						{sprintf(
							/* translators: %d: pages still being counted. */
							_n(
								'Counting %d more page… Page builder content is resolved as it goes, so this takes a moment. The numbers above can only go up.',
								'Counting %d more pages… Page builder content is resolved as it goes, so this takes a moment. The numbers above can only go up.',
								pending,
								'thinkrank'
							),
							pending
						)}
					</Alert>
				)}

				<div className="tr-flex tr-flex-wrap tr-items-end tr-gap-x-4 tr-gap-y-2">
					<ThresholdInput
						id="tr-thin-threshold-default"
						label={
							unit === 'words'
								? __('Default threshold (words)', 'thinkrank')
								: __(
										'Default threshold (characters)',
										'thinkrank'
									)
						}
						value={defaultThreshold}
						limits={limits}
						disabled={busy}
						onSave={saveDefault}
					/>
					<span className="tr-text-xs tr-text-secondary tr-pb-2">
						{__(
							'Applies to every post type that has no threshold of its own.',
							'thinkrank'
						)}
					</span>
				</div>

				{pending === 0 && types.length === 0 && (
					<EmptyState
						title={__('Nothing published yet', 'thinkrank')}
						description={__(
							'Publish some content and it will be checked here.',
							'thinkrank'
						)}
						size="sm"
					/>
				)}

				{types.map((type) => (
					<PostTypeSection
						key={type.post_type}
						type={type}
						unit={unit}
						fallback={defaultThreshold}
						limits={limits}
						disabled={busy}
						onSave={(value) => saveThreshold(type.post_type, value)}
					/>
				))}

				<div className="tr-flex tr-items-center tr-gap-3">
					<Button
						variant="secondary"
						onClick={rescan}
						loading={isRescanning}
						// Disabled while the scan is still moving, but not once
						// it has stalled: that is exactly when Rescan is needed.
						disabled={(pending > 0 && !isStalled) || isSaving}
					>
						{__('Rescan', 'thinkrank')}
					</Button>
					{!report?.scheduled && (
						<span className="tr-text-xs tr-text-secondary">
							{__(
								'Checked when you open this screen.',
								'thinkrank'
							)}
						</span>
					)}
				</div>
			</div>
		</Card>
	);
};

export default ThinContentTab;
