<?php

/**
 * Post List Columns Class
 * 
 * Handles custom columns in WordPress admin post list tables
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
 * Post List Columns Class
 * 
 * Single Responsibility: Manage custom columns in post list tables
 * 
 * @since 1.0.0
 */
class Post_List_Columns {

    /**
     * Column ID
     * 
     * @var string
     */
    private const COLUMN_ID = 'thinkrank_seo_overview';

    /**
     * Initialize post list columns
     * 
     * @return void
     */
    public function init(): void {
        // Get all public post types
        add_action('admin_init', function () {
            $post_types = $this->get_supported_post_types();

            foreach ($post_types as $post_type) {
                // Add column header
                add_filter("manage_{$post_type}_posts_columns", [$this, 'add_column_header'], 10);

                // Add column content
                add_action("manage_{$post_type}_posts_custom_column", [$this, 'render_column_content'], 10, 2);

                // Make column sortable
                add_filter("manage_edit-{$post_type}_sortable_columns", [$this, 'make_column_sortable'], 10);
            }
        });

        // Handle sorting
        add_action('pre_get_posts', [$this, 'handle_column_sorting']);

        // Enqueue admin styles for column
        add_action('admin_enqueue_scripts', [$this, 'enqueue_column_styles']);
    }

    /**
     * Get supported post types
     * 
     * @return array Array of post type names
     */
    private function get_supported_post_types(): array {
        $post_types = get_post_types(['public' => true], 'names');

        // Remove unnecessary post types
        unset($post_types['attachment']);
        unset($post_types['elementor_library']);
        unset($post_types['oceanwp_library']);
        unset($post_types['ae_global_templates']);
        // Divi library + Theme Builder templates.
        unset($post_types['et_pb_layout']);
        unset($post_types['et_theme_builder']);
        unset($post_types['et_template']);
        unset($post_types['et_header_layout']);
        unset($post_types['et_body_layout']);
        unset($post_types['et_footer_layout']);

        /**
         * Filter supported post types for ThinkRank SEO Overview column
         * 
         * @param array $post_types Array of post type names
         */
        return apply_filters('thinkrank_seo_overview_post_types', $post_types);
    }

    /**
     * Add column header
     * 
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function add_column_header(array $columns): array {
        // Insert column before date column
        $new_columns = [];

        foreach ($columns as $key => $value) {
            if ($key === 'date') {
                $new_columns[self::COLUMN_ID] = __('ThinkRank SEO Overview', 'thinkrank');
            }
            $new_columns[$key] = $value;
        }

        // If date column doesn't exist, add at the end
        if (!isset($columns['date'])) {
            $new_columns[self::COLUMN_ID] = __('ThinkRank SEO Overview', 'thinkrank');
        }

        return $new_columns;
    }

    /**
     * Render column content
     *
     * @param string $column_name Column name
     * @param int $post_id Post ID
     * @return void
     */
    public function render_column_content(string $column_name, int $post_id): void {
        if ($column_name !== self::COLUMN_ID) {
            return;
        }

        // Get SEO data
        $seo_data = $this->get_seo_data($post_id);

        // Render the column content
        $this->render_seo_overview($seo_data, $post_id);
    }

