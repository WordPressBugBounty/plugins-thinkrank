<?php

declare(strict_types=1);

namespace ThinkRank\Core;

use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Role Manager
 *
 * Wires up ThinkRank's role-based access control:
 *  - keeps the administrator role's capabilities in sync;
 *  - centrally guards the whole `/thinkrank/v1/` REST namespace via a single
 *    `rest_pre_dispatch` filter (route prefix → capability), so no per-endpoint
 *    permission callback needs to change.
 *
 * Menu-access gating (the admin page capability) and the SPA nav filtering are
 * handled by the Admin manager and the React app respectively, both reading
 * from {@see Capability_Manager}.
 *
 * @since 1.12.0
 */
class Role_Manager {

    /**
     * Initialize hooks.
     *
     * @return void
     */
    public function init(): void {
        add_action('init', [Capability_Manager::class, 'ensure']);
        add_filter('user_has_cap', [$this, 'grant_admin_caps'], 10, 2);
        add_filter('rest_pre_dispatch', [$this, 'gate_rest'], 10, 3);
    }

    /**
     * Give every ThinkRank capability to anyone who can `manage_options`.
     *
     * The bypass already existed, but only where the plugin could see it.
     * Capability_Manager::current_user_can() is a plugin static, invisible to
     * WordPress core — and add_menu_page() evaluates its capability argument
     * with core's current_user_can(). Meanwhile Capability_Manager::ensure()
     * grants the capabilities to the `administrator` role by name.
     *
     * So a user holding manage_options through some other role — a multisite
     * super admin, or a custom admin role from Members / User Role Editor —
     * passed every REST check and saw no ThinkRank menu at all: the plugin was
     * invisible in wp-admin while fully reachable over the API (#575).
     * Expressing the bypass as a core filter closes that split and makes the
     * explicit check in Capability_Manager belt-and-braces rather than the only
     * path.
     *
     * Uses `+` rather than array_merge: an entry already present keeps its
     * value, so an explicit denial elsewhere is not resurrected here.
     *
     * @since 2.1.3
     *
     * @param array $allcaps Capabilities the user holds.
     * @param array $caps    Capabilities being checked (unused).
     * @return array
     */
    public function grant_admin_caps(array $allcaps, array $caps): array {
        if (empty($allcaps['manage_options'])) {
            return $allcaps;
        }

        // Built once per request. Uses slugs() rather than capabilities():
        // core fires this filter from wp_set_current_user() during
        // wp-settings.php, before `init`, so the __() calls behind the labelled
        // map are both wasted (only the keys are used) and early enough to earn
        // a _load_textdomain_just_in_time notice on every request (#580).
        static $thinkrank_caps = null;
        if (null === $thinkrank_caps) {
            $thinkrank_caps = array_fill_keys(
                Capability_Manager::slugs(),
                true
            );
        }

        return $allcaps + $thinkrank_caps;
    }

    /**
     * Central capability gate for all ThinkRank REST routes.
     *
     * @param mixed            $result  Existing short-circuit result (or null).
     * @param \WP_REST_Server  $server  REST server.
     * @param \WP_REST_Request $request The request.
     * @return mixed Null/array to proceed, or WP_Error to block.
     */
    public function gate_rest($result, $server, $request) {
        // Respect an earlier short-circuit.
        if (null !== $result) {
            return $result;
        }

        $route = (string) $request->get_route();
        // Gate both the free (/thinkrank/v1/) and Pro (/thinkrank-pro/v1/)
        // namespaces so the Role Manager governs Pro sections too — otherwise the
        // whole Pro namespace bypasses the capability gate.
        if (strpos($route, '/thinkrank/v1/') !== 0 && strpos($route, '/thinkrank-pro/v1/') !== 0) {
            return $result;
        }

        // MCP + OAuth routes authenticate INSIDE their handlers (Bearer token /
        // OAuth access token — server-to-server calls with no logged-in user),
        // so the namespace-wide capability gate must not touch them. The MCP
        // management routes (/mcp/connection, /mcp/connect, …) stay gated. See
        // ThinkRank\Mcp\Mcp_Manager.
        if ('/thinkrank/v1/mcp' === $route || strpos($route, '/thinkrank/v1/mcp/oauth/') === 0) {
            return $result;
        }

        if (!Capability_Manager::current_user_can(Capability_Manager::ACCESS)) {
            return new WP_Error(
                'thinkrank_forbidden',
                __('You do not have permission to access ThinkRank.', 'thinkrank'),
                ['status' => rest_authorization_required_code()]
            );
        }

        $capability = Capability_Manager::capability_for_route($route);
        if ($capability !== Capability_Manager::ACCESS && !Capability_Manager::current_user_can($capability)) {
            return new WP_Error(
                'thinkrank_forbidden_section',
                __('You do not have permission to access this ThinkRank section.', 'thinkrank'),
                ['status' => rest_authorization_required_code()]
            );
        }

        return $result;
    }
}
