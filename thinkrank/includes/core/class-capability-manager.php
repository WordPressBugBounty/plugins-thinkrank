<?php

declare(strict_types=1);

namespace ThinkRank\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Capability Manager
 *
 * Single source of truth for ThinkRank's role/capability access control.
 * Defines one capability per admin area (plus a base access cap and a
 * manage-roles cap), maps REST route prefixes to those capabilities, and
 * reads/writes the per-role assignment matrix.
 *
 * Administrators implicitly have every capability via the `manage_options`
 * bypass in {@see Capability_Manager::current_user_can()}, so the matrix
 * never edits the administrator role (no lock-out possible).
 *
 * @since 1.12.0
 */
class Capability_Manager {

    /**
     * Option storing the version that capabilities were last synced at.
     */
    private const VERSION_OPTION = 'thinkrank_caps_version';
    private const VERSION        = '3';

    /**
     * Base capability required to open ThinkRank at all.
     */
    public const ACCESS = 'thinkrank_access';

    /**
     * Capability required to manage the Role Manager itself.
     */
    public const MANAGE_ROLES = 'thinkrank_manage_roles';

    /**
     * Every ThinkRank capability, in the order the Role Manager lists them.
     *
     * Slugs live here rather than as the keys of capabilities() because the two
     * callers want different things and only one of them can afford a
     * translation. grant_admin_caps() runs on `user_has_cap`, which core fires
     * from wp_set_current_user() during wp-settings.php — before `init`, so a
     * __() there is both wasted (it discards the labels) and illegal, and WP
     * 6.7+ answers it with a _load_textdomain_just_in_time notice on every
     * request. Same class of bug as the cron interval labels in #331.
     *
     * capabilities() below builds its labels from this list, so a capability
     * added here cannot go missing from the admin bypass or the Role Manager UI.
     *
     * @since 2.2.0
     */
    private const SLUGS = [
        self::ACCESS,
        'thinkrank_site_identity',
        'thinkrank_analytics',
        'thinkrank_performance',
        'thinkrank_global_seo',
        'thinkrank_image_seo',
        'thinkrank_schema',
        'thinkrank_social_media',
        'thinkrank_crawling',
        'thinkrank_instant_indexing',
        'thinkrank_author_archives',
        'thinkrank_content_tools',
        'thinkrank_ai_insights',
        'thinkrank_internal_links',
        'thinkrank_external_links',
        'thinkrank_redirections',
        'thinkrank_broken_links',
        'thinkrank_woocommerce',
        'thinkrank_settings',
        self::MANAGE_ROLES,
    ];

    /**
     * Capability slugs, with no translation involved.
     *
     * Safe to call at any point in the request, including before `init`.
     *
     * @since 2.2.0
     *
     * @return array<int,string>
     */
    public static function slugs(): array {
        return self::SLUGS;
    }

    /**
     * Capability => label. Keyed by capability slug.
     *
     * Only for user-facing output (the Role Manager matrix). Calling this
     * before `init` triggers a textdomain notice — use slugs() when the labels
     * are not needed.
     *
     * @return array<string,string>
     */
    public static function capabilities(): array {
        $labels = [
            self::ACCESS                  => __('Access ThinkRank', 'thinkrank'),
            'thinkrank_site_identity'     => __('Site Identity', 'thinkrank'),
            'thinkrank_analytics'         => __('Analytics', 'thinkrank'),
            'thinkrank_performance'       => __('Performance', 'thinkrank'),
            'thinkrank_global_seo'        => __('Bulk SEO Optimization', 'thinkrank'),
            'thinkrank_image_seo'         => __('Image SEO', 'thinkrank'),
            'thinkrank_schema'            => __('Schema Manager', 'thinkrank'),
            'thinkrank_social_media'      => __('Social Media', 'thinkrank'),
            'thinkrank_crawling'          => __('Crawling & AI Indexing', 'thinkrank'),
            'thinkrank_instant_indexing'  => __('Instant Indexing', 'thinkrank'),
            'thinkrank_author_archives'   => __('Author Archives', 'thinkrank'),
            'thinkrank_content_tools'     => __('AI Tools', 'thinkrank'),
            'thinkrank_ai_insights'       => __('AI Insights', 'thinkrank'),
            'thinkrank_internal_links'    => __('Internal Links', 'thinkrank'),
            'thinkrank_external_links'    => __('External Links', 'thinkrank'),
            'thinkrank_redirections'      => __('Redirections', 'thinkrank'),
            'thinkrank_broken_links'      => __('Broken Links', 'thinkrank'),
            'thinkrank_woocommerce'       => __('WooCommerce', 'thinkrank'),
            'thinkrank_settings'          => __('Settings & API Keys', 'thinkrank'),
            self::MANAGE_ROLES            => __('Manage Roles', 'thinkrank'),
        ];

        // SLUGS is the source of truth for which capabilities exist; the map
        // above only supplies wording. Ordering by SLUGS means a slug added
        // without a label still appears (labelled by its slug) rather than
        // silently vanishing from the matrix.
        $out = [];
        foreach (self::SLUGS as $slug) {
            $out[$slug] = $labels[$slug] ?? $slug;
        }

        return $out;
    }

