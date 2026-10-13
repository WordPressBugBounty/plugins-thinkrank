/**
 * Submit URLs Component
 *
 * Redesigned — uses plugin UI components and Tailwind classes.
 *
 * Adds live client-side URL analysis (valid / off-site / invalid / duplicate),
 * a count-aware submit action, quick clean-up tools, and a proper disabled
 * state so users understand what will actually be sent to IndexNow before they
 * submit — the backend silently drops foreign-host URLs, so we surface that here.
 *
 * @package
 * @since 1.0.0
 */

import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import classnames from 'classnames';
import {
	Card,
	Button,
	Container,
	Badge,
	Text,
	EmptyState,
	SkeletonCard,
} from '../../ui';
import SettingsCard from '../../common/SettingsCard';
import SectionHeader from '../../common/SectionHeader';
import FormField from '../../common/FormField';
import apiFetch from '@wordpress/api-fetch';
import logger from '../../../../shared/utils/logger';

const MAX_URLS = 100;

const IconSend = ({ className }) => (
	<svg
		className={className}
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<line x1="22" y1="2" x2="11" y2="13" />
		<polygon points="22 2 15 22 11 13 2 9 22 2" />
	</svg>
);

const IconBolt = ({ className }) => (
	<svg
		className={className}
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
	</svg>
);

/**
 * The current site host, used to flag URLs that don't belong to this site
 * (IndexNow only accepts same-host URLs; the backend drops the rest).
 */
const getSiteHost = () => {
	try {
		const raw = window.thinkrankAdmin?.siteUrl || window.location.origin;
		return new URL(raw).hostname.toLowerCase();
	} catch (e) {
		return '';
	}
};

/**
 * Classify a single trimmed line: 'valid' | 'offsite' | 'invalid'.
 * @param {string} value    A single trimmed line from the textarea.
 * @param {string} siteHost The current site host to compare against.
 */
const classifyUrl = (value, siteHost) => {
	let parsed;
	try {
		parsed = new URL(value);
	} catch (e) {
		return 'invalid';
	}
	if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
		return 'invalid';
	}
	if (siteHost && parsed.hostname.toLowerCase() !== siteHost) {
		return 'offsite';
	}
	return 'valid';
};

/**
 * Break the textarea contents into counts the UI can act on.
 * @param {string} text     Raw textarea value.
 * @param {string} siteHost The current site host to compare against.
 */
const analyzeUrls = (text, siteHost) => {
	const lines = text
		.split('\n')
		.map((line) => line.trim())
		.filter(Boolean);

	const seen = new Set();
	let valid = 0;
	let offsite = 0;
	let invalid = 0;
	let duplicates = 0;

	lines.forEach((line) => {
		const kind = classifyUrl(line, siteHost);
		if (kind === 'valid') {
			valid++;
		} else if (kind === 'offsite') {
			offsite++;
		} else {
			invalid++;
		}
		if (seen.has(line)) {
			duplicates++;
		} else {
			seen.add(line);
		}
	});

	return { total: lines.length, valid, offsite, invalid, duplicates };
};

// Shared line-height for the textarea and its number gutter — they MUST match
// exactly or the serials drift out of alignment as the list scrolls.
const LINE_HEIGHT = '1.625';