    /**
     * Get SEO data for a post
     *
     * @param int $post_id Post ID
     * @return array SEO data
     */
    private function get_seo_data(int $post_id): array {
        // Get SEO score and metrics from database table
        $seo_metrics = $this->get_seo_metrics_from_database($post_id);

        // Get focus keyword(s). The column shows only the primary keyword, but
        // the inline editor operates on the full comma-separated set so editing
        // never drops the secondary keywords.
        $focus_keywords = \ThinkRank\SEO\Focus_Keywords::get($post_id);
        $focus_keyword = implode(', ', $focus_keywords);
        $focus_keyword_primary = $focus_keywords[0] ?? '';

        // Get the effective meta title/description. When no custom per-post value
        // is set, ThinkRank still renders these from the Bulk SEO Optimization
        // (Global SEO) patterns, so resolve those inherited values here instead of
        // reporting "Not Set" for content that is actually output on the frontend.
        $raw_title = get_post_meta($post_id, '_thinkrank_seo_title', true);
        $meta_title = $raw_title !== ''
            ? \ThinkRank\SEO\Pattern_Resolver::resolve_value($raw_title, $post_id)
            : \ThinkRank\SEO\Pattern_Resolver::title($post_id);

        $raw_description = get_post_meta($post_id, '_thinkrank_meta_description', true);
        $meta_description = $raw_description !== ''
            ? \ThinkRank\SEO\Pattern_Resolver::resolve_value($raw_description, $post_id)
            : \ThinkRank\SEO\Pattern_Resolver::description($post_id);

        // Get content quality and readability from database columns
        $content_quality = $seo_metrics['content_quality'] ?? 'Not Analyzed';
        $readability = $seo_metrics['readability_score'] ?? 'N/A';

        return [
            'seo_score' => $seo_metrics['seo_score'],
            'seo_grade' => $seo_metrics['seo_grade'],
            'content_quality' => $content_quality,
            'readability' => $readability,
            'focus_keyword' => !empty($focus_keyword),
            'focus_keyword_value' => $focus_keyword,
            'focus_keyword_primary' => $focus_keyword_primary,
            'meta_title' => !empty($meta_title),
            'meta_description' => !empty($meta_description),
            'pillar_content' => $this->is_link_suggestions_enabled(get_post_type($post_id)) && get_post_meta($post_id, '_thinkrank_pillar_content', true) === '1',
        ];
    }

    /**
     * Check if link suggestions are enabled for a post type
     * 
     * @param string $post_type Post type
     * @return bool True if enabled
     */
    private function is_link_suggestions_enabled(string $post_type): bool {
        $settings = get_option('thinkrank_global_seo_settings', []);

        if (isset($settings[$post_type]['link_suggestions'])) {
            return (bool) $settings[$post_type]['link_suggestions'];
        }

        return true;
    }

    /**
     * Cached result of the scores-table existence check.
     *
     * The post-list column renders this method once per row; the table schema
     * cannot change mid-request, so the `SHOW TABLES LIKE` probe is memoized for
     * the lifetime of the request instead of re-running per row.
     *
     * @since 1.16.2
     * @var bool|null Null until first resolved, then the boolean result.
     */
    private static ?bool $scores_table_exists = null;

