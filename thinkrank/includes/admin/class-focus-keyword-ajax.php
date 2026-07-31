<?php
/**
 * Focus Keyword AJAX Handler
 *
 * Handles AJAX requests for inline editing of focus keywords in post list
 *
 * @package ThinkRank\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Focus Keyword AJAX Handler Class
 *
 * Single Responsibility: Handle AJAX operations for focus keyword updates
 *
 * @since 1.0.0
 */
class Focus_Keyword_Ajax {

    /**
     * AJAX action name
     *
     * @var string
     */
    private const AJAX_ACTION = 'thinkrank_update_focus_keyword';

    /**
     * Nonce action name
     *
     * @var string
     */
    private const NONCE_ACTION = 'thinkrank_focus_keyword_nonce';

    /**
     * Initialize AJAX handler
     *
     * @return void
     */
    public function init(): void {
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handle_update_focus_keyword']);
    }

    /**
     * Get nonce action name
     *
     * @return string
     */
    public static function get_nonce_action(): string {
        return self::NONCE_ACTION;
    }

    /**
     * Get AJAX action name
     *
     * @return string
     */
    public static function get_ajax_action(): string {
        return self::AJAX_ACTION;
    }

    /**
     * Handle AJAX request to update focus keyword
     *
     * @return void
     */
    public function handle_update_focus_keyword(): void {
        // Verify nonce
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed. Please refresh the page and try again.', 'thinkrank')
            ], 403);
        }

        // Check user capabilities
        if (!current_user_can('edit_posts')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'thinkrank')
            ], 403);
        }

        // Get and validate post ID
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id) {
            wp_send_json_error([
                'message' => __('Invalid post ID.', 'thinkrank')
            ], 400);
        }

        // Check if user can edit this specific post
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error([
                'message' => __('You do not have permission to edit this post.', 'thinkrank')
            ], 403);
        }

        // The SEO Overview column edits only the PRIMARY focus keyword. Take the
        // new primary from the input (first value if a list is pasted) and keep
        // the existing secondary keywords so inline editing never drops them.
        $raw_input = isset($_POST['focus_keyword']) ? wp_unslash($_POST['focus_keyword']) : '';
        $new_primary = \ThinkRank\SEO\Focus_Keywords::normalize($raw_input, 1);

        $existing = \ThinkRank\SEO\Focus_Keywords::get($post_id);
        $secondary = array_slice($existing, 1);

        // Empty input drops the primary and promotes the next keyword; otherwise
        // the new primary leads, followed by the untouched secondaries.
        $new_list = empty($new_primary)
            ? $secondary
            : array_merge($new_primary, $secondary);

        // Persist via the helper (normalizes + keeps legacy meta in sync, and
        // preserves any gated overflow on free).
        $keywords = \ThinkRank\SEO\Focus_Keywords::save($post_id, $new_list);

        // Only the primary keyword is shown and edited in the column.
        $primary_keyword = $keywords[0] ?? '';

        // Prepare response data
        $response_data = [
            'message' => __('Focus keyword updated successfully.', 'thinkrank'),
            'focus_keyword' => $primary_keyword,
            'display_value' => !empty($keywords) ? $primary_keyword : __('Not Set', 'thinkrank'),
            'has_keyword' => !empty($keywords)
        ];

	    // update post modified time
	    $this->update_post_modified( $post_id );

        /**
         * Filter the AJAX response data for focus keyword update
         *
         * @param array $response_data Response data
         * @param int $post_id Post ID
         * @param string $focus_keyword Updated focus keyword
         */
        $response_data = apply_filters('thinkrank_focus_keyword_ajax_response', $response_data, $post_id, $primary_keyword);

        wp_send_json_success($response_data);
    }

	/**
	 * Force update post_modified and post_modified_gmt timestamps.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	function update_post_modified( $post_id ) {

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return false;
		}

		global $wpdb;

		$now     = current_time( 'mysql' );
		$now_gmt = current_time( 'mysql', true );

		// Update database directly
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct update to set timestamps without triggering save hooks.
		$updated = $wpdb->update(
			$wpdb->posts,
			[
				'post_modified'     => $now,
				'post_modified_gmt' => $now_gmt,
			],
			[ 'ID' => $post_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		// Clear cache so WordPress picks up new values
		clean_post_cache( $post_id );

		return ( $updated !== false );
	}

}