// Textarea with a scroll-synced serial gutter (1, 2, 3 …), one number per line.
// The numbers are decoration only — `value` stays clean, newline-separated URLs.
const NumberedTextarea = ({
	id,
	value,
	onChange,
	onKeyDown,
	rows = 10,
	placeholder,
	disabled = false,
}) => {
	const textareaRef = useRef(null);
	const gutterRef = useRef(null);
	const lineCount = Math.max(1, value.split('\n').length);

	// Keep the gutter vertically aligned with the textarea while it scrolls.
	const syncScroll = () => {
		if (gutterRef.current && textareaRef.current) {
			gutterRef.current.scrollTop = textareaRef.current.scrollTop;
		}
	};

	return (
		<div
			className={classnames(
				'tr-flex tr-w-full tr-overflow-hidden tr-rounded-md tr-bg-white',
				'tr-border-[1.5px] tr-border-primary tr-transition-colors tr-duration-150',
				'focus-within:tr-border-brand',
				disabled && 'tr-cursor-not-allowed tr-opacity-70'
			)}
		>
			<div
				ref={gutterRef}
				aria-hidden="true"
				className={classnames(
					'tr-shrink-0 tr-select-none tr-overflow-hidden',
					'tr-border-r tr-border-secondary tr-bg-[#FAFBFC]',
					'tr-px-3 tr-py-2.5 tr-text-right tr-font-mono tr-text-sm tr-text-tertiary'
				)}
				style={{ lineHeight: LINE_HEIGHT }}
			>
				{Array.from({ length: lineCount }, (_, i) => (
					<div key={i}>{i + 1}</div>
				))}
			</div>
			<textarea
				ref={textareaRef}
				id={id}
				value={value}
				onChange={onChange}
				onKeyDown={onKeyDown}
				onScroll={syncScroll}
				rows={rows}
				placeholder={placeholder}
				disabled={disabled}
				wrap="off"
				spellCheck={false}
				className={classnames(
					'tr-min-w-0 tr-flex-1 tr-resize-y tr-border-0 tr-bg-transparent',
					'tr-px-3 tr-py-2.5 tr-font-mono tr-text-sm tr-text-primary',
					'tr-whitespace-pre placeholder:tr-text-tertiary',
					'focus:tr-outline-none disabled:tr-cursor-not-allowed'
				)}
				style={{ lineHeight: LINE_HEIGHT }}
			/>
		</div>
	);
};