    /**
     * Nav section id => required capability. Used by the SPA (localized) and
     * mirrors the route map below.
     *
     * @return array<string,string>
     */
    public static function section_map(): array {
        return [
            'site-identity'        => 'thinkrank_site_identity',
            'analytics'            => 'thinkrank_analytics',
            'performance'          => 'thinkrank_performance',
            'global-seo'           => 'thinkrank_global_seo',
            'image-seo'            => 'thinkrank_image_seo',
            'schema'               => 'thinkrank_schema',
            'social-media'         => 'thinkrank_social_media',
            'crawling-ai-indexing' => 'thinkrank_crawling',
            'instant-indexing'     => 'thinkrank_instant_indexing',
            'author-archives'      => 'thinkrank_author_archives',
            'ai-insights'          => 'thinkrank_ai_insights',
            'internal-links'       => 'thinkrank_internal_links',
            'external-links'       => 'thinkrank_external_links',
            'redirections'         => 'thinkrank_redirections',
            'broken-links'         => 'thinkrank_broken_links',
            'woocommerce'          => 'thinkrank_woocommerce',
            'integrations'         => 'thinkrank_settings',
            'role-manager'         => self::MANAGE_ROLES,
        ];
    }

    /**
     * REST route prefix (first segment after the namespace) => capability.
     *
     * @return array<string,string>
     */
    public static function route_map(): array {
        return [
            'site-identity'     => 'thinkrank_site_identity',
            'seo-analytics'     => 'thinkrank_analytics',
            'analytics'         => 'thinkrank_analytics',
            // Analytics sub-features that register their own Pro route prefixes
            // (rather than nesting under /analytics/) — gate them with the
            // Analytics capability, not the base ACCESS fall-through.
            'rank-tracker'      => 'thinkrank_analytics',
            'keywords'          => 'thinkrank_analytics',
            'email-report'      => 'thinkrank_analytics',
            'top-content'       => 'thinkrank_analytics',
            'url-inspection'    => 'thinkrank_analytics',
            'refresh-radar'     => 'thinkrank_analytics',
            'seo-score'         => 'thinkrank_content_tools',
            'content-brief'     => 'thinkrank_content_tools',
            'pillar-content'    => 'thinkrank_content_tools',
            'ai'                => 'thinkrank_content_tools',
            // /metadata/<id> reads a post's stored SEO meta and belongs to the
            // AI Tools section — gate it with the same capability as the AI
            // generators above (was unmapped, so it fell back to base ACCESS).
            'metadata'          => 'thinkrank_content_tools',
            'performance'       => 'thinkrank_performance',
            'global-seo'        => 'thinkrank_global_seo',
            'global-robot-meta' => 'thinkrank_crawling',
            'image-seo'         => 'thinkrank_image_seo',
            'ai-insights'       => 'thinkrank_ai_insights',
            // Brand Visibility is part of the AI Insights section.
            'brand-visibility'  => 'thinkrank_ai_insights',
            'schema'            => 'thinkrank_schema',
            // Custom Schema (Pro) lives in the Schema Manager section but
            // registers its own /custom-schema/ prefix.
            'custom-schema'     => 'thinkrank_schema',
            // Custom Field Mapping (Pro) also lives in the Schema Manager
            // section and registers its own /field-mapping/ prefix.
            'field-mapping'     => 'thinkrank_schema',
            'social-media'      => 'thinkrank_social_media',
            'social-platforms'  => 'thinkrank_settings',
            'sitemap'           => 'thinkrank_crawling',
            // Publisher Sitemaps (Pro) is part of the Crawling & AI Indexing
            // section but registers its own /publisher-sitemaps/ prefix.
            'publisher-sitemaps' => 'thinkrank_crawling',
            'llms-txt'          => 'thinkrank_crawling',
            'instant-indexing'  => 'thinkrank_instant_indexing',
            'author-archives'   => 'thinkrank_author_archives',
            'internal-links'    => 'thinkrank_internal_links',
            'external-links'    => 'thinkrank_external_links',
            'redirections'      => 'thinkrank_redirections',
            'broken-links'      => 'thinkrank_broken_links',
            'woocommerce'       => 'thinkrank_woocommerce',
            // Multi-location (Pro) is managed inside Site Identity › Business Info.
            'locations'         => 'thinkrank_site_identity',
            'integrations'      => 'thinkrank_settings',
            'settings-management' => 'thinkrank_settings',
            'settings'          => 'thinkrank_settings',
            'role-manager'      => self::MANAGE_ROLES,
        ];
    }

