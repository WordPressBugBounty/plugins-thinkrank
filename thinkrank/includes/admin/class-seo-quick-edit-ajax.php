<?php
/**
 * SEO Quick Edit AJAX Handler
 *
 * Backs the Quick Edit SEO modal on post list tables (edit.php): one action
 * loads a post's editable SEO fields, the other persists them and returns the
 * re-rendered SEO Overview column cell so the list updates without a reload.
 *
 * @package ThinkRank\Admin
 * @since 1.28.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO Quick Edit AJAX Handler Class
 *
 * Single Responsibility: serve and persist the Quick Edit SEO modal fields.
 *
 * @since 1.28.0
 */
class Seo_Quick_Edit_Ajax {

    /**
     * AJAX action that returns a post's editable SEO fields.
     *
     * @var string
     */
    private const AJAX_ACTION_GET = 'thinkrank_seo_quick_edit_get';

    /**
     * AJAX action that saves the modal's fields.
     *
     * @var string
     */
    private const AJAX_ACTION_SAVE = 'thinkrank_seo_quick_edit_save';

    /**
     * Nonce action shared by both AJAX actions.
     *
     * @var string
     */
    private const NONCE_ACTION = 'thinkrank_seo_quick_edit_nonce';

    /**
     * Initialize AJAX handlers
     *
     * @return void
     */
    public function init(): void {
        add_action('wp_ajax_' . self::AJAX_ACTION_GET, [$this, 'handle_get']);
        add_action('wp_ajax_' . self::AJAX_ACTION_SAVE, [$this, 'handle_save']);
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
     * Get the "load fields" AJAX action name
     *
     * @return string
     */
    public static function get_ajax_action_get(): string {
        return self::AJAX_ACTION_GET;
    }

    /**
     * Get the "save fields" AJAX action name
     *
     * @return string
     */
    public static function get_ajax_action_save(): string {
        return self::AJAX_ACTION_SAVE;
    }

    /**
     * Nonce + capability gate shared by both handlers.
     *
     * Sends a JSON error response (and exits) on failure; returns the validated
     * post ID on success.
     *
     * @return int Validated post ID.
     */
    private function authorize(): int {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed. Please refresh the page and try again.', 'thinkrank'),
            ], 403);
        }

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !get_post($post_id)) {
            wp_send_json_error([
                'message' => __('Invalid post ID.', 'thinkrank'),
            ], 400);
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error([
                'message' => __('You do not have permission to edit this post.', 'thinkrank'),
            ], 403);
        }

        return $post_id;
    }

    /**
     * Return the modal's field values for a post.
     *
     * Sends both the RAW per-post meta (what the inputs edit) and the EFFECTIVE
     * values resolved through the Global SEO patterns (what the frontend
     * renders when the raw value is empty). The modal shows the effective value
     * as a placeholder, so an empty field reads as "inheriting the pattern"
     * rather than "blank" — and clearing a field genuinely returns the post to
     * pattern inheritance instead of saving an empty override.
     *
     * @return void
     */
    public function handle_get(): void {
        $post_id = $this->authorize();
        $post    = get_post($post_id);

        wp_send_json_success([
            'post_title'            => $post->post_title,
            'seo_title'             => (string) get_post_meta($post_id, '_thinkrank_seo_title', true),
            'meta_description'      => (string) get_post_meta($post_id, '_thinkrank_meta_description', true),
            'effective_title'       => \ThinkRank\SEO\Pattern_Resolver::effective_title($post_id),
            'effective_description' => \ThinkRank\SEO\Pattern_Resolver::effective_description($post_id),
            'focus_keywords'        => \ThinkRank\SEO\Focus_Keywords::get($post_id),
            'keyword_limit'         => \ThinkRank\SEO\Focus_Keywords::limit(),
            'edit_link'             => get_edit_post_link($post_id, 'raw'),
        ]);
    }

    /**
     * Persist the modal's fields and return the re-rendered column cell.
     *
     * Persistence routes through Metabox_Manager::save_seo_fields() — the same
     * sanitization and storage the editor metabox uses — so Quick Edit can
     * never drift from the metabox in how values are written. Only fields
     * present in the request are touched.
     *
     * @return void
     */
    public function handle_save(): void {
        $post_id = $this->authorize();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in authorize()
        $fields = [];

        if (isset($_POST['seo_title'])) {
            $fields['thinkrank_seo_title'] = sanitize_text_field(wp_unslash($_POST['seo_title']));
        }

        if (isset($_POST['meta_description'])) {
            $fields['thinkrank_meta_description'] = sanitize_textarea_field(wp_unslash($_POST['meta_description']));
        }

        if (isset($_POST['focus_keywords'])) {
            // Comma-separated string; Focus_Keywords::save() (via the shared
            // persistence) normalizes, dedupes and applies the plan cap.
            $fields['thinkrank_focus_keyword'] = sanitize_text_field(wp_unslash($_POST['focus_keywords']));
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (empty($fields)) {
            wp_send_json_error([
                'message' => __('Nothing to save.', 'thinkrank'),
            ], 400);
        }

        (new Metabox_Manager())->save_seo_fields($post_id, $fields);

        // Bump post_modified so any stored analysis is treated as stale — the
        // column must re-score against the post as it now stands.
        Focus_Keyword_Ajax::update_post_modified($post_id);

        wp_send_json_success([
            'message'     => __('SEO fields updated.', 'thinkrank'),
            'column_html' => $this->render_column_cell($post_id),
        ]);
    }

    /**
     * Re-render the SEO Overview column cell for a post.
     *
     * The saved title/description change the calculated-score fingerprint, so
     * this render recomputes the score and the returned markup carries the
     * post's fresh state — the JS swaps it into the row in place.
     *
     * @param int $post_id Post ID.
     * @return string Column cell HTML.
     */
    private function render_column_cell(int $post_id): string {
        ob_start();
        (new Post_List_Columns())->render_column_content('thinkrank_seo_overview', $post_id);

        return (string) ob_get_clean();
    }
}
