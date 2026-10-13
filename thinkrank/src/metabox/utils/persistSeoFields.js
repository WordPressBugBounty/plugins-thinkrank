/**
 * Persist the metabox SEO fields through admin-ajax.
 *
 * Every editor integration needs the same thing: take the MetaboxApp form state
 * and write it to post meta without touching the editor's own document save.
 * The payload has to mirror the field names `Metabox_Manager::persist_metadata()`
 * reads, which is exactly the set the classic editor submits as hidden inputs.
 *
 * This used to be copy-pasted verbatim into the Oxygen, Divi and Elementor
 * bundles, so a new SEO field meant remembering four separate places. It now
 * lives here and every caller shares it.
 *
 * @package
 * @since 1.25.0
 */

import { __ } from '@wordpress/i18n';

/**
 * Serialize the React form state into the field names the metabox save routine
 * expects (mirrors the classic editor's hidden inputs).
 *
 * @param {Object} formData Current MetaboxApp form state.
 * @return {URLSearchParams} Form-encoded payload.
 */
export function buildSeoPayload(formData) {
	const data = window.thinkrankMetabox || {};
	const params = new URLSearchParams();
	params.append('action', data.saveAction || 'thinkrank_save_metabox');
	params.append('nonce', data.nonce || '');
	params.append('post_id', String(data.postId || ''));

	const str = (v) => (v === undefined || v === null ? '' : String(v));
	const json = (v) => {
		try {
			return JSON.stringify(v ?? null);
		} catch (e) {
			return '';
		}
	};

	params.append('thinkrank_seo_title', str(formData.thinkrank_seo_title));
	params.append(
		'thinkrank_meta_description',
		str(formData.thinkrank_meta_description)
	);
	params.append(
		'thinkrank_focus_keyword',
		str(formData.thinkrank_focus_keyword)
	);
	params.append(
		'thinkrank_focus_keywords',
		json(formData.thinkrank_focus_keywords || [])
	);
	params.append(
		'thinkrank_canonical_url',
		str(formData.thinkrank_canonical_url)
	);
	// Permalink slug — omitting this silently dropped slug edits on every
	// editor that saves through this route (Elementor, Divi, Oxygen). Empty
	// is safe: the server treats it as "keep the current slug".
	params.append('thinkrank_post_slug', str(formData.thinkrank_post_slug));
	params.append(
		'thinkrank_pillar_content',
		formData.thinkrank_pillar_content ? '1' : ''
	);
	params.append(
		'thinkrank_exclude_from_search',
		formData.thinkrank_exclude_from_search ? '1' : '0'
	);
	params.append(
		'thinkrank_exclude_from_archives',
		formData.thinkrank_exclude_from_archives ? '1' : '0'
	);
	// The redirect is only sent when a provider can store it. An absent key
	// tells the server the field was not submitted; an empty one means "remove
	// the redirect", so posting it with Pro deactivated would wipe every rule
	// Pro had stored. The classic editor applies the same rule to its hidden
	// inputs in MetaboxApp.
	if (data.redirectSupported) {
		params.append(
			'thinkrank_redirect_url',
			str(formData.thinkrank_redirect_url)
		);
		params.append(
			'thinkrank_redirect_type',
			str(formData.thinkrank_redirect_type || 301)
		);
	}
	params.append(
		'thinkrank_robots_meta_enabled',
		formData.thinkrank_robots_meta_enabled ? '1' : '0'
	);
	params.append(
		'thinkrank_robots_meta',
		json(formData.thinkrank_robots_meta || {})
	);
	params.append(
		'thinkrank_advanced_robots_meta',
		json(formData.thinkrank_advanced_robots_meta || {})
	);

	[
		'thinkrank_og_title',
		'thinkrank_og_description',
		'thinkrank_og_image',
		'thinkrank_twitter_title',
		'thinkrank_twitter_description',
		'thinkrank_twitter_image',
	].forEach((key) => params.append(key, str(formData[key])));

	return params;
}

/**
 * Write the SEO fields to post meta.
 *
 * Saves SEO metadata ONLY — it never touches the editor's own document save,
 * post status or content. Callers decide how to surface the outcome.
 *
 * @param {Object} formData Current MetaboxApp form state.
 * @return {Promise<{success: boolean, message: string}>} Save outcome.
 */
export async function persistSeoFields(formData) {
	const data = window.thinkrankMetabox || {};
	const failure = __('Failed to save SEO settings.', 'thinkrank');

	try {
		const response = await fetch(data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: buildSeoPayload(formData).toString(),
		});
		const result = await response.json();

		if (result && result.success) {
			return {
				success: true,
				message:
					result.data?.message ||
					__('SEO settings saved successfully!', 'thinkrank'),
			};
		}

		return {
			success: false,
			message: result?.data?.message || failure,
		};
	} catch (e) {
		return { success: false, message: failure };
	}
}