    /**
     * Settings-management category => the section capability that owns it.
     *
     * `/settings-management/category/<category>` is the one cross-section route
     * in the plugin. Every other prefix belongs to exactly one section, so
     * resolving a capability from the first path segment is right for them; here
     * the segment is the same for all thirteen categories and the *category*
     * names whose data is being touched.
     *
     * Mapping the whole prefix to `thinkrank_settings` therefore gave one answer
     * to a question with thirteen. It was too strict for Analytics, whose tab
     * persists through this route and 403'd for a role that had been granted
     * Analytics, and too loose for anyone holding `thinkrank_settings`, who
     * could read every other section's settings here while the direct section
     * routes correctly refused them (#573).
     *
     * Every key of Settings_Manager::$settings_categories must appear below;
     * CapabilityManagerTest pins the two together. An unlisted category falls
     * back to `thinkrank_settings`, which fails closed rather than open.
     *
     * @since 2.1.3
     *
     * @return array<string,string>
     */
    public static function settings_category_map(): array {
        return [
            'seo_analytics'          => 'thinkrank_analytics',
            'social_media'           => 'thinkrank_social_media',
            'sitemap'                => 'thinkrank_crawling',
            'schema_management'      => 'thinkrank_schema',
            'performance_monitoring' => 'thinkrank_performance',
            'site_identity'          => 'thinkrank_site_identity',
            'content_analysis'       => 'thinkrank_content_tools',
            'content_optimization'   => 'thinkrank_content_tools',
            // Plugin-wide configuration with no single owning section.
            'core'                   => 'thinkrank_settings',
            'seo'                    => 'thinkrank_settings',
            'ui'                     => 'thinkrank_settings',
            'integrations'           => 'thinkrank_settings',
            'basic_integrations'     => 'thinkrank_settings',
        ];
    }

    /**
     * The capability owning a settings-management category.
     *
     * @since 2.1.3
     *
     * @param string $category Category key.
     * @return string
     */
    public static function capability_for_settings_category(string $category): string {
        return self::settings_category_map()[$category] ?? 'thinkrank_settings';
    }

    /**
     * Whether the current user has a ThinkRank capability.
     *
     * Administrators (`manage_options`) always pass — this is the lock-out
     * safety net and means the matrix never needs to touch the admin role.
     *
     * @param string $capability Capability slug.
     * @return bool
     */
    public static function current_user_can(string $capability): bool {
        if (current_user_can('manage_options')) {
            return true;
        }
        return current_user_can($capability);
    }

    /**
     * The capability guarding a REST route, or the base access cap when the
     * route's prefix isn't specifically mapped.
     *
     * @param string $route Full REST route (e.g. /thinkrank/v1/schema/...).
     * @return string
     */
    public static function capability_for_route(string $route): string {
        // Settings-management categories resolve by category rather than by
        // prefix — see settings_category_map() for why this one route differs.
        if (preg_match('#/thinkrank(?:-pro)?/v1/settings-management/category/([a-zA-Z0-9_-]+)#', $route, $c)) {
            return self::capability_for_settings_category($c[1]);
        }

        if (!preg_match('#/thinkrank(?:-pro)?/v1/([^/]+)#', $route, $m)) {
            return self::ACCESS;
        }
        return self::route_map()[$m[1]] ?? self::ACCESS;
    }

    /**
     * The list of ThinkRank capabilities the given user holds (for localizing
     * to the SPA). Administrators get the full set.
     *
     * @param int $user_id Optional user id (defaults to current user).
     * @return string[]
     */
    public static function user_capabilities(int $user_id = 0): array {
        $user = $user_id ? get_userdata($user_id) : wp_get_current_user();
        if (!$user || !$user->exists()) {
            return [];
        }
        if (user_can($user, 'manage_options')) {
            return array_keys(self::capabilities());
        }
        return array_values(array_filter(
            array_keys(self::capabilities()),
            static fn($cap) => user_can($user, $cap)
        ));
    }

