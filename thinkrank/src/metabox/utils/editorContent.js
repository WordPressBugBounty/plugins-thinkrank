/**
 * Which editor the metabox sits in, and the post body that editor holds now.
 *
 * The `core/block-editor` store being registered does not mean the block
 * editor is on screen: WordPress loads it on Classic Editor screens too, and
 * treating those as block screens sent drafted sections to a block list that
 * is never rendered (#723 review on #928). The block editor is the one that
 * has a post loaded in `core/editor` and the `block-editor-page` body class.
 *
 * @package
 * @since 2.21.3
 */

import { getLiveContent } from '../../admin/components/seo/liveScore';

/**
 * Whether the post is being edited in the block editor.
 *
 * @return {boolean} True only on a real block editor screen.
 */
export const isBlockEditor = () => {
	const data = window.wp?.data;
	if (
		!data?.select ||
		!document.body?.classList?.contains('block-editor-page')
	) {
		return false;
	}
	try {
		if (!data.select('core/editor')?.getCurrentPostId?.()) {
			return false;
		}
		return (
			typeof data.select('core/block-editor')?.getBlocks === 'function'
		);
	} catch (e) {
		return false;
	}
};

/**
 * The Classic Editor's TinyMCE instance, when it is in Visual mode.
 *
 * @return {?Object} TinyMCE editor or null.
 */
export const classicVisualEditor = () => {
	const editor = window.tinymce?.get?.('content');
	if (!editor || editor.isHidden?.()) {
		return null;
	}
	return editor;
};

/**
 * The Classic Editor's textarea (Text mode, or no TinyMCE at all).
 *
 * @return {?HTMLTextAreaElement} Textarea or null.
 */
export const classicTextarea = () => {
	const node = document.getElementById('content');
	return node && node.tagName === 'TEXTAREA' ? node : null;
};

/**
 * The post body as the editor holds it right now, as HTML.
 *
 * Empty when no editor this code knows is on screen (page builders): the
 * caller then leaves the content out and the server reads the stored post.
 *
 * @return {string} Content HTML, or ''.
 */
export const getEditorContent = () => {
	// The block editor's markup comes from the one reader the live score
	// uses. The server resolves builder markup itself, so the HTML goes as is.
	if (isBlockEditor()) {
		return getLiveContent() || '';
	}
	const visual = classicVisualEditor();
	if (visual) {
		return visual.getContent() || '';
	}
	const textarea = classicTextarea();
	return textarea ? textarea.value || '' : '';
};

/**
 * Whether the content this screen sends is the post's whole body.
 *
 * True in the block editor and in a Classic Editor that is on screen, where
 * an empty body means the author cleared the post. False on builder screens,
 * where the body lives in the builder's own storage and the edit screen holds
 * nothing (Oxygen keeps an empty, hidden `#content`), so the server reads the
 * saved post instead of grading an empty one.
 *
 * @since 2.22.0
 *
 * @return {boolean} True when '' from this editor really means empty.
 */
export const editorHoldsBody = () => {
	if (isBlockEditor()) {
		return true;
	}
	const wrap = document.getElementById('postdivrich');
	if (!wrap || wrap.getClientRects().length === 0) {
		return false;
	}
	return Boolean(classicVisualEditor() || classicTextarea());
};
