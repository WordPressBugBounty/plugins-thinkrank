/**
 * Analysis panel — AI tools + SEO score.
 *
 * Top: an AI Assistant card (content type, tone, Generate-with-AI, Analyze).
 * Below: the full SEOScoreCalculator (score ring, scoring factors, suggestions),
 * which reports its score upward via `onScoreChange` for the drawer header ring.
 */

import { __ } from '@wordpress/i18n';
import Select from '../../../admin/components/ui/Select';
import Button from '../../../admin/components/ui/Button';
import PanelCard from '../ui/PanelCard';
import { IconSparkle } from '../ui/icons';
import SEOScoreCalculator from '../../../admin/components/seo/SEOScoreCalculator';

const CONTENT_TYPES = [
	{ value: 'blog_post', label: __('Blog Post', 'thinkrank') },
	{ value: 'page', label: __('Page', 'thinkrank') },
	{ value: 'product', label: __('Product', 'thinkrank') },
	{ value: 'service', label: __('Service', 'thinkrank') },
];

/**
 * Append an HTML fragment to the post body, supporting both the block editor
 * (parse to blocks and insert) and the classic editor (TinyMCE / textarea).
 * The change lands in the live editor so the SEO score can be recalculated
 * immediately, before the post is saved.
 *
 * @param {string} html HTML fragment to append.
 */
const appendEditorContent = (html) => {
	if (!html) return;
	const wp = window.wp;
	// Block editor: convert the HTML to blocks and insert at the end.
	if (
		wp?.blocks?.rawHandler &&
		wp?.data?.dispatch?.('core/block-editor')?.insertBlocks
	) {
		const blocks = wp.blocks.rawHandler({ HTML: html });
		if (blocks && blocks.length) {
			wp.data.dispatch('core/block-editor').insertBlocks(blocks);
			return;
		}
	}
	// Classic editor (TinyMCE visual mode).
	if (window.tinymce) {
		const editor = window.tinymce.get('content');
		if (editor && !editor.isHidden()) {
			editor.setContent(editor.getContent() + html);
			editor.fire('change');
			return;
		}
	}
	// Classic editor (text mode) / fallback textarea.
	const textarea = document.getElementById('content');
	if (textarea) {
		textarea.value += '\n' + html;
		textarea.dispatchEvent(new Event('change', { bubbles: true }));
	}
};

const TONES = [
	{ value: 'professional', label: __('Professional', 'thinkrank') },
	{ value: 'friendly', label: __('Friendly', 'thinkrank') },
	{ value: 'authoritative', label: __('Authoritative', 'thinkrank') },
	{ value: 'conversational', label: __('Conversational', 'thinkrank') },
];

const AnalysisPanel = ({
	postId,
	formData,
	onFieldChange,
	onGenerate,
	isGenerating,
	onAnalyze,
	isAnalyzing,
	strings = {},
	scoreData,
	postTitle,
	contentPreview = '',
	contentFull = '',
	onScoreChange,
}) => {
	const aiConfigured = Boolean(window.thinkrankMetabox?.aiConfigured);
	const hasTitle = Boolean(postTitle && postTitle.trim());

	return (
		<div className="tr-flex tr-flex-col tr-gap-4">
			<PanelCard
				icon={<IconSparkle />}
				title={__('AI Assistant', 'thinkrank')}
				subtitle={__(
					'Generate optimized metadata or analyze your content.',
					'thinkrank'
				)}
				bodyClassName="tr-flex tr-flex-col tr-gap-4"
			>
				<div className="tr-grid tr-grid-cols-2 tr-gap-3">
					<Select
						className="tr-w-full"
						label={__('Content Type', 'thinkrank')}
						id="thinkrank_content_type"
						value={formData.thinkrank_content_type}
						onChange={(e) =>
							onFieldChange(
								'thinkrank_content_type',
								e.target.value
							)
						}
						options={CONTENT_TYPES}
					/>
					<Select
						className="tr-w-full"
						label={__('Tone', 'thinkrank')}
						id="thinkrank_tone"
						value={formData.thinkrank_tone}
						onChange={(e) =>
							onFieldChange('thinkrank_tone', e.target.value)
						}
						options={TONES}
					/>
				</div>
				<div className="tr-flex tr-gap-2">
					<Button
						variant="ai"
						className="tr-flex-1"
						onClick={onGenerate}
						loading={isGenerating}
						disabled={!aiConfigured}
						title={
							!aiConfigured
								? __(
										'Configure an AI provider in ThinkRank → Settings to enable AI generation',
										'thinkrank'
									)
								: undefined
						}
					>
						{isGenerating
							? strings.generating ||
								__('Generating…', 'thinkrank')
							: `✨ ${__('Generate with AI', 'thinkrank')}`}
					</Button>
					<Button
						variant="secondary"
						className="tr-flex-1"
						onClick={onAnalyze}
						loading={isAnalyzing}
						disabled={!hasTitle}
						title={
							!hasTitle
								? __(
										'Add a post title before analyzing content',
										'thinkrank'
									)
								: undefined
						}
					>
						{isAnalyzing
							? strings.analyzing || __('Analyzing…', 'thinkrank')
							: __('Analyze Content', 'thinkrank')}
					</Button>
				</div>
				{!aiConfigured && (
					<p className="tr-text-xs tr-text-tertiary tr-m-0">
						{__(
							'Add an AI provider API key in ThinkRank → Settings to enable AI generation.',
							'thinkrank'
						)}
					</p>
				)}
			</PanelCard>

			<SEOScoreCalculator
				postId={postId}
				targetKeyword={formData.thinkrank_focus_keyword}
				targetKeywords={formData.thinkrank_focus_keywords}
				postTitle={postTitle}
				metaDescription={formData.thinkrank_meta_description}
				contentPreview={contentPreview}
				contentFull={contentFull}
				scoreData={scoreData}
				onScoreChange={onScoreChange}
				seoTitle={formData.thinkrank_seo_title}
				contentType={formData.thinkrank_content_type}
				tone={formData.thinkrank_tone}
				onApplyTitle={(title) =>
					onFieldChange('thinkrank_seo_title', title)
				}
				onApplyDescription={(description) =>
					onFieldChange('thinkrank_meta_description', description)
				}
				onApplyContent={appendEditorContent}
			/>
		</div>
	);
};

export default AnalysisPanel;
