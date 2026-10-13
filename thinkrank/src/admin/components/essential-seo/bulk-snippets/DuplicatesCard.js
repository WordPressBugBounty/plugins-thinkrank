/**
 * Sitewide duplicate titles and meta descriptions.
 *
 * Two pages carrying one title between them compete for the same result, and a
 * single bad template stamps the same metadata on fifty archive pages without
 * anything saying so (#564). The chips on the table next door answer "what is
 * wrong with this post type"; this answers "which pages on this site say the
 * same thing as another one", which has to cross post types to be true.
 *
 * The server owns every judgement here, as it does for the table: this renders
 * the report and links into it.
 *
 * @package
 * @since 2.10.0
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { Card, Button, Alert, SkeletonCard } from '../../ui';
import SectionHeader from '../../common/SectionHeader';
import logger from '../../../../shared/utils/logger';

const PATH = '/thinkrank/v1/global-seo/snippets/duplicates';

const ISSUE_LABELS = {
	duplicate_title: __('Duplicate titles', 'thinkrank'),
	duplicate_description: __('Duplicate descriptions', 'thinkrank'),
};

const ISSUES = ['duplicate_title', 'duplicate_description'];

/**
 * One headline number, and the button that filters the table to it.
 *
 * @param {Object}   props         Component props.
 * @param {string}   props.label   What is being counted.
 * @param {number}   props.posts   Pages caught up in a duplicate.
 * @param {number}   props.groups  Distinct values being duplicated.
 * @param {Function} props.onShow  Filter the table to this issue.
 * @param {boolean}  props.partial Whether the scan is still running.
 */
const DuplicateCount = ({ label, posts, groups, onShow, partial }) => (
	<div className="tr-flex-1 tr-min-w-[180px] tr-px-4 tr-py-3 tr-rounded-lg tr-border tr-border-secondary">
		<div className="tr-text-sm tr-text-secondary">{label}</div>
		<div className="tr-flex tr-items-baseline tr-gap-2 tr-mt-1">
			<span className="tr-text-2xl tr-font-semibold tr-text-primary">
				{partial ? `${posts}+` : posts}
			</span>
			<span className="tr-text-xs tr-text-secondary">
				{sprintf(
					/* translators: %d: number of repeated values. */
					_n(
						'across %d repeated value',
						'across %d repeated values',
						groups,
						'thinkrank'
					),
					groups
				)}
			</span>
		</div>
		{posts > 0 && (
			<Button variant="link" onClick={onShow}>
				{__('Show in the table', 'thinkrank')}
			</Button>
		)}
	</div>
);

/**
 * One repeated value and the pages carrying it.
 *
 * @param {Object} props       Component props.
 * @param {Object} props.group A group from the report.
 */
export const DuplicateGroup = ({ group }) => (
	<li className="tr-px-4 tr-py-3 tr-border-t tr-border-secondary">
		<div className="tr-flex tr-flex-wrap tr-items-baseline tr-gap-2">
			<span className="tr-text-sm tr-font-medium tr-text-primary tr-break-words">
				{group.value || __('(renders to nothing)', 'thinkrank')}
			</span>
			<span className="tr-text-xs tr-text-secondary">
				{sprintf(
					/* translators: %d: number of pages sharing one value. */
					_n('%d page', '%d pages', group.total, 'thinkrank'),
					group.total
				)}
			</span>
		</div>
		<div className="tr-flex tr-flex-wrap tr-gap-x-3 tr-gap-y-1 tr-mt-1">
			{group.posts.map((post) => (
				<a
					key={post.post_id}
					// can_edit is the server's answer about this reader, so ask
					// it rather than reading an empty edit_url as one. The two
					// agree only because get_edit_post_link() returns nothing to
					// a user who cannot edit; the page is one half of a
					// duplicate either way, so it must stay a link to somewhere.
					href={(post.can_edit && post.edit_url) || post.permalink}
					className="tr-text-xs tr-text-brand"
					target="_blank"
					rel="noreferrer"
				>
					{post.post_title || `#${post.post_id}`}
					<span className="tr-text-secondary">
						{' '}
						({post.post_type})
					</span>
				</a>
			))}
			{group.partial && (
				<span className="tr-text-xs tr-text-secondary">
					{sprintf(
						/* translators: %d: pages not listed by name. */
						__('and %d more', 'thinkrank'),
						group.total - group.posts.length
					)}
				</span>
			)}
		</div>
	</li>
);

/**
 * @param {Object}   props               Component props.
 * @param {Function} props.onFilterIssue Filter the table below to an issue.
 */
