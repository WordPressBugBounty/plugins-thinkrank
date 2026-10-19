/**
 * Email Reporting Panel
 *
 * Per-site Email Reporting under Essential SEO → Analytics → Email Reporting.
 * Built on the ThinkRank design system (src/admin/components/ui): one card,
 * one header — icon, title, status pill, description and the autosaving
 * switch — then what gets sent and the actions, all in the same card.
 *
 * The free report is fixed: every 30 days, to the site admin email, with every
 * section. Schedule, recipients and branding settings belong to ThinkRank
 * Pro, which renders them into the `thinkrank.emailReport.proSettings` slot
 * (#673).
 *
 * The report is built from Google Search Console. While that is not
 * connected, scheduled sends are paused server-side and the panel says so —
 * a paused report must never look like a healthy one (#742).
 *
 * @package
 * @since 1.9.0
 */

import { useEffect, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Card, Toggle, Button, Alert, Badge, SkeletonCard } from '../../ui';
import ProGatedTab from '../../pro/ProGatedTab';
import ProTeaser from '../../pro/ProTeaser';
import { TrafficOverviewIllustration } from '../../pro/pro-teaser-illustrations';
import notify from '../../../utils/notify';

const STORE = 'thinkrank/email-report';

const EnvelopeIcon = () => (
	<svg
		width="22"
		height="22"
		viewBox="0 0 24 24"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
	>
		<rect
			x="3"
			y="5"
			width="18"
			height="14"
			rx="2"
			stroke="currentColor"
			strokeWidth="1.6"
		/>
		<path
			d="M3 7l9 6 9-6"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinejoin="round"
		/>
	</svg>
);

/**
 * Format an ISO datetime as a localized human-readable string.
 *
 * The server sends a real ISO 8601 string with the site's UTC offset, so
 * `new Date()` resolves it to one unambiguous instant regardless of the
 * viewer's own timezone.
 * @param {string|null} iso ISO 8601 datetime.
 * @return {string|null} Localized date and time.
 */
const formatDateTime = (iso) => {
	if (!iso) {
		return null;
	}
	try {
		const d = new Date(iso);
		if (Number.isNaN(d.getTime())) {
			return iso;
		}
		return new Intl.DateTimeFormat(undefined, {
			dateStyle: 'medium',
			timeStyle: 'short',
		}).format(d);
	} catch (e) {
		return iso;
	}
};

/**
 * Format a MySQL-style site-local datetime ("2026-09-20 14:00:00").
 * @param {string|null} value Site-local datetime.
 * @return {string|null} Localized date and time.
 */
const formatSiteTime = (value) => {
	if (!value) {
		return null;
	}
	return formatDateTime(String(value).replace(' ', 'T'));
};

/**
 * Human wording for a recorded skip reason.
 * @param {string} reason  Machine reason from the server.
 * @param {string} message Optional detail the server stored with it.
 * @return {string} Sentence fragment.
 */
const skipReasonText = (reason, message) => {
	switch (reason) {
		case 'search_console_not_connected':
			return __('Google Search Console was not connected.', 'thinkrank');
		case 'no_data':
			return __(
				'Search Console returned no data for the period.',
				'thinkrank'
			);
		case 'fetch_failed':
			return message
				? sprintf(
						/* translators: %s: the error Search Console returned. */
						__(
							'Search Console could not be reached or refused the request (%s).',
							'thinkrank'
						),
						message
					)
				: __(
						'Search Console could not be reached or refused the request.',
						'thinkrank'
					);
		default:
			return __('the report had nothing to send.', 'thinkrank');
	}
};

/**
 * One label/value row of the report summary.
 * @param {Object} root0
 * @param {string} root0.label Row label.
 * @param {*}      root0.value Row content.
 * @param {*}      root0.hint  Optional secondary line under the value.
 */