const SubmitURLs = ({ onNavigate }) => {
	const [urls, setUrls] = useState('');
	const [isSubmitting, setIsSubmitting] = useState(false);
	const [isEnabled, setIsEnabled] = useState(false);
	const [isLoading, setIsLoading] = useState(true);
	const [notice, setNotice] = useState(null);

	const siteHost = useMemo(getSiteHost, []);
	const stats = useMemo(() => analyzeUrls(urls, siteHost), [urls, siteHost]);
	const overLimit = stats.total > MAX_URLS;
	const hasContent = stats.total > 0;

	useEffect(() => {
		const fetchSettings = async () => {
			try {
				const response = await apiFetch({
					path: '/thinkrank/v1/instant-indexing/settings',
					method: 'GET',
				});
				if (response && response.data) {
					setIsEnabled(
						response.data.enabled !== undefined
							? response.data.enabled
							: false
					);
				}
			} catch (error) {
				logger.error('Failed to fetch settings:', error);
			} finally {
				setIsLoading(false);
			}
		};

		fetchSettings();
	}, []);

	const goTo = (section, item) => {
		if (typeof onNavigate === 'function') {
			onNavigate(section, item);
		}
	};

	// Trim lines, drop blanks, and de-duplicate while preserving order.
	const handleCleanUp = () => {
		const seen = new Set();
		const cleaned = urls
			.split('\n')
			.map((line) => line.trim())
			.filter((line) => {
				if (!line || seen.has(line)) {
					return false;
				}
				seen.add(line);
				return true;
			});
		setUrls(cleaned.join('\n'));
	};

	const handleSubmit = async () => {
		if (stats.valid === 0) {
			setNotice({
				status: 'warning',
				message: siteHost
					? sprintf(
							/* translators: %s: site host */
							__(
								'Add at least one valid URL from %s.',
								'thinkrank'
							),
							siteHost
						)
					: __('Please enter at least one valid URL.', 'thinkrank'),
			});
			return;
		}

		setIsSubmitting(true);
		setNotice(null);

		try {
			const response = await apiFetch({
				path: '/thinkrank/v1/instant-indexing/submit',
				method: 'POST',
				data: { urls },
			});

			if (response.success) {
				const count = Number(response.count ?? 0);
				setNotice({
					status: 'success',
					message:
						count > 0
							? sprintf(
									/* translators: %d: number of URLs submitted */
									_n(
										'Submitted %d URL to the IndexNow API.',
										'Submitted %d URLs to the IndexNow API.',
										count,
										'thinkrank'
									),
									count
								)
							: response.message ||
								__('URLs submitted.', 'thinkrank'),
				});
				setUrls('');
			} else {
				throw new Error(response.message);
			}
		} catch (error) {
			logger.error('Submission error:', error);
			setNotice({
				status: 'error',
				message:
					error.message ||
					__(
						'Failed to submit URLs. Please check your API key and try again.',
						'thinkrank'
					),
			});
		} finally {
			setIsSubmitting(false);
		}
	};

	// Ctrl / ⌘ + Enter submits without reaching for the mouse.
	const handleKeyDown = (event) => {
		if (
			(event.metaKey || event.ctrlKey) &&
			event.key === 'Enter' &&
			!isSubmitting
		) {
			event.preventDefault();
			handleSubmit();
		}
	};

	if (isLoading) {
		// A blank flash reads as broken; show the shape of what's coming.
		return <SkeletonCard lines={4} label={__('Loading…', 'thinkrank')} />;
	}

	// Contextual validation messages shown under the textarea.
	const issues = [];
	if (overLimit) {
		issues.push({
			tone: 'error',
			text: sprintf(
				/* translators: %d: number of URLs over the limit */
				_n(
					'Over the limit: only the first 100 URLs will be submitted (%d ignored).',
					'Over the limit: only the first 100 URLs will be submitted (%d ignored).',
					stats.total - MAX_URLS,
					'thinkrank'
				),
				stats.total - MAX_URLS
			),
		});
	}
	if (stats.invalid > 0) {
		issues.push({
			tone: 'error',
			text: sprintf(
				/* translators: %d: number of invalid lines */
				_n(
					'%d line is not a valid URL and will be skipped.',
					'%d lines are not valid URLs and will be skipped.',
					stats.invalid,
					'thinkrank'
				),
				stats.invalid
			),
		});
	}
	if (stats.offsite > 0) {
		issues.push({
			tone: 'warning',
			text: sprintf(
				/* translators: 1: number of off-site URLs, 2: site host */
				_n(
					'%1$d URL points to another site and will be skipped: only %2$s URLs are sent.',
					'%1$d URLs point to another site and will be skipped: only %2$s URLs are sent.',
					stats.offsite,
					'thinkrank'
				),
				stats.offsite,
				siteHost || __('same-site', 'thinkrank')
			),
		});
	}
	if (stats.duplicates > 0) {
		issues.push({
			tone: 'warning',
			text: sprintf(
				/* translators: %d: number of duplicate URLs */
				_n(
					'%d duplicate URL will be sent once.',
					'%d duplicate URLs will be sent once.',
					stats.duplicates,
					'thinkrank'
				),
				stats.duplicates
			),
		});
	}

	const headerTitle = (
		<div className="tr-min-w-0">
			<h2 className="tr-m-0 tr-text-sm tr-font-semibold tr-text-primary tr-leading-snug">
				{__('Submit URLs', 'thinkrank')}
			</h2>
			<p className="tr-mt-1 tr-mb-0 tr-text-xs tr-text-tertiary">
				{__('Send URLs directly to the IndexNow API.', 'thinkrank')}{' '}
				<a
					href="https://www.bing.com/indexnow"
					target="_blank"
					rel="noopener noreferrer"
				>
					{__('Learn more.', 'thinkrank')}
				</a>
			</p>
		</div>
	);

	const urlCounter = hasContent ? (
		<div className="tr-flex tr-items-center tr-gap-2">
			{stats.offsite > 0 && (
				<Badge variant="warning">
					{sprintf(
						/* translators: %d: number of off-site URLs */
						_n(
							'%d off-site',
							'%d off-site',
							stats.offsite,
							'thinkrank'
						),
						stats.offsite
					)}
				</Badge>
			)}
			{stats.invalid > 0 && (
				<Badge variant="poor">
					{sprintf(
						/* translators: %d: number of invalid URLs */
						_n(
							'%d invalid',
							'%d invalid',
							stats.invalid,
							'thinkrank'
						),
						stats.invalid
					)}
				</Badge>
			)}
			<Text
				size={12}
				weight={500}
				color={overLimit ? 'error' : 'tertiary'}
				className="tr-tabular-nums"
			>
				{sprintf(
					/* translators: 1: number of URLs entered, 2: maximum */
					__('%1$d / %2$d', 'thinkrank'),
					stats.total,
					MAX_URLS
				)}
			</Text>
		</div>
	) : null;

	let submitLabel = __('Submit URLs', 'thinkrank');
	if (isSubmitting) {
		submitLabel = __('Submitting…', 'thinkrank');
	} else if (stats.valid > 0) {
		submitLabel = sprintf(
			/* translators: %d: number of valid URLs */
			_n('Submit %d URL', 'Submit %d URLs', stats.valid, 'thinkrank'),
			stats.valid
		);
	}

	return (
		<div className="thinkrank-submit-urls">
			<SettingsCard
				title={headerTitle}
				enabled={isEnabled}
				notice={notice}
				onNoticeRemove={() => setNotice(null)}
				showDisabledWarning={false}
			>
				{!isEnabled ? (
					<Card padding={false}>
						<EmptyState
							icon={IconBolt}
							title={__(
								'Instant Indexing is turned off',
								'thinkrank'
							)}
							description={__(
								'Enable Instant Indexing in Settings to start sending URLs to the IndexNow API.',
								'thinkrank'
							)}
							action={
								<Button
									variant="primary"
									onClick={() =>
										goTo(
											'instant-indexing',
											'instant-indexing-settings'
										)
									}
								>
									{__('Go to Settings', 'thinkrank')}
								</Button>
							}
						/>
					</Card>
				) : (
					<Card padding={false}>
						<SectionHeader
							title={__('URLs to Submit', 'thinkrank')}
							description={__(
								'Insert URLs to send to the IndexNow API (one per line, up to 100).',
								'thinkrank'
							)}
							trailing={
								hasContent ? (
									<div className="tr-flex tr-items-center tr-gap-1">
										<Button
											variant="ghost"
											size="sm"
											onClick={handleCleanUp}
										>
											{__('Clean up', 'thinkrank')}
										</Button>
										<Button
											variant="ghost"
											size="sm"
											onClick={() => setUrls('')}
										>
											{__('Clear', 'thinkrank')}
										</Button>
									</div>
								) : null
							}
						/>
						<Container
							direction="column"
							gap={6}
							className="tr-px-6 tr-py-6 tr-divide-y tr-divide-line-2"
						>
							<FormField
								id="tr-ii-submit-urls"
								label={__('URLs', 'thinkrank')}
								description={
									siteHost
										? sprintf(
												/* translators: %s: site host */
												__(
													'One URL per line, up to 100. URLs must belong to %s.',
													'thinkrank'
												),
												siteHost
											)
										: __(
												'One URL per line, up to 100.',
												'thinkrank'
											)
								}
								counter={urlCounter}
							>
								<NumberedTextarea
									id="tr-ii-submit-urls"
									value={urls}
									onChange={(e) => setUrls(e.target.value)}
									onKeyDown={handleKeyDown}
									rows={10}
									placeholder={
										siteHost
											? `https://${siteHost}/sample-page`
											: 'https://example.com/sample-page'
									}
								/>
								{issues.length > 0 && (
									<div className="tr-flex tr-flex-col tr-gap-1">
										{issues.map((issue, i) => (
											<Text
												key={i}
												size={12}
												color={issue.tone}
											>
												{issue.text}
											</Text>
										))}
									</div>
								)}
							</FormField>
							<div className="tr-flex tr-flex-wrap tr-items-center tr-justify-between tr-gap-3 tr-pt-6">
								<Button
									variant="ghost"
									size="sm"
									onClick={() =>
										goTo(
											'instant-indexing',
											'instant-indexing-history'
										)
									}
								>
									{__('View submission history', 'thinkrank')}
								</Button>
								<Button
									variant="primary"
									icon={IconSend}
									onClick={handleSubmit}
									loading={isSubmitting}
									disabled={isSubmitting || stats.valid === 0}
								>
									{submitLabel}
								</Button>
							</div>
						</Container>
					</Card>
				)}
			</SettingsCard>
		</div>
	);
};

export default SubmitURLs;
