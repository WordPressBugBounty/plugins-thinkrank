<?php
/**
 * Term SEO fields on the taxonomy term edit screen.
 *
 * @package ThinkRank\Admin
 */

declare(strict_types=1);

namespace ThinkRank\Admin;

use ThinkRank\Frontend\SEO_Manager;
use ThinkRank\SEO\Object_Redirect;
use ThinkRank\SEO\Pattern_Resolver;
use ThinkRank\SEO\Term_Seo_Meta;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Adds a ThinkRank SEO panel to Posts → Categories, Tags, Products →
 * Categories and every other public taxonomy's term edit screen.
 *
 * Everything else about term SEO already existed — storage, frontend output,
 * the abilities, the importers — but there was nowhere in wp-admin to type a
 * value (#885). This is that input surface and nothing more.
 *
 * The panel is a React app rendered inside the term form. Its inputs carry
 * `thinkrank_term_seo[…]` names, so they travel with WordPress's own "Update"
 * submit and are saved here on `edit_term`, through the same
 * Term_Seo_Meta writer the update-term-seo ability uses. There is no REST
 * round-trip to race the form submit. A field the panel never rendered is not
 * in the POST, and an absent key is left alone, so a page that failed to load
 * the script cannot clear anything.
 *
 * @since 2.16.0
 */
class Term_Seo_Fields {
    /**
     * POST key holding the panel's fields.
     */
    public const FIELD = 'thinkrank_term_seo';

    /**
     * POST key holding the nonce.
     */
    public const NONCE_FIELD = 'thinkrank_term_seo_nonce';

    /**
     * Canonical field names the form may submit to Term_Seo_Meta.
     *
     * The focus keyword is left out: nothing reads it for a term, so a field
     * for it would promise analysis the screen does not do.
     */
    private const META_FIELDS = [
        'title',
        'description',
        'canonical_url',
        'robots_meta_enabled',
        'robots_meta',
        'advanced_robots_meta',
        'og_title',
        'og_description',
        'og_image',
        'twitter_title',
        'twitter_description',
        'twitter_image',
    ];

    /**
     * Register hooks.
     *
     * @return void
     */
    public function init(): void {
        // Taxonomies are registered on `init`, so the per-taxonomy form hooks
        // can only be added once they exist.
        add_action('admin_init', [$this, 'register_taxonomy_hooks']);
        add_action('edit_term', [$this, 'save'], 10, 3);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * Taxonomies that get the panel.
     *
     * Every public taxonomy with an admin UI, so product_cat, product_tag and
     * a custom post type's taxonomies are covered without registering each.
     *
     * @return string[]
     */
    public function get_taxonomies(): array {
        $taxonomies = array_values(array_diff(
            array_map('strval', (array) get_taxonomies(['public' => true, 'show_ui' => true], 'names')),
            // Post formats have no term edit screen anyone uses for SEO.
            ['post_format']
        ));

        /**
         * Filter the taxonomies whose term edit screen shows ThinkRank's SEO panel.
         *
         * @since 2.16.0
         *
         * @param string[] $taxonomies Taxonomy names.
         */
        $taxonomies = apply_filters('thinkrank_term_seo_taxonomies', $taxonomies);

        return array_values(array_filter(array_map('strval', (array) $taxonomies)));
    }

    /**
     * Hook the panel into each supported taxonomy's edit form.
     *
     * @return void
     */
    public function register_taxonomy_hooks(): void {
        foreach ($this->get_taxonomies() as $taxonomy) {
            add_action("{$taxonomy}_edit_form", [$this, 'render'], 10, 2);
        }
    }

    /**
     * Print the panel's mount point and nonce inside the term form.
     *
     * @param \WP_Term $term     Term being edited.
     * @param string   $taxonomy Taxonomy name.
     * @return void
     */
    public function render($term, $taxonomy = ''): void {
        if (!$term instanceof \WP_Term || !current_user_can('edit_term', (int) $term->term_id)) {
            return;
        }

        wp_nonce_field(self::nonce_action((int) $term->term_id), self::NONCE_FIELD);

        printf(
            '<div id="thinkrank-term-seo-root" class="thinkrank-term-seo" data-term-id="%d"><p class="description">%s</p></div>',
            (int) $term->term_id,
            esc_html__('Loading ThinkRank SEO…', 'thinkrank')
        );
    }

    /**
     * Save the panel's fields when the term form is submitted.
     *
     * `edit_term` fires before the term cache is cleaned and before
     * `edited_term`, so the sitemap and snippet-index invalidation that listen
     * there see the new values.
     *
     * @param int    $term_id  Term ID.
     * @param int    $tt_id    Term taxonomy ID.
     * @param string $taxonomy Taxonomy name.
     * @return void
     */
    public function save($term_id, $tt_id = 0, $taxonomy = ''): void {
        $term_id = (int) $term_id;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just below.
        if ($term_id <= 0 || !isset($_POST[self::NONCE_FIELD])) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]));
        if (!wp_verify_nonce($nonce, self::nonce_action($term_id))) {
            return;
        }

        if (!current_user_can('edit_term', $term_id)) {
            return;
        }

        if (!in_array((string) $taxonomy, $this->get_taxonomies(), true)) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized by Term_Seo_Meta::write() / Object_Redirect::save().
        $raw = isset($_POST[self::FIELD]) ? wp_unslash($_POST[self::FIELD]) : [];
        if (!is_array($raw)) {
            return;
        }

        $this->save_fields($term_id, $raw);
    }

    /**
     * Persist a submitted field map. Authorization is the caller's.
     *
     * @param int                  $term_id Term ID.
     * @param array<string, mixed> $raw     Field name => unslashed value.
     * @return void
     */
    public function save_fields(int $term_id, array $raw): void {
        // Every field is a string on the wire. An array here (a crafted
        // `thinkrank_term_seo[title][]`) is not a value any field takes.
        $fields = array_filter(
            array_intersect_key($raw, array_flip(self::META_FIELDS)),
            'is_scalar'
        );

        Term_Seo_Meta::write($term_id, $fields);

        $error = $this->save_redirect($term_id, $raw);
        if (null !== $error) {
            $this->remember_redirect_error($error);
        }
    }

    /**
     * Save the redirect field, when the panel rendered it.
     *
     * Mirrors the post metabox: the destination lives in Pro's rules table,
     * so this is the one field that can be refused, and an empty field on a
     * site without the provider is not an error.
     *
     * @param int                  $term_id Term ID.
     * @param array<string, mixed> $raw     Submitted fields.
     * @return \WP_Error|null Why the redirect was not saved.
     */
    private function save_redirect(int $term_id, array $raw): ?\WP_Error {
        if (!array_key_exists('redirect_url', $raw) || !is_scalar($raw['redirect_url'])) {
            return null;
        }

        $url = trim((string) $raw['redirect_url']);

        if (!Object_Redirect::is_supported()) {
            return '' === $url ? null : new \WP_Error(
                'thinkrank_redirect_unsupported',
                __('Redirects require ThinkRank Pro with the Redirections feature active.', 'thinkrank')
            );
        }

        // Unchanged: no write, so an untouched field never rewrites Pro's rule.
        $current = Object_Redirect::get('term', $term_id);
        $type    = array_key_exists('redirect_type', $raw) ? Object_Redirect::normalize_type($raw['redirect_type']) : $current['type'];
        if ($url === $current['url'] && ('' === $url || $type === $current['type'])) {
            return null;
        }

        $result = Object_Redirect::save('term', $term_id, $url, $type);

        return is_wp_error($result) ? $result : null;
    }

    /**
     * Keep a refused redirect's reason for the screen WordPress redirects to.
     *
     * Stored under the metabox's key, so its admin notice reports it.
     *
     * @param \WP_Error $error Failure.
     * @return void
     */
    private function remember_redirect_error(\WP_Error $error): void {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return;
        }

        set_transient(Metabox_Manager::REDIRECT_ERROR_TRANSIENT . $user_id, $error->get_error_message(), MINUTE_IN_SECONDS);
    }

    /**
     * Load the panel on a supported term edit screen.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue($hook): void {
        if ('term.php' !== $hook) {
            return;
        }

        $screen   = function_exists('get_current_screen') ? get_current_screen() : null;
        $taxonomy = $screen && !empty($screen->taxonomy) ? (string) $screen->taxonomy : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen context.
        $term_id = isset($_GET['tag_ID']) ? absint(wp_unslash($_GET['tag_ID'])) : 0;

        if ('' === $taxonomy || $term_id <= 0 || !in_array($taxonomy, $this->get_taxonomies(), true)) {
            return;
        }

        $term = get_term($term_id, $taxonomy);
        if (!$term instanceof \WP_Term || !current_user_can('edit_term', $term_id)) {
            return;
        }

        // The social image fields use the media library picker.
        wp_enqueue_media();

        $asset_file = THINKRANK_PLUGIN_DIR . 'assets/term-seo.asset.php';
        $asset      = file_exists($asset_file) ? include $asset_file : [
            'dependencies' => ['react', 'react-dom', 'wp-element', 'wp-i18n', 'wp-components'],
            'version'      => THINKRANK_VERSION,
        ];

        wp_enqueue_script(
            'thinkrank-term-seo',
            THINKRANK_PLUGIN_URL . 'assets/term-seo.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );
        wp_set_script_translations('thinkrank-term-seo', 'thinkrank');
        wp_localize_script('thinkrank-term-seo', 'thinkrankTermSeo', $this->get_localized_data($term));

        wp_enqueue_style(
            'thinkrank-term-seo',
            THINKRANK_PLUGIN_URL . 'assets/term-seo.css',
            ['wp-components'],
            $asset['version']
        );
    }

    /**
     * Configuration the panel is rendered from.
     *
     * @param \WP_Term $term Term being edited.
     * @return array<string, mixed>
     */
    public function get_localized_data(\WP_Term $term): array {
        $term_id  = (int) $term->term_id;
        $link     = get_term_link($term);
        $redirect = Object_Redirect::get('term', $term_id);

        return [
            'termId'           => $term_id,
            'taxonomy'         => (string) $term->taxonomy,
            'termName'         => (string) $term->name,
            'termUrl'          => is_wp_error($link) ? '' : (string) $link,
            'homeUrl'          => untrailingslashit(home_url()),
            'siteName'         => get_bloginfo('name'),
            'faviconUrl'       => (string) get_site_icon_url(),
            'fieldName'        => self::FIELD,
            'values'           => Term_Seo_Meta::read($term_id),
            'patternVariables' => Pattern_Resolver::term_variables($term_id),
            // What the archive prints while the SEO Title and Meta Description
            // fields are empty, so the search preview shows that instead of
            // the bare term name (#1217).
            'fallbackTitle'       => SEO_Manager::term_archive_fallback_title($term),
            'fallbackDescription' => SEO_Manager::term_archive_fallback_description($term),
            'redirect'         => [
                'supported' => Object_Redirect::is_supported(),
                'url'       => $redirect['url'],
                'type'      => $redirect['type'],
                'types'     => Object_Redirect::TYPES,
            ],
        ];
    }

    /**
     * Nonce action for one term, so a nonce cannot be replayed on another.
     *
     * @param int $term_id Term ID.
     * @return string
     */
    public static function nonce_action(int $term_id): string {
        return 'thinkrank_term_seo_' . $term_id;
    }
}