const SummaryRow = ({ label, value, hint }) => (
	<div className="tr-flex tr-flex-wrap tr-gap-x-6 tr-gap-y-1 tr-py-3 tr-border-b tr-border-secondary last:tr-border-b-0">
		<dt className="tr-text-sm tr-font-medium tr-text-secondary tr-m-0 tr-w-40 tr-shrink-0">
			{label}
		</dt>
		<dd className="tr-text-sm tr-text-primary tr-m-0 tr-min-w-0 tr-flex-1">
			{value}
			{hint && (
				<div className="tr-text-xs tr-text-tertiary tr-mt-1">
					{hint}
				</div>
			)}
		</dd>
	</div>
);

const EmailReportingPanel = ({ onNavigate }) => {
	const {
		config,
		sections,
		nextRun,
		readiness,
		hasLoaded,
		isLoading,
		isSendingTest,
	} = useSelect((select) => {
		const s = select(STORE);
		return {
			config: s.getConfig(),
			sections: s.getSections(),
			nextRun: s.getNextRun(),
			readiness: s.getReadiness(),
			hasLoaded: s.hasLoaded(),
			isLoading: s.isLoading(),
			isSendingTest: s.isSendingTest(),
		};
	}, []);

	const { load, save, sendTest, updateField } = useDispatch(STORE);

	const [notice, setNotice] = useState(null);
	const [isTogglingFeature, setIsTogglingFeature] = useState(false);

	useEffect(() => {
		load().catch((err) => {
			setNotice({
				type: 'error',
				message:
					err?.message ||
					__('Failed to load email report settings.', 'thinkrank'),
			});
		});
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, []);

	const cfg = config || {};
	const enabled = Boolean(cfg.enabled);
	const frequency = Number(cfg.frequency_days) || 30;
	const recipients = Array.isArray(cfg.recipients) ? cfg.recipients : [];
	const lastSkip =
		cfg.last_skip && typeof cfg.last_skip === 'object'
			? cfg.last_skip
			: null;
	const enabledKeys = Array.isArray(cfg.sections_enabled)
		? cfg.sections_enabled
		: [];
	const sectionLabels = enabledKeys
		.map((key) => sections.find((section) => section.key === key)?.label)
		.filter(Boolean);

	// Readiness is null until the config request lands; treat unknown as
	// connected so the warning never flashes on a healthy site.
	const searchConsoleConnected = readiness
		? Boolean(readiness.search_console)
		: true;
	// GA4 and its Site traffic card are ThinkRank Pro's (#1115). Without Pro
	// there is no card to miss, so the hint below never asks for GA4.
	const analyticsConnected =
		!window.thinkrankAdmin?.isPro ||
		(readiness ? Boolean(readiness.analytics) : true);
	const paused = enabled && !searchConsoleConnected;

	let status = { variant: 'neutral', label: __('Off', 'thinkrank') };
	if (paused) {
		status = { variant: 'warning', label: __('Paused', 'thinkrank') };
	} else if (enabled) {
		status = { variant: 'good', label: __('Active', 'thinkrank') };
	}

	const goToIntegrations = () => {
		if (typeof onNavigate === 'function') {
			onNavigate('integrations', 'google-services');
			return;
		}
		// Outside the Essential SEO screen there is no in-app navigator;
		// a full load of the Integrations panel is the safe fallback.
		try {
			const url = new URL(window.location.href);
			url.searchParams.set('page', 'thinkrank-essential-seo');
			url.searchParams.set('nav_section', 'integrations');
			url.searchParams.set('nav_item', 'google-services');
			window.location.assign(url.toString());
		} catch (e) {
			// Nothing sensible to do without a URL API.
		}
	};

	/**
	 * Turn email reporting on or off: optimistic, rolled back on failure.
	 * @param {boolean} value New enabled state.
	 */
	const handleEnabledToggle = async (value) => {
		setNotice(null);
		updateField('enabled', value);
		setIsTogglingFeature(true);

		try {
			await save({ enabled: value });

			notify.success(
				value
					? __('Email reporting is on.', 'thinkrank')
					: __('Email reporting is off.', 'thinkrank')
			);
		} catch (err) {
			updateField('enabled', !value);
			notify.error(
				err?.message || __('Couldn’t save that change.', 'thinkrank')
			);
		} finally {
			setIsTogglingFeature(false);
		}
	};

	const handleSendTest = async () => {
		setNotice(null);
		try {
			const response = await sendTest();
			if (response?.success) {
				setNotice({
					type: response?.not_connected ? 'warning' : 'success',
					message: response?.not_connected
						? __(
								'Test email sent. Search Console isn’t connected, so the email explains how to connect it instead of showing a report.',
								'thinkrank'
							)
						: __(
								'Test email sent. Check your inbox in a moment.',
								'thinkrank'
							),
				});
			} else {
				setNotice({
					type: 'error',
					message:
						response?.result?.error ||
						__('Test email could not be sent.', 'thinkrank'),
				});
			}
		} catch (err) {
			setNotice({
				type: 'error',
				message: err?.message || __('Test email failed.', 'thinkrank'),
			});
		}
	};

	if (isLoading && !hasLoaded) {
		return (
			<SkeletonCard
				lines={4}
				label={__('Loading email report settings…', 'thinkrank')}
			/>
		);
	}

	const lastSkipAt = lastSkip ? formatSiteTime(lastSkip.at) : null;

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			{notice && (
				<Alert variant={notice.type} onDismiss={() => setNotice(null)}>
					{notice.message}
				</Alert>
			)}

			<Card padding={false} className="tr-overflow-hidden">
				{/* Header: icon, title, status, description, switch */}
				<div className="tr-flex tr-items-start tr-gap-4 tr-p-6">
					<span className="tr-flex tr-items-center tr-justify-center tr-w-11 tr-h-11 tr-rounded-xl tr-bg-brand-bg tr-text-brand tr-shrink-0">
						<EnvelopeIcon />
					</span>
					<div className="tr-flex-1 tr-min-w-0">
						<div className="tr-flex tr-items-center tr-gap-2.5 tr-flex-wrap">
							<h2 className="tr-m-0 tr-text-base tr-font-semibold tr-text-primary">
								{__('Email Reporting', 'thinkrank')}
							</h2>
							<Badge variant={status.variant}>
								{status.label}
							</Badge>
						</div>
						<p className="tr-mt-1 tr-mb-0 tr-text-sm tr-text-secondary tr-max-w-2xl">
							{/* GA4 site traffic is a ThinkRank Pro card (#1115). */}
							{window.thinkrankAdmin?.isPro
								? __(
										'A Search Console-style summary by email: clicks, impressions, CTR and position with the change vs the previous period, the pages and queries that grew or fell, where your keywords rank, and, when connected, site traffic and visits from AI assistants.',
										'thinkrank'
									)
								: __(
										'A Search Console-style summary by email: clicks, impressions, CTR and position with the change vs the previous period, the pages and queries that grew or fell, where your keywords rank, and visits from AI assistants once AI Insights has recorded some.',
										'thinkrank'
									)}
						</p>
					</div>
					<div className="tr-flex tr-flex-col tr-items-end tr-gap-1 tr-shrink-0">
						<Toggle
							label={
								enabled
									? __('On', 'thinkrank')
									: __('Off', 'thinkrank')
							}
							checked={enabled}
							onChange={handleEnabledToggle}
							disabled={isTogglingFeature}
						/>
						{isTogglingFeature && (
							<span className="tr-text-xs tr-text-tertiary">
								{__('Saving…', 'thinkrank')}
							</span>
						)}
					</div>
				</div>

				{paused && (
					<div className="tr-px-6 tr-pb-6">
						<Alert
							variant="warning"
							title={__(
								'Reports are paused: Google Search Console isn’t connected',
								'thinkrank'
							)}
						>
							<p className="tr-m-0">
								{__(
									'The report is built from Search Console data, so nothing is sent until it is connected. The schedule stays as it is: the first report goes out on the next hourly check after you connect.',
									'thinkrank'
								)}
							</p>
							{lastSkip && lastSkipAt && (
								<p className="tr-mt-2 tr-mb-0">
									{sprintf(
										/* translators: 1: date and time, 2: reason sentence. */
										__(
											'Last scheduled run on %1$s sent nothing: %2$s',
											'thinkrank'
										),
										lastSkipAt,
										skipReasonText(
											lastSkip.reason,
											lastSkip.message
										)
									)}
								</p>
							)}
							<div className="tr-mt-3">
								<Button
									variant="primary"
									size="sm"
									onClick={goToIntegrations}
								>
									{__('Connect Search Console', 'thinkrank')}
								</Button>
							</div>
						</Alert>
					</div>
				)}

				{enabled && !paused && lastSkip && lastSkipAt && (
					<div className="tr-px-6 tr-pb-6">
						<Alert variant="info">
							{sprintf(
								/* translators: 1: date and time, 2: reason sentence. */
								__(
									'Last scheduled run on %1$s sent nothing: %2$s',
									'thinkrank'
								),
								lastSkipAt,
								skipReasonText(
									lastSkip.reason,
									lastSkip.message
								)
							)}
						</Alert>
					</div>
				)}

				{enabled && (
					<div className="tr-border-t tr-border-secondary">
						<dl className="tr-px-6 tr-py-2 tr-m-0">
							<SummaryRow
								label={__('Frequency', 'thinkrank')}
								value={sprintf(
									/* translators: %d: number of days between reports. */
									_n(
										'Every %d day',
										'Every %d days',
										frequency,
										'thinkrank'
									),
									frequency
								)}
								hint={__(
									'Each report covers the period since the last one and compares it with the period before.',
									'thinkrank'
								)}
							/>
							<SummaryRow
								label={__('Recipients', 'thinkrank')}
								value={
									recipients.length > 0
										? recipients.join(', ')
										: __('No recipients', 'thinkrank')
								}
							/>
							<SummaryRow
								label={__('Sections', 'thinkrank')}
								value={
									sectionLabels.length > 0
										? sectionLabels.join(', ')
										: __('No sections', 'thinkrank')
								}
								hint={
									!analyticsConnected &&
									searchConsoleConnected
										? __(
												'Site traffic needs Google Analytics 4. Connect it to add that card. The AI assistants card appears once AI Insights has recorded visits.',
												'thinkrank'
											)
										: __(
												'Sections with nothing to show for the period are left out rather than sent empty.',
												'thinkrank'
											)
								}
							/>
							<SummaryRow
								label={__('Next report', 'thinkrank')}
								value={
									paused
										? __(
												'Paused until Search Console is connected',
												'thinkrank'
											)
										: formatDateTime(nextRun) ||
											__('Not scheduled yet', 'thinkrank')
								}
							/>
						</dl>
					</div>
				)}

				{enabled && (
					<div className="tr-flex tr-items-center tr-gap-3 tr-flex-wrap tr-px-6 tr-py-4 tr-border-t tr-border-secondary">
						<Button
							variant="secondary"
							loading={isSendingTest}
							onClick={handleSendTest}
						>
							{__('Send Test Email', 'thinkrank')}
						</Button>
						<span className="tr-text-sm tr-text-secondary">
							{paused
								? __(
										'Sends the “connect Search Console” email your recipients would get.',
										'thinkrank'
									)
								: __(
										'Sends the report as it stands today to every recipient.',
										'thinkrank'
									)}
						</span>
					</div>
				)}
			</Card>

			{enabled && (
				<ProGatedTab
					filter="thinkrank.emailReport.proSettings"
					teaser={
						<ProTeaser
							illustration={<TrafficOverviewIllustration />}
							title={__('Branded email reports', 'thinkrank')}
							description={__(
								'Send the report on your schedule, to your whole team or your clients, looking like it came from you.',
								'thinkrank'
							)}
							features={[
								__('Every 7, 15 or 30 days', 'thinkrank'),
								__('Up to 50 recipients', 'thinkrank'),
								__(
									'Your logo, header colour, subject line, intro and footer',
									'thinkrank'
								),
								__('Pick the sections to include', 'thinkrank'),
							]}
						/>
					}
				/>
			)}
		</div>
	);
};

export default EmailReportingPanel;
