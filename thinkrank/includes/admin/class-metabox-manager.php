<?php

/**
 * Meta Box Manager
 * 
 * Handles ThinkRank meta boxes in post/page edit screens
 * 
 * @package ThinkRank\Admin
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

use ThinkRank\AI\Metadata_Generator;
use ThinkRank\AI\SEOScoreCalculator;
use ThinkRank\Core\Settings;
use ThinkRank\Core\Database;
use ThinkRank\Core\Plan_Config;
use ThinkRank\SEO\Focus_Keywords;
use ThinkRank\SEO\Pattern_Resolver;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta Box Manager Class
 * 
 * Single Responsibility: Manage post/page meta boxes
 * 
 * @since 1.0.0
 */
class Metabox_Manager {

    /**
     * Settings instance
     * 
     * @var Settings
     */
    private Settings $settings;

    /**
     * Metadata generator instance
     *
     * @var Metadata_Generator
     */
    private Metadata_Generator $metadata_generator;

    /**
     * SEO Score Calculator instance
     *
     * @var SEOScoreCalculator
     */
    private SEOScoreCalculator $seo_calculator;

    /**
     * Constructor
     *
     * @param Settings|null $settings Settings instance
     * @param Metadata_Generator|null $metadata_generator Metadata generator instance
     * @param SEOScoreCalculator|null $seo_calculator SEO Score Calculator instance
     */
    public function __construct(?Settings $settings = null, ?Metadata_Generator $metadata_generator = null, ?SEOScoreCalculator $seo_calculator = null) {
        $this->settings = $settings ?? Settings::instance();
        $this->metadata_generator = $metadata_generator ?? new Metadata_Generator();
        $this->seo_calculator = $seo_calculator ?? new SEOScoreCalculator(new Database());
    }

