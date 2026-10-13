/**
 * ThinkRank Metabox Entry Point
 *
 * React-based metabox implementation for SEO optimization
 *
 * @package
 * @since 1.0.0
 */

import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import './styles.scss';
import '../global/base.css';
import '../global/tailwind.css';
import MetaboxApp from './components/MetaboxApp';
import { registerHeaderButton, canPinHeaderButton } from './HeaderButton';

/**
 * Initialize React metabox
 */
const mount = () => {
	// Find metabox container
	const container = document.getElementById('thinkrank-metabox-container');

	if (container) {
		// Get data from global thinkrankMetabox object (localized by PHP)
		const metaboxData = window.thinkrankMetabox || {};

		// Get existing metadata from form fields
		const existingMetadata = {
			title: getFieldValue('thinkrank_seo_title'),
			description: getFieldValue('thinkrank_meta_description'),
			focus_keyword: getFieldValue('thinkrank_focus_keyword'),
			focus_keywords: getFieldValue('thinkrank_focus_keywords'),
			seo_score: getFieldValue('thinkrank_seo_score'),
			generated_at: getFieldValue('thinkrank_generated_at'),
			pillar_content: getFieldValue('thinkrank_pillar_content'),
			exclude_from_search: getFieldValue('thinkrank_exclude_from_search'),
			exclude_from_archives: getFieldValue(
				'thinkrank_exclude_from_archives'
			),
			canonical_url: getFieldValue('thinkrank_canonical_url'),
			redirect_url: getFieldValue('thinkrank_redirect_url'),
			redirect_type: getFieldValue('thinkrank_redirect_type'),
			robots_meta_enabled: getFieldValue('thinkrank_robots_meta_enabled'),
			robots_meta: getFieldValue('thinkrank_robots_meta'),
			advanced_robots_meta: getFieldValue(
				'thinkrank_advanced_robots_meta'
			),
			og_title: getFieldValue('thinkrank_og_title'),
			og_description: getFieldValue('thinkrank_og_description'),
			og_image: getFieldValue('thinkrank_og_image'),
			twitter_title: getFieldValue('thinkrank_twitter_title'),
			twitter_description: getFieldValue('thinkrank_twitter_description'),
			twitter_image: getFieldValue('thinkrank_twitter_image'),
		};

		// Get content preview
		const contentPreview = getFieldValue('thinkrank_content_preview');

		// The uncapped copy the Analysis panel measures. It comes from the
		// localized config rather than a hidden field: the field carries the
		// 4,000-character AI budget, and measuring that froze Word Count on any
		// post whose text the editor cannot read — a builder-built post left
		// with an empty post_content (#778).
		// Passed through as-is, including undefined: an empty string is a real
		// answer ("this post has no body") and must not be turned into a missing
		// value, which the panel treats as "ask contentPreview instead" (#828).
		const contentFull = metaboxData.contentFull;

		// Get post title and home URL
		const postTitle = document.getElementById('title')?.value || '';
		const homeUrl = metaboxData.homeUrl || window.location.origin;

		// The drawer no longer renders its own launcher. In the Block Editor a
		// pinned "Configure SEO" button (registered via the plugin API below)
		// opens it; in the Classic Editor the ThinkRank sidebar metabox button
		// does. Both dispatch the `thinkrank:toggle-seo-drawer` event.
		const isBlockEditor =
			document.body.classList.contains('block-editor-page');

		// Prefer the WordPress-managed pinned slot (plugin API) for the launcher.
		// Fall back to the drawer's own header launcher only if those runtime
		// APIs are missing (e.g. a very old WordPress), so the button never
		// simply disappears.
		const canPin = isBlockEditor && canPinHeaderButton();

		// The drawer renders its own launcher only when we can't pin (block-editor
		// fallback). The Classic Editor always uses its sidebar button.
		const hideLauncher = isBlockEditor ? canPin : true;

		try {
			// Clear the container and render React component using createRoot
			container.innerHTML = '';

			// Create root and render component
			const root = createRoot(container);
			root.render(
				<MetaboxApp
					postId={metaboxData.postId}
					existingMetadata={existingMetadata}
					contentPreview={contentPreview}
					contentFull={contentFull}
					strings={metaboxData.strings || {}}
					postTitle={postTitle}
					homeUrl={homeUrl}
					postModified={metaboxData.postModified}
					linkSuggestionsEnabled={metaboxData.linkSuggestionsEnabled}
					hideLauncher={hideLauncher}
					// This root IS the "ThinkRank SEO" metabox body, so give it
					// visible content — the drawer is fixed-position and would
					// otherwise leave the box empty.
					showPanelCard={true}
				/>
			);

			// Pin the "Configure SEO" launcher into WordPress' managed header
			// slot (stable, unlike the old raw DOM injection). When unavailable,
			// the drawer's own launcher stays (see hideLauncher above).
			if (canPin) {
				registerHeaderButton();
			}
		} catch (error) {
			// Fallback error display for metabox render failure
			container.innerHTML = `
                <div class="notice notice-error">
                    <p>${__('Failed to load ThinkRank metabox. Please refresh the page.', 'thinkrank')}</p>
                </div>
            `;
		}
	}
};

// Optimization plugins can defer this bundle past DOMContentLoaded, and a
// listener added after the event never fires — the metabox would never mount.
if (document.readyState !== 'loading') {
	mount();
} else {
	document.addEventListener('DOMContentLoaded', mount);
}

/**
 * Helper function to get field value
 * @param fieldId
 */
function getFieldValue(fieldId) {
	const field = document.getElementById(fieldId);
	return field ? field.value : '';
}