    /**
     * Get SEO metrics from database table
     *
     * @param int $post_id Post ID
     * @return array SEO metrics (seo_score, content_quality, readability_score)
     */
    private function get_seo_metrics_from_database(int $post_id): array {
        global $wpdb;

        $table_name = $wpdb->prefix . 'thinkrank_seo_scores';

        // Default values
        $default_metrics = [
            'seo_score' => null,
            'seo_grade' => null,
            'content_quality' => null,
            'readability_score' => null,
        ];

        // Check if table exists (memoized per request — the column runs this
        // per row and the schema is stable within a single page load).
        if (null === self::$scores_table_exists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SEO score retrieval requires direct database access
            self::$scores_table_exists = (bool) $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table_name
            ));
        }

        if (!self::$scores_table_exists) {
            return $default_metrics;
        }

        // Get the most recent metrics from the database
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SEO score retrieval requires direct database access
        $result = $wpdb->get_row($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is properly escaped
            "SELECT overall_score, grade, content_quality, readability_score FROM `{$table_name}` WHERE post_id = %d ORDER BY created_at DESC LIMIT 1",
            $post_id
        ), ARRAY_A);

        if (!$result) {
            return $default_metrics;
        }

        return [
            'seo_score' => $result['overall_score'] !== null ? intval($result['overall_score']) : null,
            'seo_grade' => $result['grade'] !== null ? $result['grade'] : null,
            'content_quality' => $result['content_quality'],
            'readability_score' => $result['readability_score'],
        ];
    }

    /**
     * Render SEO overview content
     *
     * @param array $seo_data SEO data
     * @param int $post_id Post ID
     * @return void
     */
    private function render_seo_overview(array $seo_data, int $post_id): void {
?>
        <div class="thinkrank-seo-overview">
            <div class="thinkrank-seo-row thinkrank-seo-row-top">
                <?php if ($seo_data['seo_score'] !== null): ?>
                    <div class="thinkrank-seo-badge thinkrank-score-<?php echo esc_attr($this->get_score_class($seo_data['seo_score'])); ?>"
                        title="<?php esc_attr_e('SEO Score', 'thinkrank'); ?>">
                        <?php echo esc_html($seo_data['seo_score']); ?>% <?php echo esc_html($seo_data['seo_grade']); ?>
                    </div>
                <?php else: ?>
                    <div class="thinkrank-seo-badge thinkrank-not-set" title="<?php esc_attr_e('SEO Score', 'thinkrank'); ?>">
                        <?php esc_html_e('N/A', 'thinkrank'); ?>
                    </div>
                <?php endif; ?>

                <div class="thinkrank-seo-separator">•</div>

                <div class="thinkrank-seo-badge thinkrank-status-<?php echo esc_attr(sanitize_html_class(strtolower(str_replace(' ', '-', $seo_data['content_quality'])))); ?>"
                    title="<?php esc_attr_e('Content Quality', 'thinkrank'); ?>">
                    <?php echo esc_html($seo_data['content_quality']); ?>
                </div>

                <?php if ($seo_data['pillar_content']): ?>
                    <div class="thinkrank-seo-separator">•</div>
                    <div class="thinkrank-seo-badge thinkrank-pillar-icon" title="<?php esc_attr_e('Pillar Content', 'thinkrank'); ?>">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M2.5 11V3.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" />
                            <path d="M6 11V3.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" />
                            <path d="M9.5 11V3.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" />
                            <path d="M1.5 3.5H10.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" />
                            <path d="M2 3.5L6 1L10 3.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M1 11H11" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" />
                        </svg>
                    </div>
                <?php endif; ?>
            </div>

            <div class="thinkrank-seo-row thinkrank-seo-row-middle">
                <div class="thinkrank-focus-keyword-item" data-post-id="<?php echo esc_attr($post_id); ?>">
                    <span class="thinkrank-focus-keyword-display <?php echo $seo_data['focus_keyword'] ? 'thinkrank-set' : 'thinkrank-not-set'; ?>"
                        data-keyword="<?php echo esc_attr($seo_data['focus_keyword_primary']); ?>"
                        title="<?php esc_attr_e('Focus Keyword - Click to edit', 'thinkrank'); ?>">
                        <?php echo $seo_data['focus_keyword'] ? esc_html($seo_data['focus_keyword_primary']) : esc_html__('Not Set', 'thinkrank'); ?>
                    </span>
                    <svg class="thinkrank-edit-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M8 2H3.333A1.333 1.333 0 0 0 2 3.333v9.334A1.333 1.333 0 0 0 3.333 14h9.334A1.334 1.334 0 0 0 14 12.667V8" stroke="#A5AAAC" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M12.25 1.75a1.414 1.414 0 1 1 2 2L8.24 9.758a1.334 1.334 0 0 1-.568.336l-1.915.56a.334.334 0 0 1-.414-.413l.56-1.915c.063-.215.18-.41.338-.568l6.008-6.01Z" stroke="#A5AAAC" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <svg class="thinkrank-success-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0Zm4.486 4.515a.688.688 0 0 0-.972 0L6.5 9.528 4.486 7.515a.688.688 0 0 0-.972.972l2.5 2.5a.688.688 0 0 0 .972 0l5.5-5.5a.688.688 0 0 0 0-.972Z" fill="#4451FF" />
                    </svg>
                    <span class="thinkrank-focus-keyword-loading" style="display:none;">
                        <span class="spinner is-active" style="float:none;margin:0;"></span>
                    </span>
                </div>
            </div>

            <div class="thinkrank-seo-row thinkrank-seo-row-bottom">
                <div class="thinkrank-seo-metric" title="<?php esc_attr_e('Readability', 'thinkrank'); ?>">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M6.74999 11.75C6.74999 13.1307 5.63071 14.25 4.24999 14.25C2.86928 14.25 1.75 13.1307 1.75 11.75C1.75 10.3693 2.86928 9.25001 4.24999 9.25001C5.63071 9.25001 6.74999 10.3693 6.74999 11.75Z" stroke="#373D44" stroke-width="1.5" stroke-miterlimit="10" />
                        <path d="M14.25 11.75C14.25 13.1307 13.1307 14.25 11.75 14.25C10.3693 14.25 9.25 13.1307 9.25 11.75C9.25 10.3693 10.3693 9.25001 11.75 9.25001C13.1307 9.25001 14.25 10.3693 14.25 11.75Z" stroke="#373D44" stroke-width="1.5" stroke-miterlimit="10" />
                        <path d="M0 11.75H1.74999" stroke="#373D44" stroke-width="1.5" stroke-miterlimit="10" />
                        <path d="M6.75 11.75H9.24999" stroke="#373D44" stroke-width="1.5" stroke-miterlimit="10" />
                        <path d="M14.25 11.75H16" stroke="#373D44" stroke-width="1.5" stroke-miterlimit="10" />
                        <path d="M3 7.375V3.00001C3 2.30967 3.55965 1.75002 4.25 1.75002H11.75C12.4403 1.75002 13 2.30967 13 3.00001V7.375" stroke="#373D44" stroke-width="1.5" stroke-miterlimit="10" />
                        <path d="M4.875 4.25H11.125" stroke="#373D44" stroke-width="1.5" stroke-miterlimit="10" />
                        <path d="M4.875 6.75H11.125" stroke="#373D44" stroke-width="1.5" stroke-miterlimit="10" />
                    </svg>
                    <span class="thinkrank-metric-value"><?php echo esc_html($seo_data['readability']); ?></span>
                </div>

                <div class="thinkrank-seo-separator">•</div>

                <div class="thinkrank-seo-metric" title="<?php esc_attr_e('Meta Title', 'thinkrank'); ?>">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M10 3.33398H14" stroke="#373D44" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M10 8H14" stroke="#373D44" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M2 12.666H14" stroke="#373D44" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M2 7.99933L4.36867 2.84999C4.39638 2.7947 4.43893 2.74821 4.49156 2.71572C4.54419 2.68322 4.60482 2.66602 4.66667 2.66602C4.72852 2.66602 4.78915 2.68322 4.84177 2.71572C4.8944 2.74821 4.93695 2.7947 4.96467 2.84999L7.33333 7.99933" stroke="#373D44" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M2.61328 6.66602H6.71995" stroke="#373D44" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span class="thinkrank-metric-value"><?php echo $seo_data['meta_title'] ? esc_html__('Set', 'thinkrank') : esc_html__('Not Set', 'thinkrank'); ?></span>
                </div>

                <div class="thinkrank-seo-separator">•</div>

                <div class="thinkrank-seo-metric" title="<?php esc_attr_e('Meta Description', 'thinkrank'); ?>">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M4.0013 14.6673C3.64768 14.6673 3.30854 14.5268 3.05849 14.2768C2.80844 14.0267 2.66797 13.6876 2.66797 13.334V2.66732C2.66797 2.3137 2.80844 1.97456 3.05849 1.72451C3.30854 1.47446 3.64768 1.33399 4.0013 1.33399H9.33464C9.54567 1.33364 9.75469 1.37505 9.94966 1.45583C10.1446 1.53661 10.3217 1.65516 10.4706 1.80465L12.8626 4.19665C13.0125 4.34566 13.1314 4.52288 13.2124 4.71809C13.2934 4.91331 13.335 5.12263 13.3346 5.33399V13.334C13.3346 13.6876 13.1942 14.0267 12.9441 14.2768C12.6941 14.5268 12.3549 14.6673 12.0013 14.6673H4.0013Z" stroke="#373D44" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M6.66536 6H5.33203" stroke="#373D44" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M10.6654 8.66602H5.33203" stroke="#373D44" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M10.6654 11.334H5.33203" stroke="#373D44" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span class="thinkrank-metric-value"><?php echo $seo_data['meta_description'] ? esc_html__('Set', 'thinkrank') : esc_html__('Not Set', 'thinkrank'); ?></span>
                </div>
            </div>
        </div>
<?php
    }

    /**
     * Get score class for styling
     * 
     * @param int $score SEO score
     * @return string CSS class
     */
    private function get_score_class(int $score): string {
        if ($score >= 90) {
            return 'great';
        } elseif ($score >= 70) {
            return 'good';
        } elseif ($score >= 50) {
            return 'medium';
        } else {
            return 'poor';
        }
    }

    /**
     * Make column sortable
     * 
     * @param array $columns Sortable columns
     * @return array Modified sortable columns
     */
    public function make_column_sortable(array $columns): array {
        $columns[self::COLUMN_ID] = 'thinkrank_seo_score';
        return $columns;
    }

    /**
     * Handle column sorting
     * 
     * @param \WP_Query $query WordPress query object
     * @return void
     */
    public function handle_column_sorting(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('orderby') !== 'thinkrank_seo_score') {
            return;
        }

        // Sort on the scores table — the same source the column renders. The
        // `_thinkrank_seo_score` meta cannot be used here: it is written only by
        // the AI analyze flow and reset to 0 on every save, and ordering by
        // meta_key INNER JOINs postmeta, which silently drops every post that
        // lacks the key from the list entirely.
        add_filter('posts_clauses', [$this, 'add_seo_score_order_clauses'], 10, 2);
    }

    /**
     * Order the posts list by each post's latest SEO score.
     *
     * LEFT JOIN so posts that have never been scored still appear (they order as
     * NULL: last when sorting DESC). The subquery collapses the append-only
     * score history to one row per post — the newest by primary key — so posts
     * are never duplicated by the join.
     *
     * @param array     $clauses Query clauses
     * @param \WP_Query $query   Query object
     * @return array Modified clauses
     */
    public function add_seo_score_order_clauses(array $clauses, \WP_Query $query): array {
        global $wpdb;

        // Leave any other query untouched — and leave the filter attached, since
        // the query that asked for this ordering may not be the first to run.
        if ($query->get('orderby') !== 'thinkrank_seo_score') {
            return $clauses;
        }

        // Handled — don't touch anything that runs later in the request.
        remove_filter('posts_clauses', [$this, 'add_seo_score_order_clauses'], 10);

        $table_name = $wpdb->prefix . 'thinkrank_seo_scores';
        $order      = strtoupper((string) $query->get('order')) === 'ASC' ? 'ASC' : 'DESC';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name derives from $wpdb->prefix and $order is whitelisted above
        $clauses['join'] .= " LEFT JOIN (
            SELECT s.post_id, s.overall_score
            FROM `{$table_name}` s
            INNER JOIN (
                SELECT post_id, MAX(id) AS latest_id
                FROM `{$table_name}`
                GROUP BY post_id
            ) latest ON latest.latest_id = s.id
        ) thinkrank_scores ON thinkrank_scores.post_id = {$wpdb->posts}.ID";

        // Tie-break on ID so equal scores keep a stable, deterministic order.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $order is whitelisted above
        $clauses['orderby'] = "thinkrank_scores.overall_score {$order}, {$wpdb->posts}.ID DESC";

        return $clauses;
    }

    /**
     * Enqueue column styles
     *
     * @param string $hook_suffix Current admin page hook
     * @return void
     */
    public function enqueue_column_styles(string $hook_suffix): void {
        // Only load on post list pages (edit.php for all post types)
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'edit') {
            return;
        }

        // Enqueue column styles
        wp_enqueue_style(
            'thinkrank-admin-columns',
            THINKRANK_PLUGIN_URL . 'static/css/admin-columns.css',
            [],
            THINKRANK_VERSION
        );

        // Enqueue inline editing script
        wp_enqueue_script(
            'thinkrank-focus-keyword-inline-edit',
            THINKRANK_PLUGIN_URL . 'assets/focus-keyword-inline-edit.js',
            ['jquery'],
            THINKRANK_VERSION,
            true
        );

        // Localize script with AJAX data
        wp_localize_script(
            'thinkrank-focus-keyword-inline-edit',
            'thinkrankFocusKeyword',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(\ThinkRank\Admin\Focus_Keyword_Ajax::get_nonce_action()),
                'action' => \ThinkRank\Admin\Focus_Keyword_Ajax::get_ajax_action(),
                'i18n' => [
                    'notSet' => __('Not Set', 'thinkrank'),
                    'clickToEdit' => __('Click to edit', 'thinkrank'),
                    'pressEnter' => __('Press Enter to save, Esc to cancel', 'thinkrank'),
                    'saving' => __('Saving...', 'thinkrank'),
                    'saved' => __('Saved!', 'thinkrank'),
                    'error' => __('Error updating focus keyword. Please try again.', 'thinkrank'),
                ]
            ]
        );
    }

}
