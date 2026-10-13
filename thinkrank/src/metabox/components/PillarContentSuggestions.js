import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { decodeEntities } from '@wordpress/html-entities';
import logger from '../../shared/utils/logger';

const PillarContentSuggestions = ({ postId }) => {
	const [suggestions, setSuggestions] = useState([]);
	const [isLoading, setIsLoading] = useState(true);
	const [copiedId, setCopiedId] = useState(null);

	const activePostStatus = useSelect((select) => {
		const editor = select('core/editor');
		return editor ? editor.getCurrentPostAttribute('status') : null;
	}, []);

	const effectiveStatus =
		activePostStatus ||
		(window.thinkrankMetabox
			? window.thinkrankMetabox.postStatus
			: 'draft');

	useEffect(() => {
		if (!postId || effectiveStatus !== 'publish') return;

		const fetchSuggestions = async () => {
			try {
				const response = await apiFetch({
					path: `/thinkrank/v1/pillar-content/suggestions?post_id=${postId}`,
				});
				setSuggestions(response || []);
			} catch (error) {
				logger.error(
					'Error fetching pillar content suggestions:',
					error
				);
				setSuggestions([]);
			} finally {
				setIsLoading(false);
			}
		};

		fetchSuggestions();
	}, [postId, effectiveStatus]);

	const copyToClipboard = (text, id) => {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard
				.writeText(text)
				.then(() => {
					setCopiedId(id);
					setTimeout(() => setCopiedId(null), 2000);
				})
				.catch((err) => logger.error('Failed to copy: ', err));
		} else {
			const textArea = document.createElement('textarea');
			textArea.value = text;
			textArea.style.position = 'fixed';
			textArea.style.left = '-9999px';
			textArea.style.top = '0';
			document.body.appendChild(textArea);
			textArea.focus();
			textArea.select();
			try {
				if (document.execCommand('copy')) {
					setCopiedId(id);
					setTimeout(() => setCopiedId(null), 2000);
				}
			} catch (err) {
				logger.error('Fallback copy failed', err);
			}
			document.body.removeChild(textArea);
		}
	};

	const insertLink = (post) => {
		const decodedTitle = decodeEntities(post.title);

		if (window.wp && window.wp.data) {
			const { select, dispatch } = window.wp.data;
			const blockSelect =
				select('core/block-editor') || select('core/editor');
			const blockDispatch =
				dispatch('core/block-editor') || dispatch('core/editor');

			if (blockSelect && blockDispatch) {
				const selectionStart = blockSelect.getSelectionStart();
				const selectionEnd = blockSelect.getSelectionEnd();

				if (
					selectionStart &&
					selectionEnd &&
					selectionStart.clientId === selectionEnd.clientId &&
					selectionStart.offset !== selectionEnd.offset
				) {
					const clientId = selectionStart.clientId;
					const block = blockSelect.getBlock(clientId);
					const attributeKey =
						selectionStart.attributeKey || 'content';
					const currentContent = block.attributes[attributeKey];

					if (currentContent !== undefined) {
						if (window.wp.richText) {
							try {
								const record = window.wp.richText.create({
									html: currentContent,
								});
								const linkFormat = {
									type: 'core/link',
									attributes: {
										url: post.permalink,
										title: decodedTitle,
										rel: 'noopener noreferrer',
									},
								};
								const start = Math.min(
									selectionStart.offset,
									selectionEnd.offset
								);
								const end = Math.max(
									selectionStart.offset,
									selectionEnd.offset
								);
								const newRecord =
									window.wp.richText.applyFormat(
										record,
										linkFormat,
										start,
										end
									);
								const newContent =
									window.wp.richText.toHTMLString({
										value: newRecord,
									});
								blockDispatch.updateBlockAttributes(clientId, {
									[attributeKey]: newContent,
								});
								return;
							} catch (e) {
								logger.warn(
									'ThinkRank: RichText format failed, falling back',
									e
								);
							}
						}
						const selection = window.getSelection();
						const selectedText = selection
							? selection.toString()
							: '';
						if (selectedText) {
							const linkHTML = `<a href="${post.permalink}" title="${decodedTitle}">${selectedText}</a>`;
							blockDispatch.updateBlockAttributes(clientId, {
								[attributeKey]: currentContent.replace(
									selectedText,
									linkHTML
								),
							});
							return;
						}
					}
				}
			}
		}

		if (
			window.tinyMCE &&
			window.tinyMCE.activeEditor &&
			!window.tinyMCE.activeEditor.isHidden()
		) {
			const editor = window.tinyMCE.activeEditor;
			const content = editor.selection.getContent({ format: 'text' });
			if (content) {
				editor.selection.setContent(
					`<a href="${post.permalink}" title="${decodedTitle}">${content}</a>`
				);
			} else {
				editor.execCommand(
					'mceInsertContent',
					false,
					`<a href="${post.permalink}" title="${decodedTitle}">${decodedTitle}</a>`
				);
			}
			return;
		}

		const textArea = document.querySelector('textarea.wp-editor-area');
		if (textArea && textArea.offsetParent !== null) {
			const start = textArea.selectionStart;
			const end = textArea.selectionEnd;
			if (start !== end) {
				const value = textArea.value;
				const selected = value.substring(start, end);
				const replacement = `<a href="${post.permalink}" title="${decodedTitle}">${selected}</a>`;
				textArea.value =
					value.substring(0, start) +
					replacement +
					value.substring(end);
				const newCursorPos = start + replacement.length;
				textArea.focus();
				textArea.setSelectionRange(newCursorPos, newCursorPos);
				return;
			}
		}

		const linkHTML = `<a href="${post.permalink}" title="${decodedTitle}">${decodedTitle}</a>`;
		const coreEditor = window.wp?.data?.dispatch('core/editor');
		if (coreEditor && typeof coreEditor.insertHTML === 'function') {
			coreEditor.insertHTML(linkHTML);
			return;
		}
		if (
			window.wp?.data?.dispatch('core/block-editor') &&
			window.wp.blocks?.createBlock
		) {
			const newBlock = window.wp.blocks.createBlock('core/paragraph', {
				content: linkHTML,
			});
			window.wp.data.dispatch('core/block-editor').insertBlocks(newBlock);
			return;
		}
		if (window.send_to_editor) {
			window.send_to_editor(linkHTML);
		}
	};

	if (effectiveStatus !== 'publish') return null;

	if (isLoading) {
		return (
			<div className="tr-flex tr-items-center tr-justify-center tr-py-4">
				<span className="tr-w-5 tr-h-5 tr-border-2 tr-border-brand tr-border-t-transparent tr-rounded-full tr-animate-spin" />
			</div>
		);
	}

	if (!suggestions || suggestions.length === 0) return null;

	return (
		<div className="tr-bg-white tr-rounded-lg tr-border tr-border-secondary tr-mt-2">
			<div className="tr-px-4 tr-py-3 tr-border-b tr-border-secondary">
				<h4 className="tr-text-sm tr-font-semibold tr-text-primary tr-m-0">
					{__('Link Suggestions', 'thinkrank')}
				</h4>
				<p className="tr-text-xs tr-text-tertiary tr-m-0 tr-mt-0.5">
					{__(
						'Recommended pillar content to link to from this post.',
						'thinkrank'
					)}
				</p>
			</div>
			<ul className="tr-m-0 tr-p-0 tr-list-none">
				{suggestions.map((post) => (
					<li
						key={post.id}
						className="tr-flex tr-items-center tr-justify-between tr-px-4 tr-py-2.5 tr-border-b tr-border-secondary last:tr-border-0 tr-gap-3"
					>
						<div className="tr-flex-1 tr-min-w-0">
							<a
								href={post.permalink}
								target="_blank"
								rel="noopener noreferrer"
								className="tr-text-sm tr-font-medium tr-text-brand hover:tr-text-brand tr-no-underline tr-block tr-truncate"
							>
								{decodeEntities(post.title)}
							</a>
							<span className="tr-inline-flex tr-items-center tr-px-1.5 tr-py-0.5 tr-rounded tr-text-xs tr-bg-white tr-text-tertiary tr-mt-0.5">
								{post.type}
							</span>
						</div>
						<div className="tr-flex tr-gap-1 tr-shrink-0">
							<button
								type="button"
								title={__('Insert Link', 'thinkrank')}
								onMouseDown={(e) => e.preventDefault()}
								onClick={() => insertLink(post)}
								className="tr-flex tr-items-center tr-justify-center tr-w-7 tr-h-7 tr-rounded tr-border tr-border-secondary tr-bg-white tr-text-tertiary hover:tr-bg-white hover:tr-text-brand tr-transition-colors tr-cursor-pointer"
							>
								<svg
									className="tr-w-3.5 tr-h-3.5"
									fill="none"
									stroke="currentColor"
									viewBox="0 0 24 24"
								>
									<path
										strokeLinecap="round"
										strokeLinejoin="round"
										strokeWidth={2}
										d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"
									/>
								</svg>
							</button>
							<button
								type="button"
								title={
									copiedId === post.id
										? __('Copied!', 'thinkrank')
										: __('Copy Link', 'thinkrank')
								}
								onClick={() =>
									copyToClipboard(post.permalink, post.id)
								}
								className="tr-flex tr-items-center tr-justify-center tr-w-7 tr-h-7 tr-rounded tr-border tr-border-secondary tr-bg-white tr-text-tertiary hover:tr-bg-white hover:tr-text-brand tr-transition-colors tr-cursor-pointer"
							>
								{copiedId === post.id ? (
									<svg
										className="tr-w-3.5 tr-h-3.5 tr-text-success"
										fill="none"
										stroke="currentColor"
										viewBox="0 0 24 24"
									>
										<path
											strokeLinecap="round"
											strokeLinejoin="round"
											strokeWidth={2}
											d="M5 13l4 4L19 7"
										/>
									</svg>
								) : (
									<svg
										className="tr-w-3.5 tr-h-3.5"
										fill="none"
										stroke="currentColor"
										viewBox="0 0 24 24"
									>
										<path
											strokeLinecap="round"
											strokeLinejoin="round"
											strokeWidth={2}
											d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"
										/>
									</svg>
								)}
							</button>
						</div>
					</li>
				))}
			</ul>
		</div>
	);
};

export default PillarContentSuggestions;
