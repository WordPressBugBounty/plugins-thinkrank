/**
 * Question Coverage card (#723).
 *
 * "Questions people search around this topic, and whether this page answers
 * them." Built from the focus keyword: AI sub-questions across fixed facets,
 * plus real queries the page already gets Search Console impressions for,
 * each judged against the post's sections.
 *
 * Runs on demand only, because it spends tokens on the site's own AI key.
 * The first run on a post shows the token estimate before anything is spent.
 * The last result loads with the panel. Missing questions offer a draft
 * section, which the author reviews before it goes into the post.
 *
 * Copy rule: coverage and questions only. Never citations, AI rankings or
 * visibility scores.
 *
 * @package
 * @since 2.21.2
 */

import { __, sprintf, _n } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import Button from '../../../admin/components/ui/Button';
import Badge from '../../../admin/components/ui/Badge';
import { SkeletonText } from '../../../admin/components/ui/Skeleton';
import PanelCard from '../ui/PanelCard';
import linkifyMessage from '../../../shared/utils/linkifyMessage';
import {
	isBlockEditor,
	classicVisualEditor,
	getEditorContent,
} from '../../utils/editorContent';

const ENDPOINT = '/thinkrank/v1/content-analysis/question-coverage';

const IconQuestions = () => (
	<svg
		className="tr-w-4 tr-h-4"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<circle cx="12" cy="12" r="10" />
		<path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
		<line x1="12" y1="17" x2="12.01" y2="17" />
	</svg>
);

const GROUPS = [
	{
		status: 'missing',
		label: __('Not answered', 'thinkrank'),
		variant: 'poor',
	},
	{
		status: 'partial',
		label: __('Partly answered', 'thinkrank'),
		variant: 'warning',
	},
	{ status: 'answered', label: __('Answered', 'thinkrank'), variant: 'good' },
];

const plain = (html) =>
	String(html || '')
		.replace(/<[^>]+>/g, ' ')
		.replace(/\s+/g, ' ')
		.trim()
		.toLowerCase();

// Only on a real block editor screen: the store is registered on Classic
// Editor screens too, where its block list is never rendered.
const blockEditor = () => {
	if (!isBlockEditor()) {
		return null;
	}
	const data = window.wp.data;
	return {
		select: data.select('core/block-editor'),
		dispatch: data.dispatch('core/block-editor'),
	};
};

// Every top-level heading block, with its text.
const headingBlocks = (editor) =>
	editor.select
		.getBlocks()
		.map((block, index) => ({ block, index }))
		.filter(({ block }) => block.name === 'core/heading')
		.map(({ block, index }) => ({
			clientId: block.clientId,
			index,
			level: block.attributes?.level || 2,
			text: plain(block.attributes?.content),
		}));

/**
 * Select and scroll to the section that answers a question.
 *
 * @param {string} heading Heading text, or '' for the introduction.
 * @return {boolean} Whether the section was found.
 */
const scrollToSection = (heading) => {
	const target = plain(heading);
	const editor = blockEditor();
	if (editor) {
		const blocks = editor.select.getBlocks();
		const match =
			target === ''
				? blocks[0]
				: headingBlocks(editor).find((h) => h.text === target);
		const clientId = match?.clientId;
		if (clientId) {
			editor.dispatch.selectBlock(clientId);
			return true;
		}
		return false;
	}
	// Classic editor: TinyMCE's iframe document.
	const visual = classicVisualEditor();
	const body = visual?.getBody?.();
	if (body) {
		const node =
			target === ''
				? body.firstElementChild
				: classicHeadings(body).find((h) => h.text === target)?.node;
		if (node) {
			node.scrollIntoView({ behavior: 'smooth', block: 'center' });
			visual.selection?.select?.(node, true);
			visual.selection?.collapse?.(true);
			return true;
		}
	}
	return false;
};

// Every top-level H2/H3 in the Classic Editor's Visual body, with its text.
const classicHeadings = (body) =>
	Array.from(body.children)
		.filter((node) => /^H[23]$/.test(node.tagName))
		.map((node) => ({
			node,
			level: Number(node.tagName.slice(1)),
			text: plain(node.textContent),
		}));

/**
 * The top-level node a drafted section goes before, from the heading it goes
 * after. Null means the end of the post.
 *
 * @param {Array}   headings From headingBlocks() or classicHeadings().
 * @param {?string} after    Heading it goes after; '' = before everything.
 * @return {?Object} The heading entry to insert before, or null.
 */
