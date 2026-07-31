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
        add_filter('rest_pre_dispatch', [$this, 'gate_rest'], 10, 3);
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
