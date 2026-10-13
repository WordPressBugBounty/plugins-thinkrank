/**
 * MCP Activity
 *
 * What AI assistants did on this site through the MCP server (#717): every
 * change with the values before and after it, every refused call, and a daily
 * count of reads per connection. A change that recorded its previous value can
 * be reverted here; the revert goes back through the same ability the agent
 * used, and is refused when someone has edited the value since, unless the
 * admin chooses to apply it anyway.
 *
 * Data: GET /thinkrank/v1/mcp/activity (filtered page + facets),
 * GET /mcp/activity/export (streamed CSV download), POST /mcp/activity/{id}/revert,
 * GET/POST /mcp/governance (retention).
 *
 * @package
 * @since 2.15.0
 */

import { __, sprintf, _n } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import {
	Alert,
	Badge,
	Button,
	EmptyState,
	Input,
	Modal,
	Select,
	SkeletonText,
	Toggle,
} from '../ui';
import logger from '../../../shared/utils/logger';
import restUrl from '../../utils/restUrl';
import notify from '../../utils/notify';

const PER_PAGE = 25;

const RETENTION_OPTIONS = [
	{ value: '30', label: __('Keep 30 days', 'thinkrank') },
	{ value: '90', label: __('Keep 90 days', 'thinkrank') },
	{ value: '365', label: __('Keep 1 year', 'thinkrank') },
];

const RESULT_OPTIONS = [
	{ value: '', label: __('All results', 'thinkrank') },
	{ value: 'ok', label: __('Succeeded', 'thinkrank') },
	{ value: 'denied', label: __('Refused', 'thinkrank') },
	{ value: 'error', label: __('Failed', 'thinkrank') },
];

const IconActivity = () => (
	<svg
		width="18"
		height="18"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
	</svg>
);

const IconDownload = () => (
	<svg
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
		<polyline points="7 10 12 15 17 10" />
		<line x1="12" y1="15" x2="12" y2="3" />
	</svg>
);

const formatTime = (iso) => {
	// An unparsable timestamp does not throw; it renders "Invalid Date".
	const date = new Date(iso);
	return Number.isNaN(date.getTime()) ? iso : date.toLocaleString();
};

// A stored value as one readable line.
const show = (value) => {
	if (value === null || value === undefined || value === '') {
		return __('(empty)', 'thinkrank');
	}
	if (typeof value === 'object') {
		return JSON.stringify(value);
	}
	if (typeof value === 'boolean') {
		return value ? __('on', 'thinkrank') : __('off', 'thinkrank');
	}
	return String(value);
};

const resultBadge = (row) => {
	if (row.kind === 'revert') {
		return { variant: 'info', label: __('Reverted by admin', 'thinkrank') };
	}
	if (row.result === 'denied') {
		return { variant: 'warning', label: __('Refused', 'thinkrank') };
	}
	if (row.result === 'error') {
		return { variant: 'poor', label: __('Failed', 'thinkrank') };
	}
	if (row.reverted_by) {
		return { variant: 'neutral', label: __('Undone', 'thinkrank') };
	}
	return { variant: 'good', label: __('Changed', 'thinkrank') };
};

// Before → after for every key a change touched.
const DiffTable = ({ before, after, beforeLabel, afterLabel }) => {
	const keys = Array.from(
		new Set([...Object.keys(before || {}), ...Object.keys(after || {})])
	);
	if (!keys.length) {
		return null;
	}
	return (
		<table className="tr-w-full tr-text-xs tr-border-collapse">
			<thead>
				<tr className="tr-text-left tr-text-tertiary">
					<th className="tr-py-1 tr-pr-3 tr-font-medium">
						{__('Field', 'thinkrank')}
					</th>
					<th className="tr-py-1 tr-pr-3 tr-font-medium">
						{beforeLabel || __('Before', 'thinkrank')}
					</th>
					<th className="tr-py-1 tr-font-medium">
						{afterLabel || __('After', 'thinkrank')}
					</th>
				</tr>
			</thead>
			<tbody>
				{keys.map((key) => (
					<tr
						key={key}
						className="tr-align-top tr-border-t tr-border-secondary"
					>
						<td className="tr-py-1.5 tr-pr-3 tr-font-mono tr-text-secondary">
							{key}
						</td>
						<td className="tr-py-1.5 tr-pr-3 tr-text-secondary tr-break-words">
							{show(before?.[key])}
						</td>
						<td className="tr-py-1.5 tr-text-primary tr-break-words">
							{show(after?.[key])}
						</td>
					</tr>
				))}
			</tbody>
		</table>
	);
};