const insertBefore = (headings, after) => {
	if (after === '') {
		return headings[0] || { start: true };
	}
	if (!after) {
		return null;
	}
	const target = plain(after);
	const at = headings.findIndex((h) => h.text === target);
	if (at === -1) {
		return null;
	}
	return (
		headings.slice(at + 1).find((h) => h.level <= headings[at].level) ||
		null
	);
};

/**
 * Insert into the Classic Editor's Visual mode in place.
 *
 * @param {Object}  editor TinyMCE editor.
 * @param {string}  html   Draft HTML.
 * @param {?string} after  Heading it goes after.
 * @return {boolean} Whether it was inserted.
 */
const insertClassic = (editor, html, after) => {
	const body = editor.getBody?.();
	const doc = editor.getDoc?.();
	if (!body || !doc) {
		return false;
	}
	const before = insertBefore(classicHeadings(body), after);
	const template = doc.createElement('template');
	template.innerHTML = html;
	const fragment = template.content;
	if (before?.start) {
		body.insertBefore(fragment, body.firstChild);
	} else if (before?.node) {
		body.insertBefore(fragment, before.node);
	} else {
		body.appendChild(fragment);
	}
	editor.undoManager?.add?.();
	editor.fire?.('change');
	return true;
};

/**
 * Insert a drafted section after the section it belongs after: just before
 * the next heading of the same or a higher level. Falls back to the end of
 * the post (block editor) or to the caller's append.
 *
 * @param {string}   html     Draft HTML.
 * @param {?string}  after    Heading it goes after; '' = before everything.
 * @param {Function} fallback Append to the end of the post.
 */
export const insertSection = (html, after, fallback) => {
	const editor = blockEditor();
	if (!editor) {
		const visual = classicVisualEditor();
		if (!visual || !insertClassic(visual, html, after)) {
			fallback(html);
		}
		return;
	}
	const newBlocks = window.wp.blocks?.rawHandler?.({ HTML: html });
	if (!newBlocks?.length) {
		fallback(html);
		return;
	}
	const before = insertBefore(headingBlocks(editor), after);
	let index = editor.select.getBlocks().length;
	if (before?.start) {
		index = 0;
	} else if (before) {
		index = before.index;
	}
	editor.dispatch.insertBlocks(newBlocks, index);
};

const QuestionRow = ({ question, onDraft, drafting, disabled }) => {
	const fromSearchConsole = question.source === 'search_console';
	const sectionLabel =
		question.section === ''
			? __('the introduction', 'thinkrank')
			: question.section;
	const afterLabel =
		question.after === ''
			? __('before the first heading', 'thinkrank')
			: question.after;

	return (
		<li className="tr-py-2.5 tr-border-t tr-border-secondary first:tr-border-t-0">
			<div className="tr-flex tr-items-start tr-justify-between tr-gap-2">
				<span className="tr-text-sm tr-text-primary">
					{question.question}
				</span>
				{question.status !== 'answered' && (
					<Button
						variant="secondary"
						size="sm"
						onClick={() => onDraft(question)}
						loading={drafting}
						disabled={disabled}
						className="tr-shrink-0"
					>
						{__('Draft a section', 'thinkrank')}
					</Button>
				)}
			</div>
			<div className="tr-flex tr-flex-wrap tr-items-center tr-gap-x-2 tr-gap-y-1 tr-mt-1 tr-text-xs tr-text-secondary">
				{fromSearchConsole && (
					<Badge variant="info">
						{sprintf(
							/* translators: %s: number of impressions. */
							__(
								'Real query from Search Console · %s impressions',
								'thinkrank'
							),
							Number(question.impressions || 0).toLocaleString()
						)}
					</Badge>
				)}
				{question.section !== null &&
					question.section !== undefined && (
						<button
							type="button"
							onClick={() => scrollToSection(question.section)}
							className="tr-p-0 tr-border-0 tr-bg-transparent tr-text-xs tr-text-brand tr-cursor-pointer hover:tr-underline"
						>
							{sprintf(
								/* translators: %s: section heading. */
								__('In: %s', 'thinkrank'),
								sectionLabel
							)}
						</button>
					)}
				{question.status !== 'answered' &&
					question.after !== null &&
					question.after !== undefined && (
						<span>
							{sprintf(
								/* translators: %s: section heading. */
								__('Would fit after: %s', 'thinkrank'),
								afterLabel
							)}
						</span>
					)}
			</div>
			{question.reason && (
				<p className="tr-m-0 tr-mt-1 tr-text-xs tr-text-tertiary">
					{question.reason}
				</p>
			)}
		</li>
	);
};