    /**
     * Initialize meta box manager
     * 
     * @return void
     */
    public function init(): void {
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta_boxes'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_metabox_scripts']);
        add_action('init', [$this, 'register_meta_fields']);

        // AJAX handlers for meta box functionality
        add_action('wp_ajax_thinkrank_generate_post_metadata', [$this, 'ajax_generate_post_metadata']);

        // Full metabox save used by editors that don't submit the #post form
        // (e.g. the Elementor editor). Persists every metabox field at once.
        add_action('wp_ajax_thinkrank_save_metabox', [$this, 'ajax_save_metabox']);

        // Removed debug hooks
    }

    /**
     * Register meta fields for REST API access
     *
     * @return void
     */
    public function register_meta_fields(): void {
        // Register schema form data meta fields
        register_post_meta('', '_thinkrank_schema_form_data', [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_json_meta_field'],
            'auth_callback' => function () {
                return current_user_can('edit_posts') || current_user_can('edit_pages');
            }
        ]);

        register_post_meta('', '_thinkrank_selected_schema_type', [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'auth_callback' => function () {
                return current_user_can('edit_posts') || current_user_can('edit_pages');
            }
        ]);

        register_post_meta('', '_thinkrank_additional_schemas', [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_json_ld_field'],
            'auth_callback' => function() {
                return current_user_can('edit_posts') || current_user_can('edit_pages');
            }
        ]);

        // SEO meta fields for import support
        $string_meta_fields = [
            '_thinkrank_canonical_url',
            '_thinkrank_og_title',
            '_thinkrank_og_description',
            '_thinkrank_og_image',
            '_thinkrank_twitter_title',
            '_thinkrank_twitter_description',
            '_thinkrank_twitter_image',
            '_thinkrank_imported_from',
        ];

        foreach ($string_meta_fields as $meta_key) {
            register_post_meta('', $meta_key, [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => function () {
                    return current_user_can('edit_posts') || current_user_can('edit_pages');
                }
            ]);
        }

        // Multiple focus keywords (array). The legacy single-value
        // `_thinkrank_focus_keyword` is kept in sync by Focus_Keywords for
        // backward compatibility and registered for REST as a string elsewhere.
        register_post_meta('', Focus_Keywords::META_KEY, [
            'show_in_rest' => [
                'schema' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'single' => true,
            'type' => 'array',
            'sanitize_callback' => function ($value) {
                return Focus_Keywords::normalize($value);
            },
            'auth_callback' => function () {
                return current_user_can('edit_posts') || current_user_can('edit_pages');
            }
        ]);

        register_post_meta('', Focus_Keywords::LEGACY_META_KEY, [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'auth_callback' => function () {
                return current_user_can('edit_posts') || current_user_can('edit_pages');
            }
        ]);

        register_post_meta('', '_thinkrank_robots_meta_enabled', [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'integer',
            'auth_callback' => function () {
                return current_user_can('edit_posts') || current_user_can('edit_pages');
            }
        ]);

        register_post_meta('', '_thinkrank_robots_meta', [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_json_meta_field'],
            'auth_callback' => function () {
                return current_user_can('edit_posts') || current_user_can('edit_pages');
            }
        ]);

        register_post_meta('', '_thinkrank_advanced_robots_meta', [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_json_meta_field'],
            'auth_callback' => function () {
                return current_user_can('edit_posts') || current_user_can('edit_pages');
            }
        ]);

        register_post_meta('', '_thinkrank_primary_category', [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'integer',
            'auth_callback' => function () {
                return current_user_can('edit_posts') || current_user_can('edit_pages');
            }
        ]);
    }

    /**
     * Sanitize JSON meta field data
     *
     * Validates JSON structure and recursively sanitizes all string values
     * to prevent XSS and injection attacks.
     *
     * @param string $value Raw JSON string value
     * @return string Sanitized JSON string or empty string if invalid
     */
    public function sanitize_json_meta_field(string $value): string {
        // Return empty string for non-string values
        if (!is_string($value) || empty($value)) {
            return '';
        }

        // Validate JSON structure
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Invalid JSON - return empty string
            return '';
        }

        // Check for reasonable data size (prevent JSON bombs)
        if (strlen($value) > 50000) { // 50KB limit
            return '';
        }

        // Recursively sanitize all values
        $sanitized = $this->sanitize_json_recursively($decoded);

        // Re-encode as JSON
        $result = wp_json_encode($sanitized);
        return $result !== false ? $result : '';
    }

    /**
     * Recursively sanitize JSON data
     *
     * @param mixed $data Data to sanitize
     * @param int $depth Current recursion depth
     * @return mixed Sanitized data
     */
    private function sanitize_json_recursively($data, int $depth = 0): mixed {
        // Prevent deep recursion attacks
        if ($depth > 10) {
            return null;
        }

        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                $clean_key = sanitize_key($key);
                $sanitized[$clean_key] = $this->sanitize_json_recursively($value, $depth + 1);
            }
            return $sanitized;
        }

        if (is_string($data)) {
            // Sanitize string data to prevent XSS
            return sanitize_textarea_field($data);
        }

        if (is_numeric($data)) {
            return $data;
        }

        if (is_bool($data)) {
            return $data;
        }

        // For any other data type, return null
        return null;
    }

	/**
	 * Sanitize JSON-LD meta field data
	 *
	 * Validates JSON structure, recursively sanitizes all string values,
	 * and preserves @ characters in keys (crucial for JSON-LD).
	 *
	 * @param string $value Raw JSON string value
	 *
	 * @return string Sanitized JSON string or empty string if invalid
	 */
	public function sanitize_json_ld_field( string $value ): string {
		// Return empty string for non-string values
		if ( ! is_string( $value ) || empty( $value ) ) {
			return '';
		}

		// Validate JSON structure
		$decoded = json_decode( $value, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// Invalid JSON - return empty string
			return '';
		}

		// Check for reasonable data size (prevent JSON bombs)
		if ( strlen( $value ) > 200000 ) { // Limit to 200KB for larger schemas
			return '';
		}

		// Recursively sanitize all values
		$sanitized = $this->sanitize_json_ld_recursively( $decoded );

		// Re-encode as JSON
		$result = wp_json_encode( $sanitized );

		return $result !== false ? $result : '';
	}

	/**
	 * Recursively sanitize JSON-LD data
	 *
	 * Similar to sanitize_json_recursively but preserves @ symbol and case in keys.
	 *
	 * @param mixed $data Data to sanitize
	 * @param int $depth Current recursion depth
	 *
	 * @return mixed Sanitized data
	 */
	private function sanitize_json_ld_recursively( $data, int $depth = 0 ): mixed {
		// Prevent deep recursion attacks
		if ( $depth > 10 ) {
			return null;
		}

		if ( is_array( $data ) ) {
			$sanitized = [];
			foreach ( $data as $key => $value ) {
				// Allow alphanumeric, underscore, dash, and @ (crucial for JSON-LD)
				// Also preserve case as JSON-LD keys are case-sensitive
				$clean_key               = preg_replace( '/[^a-zA-Z0-9_\-@]/', '', (string) $key );
				$sanitized[ $clean_key ] = $this->sanitize_json_ld_recursively( $value, $depth + 1 );
			}

			return $sanitized;
		}

		if ( is_string( $data ) ) {
			// Sanitize string data to prevent XSS
			return sanitize_textarea_field( $data );
		}

		if ( is_numeric( $data ) ) {
			return $data;
		}

		if ( is_bool( $data ) ) {
			return $data;
		}

		// For any other data type, return null
		return null;
	}

    // Removed debug methods

    /**
     * Add ThinkRank meta boxes
     *
     * @return void
     */
    public function add_meta_boxes(): void {
        $post_types = $this->get_supported_post_types();

        $is_block_editor = $this->is_block_editor_screen();

        foreach ($post_types as $post_type) {
            add_meta_box(
                'thinkrank-seo-metabox',
                __('ThinkRank SEO', 'thinkrank'),
                [$this, 'render_seo_metabox'],
                $post_type,
                'normal',
                'high'
            );

            // Classic Editor sidebar quick-access widget (mirrors SureRank's
            // "Manage your SEO" sidebar box). Skipped in the Block Editor, which
            // surfaces the full panel below the content instead.
            if (!$is_block_editor) {
                add_meta_box(
                    'thinkrank-seo-sidebar',
                    __('ThinkRank', 'thinkrank'),
                    [$this, 'render_sidebar_meta_box'],
                    $post_type,
                    'side',
                    'high'
                );
            }
        }
    }

    /**
     * Whether the current edit screen is using the Block Editor.
     *
     * @return bool True when the Block Editor is active, false for Classic Editor.
     */
    private function is_block_editor_screen(): bool {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();

        return $screen instanceof \WP_Screen
            && method_exists($screen, 'is_block_editor')
            && $screen->is_block_editor();
    }

    /**
     * Render the Classic Editor sidebar quick-access widget.
     *
     * Shows a short label plus a button that scrolls to (and expands) the full
     * "ThinkRank SEO" panel in the main column.
     *
     * @param \WP_Post $post Post object.
     * @return void
     */
    public function render_sidebar_meta_box(\WP_Post $post): void {
        $box_title = apply_filters('thinkrank_seo_sidebar_box_title', __('Optimize this content for search & AI with ThinkRank.', 'thinkrank'), $post);
        $cta_label = apply_filters('thinkrank_seo_sidebar_cta_label', __('Open ThinkRank SEO', 'thinkrank'), $post);
        ?>
        <div class="thinkrank-classic-sidebar-box">
            <p class="thinkrank-classic-sidebar-box-title"><?php echo esc_html($box_title); ?></p>
            <button
                type="button"
                class="button button-primary thinkrank-classic-sidebar-box-cta"
            >
                <?php echo esc_html($cta_label); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Render SEO meta box
     * 
     * @param \WP_Post $post Post object
     * @return void
     */
    public function render_seo_metabox(\WP_Post $post): void {
        // Add nonce for security
        wp_nonce_field('thinkrank_metabox_nonce', 'thinkrank_metabox_nonce');

        // Get existing metadata
        $existing_metadata = $this->get_post_metadata($post->ID);

        // Get post content for AI analysis
        $content_preview = $this->get_content_preview($post);

        // Render React metabox container with hidden form fields for data
?>
        <div id="thinkrank-metabox-container" class="thinkrank-metabox">

            <!-- Hidden form fields for React to read initial data -->
            <input type="hidden" id="thinkrank_seo_title" name="thinkrank_seo_title" value="<?php echo esc_attr($existing_metadata['title'] ?? ''); ?>" />
            <input type="hidden" id="thinkrank_meta_description" name="thinkrank_meta_description" value="<?php echo esc_attr($existing_metadata['description'] ?? ''); ?>" />
            <input type="hidden" id="thinkrank_focus_keyword" name="thinkrank_focus_keyword" value="<?php echo esc_attr($existing_metadata['focus_keyword'] ?? ''); ?>" />
            <input type="hidden" id="thinkrank_focus_keywords" name="thinkrank_focus_keywords" value="<?php echo esc_attr(wp_json_encode($existing_metadata['focus_keywords'] ?? [])); ?>" />
            <input type="hidden" id="thinkrank_seo_score" name="thinkrank_seo_score" value="<?php echo esc_attr($existing_metadata['seo_score'] ?? '0'); ?>" />
            <input type="hidden" id="thinkrank_generated_at" name="thinkrank_generated_at" value="<?php echo esc_attr($existing_metadata['generated_at'] ?? ''); ?>" />
            <input type="hidden" id="thinkrank_pillar_content" name="thinkrank_pillar_content" value="<?php echo esc_attr($existing_metadata['pillar_content'] ?? ''); ?>" />
            <input type="hidden" id="thinkrank_canonical_url" name="thinkrank_canonical_url" value="<?php echo esc_url($existing_metadata['canonical_url'] ?? ''); ?>" />
            <input type="hidden" id="thinkrank_robots_meta_enabled" name="thinkrank_robots_meta_enabled" value="<?php echo esc_attr((string) ($existing_metadata['robots_meta_enabled'] ?? '0')); ?>" />
            <input type="hidden" id="thinkrank_robots_meta" name="thinkrank_robots_meta" value="<?php echo esc_attr((string) ($existing_metadata['robots_meta'] ?? '')); ?>" />
            <input type="hidden" id="thinkrank_advanced_robots_meta" name="thinkrank_advanced_robots_meta" value="<?php echo esc_attr((string) ($existing_metadata['advanced_robots_meta'] ?? '')); ?>" />
            <input type="hidden" id="thinkrank_og_title" name="thinkrank_og_title" value="<?php echo esc_attr((string) ($existing_metadata['og_title'] ?? '')); ?>" />
            <input type="hidden" id="thinkrank_og_description" name="thinkrank_og_description" value="<?php echo esc_attr((string) ($existing_metadata['og_description'] ?? '')); ?>" />
            <input type="hidden" id="thinkrank_og_image" name="thinkrank_og_image" value="<?php echo esc_url((string) ($existing_metadata['og_image'] ?? '')); ?>" />
            <input type="hidden" id="thinkrank_twitter_title" name="thinkrank_twitter_title" value="<?php echo esc_attr((string) ($existing_metadata['twitter_title'] ?? '')); ?>" />
            <input type="hidden" id="thinkrank_twitter_description" name="thinkrank_twitter_description" value="<?php echo esc_attr((string) ($existing_metadata['twitter_description'] ?? '')); ?>" />
            <input type="hidden" id="thinkrank_twitter_image" name="thinkrank_twitter_image" value="<?php echo esc_url((string) ($existing_metadata['twitter_image'] ?? '')); ?>" />
            <textarea id="thinkrank_content_preview" style="display: none;"><?php echo esc_textarea($content_preview); ?></textarea>
        </div>
<?php

    }

    /**
     * Save meta box data
     *
     * @param int $post_id Post ID
     * @param \WP_Post $post Post object
     * @return void
     */
    public function save_meta_boxes(int $post_id, \WP_Post $post): void {
        // Verify nonce
        if (!isset($_POST['thinkrank_metabox_nonce'])) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['thinkrank_metabox_nonce']));
        if (!wp_verify_nonce($nonce, 'thinkrank_metabox_nonce')) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Skip autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // The classic/block editor submits the metabox fields as part of the
        // #post form, so they arrive (slashed) in $_POST. Hand them straight to
        // the shared persistence routine.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
        $this->persist_metadata($post_id, wp_unslash($_POST));
    }

    /**
     * Persist metabox fields for a post from a form-field-name => value map,
     * reusing the shared metabox persistence (sanitization, JSON encoding,
     * focus-keyword normalization, empty-value deletion).
     *
     * Intended for non-form callers such as the MCP abilities layer. The
     * caller is responsible for authorization; keys use the same
     * `thinkrank_*` field names accepted by the metabox form (e.g.
     * `thinkrank_seo_title`, `thinkrank_meta_description`, `thinkrank_robots_meta`).
     *
     * @param int   $post_id Post to update.
     * @param array $fields  Field name => value map.
     * @return void
     */
    public function save_seo_fields(int $post_id, array $fields): void {
        $this->persist_metadata($post_id, $fields);
    }

    /**
     * Persist all metabox fields for a post from a $_POST-shaped (already
     * unslashed) source array.
     *
     * Shared by `save_meta_boxes()` (classic/block editor form submit) and
     * `ajax_save_metabox()` (editors like Elementor that don't submit the #post
     * form). Nonce/permission checks are the caller's responsibility. Each field
     * is independently sanitized; missing keys are left untouched.
     *
     * @param int   $post_id Post to update.
     * @param array $src     Field name => raw value map (unslashed).
     * @return void
     */
    private function persist_metadata(int $post_id, array $src): void {
        // Save metadata. Focus keywords are handled separately (array meta) via
        // Focus_Keywords below, so they are intentionally absent from this list.
        $fields = [
            'thinkrank_seo_title' => 'sanitize_text_field',
            'thinkrank_meta_description' => 'sanitize_textarea_field',
            'thinkrank_seo_score' => 'absint',
            'thinkrank_generated_at' => 'sanitize_text_field',
            'thinkrank_pillar_content' => 'sanitize_text_field',
        ];

        // Focus keywords: prefer the JSON array field; fall back to the legacy
        // single string. Focus_Keywords::save() normalizes (dedupe, drop empty,
        // cap at MAX) and keeps the legacy single-value meta in sync.
        if (isset($src['thinkrank_focus_keywords'])) {
            $raw_keywords = $src['thinkrank_focus_keywords'];
            $decoded = is_string($raw_keywords) ? json_decode($raw_keywords, true) : $raw_keywords;
            Focus_Keywords::save($post_id, is_array($decoded) ? $decoded : []);
        } elseif (isset($src['thinkrank_focus_keyword'])) {
            Focus_Keywords::save($post_id, $src['thinkrank_focus_keyword']);
        }

        // Update the post slug (post_name) when the metabox permalink field
        // was edited. This touches the WP post itself, not post meta.
        if (isset($src['thinkrank_post_slug'])) {
            $this->maybe_update_slug($post_id, (string) $src['thinkrank_post_slug']);
        }

        // Save canonical URL separately with URL sanitization
        if (isset($src['thinkrank_canonical_url'])) {
            $canonical_url = esc_url_raw((string) $src['thinkrank_canonical_url']);
            if (empty($canonical_url)) {
                delete_post_meta($post_id, '_thinkrank_canonical_url');
            } else {
                update_post_meta($post_id, '_thinkrank_canonical_url', $canonical_url);
            }
        }

        foreach ($fields as $field => $sanitize_callback) {
            if (isset($src[$field])) {
                $value = call_user_func($sanitize_callback, $src[$field]);
                update_post_meta($post_id, "_{$field}", $value);
            }
        }

        $this->save_robots_meta($post_id, $src);
        $this->save_social_meta($post_id, $src);

        // Update last modified timestamp
        update_post_meta($post_id, '_thinkrank_last_updated', current_time('mysql'));
    }

    /**
     * Update the post slug (post_name) from the metabox permalink field.
     *
     * Runs inside the save_post cycle, so wp_update_post() would recurse — a
     * static guard prevents re-entry. WordPress applies wp_unique_post_slug(),
     * so a colliding slug is de-duplicated automatically. Empty input is left
     * alone (WP keeps/auto-generates the slug); auto-drafts and revisions are
     * skipped so we don't fight the editor's own slug generation.
     *
     * @param int    $post_id  Post to update.
     * @param string $raw_slug Desired slug from the metabox.
     * @return void
     */
    private function maybe_update_slug(int $post_id, string $raw_slug): void {
        static $updating = false;
        if ($updating) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || wp_is_post_revision($post_id)) {
            return;
        }

        if (in_array($post->post_status, ['auto-draft', 'trash'], true)) {
            return;
        }

        $desired = sanitize_title($raw_slug);
        if ($desired === '' || $desired === $post->post_name) {
            return;
        }

        $updating = true;
        wp_update_post([
            'ID'        => $post_id,
            'post_name' => $desired,
        ]);
        $updating = false;
    }

    /**
     * Persist per-post Open Graph and Twitter Card overrides.
     *
     * Empty values delete the meta entry so the frontend falls back to the
     * SEO title / meta description / featured image chain.
     */
    private function save_social_meta(int $post_id, array $src): void {
        $text_fields = [
            'thinkrank_og_title'            => '_thinkrank_og_title',
            'thinkrank_og_description'      => '_thinkrank_og_description',
            'thinkrank_twitter_title'       => '_thinkrank_twitter_title',
            'thinkrank_twitter_description' => '_thinkrank_twitter_description',
        ];
        foreach ($text_fields as $field => $meta_key) {
            if (!isset($src[$field])) {
                continue;
            }
            $value = sanitize_textarea_field((string) $src[$field]);
            if ($value === '') {
                delete_post_meta($post_id, $meta_key);
            } else {
                update_post_meta($post_id, $meta_key, $value);
            }
        }

        $url_fields = [
            'thinkrank_og_image'      => '_thinkrank_og_image',
            'thinkrank_twitter_image' => '_thinkrank_twitter_image',
        ];
        foreach ($url_fields as $field => $meta_key) {
            if (!isset($src[$field])) {
                continue;
            }
            $value = esc_url_raw((string) $src[$field]);
            if ($value === '') {
                delete_post_meta($post_id, $meta_key);
            } else {
                update_post_meta($post_id, $meta_key, $value);
            }
        }
    }

    /**
     * Save per-post robots meta and advanced robots meta from the metabox.
     *
     * Stores the robots payloads as JSON-encoded strings, sanitized via
     * sanitize_json_meta_field. The toggle plus the two JSON blobs are the
     * single source of truth for per-post robots overrides.
     */
    private function save_robots_meta(int $post_id, array $src): void {
        if (!isset($src['thinkrank_robots_meta_enabled'])) {
            return;
        }

        $enabled = (int) (bool) $src['thinkrank_robots_meta_enabled'];
        update_post_meta($post_id, '_thinkrank_robots_meta_enabled', $enabled);

        if (isset($src['thinkrank_robots_meta'])) {
            update_post_meta($post_id, '_thinkrank_robots_meta', $this->sanitize_json_meta_field((string) $src['thinkrank_robots_meta']));
        }

        if (isset($src['thinkrank_advanced_robots_meta'])) {
            update_post_meta($post_id, '_thinkrank_advanced_robots_meta', $this->sanitize_json_meta_field((string) $src['thinkrank_advanced_robots_meta']));
        }
    }


    /**
     * Enqueue meta box scripts
     *
     * @param string $hook Current admin page hook
     * @return void
     */
    public function enqueue_metabox_scripts(string $hook): void {
        // Only load on post edit screens (including block editor)
        if (!in_array($hook, ['post.php', 'post-new.php'])) {
            return;
        }

        // Get current post type - handle both classic and block editor contexts
        $current_post_type = $this->get_current_post_type();
        if (!$current_post_type || !in_array($current_post_type, $this->get_supported_post_types())) {
            return;
        }

        // Get post object for additional data
        global $post;

        // Ensure wp.media is available for the social-image media picker.
        wp_enqueue_media();

        // No chunk dependencies needed - all bundled into main metabox.js
        // Enqueue React metabox script with direct dependencies
        $asset_file = THINKRANK_PLUGIN_DIR . 'assets/metabox.asset.php';
        $asset = file_exists($asset_file) ? include $asset_file : [
            'dependencies' => ['react', 'wp-element', 'wp-i18n', 'wp-api-fetch', 'wp-components'],
            'version' => THINKRANK_VERSION
        ];

        // Use dependencies directly from the asset file. The pinned "Configure
        // SEO" launcher needs wp-plugins (already listed by the build) and
        // resolves PinnedItems from wp.editor / wp.interface at runtime, so we do
        // NOT add wp-interface here: it is not a registered script handle on
        // WP 7.x, and an unmet dependency would drop the whole metabox script.
        $dependencies = $asset['dependencies'];

        wp_enqueue_script(
            'thinkrank-metabox',
            THINKRANK_PLUGIN_URL . 'assets/metabox.js',
            $dependencies,
            $asset['version'],
            true
        );

        // Localize script data (shared builder; reused by the Elementor editor
        // integration, which has no #post form / hidden inputs of its own).
        wp_localize_script('thinkrank-metabox', 'thinkrankMetabox', $this->get_localized_data($post->ID));

        // Add defer attribute for non-blocking script loading
        wp_script_add_data('thinkrank-metabox', 'defer', true);

        // Enqueue metabox styles
        wp_enqueue_style(
            'thinkrank-metabox',
            THINKRANK_PLUGIN_URL . 'assets/metabox.css',
            ['wp-components'],
            THINKRANK_VERSION
        );

        // Classic Editor sidebar widget: lightweight styles + a vanilla-JS
        // handler so the "Optimize Here" button works without depending on the
        // React bundle. The box itself is only registered in the Classic Editor.
        $sidebar_css = <<<'CSS'
.thinkrank-classic-sidebar-box-title{margin:0 0 12px;font-size:13px;line-height:1.5;color:#1e1e1e;}
.thinkrank-classic-sidebar-box-cta{width:100%;text-align:center;justify-content:center;}
CSS;
        wp_add_inline_style('thinkrank-metabox', $sidebar_css);

        $sidebar_js = <<<'JS'
(function(){
    document.addEventListener('click', function(e){
        var btn = e.target.closest && e.target.closest('.thinkrank-classic-sidebar-box-cta');
        if(!btn){return;}
        e.preventDefault();
        // Open the ThinkRank SEO drawer mounted by the React metabox app.
        window.dispatchEvent(new CustomEvent('thinkrank:toggle-seo-drawer'));
    });
})();
JS;
        wp_add_inline_script('thinkrank-metabox', $sidebar_js);
    }

    /**
     * Build the data object localized into the metabox script (`thinkrankMetabox`).
     *
     * Extracted so the Elementor editor integration can reuse the exact same
     * configuration. Callers that run outside the #post form (Elementor) also
     * read `existingMetadata`/`contentPreview`, which they add on top of this.
     *
     * @param int $post_id Post being edited.
     * @return array Localized config consumed by the React metabox.
     */
    public function get_localized_data(int $post_id): array {
        $post = get_post($post_id);
        $post_type = $post ? $post->post_type : 'post';

        // Resolve the correct REST API base for the current post type.
        // Falls back to the post type slug for any type without a rest_base.
        $post_type_obj  = get_post_type_object($post_type);
        $post_rest_base = ($post_type_obj && !empty($post_type_obj->rest_base))
            ? $post_type_obj->rest_base
            : $post_type;

        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('thinkrank_metabox_ajax'),
            'postId' => $post_id,
            'postType' => $post_type,
            'postRestBase' => $post_rest_base,
            'postPermalink' => get_permalink($post_id),
            'postSlug' => $post ? $post->post_name : '',
            'restUrl' => rest_url('thinkrank/v1/'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'homeUrl' => home_url(),
            'siteName' => get_bloginfo('name'),
            'faviconUrl' => $this->get_site_favicon_url(),
            'featuredImageUrl' => $this->get_post_featured_image_url($post_id),
            'strings' => [
                'generating' => __('Generating...', 'thinkrank'),
                'analyzing' => __('Analyzing...', 'thinkrank'),
                'error' => __('Error occurred', 'thinkrank'),
                'success' => __('Success!', 'thinkrank'),
                'generated' => __('Metadata generated successfully', 'thinkrank'),
                'contentTooShort' => __('Please add some content before generating SEO metadata.', 'thinkrank'),
                'apiError' => __('Failed to connect to AI service. Please check your API settings.', 'thinkrank'),
            ],
            'seoScore' => $this->get_persisted_seo_score($post_id),
            'postModified' => $post ? get_the_modified_date('c', $post) : '',
            'linkSuggestionsEnabled' => $this->is_link_suggestions_enabled($post_type),
            'postStatus' => get_post_status($post_id),
            'isPro' => Plan_Config::is_pro(),
            // Whether any AI provider API key is configured — gates the
            // "Generate with AI" button in the metabox
            'aiConfigured' => !empty($this->settings->get('openai_api_key', ''))
                || !empty($this->settings->get('claude_api_key', ''))
                || !empty($this->settings->get('gemini_api_key', ''))
                || !empty($this->settings->get('openrouter_api_key', '')),
            // Focus keywords plan limits (max_keywords; 0 = unlimited).
            'focusKeywords' => Plan_Config::focus_keywords(),
            // Resolved Global/Bulk SEO variable-tag patterns for this post, shown
            // as placeholder previews when a field is empty (the frontend applies
            // these same patterns on output). Typing a value overrides them.
            'patternPreviews' => Pattern_Resolver::previews($post_id),
            // Token => value map (keys without %), for live client-side preview of
            // a custom pattern typed into a metabox field.
            'patternVariables' => Pattern_Resolver::variables($post_id),
        ];
    }

    /**
     * Latest persisted SEO score for a post.
     *
     * Read from the scores table — the same source the posts list column and the
     * Analysis panel use — so the editor badge agrees with them on load rather
     * than showing 0 until the Analysis panel mounts and fetches the score.
     *
     * The `thinkrank_seo_score` form field is unusable for this: it is only
     * written by the AI "Analyze Content" flow and is reset to 0 on every save.
     *
     * @param int $post_id Post ID
     * @return int|null Score, or null when the post has never been analyzed.
     */
    private function get_persisted_seo_score(int $post_id): ?int {
        $existing = $this->seo_calculator->get_existing_score_data($post_id);
        $score    = $existing['overall_score'] ?? null;

        return $score !== null ? (int) $score : null;
    }

    /**
     * Check if link suggestions are enabled for a post type
     * 
     * @param string $post_type Post type to check
     * @return bool True if enabled, false otherwise
     */
    private function is_link_suggestions_enabled(string $post_type): bool {
        $settings = get_option('thinkrank_global_seo_settings', []);

        if (isset($settings[$post_type]['link_suggestions'])) {
            return (bool) $settings[$post_type]['link_suggestions'];
        }

        return true;
    }

    /**
     * Get current post type in admin context
     *
     * Handles both classic editor and block editor contexts
     *
     * @return string|null Current post type or null if not found
     */
    private function get_current_post_type(): ?string {
        global $post, $typenow, $current_screen;

        // Try to get post type from various sources
        if ($post && !empty($post->post_type)) {
            return $post->post_type;
        }

        if (!empty($typenow)) {
            return $typenow;
        }

        if ($current_screen && !empty($current_screen->post_type)) {
            return $current_screen->post_type;
        }

        // Fallback: check URL parameters for block editor
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL parameters for context determination, not processing form data
        if (isset($_GET['post_type'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL parameters for context determination, not processing form data
            return sanitize_text_field(wp_unslash($_GET['post_type']));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL parameters for context determination, not processing form data
        if (isset($_GET['post'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL parameters for context determination, not processing form data
            $post_id = absint($_GET['post']);
            $post_type = get_post_type($post_id);
            if ($post_type) {
                return $post_type;
            }
        }

        return null;
    }

    /**
     * Get supported post types
     *
     * @return array Supported post types
     */
    public function get_supported_post_types(): array {
        $default_types = ['post', 'page'];

        // Add WooCommerce product if available
        if (class_exists('WooCommerce')) {
            $default_types[] = 'product';
        }

        // Add other common e-commerce post types
        $ecommerce_types = ['product', 'shop_order', 'shop_coupon'];
        foreach ($ecommerce_types as $type) {
            if (post_type_exists($type) && !in_array($type, $default_types)) {
                $default_types[] = $type;
            }
        }

        // Add custom post types that are public and have UI
        $custom_post_types = get_post_types([
            'public' => true,
            'show_ui' => true,
            '_builtin' => false,
        ]);

        foreach ($custom_post_types as $post_type) {
            // Skip certain post types that shouldn't have SEO metabox
            $excluded_types = [
                'attachment',
                'revision',
                'nav_menu_item',
                'custom_css',
                'customize_changeset',
                'oembed_cache',
                'user_request',
                'wp_block',
                'wp_template',
                'wp_template_part',
                'wp_global_styles',
                'wp_navigation',
                'acf-field',
                'acf-field-group',
            ];

            if (!in_array($post_type, $excluded_types) && !in_array($post_type, $default_types)) {
                $default_types[] = $post_type;
            }
        }

        return apply_filters('thinkrank_supported_post_types', $default_types);
    }

    /**
     * Get existing post metadata
     *
     * @param int $post_id Post ID
     * @return array Existing metadata
     */
    public function get_post_metadata(int $post_id): array {
        return [
            'title' => get_post_meta($post_id, '_thinkrank_seo_title', true),
            'description' => get_post_meta($post_id, '_thinkrank_meta_description', true),
            'focus_keyword' => Focus_Keywords::get_primary($post_id),
            'focus_keywords' => Focus_Keywords::get($post_id),
            'seo_score' => get_post_meta($post_id, '_thinkrank_seo_score', true),
            'generated_at' => get_post_meta($post_id, '_thinkrank_generated_at', true),
            'pillar_content' => get_post_meta($post_id, '_thinkrank_pillar_content', true),
            'canonical_url' => get_post_meta($post_id, '_thinkrank_canonical_url', true),
            'robots_meta_enabled' => get_post_meta($post_id, '_thinkrank_robots_meta_enabled', true),
            'robots_meta' => get_post_meta($post_id, '_thinkrank_robots_meta', true),
            'advanced_robots_meta' => get_post_meta($post_id, '_thinkrank_advanced_robots_meta', true),
            'og_title' => get_post_meta($post_id, '_thinkrank_og_title', true),
            'og_description' => get_post_meta($post_id, '_thinkrank_og_description', true),
            'og_image' => get_post_meta($post_id, '_thinkrank_og_image', true),
            'twitter_title' => get_post_meta($post_id, '_thinkrank_twitter_title', true),
            'twitter_description' => get_post_meta($post_id, '_thinkrank_twitter_description', true),
            'twitter_image' => get_post_meta($post_id, '_thinkrank_twitter_image', true),
        ];
    }

    /**
     * Get site favicon URL
     *
     * Prefers WordPress' native Site Icon (zero HTTP). Only when no Site Icon is
     * configured does it probe common favicon locations — and that probe is
     * cached (including a "no favicon" sentinel) so the editor never fires
     * blocking loopback HEAD requests on every load.
     *
     * @since 1.16.2 Prefer get_site_icon_url() and cache the fallback probe.
     * @return string Favicon URL, or '' when none can be resolved.
     */
    private function get_site_favicon_url(): string {
        // Try the native Site Icon first (WordPress 4.3+) — no HTTP required.
        $site_icon_url = get_site_icon_url();
        if ($site_icon_url) {
            return $site_icon_url;
        }

        // No Site Icon configured: fall back to probing common favicon paths.
        // Cache the resolved value (empty string included) so the blocking
        // loopback probe runs at most once per ~12h per site host instead of on
        // every editor load.
        $cache_key = 'thinkrank_favicon_url_' . md5((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $cached    = get_transient($cache_key);
        if (false !== $cached) {
            return (string) $cached;
        }

        $favicon_url = '';

        $favicon_paths = [
            '/favicon.ico',
            '/favicon.png',
            '/apple-touch-icon.png',
        ];

        foreach ($favicon_paths as $path) {
            $candidate = home_url($path);
            $response  = wp_remote_head($candidate, ['timeout' => 3]);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $favicon_url = $candidate;
                break;
            }
        }

        set_transient($cache_key, $favicon_url, 12 * HOUR_IN_SECONDS);

        return $favicon_url;
    }

    /**
     * Get post featured image URL
     *
     * @param int $post_id Post ID
     * @return string|null Featured image URL or null if not available
     */
    private function get_post_featured_image_url(int $post_id): ?string {
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            // Use the full-size image — social share images need a large source
            // (the preview scales it down), not a small thumbnail.
            $image_url = wp_get_attachment_image_url($thumbnail_id, 'full');
            return $image_url ?: null;
        }
        return null;
    }

    /**
     * Get content preview for AI analysis
     * 
     * @param \WP_Post $post Post object
     * @return string Content preview
     */
    public function get_content_preview(\WP_Post $post): string {
        $content = $post->post_title . "\n\n";

        if (!empty($post->post_excerpt)) {
            $content .= $post->post_excerpt . "\n\n";
        }

        // Resolve through Builder_Content: a page builder keeps its words
        // outside post_content (Oxygen empties it entirely), and the editor
        // cannot reach postmeta, so without this the preview handed to the
        // browser is just the title and the live panel reports "No content".
        if (!class_exists('\\ThinkRank\\SEO\\Builder_Content')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/seo/class-builder-content.php';
        }
        $content .= \ThinkRank\SEO\Builder_Content::resolve($post);

        // Clean and limit content
        $content = wp_strip_all_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);

        return trim(substr($content, 0, 4000));
    }

    /**
     * AJAX handler for generating post metadata
     * 
     * @return void
     */
    public function ajax_generate_post_metadata(): void {
        // Verify nonce
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'thinkrank_metabox_ajax')) {
            wp_die('Security check failed');
        }

        // Check permissions
        $post_id = absint($_POST['post_id'] ?? 0);
        if (!current_user_can('edit_post', $post_id)) {
            wp_die('Insufficient permissions');
        }

        try {
            $options = [
                'target_keyword' => sanitize_text_field(wp_unslash($_POST['target_keyword'] ?? '')),
                'content_type' => sanitize_text_field(wp_unslash($_POST['content_type'] ?? 'blog_post')),
                'tone' => sanitize_text_field(wp_unslash($_POST['tone'] ?? 'professional')),
            ];

            $metadata = $this->metadata_generator->generate_for_post($post_id, $options);

            wp_send_json_success([
                'metadata' => $metadata,
                'message' => __('SEO metadata generated successfully!', 'thinkrank'),
            ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX: persist the full set of metabox fields for a post.
     *
     * Used by editors that don't submit the #post form (Elementor). Expects the
     * same field names the classic/block metabox submits, sent as POST params.
     * $_POST is slashed by WordPress, matching what `persist_metadata()` expects.
     *
     * @return void
     */
    public function ajax_save_metabox(): void {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'thinkrank_metabox_ajax')) {
            wp_send_json_error(['message' => __('Security check failed', 'thinkrank')], 403);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'thinkrank')], 403);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; each field sanitized inside persist_metadata()
        $this->persist_metadata($post_id, wp_unslash($_POST));

        wp_send_json_success([
            'message' => __('SEO settings saved successfully!', 'thinkrank'),
        ]);
    }
}