const DuplicatesCard = ({ onFilterIssue }) => {
	const [report, setReport] = useState(null);
	const [isLoading, setIsLoading] = useState(true);
	const [isRescanning, setIsRescanning] = useState(false);
	const [error, setError] = useState(null);
	const [openIssue, setOpenIssue] = useState(null);
	const [isStalled, setIsStalled] = useState(false);

	const load = useCallback((refresh = false) => {
		return apiFetch({
			path: refresh ? `${PATH}?refresh=1` : PATH,
		})
			.then((response) => {
				setReport(response);
				setError(null);
			})
			.catch((e) => {
				logger.error('Duplicate snippets: load failed', e);
				setError(
					e?.message ||
						__('Could not read the duplicate report.', 'thinkrank')
				);
			})
			.finally(() => setIsLoading(false));
	}, []);

	useEffect(() => {
		load();
	}, [load]);

	// Each request indexes another bounded batch, so keep asking until none
	// remain. The numbers fill in as it goes.
	const pending = report?.pending ?? 0;
	// Polls in a row that made no progress. The server always builds a batch,
	// so this only trips if something prevents that, and then polling stops
	// rather than hammering the server for the life of the tab.
	const progress = useRef({ last: null, count: 0 });
	// Keyed on the response, not on `pending`. Every response is a new object,
	// so each one schedules the next poll and is counted once. Keyed on the
	// number, a poll that returned the same `pending` as the last one never
	// re-ran this effect: polling stopped without a word, the stall check
	// below could never count to three, and Rescan stayed disabled because
	// `pending` was still above zero.
	useEffect(() => {
		if (!report || pending <= 0 || isRescanning) {
			return undefined;
		}
		const tracker = progress.current;
		tracker.count =
			tracker.last !== null && pending >= tracker.last
				? tracker.count + 1
				: 0;
		tracker.last = pending;
		if (tracker.count >= 3) {
			logger.error('Duplicate snippets: scan made no progress', pending);
			setIsStalled(true);
			return undefined;
		}
		const timer = setTimeout(load, 600);
		return () => clearTimeout(timer);
	}, [report, pending, isRescanning, load]);

	const rescan = () => {
		setIsRescanning(true);
		setIsStalled(false);
		progress.current = { last: null, count: 0 };
		load(true).finally(() => setIsRescanning(false));
	};

	if (isLoading) {
		return <SkeletonCard lines={3} />;
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

	const counts = report?.counts || {};
	const groupCounts = report?.group_counts || {};
	const total = ISSUES.reduce((sum, issue) => sum + (counts[issue] || 0), 0);
	const openGroups = openIssue ? report?.groups?.[openIssue] || [] : [];

	return (
		<Card padding={false}>
			<SectionHeader
				title={__('Duplicate titles and descriptions', 'thinkrank')}
				description={__(
					'Published pages across every post type that render the same search title, or the same meta description, as another page. Two pages with one title between them compete for the same result.',
					'thinkrank'
				)}
			/>

			<div className="tr-p-6 tr-flex tr-flex-col tr-gap-4">
				<div className="tr-flex tr-flex-wrap tr-gap-3">
					{ISSUES.map((issue) => (
						<DuplicateCount
							key={issue}
							label={ISSUE_LABELS[issue]}
							posts={counts[issue] || 0}
							groups={groupCounts[issue] || 0}
							partial={pending > 0}
							onShow={() => {
								setOpenIssue(
									openIssue === issue ? null : issue
								);
								onFilterIssue?.(issue);
							}}
						/>
					))}
				</div>

				{pending > 0 && isStalled && (
					<Alert variant="warning">
						{sprintf(
							/* translators: %d: pages not scanned. */
							_n(
								'The scan stopped with %d page left to check, so the numbers above are incomplete. Rescan to start it again.',
								'The scan stopped with %d pages left to check, so the numbers above are incomplete. Rescan to start it again.',
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
							/* translators: %d: pages still being scanned. */
							_n(
								'Scanning %d more page… The numbers above cover what has been scanned so far and can only go up.',
								'Scanning %d more pages… The numbers above cover what has been scanned so far and can only go up.',
								pending,
								'thinkrank'
							),
							pending
						)}
					</Alert>
				)}

				{pending === 0 && total === 0 && (
					<Alert variant="success">
						{sprintf(
							/* translators: %d: pages scanned. */
							_n(
								'No duplicates. All %d published page has a title and description of its own.',
								'No duplicates. All %d published pages have a title and description of their own.',
								report?.scanned || 0,
								'thinkrank'
							),
							report?.scanned || 0
						)}
					</Alert>
				)}

				{openIssue && openGroups.length > 0 && (
					<div className="tr-rounded-lg tr-border tr-border-secondary">
						<div className="tr-px-4 tr-py-2 tr-text-xs tr-font-medium tr-text-secondary">
							{ISSUE_LABELS[openIssue]}
						</div>
						<ul className="tr-list-none tr-m-0 tr-p-0">
							{openGroups.map((group) => (
								<DuplicateGroup key={group.key} group={group} />
							))}
						</ul>
						{report?.truncated?.[openIssue] && (
							<div className="tr-px-4 tr-py-2 tr-text-xs tr-text-secondary tr-border-t tr-border-secondary">
								{sprintf(
									/* translators: %d: repeated values listed. */
									__(
										'Showing the %d largest. Fixing these usually clears the rest, because they tend to share one cause.',
										'thinkrank'
									),
									openGroups.length
								)}
							</div>
						)}
					</div>
				)}

				<div className="tr-flex tr-items-center tr-gap-3">
					<Button
						variant="secondary"
						onClick={rescan}
						loading={isRescanning}
						// Held while a scan is running, since a rescan would
						// start it over, but not once it has stalled: then it is
						// the only way to get the scan going again.
						disabled={pending > 0 && !isStalled}
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

export default DuplicatesCard;
