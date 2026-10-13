/**
 * Live SEO score: the one request both the Analysis panel and the metabox
 * drawer make, and the session state they share.
 *
 * The Analysis panel only exists while its tab is open, so on its own it could
 * keep the score live only there. A title or keyword edited on the General tab
 * left the drawer's header ring on the old number until the writer opened
 * Analysis. The drawer now re-scores those edits itself through this module,
 * and because the session record and the request counter live here, the panel
 * picks the result up when it mounts instead of scoring the same text again.
 *
 * @package
 */

import apiFetch from '@wordpress/api-fetch';

import {
	calculateReadabilityScore,
	calculateContentQuality,
	resolveEditorContent,
} from './utils/contentAnalysis';

// The last live score of each post this session (post id => { key, keywords,
// data }, key being the SEO title and description it was scored with and
// keywords the focus keywords). The panel unmounts on a tab switch; without
// this it came back to the stored score of the saved post, and the keyword
// gauges ignored every unsaved edit (#729).
const sessionScores = new Map();

// The newest score request of each post (post id => request number). Scoring
// requests overlap while the writer types, and they can finish out of order;
// only the newest may land, or an older response would paint gauges for text
// that is no longer there. Module scope so a request from before a tab switch
// is superseded by one made after it, whichever component made it.
const latestScoreRequest = new Map();
let scoreRequestCount = 0;

/**
 * Key for the SEO title and description a score was calculated with.
 *
 * @param {string} title       SEO title.
 * @param {string} description Meta description.
 * @return {string} Key.
 */
export const fieldsKeyOf = (title, description) =>
	`${title || ''}\u0000${description || ''}`;

/**
 * Key for a focus keyword list.
 *
 * @param {string[]} keywords Focus keywords.
 * @return {string} Key.
 */
export const keywordsKeyOf = (keywords) =>
	(Array.isArray(keywords) ? keywords : []).join('|');

/**
 * This session's live score for a post, if there is one.
 *
 * @param {number|string} postId Post ID.
 * @return {{key: string, keywords: string, data: Object}|undefined} Entry.
 */
export const getSessionScore = (postId) => sessionScores.get(postId);

/**
 * The newest request number issued for a post.
 *
 * @param {number|string} postId Post ID.
 * @return {number|undefined} Request number.
 */
export const getLatestScoreRequest = (postId) => latestScoreRequest.get(postId);

/**
 * Start a score request for a post.
 *
 * @param {number|string} postId Post ID.
 * @return {Function} Returns true once a newer request has been started.
 */
export const beginScoreRequest = (postId) => {
	const requestId = ++scoreRequestCount;
	latestScoreRequest.set(postId, requestId);
	return () => latestScoreRequest.get(postId) !== requestId;
};

/**
 * The post body as the editor currently holds it, saved or not.
 *
 * @return {string} Content HTML.
 */
export const getLiveContent = () => {
	let content = '';
	if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
		try {
			content = wp.data.select('core/editor').getEditedPostContent();
			if (content) return content;
		} catch (e) {}
	}
	if (typeof tinymce !== 'undefined') {
		const editor = tinymce.get('content');
		if (editor && !editor.isHidden()) {
			content = editor.getContent();
			if (content) return content;
		}
	}
	const contentTextarea = document.getElementById('content');
	if (contentTextarea) content = contentTextarea.value || '';
	return content;
};

/**
 * Score a post as it stands in the editor and record the result.
 *
 * A successful response is stored as this session's live score only while it
 * is still the newest request, so a slow answer cannot overwrite a newer one.
 *
 * @param {Object}   args
 * @param {number}   args.postId          Post ID.
 * @param {string[]} args.keywords        Focus keywords, primary first.
 * @param {string}   args.seoTitle        SEO title as typed.
 * @param {string}   args.metaDescription Meta description as typed.
 * @param {boolean}  args.saveScore       Persist the score server-side.
 * @param {Function} args.isSuperseded    From beginScoreRequest().
 * @return {Promise<Object>} The endpoint response.
 */
export const requestLiveScore = async ({
	postId,
	keywords,
	seoTitle,
	metaDescription,
	saveScore,
	isSuperseded,
}) => {
	const keywordList = Array.isArray(keywords) ? keywords : [];
	const htmlContent = getLiveContent();

	// These two labels are not display-only: the endpoint applies them over
	// its own analysis and save_score() persists them, so they have to be
	// measured the way the panel and the server measure — block attributes
	// only once the markup itself yields no text. Reading textContent straight
	// off builder markup yields nothing, and `calculateReadabilityScore('')`
	// returns the literal string "No content", so a Divi page with a valid
	// 64-word body saved "No content" into `readability_score` and
	// `content_quality` and showed it in the posts list. #778 guarded the case
	// where `live_content` is empty (Oxygen); this is the same fault for
	// markup that is present but renders to nothing.
	const resolved = resolveEditorContent(htmlContent);
	const readabilityScore = calculateReadabilityScore(resolved.text);
	const contentQuality = calculateContentQuality(
		resolved.text,
		resolved.html
	);

	const response = await apiFetch({
		path: '/thinkrank/v1/seo-score/calculate',
		method: 'POST',
		data: {
			post_id: postId,
			target_keyword: keywordList[0] || '',
			target_keywords: keywordList,
			save_score: saveScore,
			live_content: htmlContent,
			// Score the SEO fields as they currently stand in the editor.
			// Without these the endpoint falls back to the saved meta, so an
			// applied title scored against the old value until the post was
			// saved.
			live_title: seoTitle || '',
			live_description: metaDescription || '',
			readability_score: readabilityScore,
			content_quality: contentQuality,
		},
	});

	if (response && response.success && !isSuperseded()) {
		sessionScores.set(postId, {
			key: fieldsKeyOf(seoTitle, metaDescription),
			keywords: keywordsKeyOf(keywordList),
			data: response.data,
		});
	}

	return response;
};