const ActivityRow = ({ row, onRevert, stale = false }) => {
	const [open, setOpen] = useState(false);

	if (row.kind === 'read') {
		return (
			<li className="tr-flex tr-items-center tr-gap-3 tr-py-3 tr-border-b tr-border-secondary last:tr-border-b-0 tr-text-xs tr-text-secondary">
				<span className="tr-w-40 tr-shrink-0">
					{formatTime(row.ts)}
				</span>
				<span className="tr-min-w-0 tr-flex-1">
					{sprintf(
						/* translators: 1: client name, 2: number of read calls. */
						_n(
							'%1$s read %2$d time that day.',
							'%1$s read %2$d times that day.',
							row.calls,
							'thinkrank'
						),
						row.client.name,
						row.calls
					)}
				</span>
			</li>
		);
	}

	const badge = resultBadge(row);
	const objectLabel = row.object?.label || row.object?.id || '';

	return (
		<li className="tr-py-3 tr-border-b tr-border-secondary last:tr-border-b-0">
			<div className="tr-flex tr-items-start tr-gap-3 tr-flex-wrap sm:tr-flex-nowrap">
				<span className="tr-w-40 tr-shrink-0 tr-text-xs tr-text-secondary">
					{formatTime(row.ts)}
				</span>
				<div className="tr-min-w-0 tr-flex-1">
					<div className="tr-flex tr-items-center tr-gap-2 tr-flex-wrap">
						<code className="tr-text-xs tr-font-semibold tr-text-primary">
							{row.tool}
						</code>
						<Badge variant={badge.variant}>{badge.label}</Badge>
					</div>
					<span className="tr-block tr-mt-0.5 tr-text-xs tr-text-secondary">
						{row.client.name}
						{objectLabel ? ` · ${objectLabel}` : ''}
						{row.message ? ` · ${row.message}` : ''}
					</span>
				</div>
				<div className="tr-flex tr-items-center tr-gap-2 tr-shrink-0">
					{row.revertable && (
						<Button
							variant="secondary"
							size="sm"
							onClick={() => onRevert(row)}
							disabled={stale}
						>
							{__('Revert', 'thinkrank')}
						</Button>
					)}
					<button
						type="button"
						onClick={() => setOpen((v) => !v)}
						aria-expanded={open}
						className="tr-p-0 tr-border-0 tr-bg-transparent tr-text-xs tr-text-brand tr-cursor-pointer hover:tr-underline"
					>
						{open
							? __('Hide details', 'thinkrank')
							: __('Details', 'thinkrank')}
					</button>
				</div>
			</div>
			{open && (
				<div className="tr-mt-3 tr-flex tr-flex-col tr-gap-3 tr-rounded-lg tr-border tr-border-secondary tr-p-3">
					{(row.before || row.after) && (
						<DiffTable before={row.before} after={row.after} />
					)}
					{!row.before &&
						!row.after &&
						row.kind === 'write' &&
						row.result === 'ok' && (
							<p className="tr-m-0 tr-text-xs tr-text-tertiary">
								{__(
									'No previous value was recorded for this call, so it cannot be reverted here.',
									'thinkrank'
								)}
							</p>
						)}
					<div>
						<span className="tr-block tr-text-xs tr-text-tertiary">
							{__('Sent by the assistant', 'thinkrank')}
						</span>
						<pre className="tr-mt-1 tr-mb-0 tr-p-2 tr-rounded tr-border tr-border-secondary tr-text-xs tr-whitespace-pre-wrap tr-break-all">
							{typeof row.input === 'string'
								? row.input
								: JSON.stringify(row.input, null, 2)}
						</pre>
					</div>
					<span className="tr-text-xs tr-text-tertiary">
						{[
							row.user?.name &&
								sprintf(
									/* translators: %s: user display name. */
									__('Ran as %s', 'thinkrank'),
									row.user.name
								),
							row.ip,
							sprintf(
								/* translators: %d: duration in milliseconds. */
								__('%d ms', 'thinkrank'),
								row.duration_ms
							),
							row.parent_id
								? sprintf(
										/* translators: %d: activity entry id. */
										__('Undoes entry #%d', 'thinkrank'),
										row.parent_id
									)
								: '',
							row.reverted_by
								? sprintf(
										/* translators: %d: activity entry id. */
										__('Undone by entry #%d', 'thinkrank'),
										row.reverted_by
									)
								: '',
							`#${row.id}`,
						]
							.filter(Boolean)
							.join(' · ')}
					</span>
				</div>
			)}
		</li>
	);
};