    /**
     * Editable roles excluding administrator (which always has everything).
     *
     * @return array<string,string> role slug => display name.
     */
    public static function editable_roles(): array {
        // get_editable_roles() lives in wp-admin/includes/user.php, which is not
        // loaded during REST requests — pull it in so this works in any context.
        if (!function_exists('get_editable_roles')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $roles = [];
        foreach (get_editable_roles() as $slug => $role) {
            if ($slug === 'administrator') {
                continue;
            }
            $roles[$slug] = translate_user_role($role['name']);
        }
        return $roles;
    }

    /**
     * The WordPress capability a role needs before a ThinkRank grant does
     * anything.
     *
     * Several endpoints run their own `edit_posts` check on top of the section
     * gate — the plugin acts on posts, and a Subscriber has no business there.
     * That check is not wrong; what was wrong is that the Role Manager modelled
     * only the section gate. Granting an area to a role below this baseline
     * saved, ticked the box and showed the section, while every request still
     * failed, with nothing in the UI to explain why (#576).
     *
     * @since 2.1.3
     */
    public const BASELINE_CAPABILITY = 'edit_posts';

    /**
     * Whether a role can actually act on a ThinkRank grant.
     *
     * @since 2.1.3
     *
     * @param string $slug Role slug.
     * @return bool
     */
    public static function role_meets_baseline(string $slug): bool {
        $role = get_role($slug);

        return $role instanceof \WP_Role && $role->has_cap(self::BASELINE_CAPABILITY);
    }

    /**
     * The current assignment matrix: role slug => [capability slugs it has].
     *
     * @return array<string,string[]>
     */
    public static function get_matrix(): array {
        $caps   = array_keys(self::capabilities());
        $matrix = [];
        foreach (array_keys(self::editable_roles()) as $slug) {
            $role = get_role($slug);
            if (!$role) {
                continue;
            }
            $matrix[$slug] = array_values(array_filter($caps, static fn($cap) => $role->has_cap($cap)));
        }
        return $matrix;
    }

    /**
     * Persist an assignment matrix (role slug => [capability slugs]).
     *
     * The administrator role is never modified. Granting any section cap also
     * grants the base ACCESS cap so the role can open ThinkRank.
     *
     * @param array $matrix role slug => array of capability slugs.
     * @return void
     */
    public static function save_matrix(array $matrix): void {
        $all      = array_keys(self::capabilities());
        $editable = self::editable_roles();

        foreach ($editable as $slug => $name) {
            $role = get_role($slug);
            if (!$role) {
                continue;
            }

            // Only modify roles explicitly present in this request, so a partial
            // save cannot silently strip capabilities from other delegated roles.
            if (!array_key_exists($slug, $matrix)) {
                continue;
            }

            $granted = is_array($matrix[$slug])
                ? array_values(array_intersect($all, array_map('sanitize_key', $matrix[$slug])))
                : [];

            // Any granted section cap implies base access.
            if (!empty(array_diff($granted, [self::ACCESS])) && !in_array(self::ACCESS, $granted, true)) {
                $granted[] = self::ACCESS;
            }

            foreach ($all as $cap) {
                if (in_array($cap, $granted, true)) {
                    $role->add_cap($cap);
                } else {
                    $role->remove_cap($cap);
                }
            }
        }
    }

    /**
     * Ensure the administrator role holds every ThinkRank capability. Runs
     * once per version (and is safe to call on activation).
     *
     * The version option alone is not a sufficient guard: uninstall strips the
     * capabilities from every role but keeps the option unless the user opted
     * into deleting all data, so a reinstall would short-circuit here and leave
     * administrators without {@see self::ACCESS} — locking them out of the admin
     * menu entirely. Verify the capability is actually present before skipping,
     * so a stranded option self-heals on the next request.
     *
     * @return void
     */
    public static function ensure(): void {
        $admin = get_role('administrator');

        if (get_option(self::VERSION_OPTION) === self::VERSION
            && $admin
            && $admin->has_cap(self::ACCESS)
        ) {
            return;
        }

        if ($admin) {
            foreach (array_keys(self::capabilities()) as $cap) {
                $admin->add_cap($cap);
            }
        }
        update_option(self::VERSION_OPTION, self::VERSION, false);

        // add_cap() updates the role, not an already-instantiated WP_User: that
        // object cached its allcaps when it was first built, which on this request
        // happened before `init`. Without rebuilding it, current_user_can() keeps
        // returning false until the next request — long enough for admin_menu to
        // skip every ThinkRank page and hand the user a "not allowed" screen right
        // after activation. Rebuild so the grant takes effect immediately.
        $user = wp_get_current_user();
        if ($user instanceof \WP_User && $user->exists()) {
            $user->get_role_caps();
        }
    }
}
