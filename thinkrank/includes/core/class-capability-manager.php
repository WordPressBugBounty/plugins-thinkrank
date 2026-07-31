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
    private const VERSION        = '2';

    /**
     * Base capability required to open ThinkRank at all.
     */
    public const ACCESS = 'thinkrank_access';

    /**
     * Capability required to manage the Role Manager itself.
     */
    public const MANAGE_ROLES = 'thinkrank_manage_roles';

    /**
     * Capability => label. Keyed by capability slug.
     *
     * @return array<string,string>
     */
    public static function capabilities(): array {
        return [
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
            'thinkrank_internal_links'    => __('Internal Links', 'thinkrank'),
            'thinkrank_settings'          => __('Settings & API Keys', 'thinkrank'),
            self::MANAGE_ROLES            => __('Manage Roles', 'thinkrank'),
        ];
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
            'internal-links'       => 'thinkrank_internal_links',
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
            'schema'            => 'thinkrank_schema',
            'social-media'      => 'thinkrank_social_media',
            'social-platforms'  => 'thinkrank_settings',
            'sitemap'           => 'thinkrank_crawling',
            'llms-txt'          => 'thinkrank_crawling',
            'instant-indexing'  => 'thinkrank_instant_indexing',
            'author-archives'   => 'thinkrank_author_archives',
            'internal-links'    => 'thinkrank_internal_links',
            'integrations'      => 'thinkrank_settings',
            'settings-management' => 'thinkrank_settings',
            'settings'          => 'thinkrank_settings',
            'role-manager'      => self::MANAGE_ROLES,
        ];
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