const MCPActivity = ({
	client = '',
	clientName = '',
	onClientChange,
	onReverted,
	refreshKey = 0,
}) => {
	const [data, setData] = useState(null);
	const [loading, setLoading] = useState(true);
	const [loadError, setLoadError] = useState('');
	const [page, setPage] = useState(1);
	const [tool, setTool] = useState('');
	const [result, setResult] = useState('');
	const [query, setQuery] = useState('');
	const [search, setSearch] = useState('');
	const [changesOnly, setChangesOnly] = useState(false);
	const [retention, setRetention] = useState('90');
	const [savingRetention, setSavingRetention] = useState(false);
	// The selector stays disabled until a load has reported the stored value.
	const [retentionLoaded, setRetentionLoaded] = useState(false);
	// Revert flow: { row, stale?: {current, after} }.
	const [reverting, setReverting] = useState(null);
	const [busy, setBusy] = useState(false);

	// A new filter starts from the first page. Reset while rendering, not in
	// an effect, so no load ever goes out with the new filter and the old
	// page.
	const filterKey = JSON.stringify([
		client,
		tool,
		result,
		search,
		changesOnly,
	]);
	const [pagedFor, setPagedFor] = useState(filterKey);
	if (pagedFor !== filterKey) {
		setPagedFor(filterKey);
		setPage(1);
	}

	// Only the newest load may paint; an older one answering late is dropped.
	const latestLoad = useRef(0);
	// Bumped when a retention save starts and again when it settles. A load
	// that began before or during the save may carry the old value, so only
	// one that began after it may set retention.
	const retentionEdits = useRef(0);

	const filters = {
		client,
		tool,
		result,
		q: search,
		changes_only: changesOnly ? 1 : 0,
	};

	// `keepRetention`: the reload after a retention save already knows the
	// stored value, so the answer only refreshes the rows.
	const load = useCallback(
		async ({ keepRetention = false } = {}) => {
			const request = ++latestLoad.current;
			const edits = keepRetention ? -1 : retentionEdits.current;
			setLoading(true);
			setLoadError('');
			try {
				const response = await apiFetch({
					path: addQueryArgs('/thinkrank/v1/mcp/activity', {
						client,
						tool,
						result,
						q: search,
						changes_only: changesOnly ? 1 : 0,
						page,
						per_page: PER_PAGE,
					}),
				});
				if (request !== latestLoad.current) {
					return;
				}
				setData(response);
				if (
					response?.retention_days &&
					edits === retentionEdits.current
				) {
					setRetention(String(response.retention_days));
					setRetentionLoaded(true);
				}
			} catch (error) {
				if (request !== latestLoad.current) {
					return;
				}
				logger.error('ThinkRank: Failed to load MCP activity', error);
				// The controls already show the new filter, so the rows of the
				// last one must not stay up as if they matched it. The facets
				// stay, so the filter can still be changed back.
				setData((current) =>
					current
						? { ...current, rows: [], total: 0, pages: 0 }
						: current
				);
				setLoadError(
					__(
						'Couldn’t load the activity log. Reload and try again.',
						'thinkrank'
					)
				);
			} finally {
				if (request === latestLoad.current) {
					setLoading(false);
				}
			}
		},
		[client, tool, result, search, changesOnly, page]
	);

	// refreshKey: a revoke or an access change in Connected AI apps changes
	// what this log shows, so it reloads too.
	useEffect(() => {
		load();
	}, [load, refreshKey]);

	// Search after typing stops, not on every key.
	useEffect(() => {
		const timer = setTimeout(() => setSearch(query.trim()), 400);
		return () => clearTimeout(timer);
	}, [query]);

	// One save at a time: retention decides what gets deleted, so two
	// overlapping saves must never leave the server on the older choice
	// while the screen shows the newer one.
	const saveRetention = async (value) => {
		if (savingRetention) {
			return;
		}
		const previous = retention;
		let saved = false;
		retentionEdits.current += 1;
		setRetention(value);
		setSavingRetention(true);
		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/mcp/governance',
				method: 'POST',
				data: { retention_days: Number(value) },
			});
			const stored = String(response?.retention_days ?? value);
			setRetention(stored);
			saved = true;
			notify.success(
				sprintf(
					/* translators: %s: number of days. */
					__('Activity is now kept for %s days.', 'thinkrank'),
					stored
				)
			);
		} catch (error) {
			logger.error('ThinkRank: Saving retention failed', error);
			setRetention(previous);
			notify.error(__('Couldn’t save the retention.', 'thinkrank'));
		} finally {
			retentionEdits.current += 1;
			setSavingRetention(false);
		}
		// A shorter retention prunes rows; the ones on screen may be gone,
		// and a Revert offered on one of them would act on a deleted entry.
		if (saved) {
			load({ keepRetention: true });
		}
	};

	// A native download: the browser fetches the streamed CSV itself and
	// writes it to disk as it arrives. Reading it through fetch into a Blob
	// held the whole file in memory, which a full export with large
	// snapshots can push past 160 MB. The REST nonce in the URL is what
	// authenticates a request the browser makes on its own.
	const exportCsv = () => {
		const link = document.createElement('a');
		link.href = restUrl('mcp/activity/export', {
			...filters,
			_wpnonce: window.thinkrankAdmin?.restNonce,
		});
		// Keeps the page in place even if the server answers with an error
		// instead of the file; the server's own filename still wins.
		link.download = 'thinkrank-mcp-activity.csv';
		document.body.appendChild(link);
		link.click();
		link.remove();
	};

	const doRevert = async (force = false) => {
		if (!reverting) {
			return;
		}
		setBusy(true);
		try {
			const response = await apiFetch({
				path: `/thinkrank/v1/mcp/activity/${reverting.row.id}/revert`,
				method: 'POST',
				data: { force },
			});
			setReverting(null);
			notify.success(
				response?.message
					? sprintf(
							/* translators: %s: note about entries left untouched. */
							__(
								'Reverted. The undo is logged as its own entry. %s',
								'thinkrank'
							),
							response.message
						)
					: __(
							'Reverted. The undo is logged as its own entry.',
							'thinkrank'
						)
			);
			load();
			if (onReverted) {
				onReverted();
			}
		} catch (error) {
			if (error?.code === 'thinkrank_mcp_revert_stale') {
				setReverting((current) => ({
					...current,
					stale: error.data || {},
				}));
			} else {
				logger.error('ThinkRank: Revert failed', error);
				notify.error(
					error?.message ||
						__('Couldn’t revert that change.', 'thinkrank')
				);
				setReverting(null);
			}
		} finally {
			setBusy(false);
		}
	};

	const rows = Array.isArray(data?.rows) ? data.rows : [];
	const clients = Array.isArray(data?.clients) ? data.clients : [];
	const tools = Array.isArray(data?.tools) ? data.tools : [];
	const pages = data?.pages || 0;

	// Retention or the prune can shrink the log under the page being viewed.
	// Step back to the last page while rendering, as the filter reset does,
	// rather than show "Page 4 of 2" over an empty list. Only on an answer
	// for this page: an older one may describe a different filter.
	if (!loading && data && data.page === page && page > Math.max(1, pages)) {
		setPage(Math.max(1, pages));
	}

	const filtered = !!(client || tool || result || search || changesOnly);
	const clearFilters = () => {
		setTool('');
		setResult('');
		setQuery('');
		setSearch('');
		setChangesOnly(false);
		if (onClientChange) {
			onClientChange('');
		}
	};

	// A connection opened from its "N changes" link may have no rows yet, so
	// the facets do not list it; without an option of its own the select
	// would read "All connections" over a list filtered to that one.
	const clientOptions = clients.map((c) => ({ value: c.key, label: c.name }));
	if (client && !clientOptions.some((option) => option.value === client)) {
		clientOptions.push({ value: client, label: clientName || client });
	}

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			<div className="tr-flex tr-items-start tr-justify-between tr-gap-4 tr-flex-wrap">
				<div className="tr-min-w-0">
					<h3 className="tr-m-0 tr-flex tr-items-center tr-gap-2 tr-text-sm tr-font-semibold tr-text-primary">
						<span className="tr-text-brand">
							<IconActivity />
						</span>
						{__('Activity', 'thinkrank')}
					</h3>
					<p className="tr-mt-1 tr-mb-0 tr-text-xs tr-text-secondary tr-max-w-xl">
						{__(
							'Every change an AI assistant made through MCP, with what it replaced, plus refused calls and a daily count of reads. Revert puts the previous value back.',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-flex tr-items-end tr-gap-2 tr-shrink-0">
					<Select
						id="tr-mcp-activity-retention"
						label={__('Retention', 'thinkrank')}
						hideLabelFromVision
						className="tr-w-[150px]"
						options={RETENTION_OPTIONS}
						value={retention}
						disabled={savingRetention || !retentionLoaded}
						onChange={(event) => saveRetention(event.target.value)}
					/>
					<Button
						variant="secondary"
						size="sm"
						icon={IconDownload}
						onClick={exportCsv}
						disabled={!rows.length}
					>
						{__('Export CSV', 'thinkrank')}
					</Button>
				</div>
			</div>

			<div className="tr-grid tr-grid-cols-1 sm:tr-grid-cols-2 lg:tr-grid-cols-4 tr-gap-3 tr-items-end">
				<Select
					id="tr-mcp-activity-client"
					label={__('Connection', 'thinkrank')}
					options={[
						{
							value: '',
							label: __('All connections', 'thinkrank'),
						},
						...clientOptions,
					]}
					value={client}
					onChange={(event) =>
						onClientChange && onClientChange(event.target.value)
					}
				/>
				<Select
					id="tr-mcp-activity-tool"
					label={__('Tool', 'thinkrank')}
					options={[
						{ value: '', label: __('All tools', 'thinkrank') },
						...tools.map((t) => ({ value: t, label: t })),
					]}
					value={tool}
					onChange={(event) => setTool(event.target.value)}
				/>
				<Select
					id="tr-mcp-activity-result"
					label={__('Result', 'thinkrank')}
					options={RESULT_OPTIONS}
					value={result}
					onChange={(event) => setResult(event.target.value)}
				/>
				<Input
					id="tr-mcp-activity-search"
					label={__('Search', 'thinkrank')}
					value={query}
					placeholder={__('Post, value, client…', 'thinkrank')}
					onChange={(event) => setQuery(event.target.value)}
				/>
			</div>
			<Toggle
				id="tr-mcp-activity-changes-only"
				checked={changesOnly}
				onChange={setChangesOnly}
				label={__('Changes only', 'thinkrank')}
			/>

			{loadError && <Alert variant="error">{loadError}</Alert>}

			{loading && !data && (
				<div
					role="status"
					aria-label={__('Loading activity…', 'thinkrank')}
				>
					<SkeletonText lines={4} />
				</div>
			)}

			{data && !data.available && (
				<Alert variant="info">
					{__(
						'The activity log is being set up. It starts recording after the next admin page load.',
						'thinkrank'
					)}
				</Alert>
			)}

			{data &&
				data.available &&
				!rows.length &&
				!loadError &&
				!loading &&
				(filtered ? (
					<EmptyState
						title={__(
							'No activity matches these filters',
							'thinkrank'
						)}
						description={__(
							'Change or clear the filters to see the rest of the log.',
							'thinkrank'
						)}
						action={
							<Button
								variant="secondary"
								size="sm"
								onClick={clearFilters}
							>
								{__('Clear filters', 'thinkrank')}
							</Button>
						}
					/>
				) : (
					<EmptyState
						title={__('No activity yet', 'thinkrank')}
						description={__(
							'When an AI assistant reads or changes something through MCP, it shows up here.',
							'thinkrank'
						)}
					/>
				))}

			{rows.length > 0 && (
				<ul
					className={`tr-m-0 tr-p-0 tr-list-none ${
						loading ? 'tr-opacity-60' : ''
					}`}
				>
					{rows.map((row) => (
						<ActivityRow
							key={row.id}
							row={row}
							// While the next filter or page loads these rows
							// are the last result, not the one the controls
							// describe, so they cannot be reverted.
							stale={loading}
							onRevert={(target) => setReverting({ row: target })}
						/>
					))}
				</ul>
			)}

			{pages > 1 && (
				<div className="tr-flex tr-items-center tr-justify-between tr-gap-3">
					<span className="tr-text-xs tr-text-secondary">
						{sprintf(
							/* translators: 1: current page, 2: number of pages, 3: number of entries. */
							__('Page %1$d of %2$d · %3$d entries', 'thinkrank'),
							// The page these rows are, not the one requested.
							data.page || page,
							pages,
							data.total
						)}
					</span>
					<div className="tr-flex tr-gap-2">
						<Button
							variant="secondary"
							size="sm"
							disabled={page <= 1 || loading}
							onClick={() => setPage((p) => Math.max(1, p - 1))}
						>
							{__('Newer', 'thinkrank')}
						</Button>
						<Button
							variant="secondary"
							size="sm"
							disabled={page >= pages || loading}
							onClick={() => setPage((p) => p + 1)}
						>
							{__('Older', 'thinkrank')}
						</Button>
					</div>
				</div>
			)}

			<Modal
				isOpen={!!reverting}
				onClose={() => (busy ? null : setReverting(null))}
				title={
					reverting?.stale
						? __('This value changed since', 'thinkrank')
						: __('Revert this change?', 'thinkrank')
				}
				size="md"
				footer={
					<div className="tr-flex tr-justify-end tr-gap-2">
						<Button
							variant="secondary"
							onClick={() => setReverting(null)}
							disabled={busy}
						>
							{__('Cancel', 'thinkrank')}
						</Button>
						<Button
							variant={reverting?.stale ? 'danger' : 'primary'}
							onClick={() => doRevert(!!reverting?.stale)}
							loading={busy}
							// A load in flight is replacing the row this
							// dialog was opened from.
							disabled={loading}
						>
							{reverting?.stale
								? __('Apply anyway', 'thinkrank')
								: __('Revert', 'thinkrank')}
						</Button>
					</div>
				}
			>
				{reverting && !reverting.stale && (
					<div className="tr-flex tr-flex-col tr-gap-3">
						<p className="tr-m-0 tr-text-sm tr-text-secondary">
							{sprintf(
								/* translators: %s: tool name. */
								__(
									'This puts back what was there before %s ran, through the same tool the assistant used.',
									'thinkrank'
								),
								reverting.row.tool
							)}
						</p>
						<DiffTable
							before={reverting.row.after}
							after={reverting.row.before}
							beforeLabel={__('Now', 'thinkrank')}
							afterLabel={__('After revert', 'thinkrank')}
						/>
					</div>
				)}
				{reverting?.stale && (
					<div className="tr-flex tr-flex-col tr-gap-3">
						<p className="tr-m-0 tr-text-sm tr-text-secondary">
							{reverting.stale.skipped
								? __(
										'Every entry in this change was edited after the assistant wrote it. Applying anyway overwrites those later edits too.',
										'thinkrank'
									)
								: __(
										'Someone edited this after the assistant wrote it. Applying anyway overwrites that later edit with the value from before the assistant’s change.',
										'thinkrank'
									)}
						</p>
						{reverting.stale.current && (
							<DiffTable
								before={reverting.stale.current}
								after={reverting.row.before}
								beforeLabel={__('Now', 'thinkrank')}
								afterLabel={__('After revert', 'thinkrank')}
							/>
						)}
					</div>
				)}
			</Modal>
		</div>
	);
};

export default MCPActivity;