// The post body as the editor holds it now. Left out when no known editor
// is on screen (page builders), so the server reads the stored post.
const liveContent = () => {
	const html = getEditorContent();
	return html.trim() ? { content: html } : {};
};

const QuestionCoverageCard = ({ postId, focusKeyword, onAppendContent }) => {
	const aiConfigured = Boolean(window.thinkrankMetabox?.aiConfigured);
	const [result, setResult] = useState(null);
	const [loading, setLoading] = useState(true);
	const [running, setRunning] = useState(false);
	const [estimate, setEstimate] = useState(null);
	const [error, setError] = useState('');
	const [draft, setDraft] = useState(null);
	const [draftingId, setDraftingId] = useState('');
	// One draft at a time. Each spends tokens and they all share the one
	// preview, so a second request could replace it with a draft for a
	// question the author did not pick last. The ref also stops a double
	// click that lands before the disabled buttons have rendered.
	const draftingRef = useRef(false);

	const keyword = String(focusKeyword || '').trim();

	useEffect(() => {
		let cancelled = false;
		if (!postId) {
			setLoading(false);
			return undefined;
		}
		apiFetch({ path: `${ENDPOINT}?post_id=${postId}` })
			.then((response) => {
				if (!cancelled) {
					setResult(response?.result || null);
				}
			})
			.catch(() => {})
			.finally(() => {
				if (!cancelled) {
					setLoading(false);
				}
			});
		return () => {
			cancelled = true;
		};
	}, [postId]);

	const request = (data) =>
		apiFetch({
			path: ENDPOINT,
			method: 'POST',
			data: {
				post_id: postId,
				keyword,
				...liveContent(),
				...data,
			},
		});

	// The first run on a post shows its cost first; later runs go straight.
	const start = useCallback(async () => {
		setError('');
		if (!result && !estimate) {
			setRunning(true);
			try {
				setEstimate(await request({ dry_run: true }));
			} catch (e) {
				setError(e?.message || __('Couldn’t start.', 'thinkrank'));
			} finally {
				setRunning(false);
			}
			return;
		}
		setRunning(true);
		setEstimate(null);
		try {
			setResult(await request({}));
		} catch (e) {
			setError(
				e?.message ||
					__('Couldn’t check coverage. Try again.', 'thinkrank')
			);
		} finally {
			setRunning(false);
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [result, estimate, postId, keyword]);

	const draftFor = async (question) => {
		if (draftingRef.current) {
			return;
		}
		draftingRef.current = true;
		setDraftingId(question.id);
		setError('');
		try {
			const response = await apiFetch({
				path: `${ENDPOINT}/draft`,
				method: 'POST',
				data: {
					post_id: postId,
					question: question.question,
					...liveContent(),
				},
			});
			setDraft({ ...response, question });
		} catch (e) {
			setError(
				e?.message || __('Couldn’t draft that section.', 'thinkrank')
			);
		} finally {
			draftingRef.current = false;
			setDraftingId('');
		}
	};

	const counts = result?.counts || {};
	const total = counts.total || 0;
	const answered = counts.answered || 0;
	const percent = total ? Math.round((answered / total) * 100) : 0;
	const keywordChanged = result && keyword && result.keyword !== keyword;

	let body;
	if (loading) {
		body = <SkeletonText lines={3} />;
	} else if (!keyword) {
		body = (
			<p className="tr-m-0 tr-text-sm tr-text-secondary">
				{__(
					'Set a focus keyword in the General tab first. The questions are built from it.',
					'thinkrank'
				)}
			</p>
		);
	} else if (!aiConfigured) {
		body = (
			<p className="tr-m-0 tr-text-sm tr-text-secondary">
				{__(
					'Add an AI provider in ThinkRank → Settings to check question coverage.',
					'thinkrank'
				)}
			</p>
		);
	} else {
		body = (
			<div className="tr-flex tr-flex-col tr-gap-3">
				{result && (
					<div>
						<div className="tr-flex tr-items-baseline tr-justify-between tr-gap-2">
							<span className="tr-text-sm tr-font-semibold tr-text-primary">
								{sprintf(
									/* translators: 1: answered questions, 2: all questions. */
									__('%1$d of %2$d answered', 'thinkrank'),
									answered,
									total
								)}
							</span>
							<span className="tr-text-xs tr-text-tertiary">
								{sprintf(
									/* translators: %s: focus keyword. */
									__('For “%s”', 'thinkrank'),
									result.keyword
								)}
							</span>
						</div>
						<div
							className="tr-mt-1.5 tr-h-2 tr-rounded-full tr-bg-line-2 tr-overflow-hidden"
							role="progressbar"
							aria-valuenow={percent}
							aria-valuemin={0}
							aria-valuemax={100}
							aria-label={__('Questions answered', 'thinkrank')}
						>
							<div
								className="tr-h-full tr-bg-success"
								style={{ width: `${percent}%` }}
							/>
						</div>
						{result.gsc?.status === 'ok' && (
							<p className="tr-m-0 tr-mt-1.5 tr-text-xs tr-text-tertiary">
								{sprintf(
									/* translators: %d: number of Search Console queries. */
									_n(
										'Includes %d real query this page gets impressions for in Search Console.',
										'Includes %d real queries this page gets impressions for in Search Console.',
										result.gsc.count,
										'thinkrank'
									),
									result.gsc.count
								)}
							</p>
						)}
					</div>
				)}

				{estimate && (
					<div className="tr-rounded-lg tr-border tr-border-secondary tr-p-3 tr-text-xs tr-text-secondary">
						{sprintf(
							/* translators: %s: estimated number of tokens. */
							__(
								'This uses about %s tokens from your AI provider. Later runs reuse the questions and only re-check the content.',
								'thinkrank'
							),
							Number(estimate.tokens || 0).toLocaleString()
						)}
					</div>
				)}

				{keywordChanged && (
					<p className="tr-m-0 tr-text-xs tr-text-warning">
						{__(
							'The focus keyword changed since the last check. Run it again for the new keyword.',
							'thinkrank'
						)}
					</p>
				)}

				{error && (
					<p className="tr-m-0 tr-text-xs tr-text-danger">
						{linkifyMessage(error)}
					</p>
				)}

				<Button
					variant={result ? 'secondary' : 'ai'}
					onClick={start}
					loading={running}
				>
					{(() => {
						if (estimate) {
							return __('Run the check', 'thinkrank');
						}
						return result
							? __('Check again', 'thinkrank')
							: __('Check question coverage', 'thinkrank');
					})()}
				</Button>

				{draft && (
					<div className="tr-rounded-lg tr-border tr-border-brand tr-p-3 tr-flex tr-flex-col tr-gap-2">
						<span className="tr-text-xs tr-font-medium tr-text-secondary">
							{__('Draft to review before adding', 'thinkrank')}
						</span>
						<div
							className="tr-text-sm tr-text-primary tr-prose"
							// The server builds this from escaped text only.
							dangerouslySetInnerHTML={{ __html: draft.html }}
						/>
						<div className="tr-flex tr-flex-wrap tr-gap-2">
							<Button
								variant="primary"
								size="sm"
								onClick={() => {
									insertSection(
										draft.html,
										draft.question.after,
										onAppendContent
									);
									setDraft(null);
								}}
							>
								{draft.question.after
									? __('Insert in place', 'thinkrank')
									: __('Insert into post', 'thinkrank')}
							</Button>
							<Button
								variant="secondary"
								size="sm"
								onClick={() => setDraft(null)}
							>
								{__('Discard', 'thinkrank')}
							</Button>
						</div>
					</div>
				)}

				{result &&
					GROUPS.map((group) => {
						const rows = (result.questions || []).filter(
							(q) => q.status === group.status
						);
						if (!rows.length) {
							return null;
						}
						return (
							<div key={group.status}>
								<div className="tr-flex tr-items-center tr-gap-2 tr-mb-1">
									<Badge variant={group.variant}>
										{group.label}
									</Badge>
									<span className="tr-text-xs tr-text-tertiary">
										{rows.length}
									</span>
								</div>
								<ul className="tr-m-0 tr-p-0 tr-list-none">
									{rows.map((question) => (
										<QuestionRow
											key={question.id}
											question={question}
											onDraft={draftFor}
											drafting={
												draftingId === question.id
											}
											disabled={draftingId !== ''}
										/>
									))}
								</ul>
							</div>
						);
					})}
			</div>
		);
	}

	return (
		<PanelCard
			icon={<IconQuestions />}
			title={__('Question coverage', 'thinkrank')}
			subtitle={__(
				'Questions people search around this topic, and whether this page answers them.',
				'thinkrank'
			)}
		>
			{body}
		</PanelCard>
	);
};

export default QuestionCoverageCard;
