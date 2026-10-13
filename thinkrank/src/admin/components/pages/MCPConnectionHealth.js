/**
 * MCP Connection Health
 *
 * The troubleshooting surface for the MCP page (see #189). Reads the read-only
 * `connection-status` report (the #188 ability's REST twin) to show, at a
 * glance, whether an external MCP/Abilities client would be able to reach and
 * use this site — HTTPS/scheme agreement, how many tools are registered, the
 * signed-in user's permissions, and database currency — and offers a live
 * "Test Connection" button that exercises the real round trip and names the
 * step that failed.
 *
 * The last verdict loads with the screen (GET /mcp/doctor, #717): the server
 * keeps it for 12 hours and re-probes with a short timeout when it is older,
 * so a host that blocks AI clients shows up without anyone clicking a button.
 *
 * @package
 * @since 1.23.0
 */

import { __, sprintf, _n } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Alert, Badge, SkeletonText } from '../ui';
import logger from '../../../shared/utils/logger';
import notify from '../../utils/notify';

const IconRefresh = () => (
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
		<polyline points="23 4 23 10 17 10" />
		<polyline points="1 20 1 14 7 14" />
		<path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" />
	</svg>
);

const IconPlay = () => (
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
		<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
		<polyline points="22 4 12 14.01 9 11.01" />
	</svg>
);

// One health row: label, a status badge, and an explanatory line.
const HealthRow = ({ label, variant, badge, detail }) => (
	<div className="tr-flex tr-items-start tr-justify-between tr-gap-4 tr-py-3 tr-border-t tr-border-secondary first:tr-border-t-0">
		<div className="tr-min-w-0">
			<span className="tr-block tr-text-sm tr-font-medium tr-text-primary">
				{label}
			</span>
			{detail && (
				<span className="tr-block tr-mt-0.5 tr-text-xs tr-text-secondary">
					{detail}
				</span>
			)}
		</div>
		<Badge variant={variant}>{badge}</Badge>
	</div>
);

// Self-test stages that are cautionary rather than hard failures — they map
// the result alert to a warning. `rewrite`, `discovery` and `challenge` land
// here because a token-pasting client still works when they fail; only clients
// that connect by URL alone (ChatGPT, claude.ai) are affected. `no_scope` is
// the admin's own choice of No access, not a fault, and `incomplete` is an
// automatic check that ran out of time, not one that found a fault.
const WARN_STAGES = [
	'tls',
	'redirect',
	'no_tools',
	'rewrite',
	'discovery',
	'challenge',
	'ua_filter',
	'no_scope',
	'incomplete',
];

// Alert tone for a self-test result.
const alertVariantFor = (result) => {
	if (!result) {
		return 'info';
	}
	if (result.ok) {
		return 'success';
	}
	return WARN_STAGES.includes(result.stage) ? 'warning' : 'error';
};

