<?php

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\Core\Capability_Manager;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Role Manager Endpoint
 *
 * REST API for the role × capability matrix: read the roles, capabilities and
 * current assignments, and save updated assignments.
 *
 * @since 1.12.0
 */
class Role_Manager_Endpoint {

    /**
     * REST namespace.
     *
     * @var string
     */
    private string $namespace = 'thinkrank/v1';

    /**
     * Permission callback — only those who can manage roles.
     *
     * @return bool
     */
    public function check_permissions(): bool {
        return Capability_Manager::current_user_can(Capability_Manager::MANAGE_ROLES);
    }

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route($this->namespace, '/role-manager', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_data'],
                'permission_callback' => [$this, 'check_permissions'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'save_matrix'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'matrix' => ['type' => 'object', 'required' => true],
                ],
            ],
        ]);
    }

    /**
     * Return roles, capabilities, and the current matrix.
     *
     * @return WP_REST_Response
     */
    public function get_data(): WP_REST_Response {
        $roles = [];
        foreach (Capability_Manager::editable_roles() as $slug => $name) {
            $roles[] = ['slug' => $slug, 'name' => $name];
        }

        $capabilities = [];
        foreach (Capability_Manager::capabilities() as $key => $label) {
            $capabilities[] = ['key' => $key, 'label' => $label];
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'roles'        => $roles,
                'capabilities' => $capabilities,
                'matrix'       => Capability_Manager::get_matrix(),
                'base_cap'     => Capability_Manager::ACCESS,
            ],
        ], 200);
    }

    /**
     * Save the matrix.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function save_matrix(WP_REST_Request $request): WP_REST_Response {
        $matrix = (array) $request->get_param('matrix');
        Capability_Manager::save_matrix($matrix);

        return new WP_REST_Response([
            'success' => true,
            'data'    => ['matrix' => Capability_Manager::get_matrix()],
        ], 200);
    }
}