const MCPConnectionHealth = () => {
	const [report, setReport] = useState(null);
	const [loading, setLoading] = useState(true);
	const [loadError, setLoadError] = useState('');
	const [testing, setTesting] = useState(false);
	const [testResult, setTestResult] = useState(null);
	// Set once the admin runs a test; the saved verdict loading on mount
	// must not then appear in its place while the test is running.
	const manualRan = useRef(false);

	// `announce` fires a toast on the outcome — passed only for the user's
	// Re-check click, never for the silent load on mount.
	const loadStatus = useCallback(async (announce = false) => {
		setLoading(true);
		setLoadError('');
		try {
			const data = await apiFetch({
				path: '/thinkrank/v1/connection-status',
			});
			setReport(data);
			if (announce) {
				notify.success(__('Connection health refreshed.', 'thinkrank'));
			}
		} catch (error) {
			logger.error('ThinkRank: Failed to load connection status', error);
			setLoadError(
				__(
					'Couldn’t load connection health. Reload the page and try again.',
					'thinkrank'
				)
			);
			if (announce) {
				notify.error(
					__('Couldn’t refresh connection health.', 'thinkrank')
				);
			}
		} finally {
			setLoading(false);
		}
	}, []);

	// The cached (or freshly auto-probed) verdict, shown on load. A failure
	// here is not worth an error banner: the button still works.
	useEffect(() => {
		let cancelled = false;
		apiFetch({ path: '/thinkrank/v1/mcp/doctor' })
			.then((data) => {
				if (!cancelled && !manualRan.current) {
					setTestResult((current) => current || data);
				}
			})
			.catch((error) => {
				logger.error('ThinkRank: MCP doctor failed', error);
			});
		return () => {
			cancelled = true;
		};
	}, []);

	useEffect(() => {
		loadStatus();
	}, [loadStatus]);

	const copyHostRequest = useCallback(async (text) => {
		try {
			await window.navigator.clipboard.writeText(text);
			notify.success(__('Message copied.', 'thinkrank'));
		} catch (error) {
			logger.error('ThinkRank: copy failed', error);
			notify.error(
				__(
					'Couldn’t copy. Select the text and copy it manually.',
					'thinkrank'
				)
			);
		}
	}, []);

	const runTest = useCallback(async () => {
		manualRan.current = true;
		setTesting(true);
		setTestResult(null);
		try {
			const data = await apiFetch({
				path: '/thinkrank/v1/mcp/self-test',
				method: 'POST',
			});
			setTestResult(data);
		} catch (error) {
			logger.error('ThinkRank: MCP self-test failed', error);
			setTestResult({
				ok: false,
				stage: 'unreachable',
				message: __(
					'The test request itself failed. Reload the page and try again.',
					'thinkrank'
				),
			});
		} finally {
			setTesting(false);
		}
	}, []);

	if (loading) {
		return (
			<div
				role="status"
				aria-label={__('Checking connection health…', 'thinkrank')}
			>
				<SkeletonText lines={3} />
			</div>
		);
	}

	if (loadError) {
		return <Alert variant="error">{loadError}</Alert>;
	}

	if (!report) {
		return null;
	}

	const { abilities, urls, user, database, plugin } = report;

	// Secure connection: HTTPS + home/siteurl/REST schemes all agree.
	const secureOk =
		urls.is_https && urls.home_siteurl_match && urls.rest_matches_home;
	const secureDetail = (() => {
		if (!urls.is_https) {
			return __(
				'Site is served over HTTP. MCP clients expect HTTPS; some refuse to connect otherwise.',
				'thinkrank'
			);
		}
		if (!urls.home_siteurl_match) {
			return __(
				'Site Address and WordPress Address use different schemes, so requests can bounce between HTTP and HTTPS.',
				'thinkrank'
			);
		}
		if (!urls.rest_matches_home) {
			return __(
				'The REST API URL scheme differs from the site address, which can redirect MCP requests.',
				'thinkrank'
			);
		}
		return __('HTTPS is on and all WordPress URLs agree.', 'thinkrank');
	})();

	const abilitiesDetail = abilities.registered
		? sprintf(
				/* translators: %d: number of registered ThinkRank tools. */
				_n(
					'%d ThinkRank tool is registered and discoverable.',
					'%d ThinkRank tools are registered and discoverable.',
					abilities.thinkrank_count,
					'thinkrank'
				),
				abilities.thinkrank_count
			)
		: __(
				'No ThinkRank tools are registered. MCP may be off or the runtime failed to load.',
				'thinkrank'
			);

	const permsOk = !!user.is_admin;
	const capCount = Array.isArray(user.thinkrank_capabilities)
		? user.thinkrank_capabilities.length
		: 0;

	const dbOk = !!database.up_to_date;

	const testAlertVariant = alertVariantFor(testResult);

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			<div className="tr-flex tr-items-start tr-justify-between tr-gap-4 tr-flex-wrap">
				<div className="tr-min-w-0">
					<h3 className="tr-m-0 tr-text-sm tr-font-semibold tr-text-primary">
						{__('Connection health', 'thinkrank')}
					</h3>
					<p className="tr-mt-1 tr-mb-0 tr-text-xs tr-text-secondary tr-max-w-xl">
						{__(
							'What an AI client sees when it tries to reach this site. Use Test connection to exercise the real round trip.',
							'thinkrank'
						)}
					</p>
				</div>
				<div className="tr-flex tr-items-center tr-gap-2 tr-shrink-0">
					<Button
						variant="secondary"
						size="sm"
						icon={IconRefresh}
						onClick={() => loadStatus(true)}
						disabled={testing}
					>
						{__('Re-check', 'thinkrank')}
					</Button>
					<Button
						variant="primary"
						size="sm"
						icon={IconPlay}
						loading={testing}
						onClick={runTest}
					>
						{__('Test connection', 'thinkrank')}
					</Button>
				</div>
			</div>

			{testResult && (
				<Alert variant={testAlertVariant}>
					<span className="tr-block">{testResult.message}</span>
					{testResult.ran_at && (
						<span className="tr-block tr-mt-1 tr-text-xs tr-opacity-80">
							{testResult.auto
								? sprintf(
										/* translators: %s: date and time. */
										__(
											'Checked automatically %s.',
											'thinkrank'
										),
										new Date(
											testResult.ran_at * 1000
										).toLocaleString()
									)
								: sprintf(
										/* translators: %s: date and time. */
										__('Checked %s.', 'thinkrank'),
										new Date(
											testResult.ran_at * 1000
										).toLocaleString()
									)}
						</span>
					)}
				</Alert>
			)}

			{testResult?.host_request && (
				<div className="tr-rounded-lg tr-border tr-border-secondary tr-p-4">
					<div className="tr-flex tr-items-start tr-justify-between tr-gap-3 tr-flex-wrap">
						<div className="tr-min-w-0">
							<span className="tr-block tr-text-sm tr-font-medium tr-text-primary">
								{__(
									'Your host blocks the user agent AI clients use',
									'thinkrank'
								)}
							</span>
							<span className="tr-block tr-mt-0.5 tr-text-xs tr-text-secondary">
								{__(
									'Nothing in WordPress can lift this block. Send this message to your host’s support team.',
									'thinkrank'
								)}
							</span>
						</div>
						<Button
							variant="secondary"
							size="sm"
							onClick={() =>
								copyHostRequest(testResult.host_request)
							}
						>
							{__('Copy message', 'thinkrank')}
						</Button>
					</div>
					<pre className="tr-mt-3 tr-mb-0 tr-p-3 tr-rounded tr-border tr-border-secondary tr-text-xs tr-whitespace-pre-wrap tr-break-words">
						{testResult.host_request}
					</pre>
				</div>
			)}

			{Array.isArray(testResult?.checks) &&
				testResult.checks.length > 0 && (
					<ul className="tr-m-0 tr-p-0 tr-list-none tr-flex tr-flex-col tr-gap-2">
						{testResult.checks.map((check) => (
							<li
								key={check.id}
								className="tr-flex tr-items-start tr-gap-2"
							>
								<span
									aria-hidden="true"
									className={
										check.ok
											? 'thinkrank-text-green'
											: 'thinkrank-text-orange'
									}
								>
									{check.ok ? '✓' : '!'}
								</span>
								<span className="tr-min-w-0 tr-text-xs">
									<span className="tr-font-medium tr-text-primary">
										{check.label}
									</span>
									<span className="tr-text-secondary">
										{': '}
										{check.detail}
									</span>
									{check.doc_url && (
										<a
											href={check.doc_url}
											target="_blank"
											rel="noopener noreferrer"
											className="tr-ml-1 tr-text-brand-600"
										>
											{__('How to fix this', 'thinkrank')}
										</a>
									)}
								</span>
							</li>
						))}
					</ul>
				)}

			{testResult?.discovery_documents &&
				Object.keys(testResult.discovery_documents).length > 0 && (
					<details className="tr-text-xs">
						<summary className="tr-cursor-pointer tr-text-secondary">
							{__(
								'OAuth discovery documents this site serves',
								'thinkrank'
							)}
						</summary>
						{Object.entries(testResult.discovery_documents).map(
							([url, doc]) => (
								<div key={url} className="tr-mt-2">
									<code className="tr-block tr-text-secondary tr-break-all">
										{url}
									</code>
									<pre className="tr-mt-1 tr-p-2 tr-rounded tr-border tr-border-secondary tr-overflow-x-auto">
										{typeof doc === 'string'
											? doc
											: JSON.stringify(doc, null, 2)}
									</pre>
								</div>
							)
						)}
					</details>
				)}

			<div className="tr-rounded-lg tr-border tr-border-secondary tr-px-4">
				<HealthRow
					label={__('Secure connection', 'thinkrank')}
					variant={secureOk ? 'good' : 'warning'}
					badge={
						secureOk
							? __('OK', 'thinkrank')
							: __('Check', 'thinkrank')
					}
					detail={secureDetail}
				/>
				<HealthRow
					label={__('Registered tools', 'thinkrank')}
					variant={abilities.registered ? 'good' : 'warning'}
					badge={String(abilities.thinkrank_count)}
					detail={abilitiesDetail}
				/>
				<HealthRow
					label={__('Your permissions', 'thinkrank')}
					variant={permsOk ? 'good' : 'poor'}
					badge={
						permsOk
							? __('Administrator', 'thinkrank')
							: __('Limited', 'thinkrank')
					}
					detail={
						permsOk
							? sprintf(
									/* translators: %d: number of ThinkRank capabilities held. */
									_n(
										'You hold %d ThinkRank capability.',
										'You hold %d ThinkRank capabilities.',
										capCount,
										'thinkrank'
									),
									capCount
								)
							: __(
									'Your account lacks administrator access, so some tools will be refused.',
									'thinkrank'
								)
					}
				/>
				<HealthRow
					label={__('Database', 'thinkrank')}
					variant={dbOk ? 'good' : 'warning'}
					badge={
						dbOk
							? __('Up to date', 'thinkrank')
							: __('Update due', 'thinkrank')
					}
					detail={
						dbOk
							? sprintf(
									/* translators: %s: database schema version. */
									__('Schema v%s installed.', 'thinkrank'),
									String(database.installed_version)
								)
							: __(
									'A database update is pending. Visit the dashboard to run it.',
									'thinkrank'
								)
					}
				/>
				<HealthRow
					label={__('Version', 'thinkrank')}
					variant="neutral"
					badge={String(plugin.thinkrank_version)}
					detail={
						plugin.pro_active
							? __('ThinkRank Pro is active.', 'thinkrank')
							: __('ThinkRank (free) is active.', 'thinkrank')
					}
				/>
			</div>
		</div>
	);
};

export default MCPConnectionHealth;
