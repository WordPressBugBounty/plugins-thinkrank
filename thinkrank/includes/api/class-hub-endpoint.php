<?php

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\Mcp\Mcp_Hub;
use ThinkRank\Mcp\Mcp_Manager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hub Endpoint
 *
 * The owner's side of the optional ThinkRank Hub connection: reading where
 * the hub is and what it holds, changing where it is, starting a connection
 * and ending one. Starting a connection turns the MCP switch on through its
 * own setter, as the settings route does, and never turns it off: the owner's
 * other assistants may rely on it.
 *
 * Every route needs `manage_options`, whatever the Role Manager delegates
 * elsewhere: handing this site to the hub is an administrator's decision.
 * `Role_Manager::gate_rest()` maps `/hub` to base access, so these routes
 * reach their own permission callbacks, which decide. The routes that change
 * something also need a signed-in browser session rather than an application
 * password.
 *
 * Ported from BetterShield's `src/Rest/HubController.php` (thinkrank#1002).
 *
 * @since 2.15.0
 */
class Hub_Endpoint {

    /**
     * REST namespace.
     *
     * @var string
     */
    private string $namespace = 'thinkrank/v1';

    /**
     * Permission callback for reads: the capability alone.
     *
     * @return bool
     */
    public function check_admin_permissions(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Permission callback for changes: the capability, and a signed-in
     * browser session rather than a token.
     *
     * @return bool|WP_Error
     */
    public function check_interactive_permissions() {
        if (!current_user_can('manage_options')) {
            return false;
        }

        return Mcp_Hub::interactive();
    }

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route($this->namespace, '/hub', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_state'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        register_rest_route($this->namespace, '/hub/settings', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save_settings'],
            'permission_callback' => [$this, 'check_interactive_permissions'],
            'args'                => [
                // Not run through a text sanitizer: Mcp_Hub::normalize_url()
                // validates the address and refuses anything else, and says
                // what is wrong with it.
                'url' => [
                    'type'              => 'string',
                    'required'          => true,
                    'validate_callback' => 'rest_validate_request_arg',
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/hub/connect', [
            'methods'             => 'POST',
            'callback'            => [$this, 'connect'],
            'permission_callback' => [$this, 'check_interactive_permissions'],
            'args'                => [
                'access' => [
                    'type'              => 'string',
                    'enum'              => [Mcp_Hub::ACCESS_READ, Mcp_Hub::ACCESS_WRITE],
                    'default'           => Mcp_Hub::ACCESS_WRITE,
                    'validate_callback' => 'rest_validate_request_arg',
                ],
                // The screen the connection started on, which is where the
                // hub sends the owner back to: a name, never an address.
                'from'   => [
                    'type'              => 'string',
                    'enum'              => ['', Mcp_Hub::FROM_WIZARD],
                    'default'           => '',
                    'validate_callback' => 'rest_validate_request_arg',
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/hub/disconnect', [
            'methods'             => 'POST',
            'callback'            => [$this, 'disconnect'],
            'permission_callback' => [$this, 'check_interactive_permissions'],
        ]);
    }

    /**
     * GET /hub — where the hub is and what it holds.
     *
     * @return WP_REST_Response
     */
    public function get_state(): WP_REST_Response {
        return new WP_REST_Response(self::state(), 200);
    }

    /**
     * POST /hub/settings — the owner's choice of hub. Refused while the
     * address is pinned in configuration and while a hub holds a grant.
     *
     * @param WP_REST_Request $request Carries `url`.
     * @return WP_REST_Response|WP_Error
     */
    public function save_settings(WP_REST_Request $request) {
        $saved = Mcp_Hub::set_url((string) $request->get_param('url'));

        if (is_wp_error($saved)) {
            return $saved;
        }

        return new WP_REST_Response(self::state(), 200);
    }

    /**
     * POST /hub/connect — turn on what the connection needs, mint its
     * ticket, and say where the browser goes next.
     *
     * @param WP_REST_Request $request Carries `access` and `from`.
     * @return WP_REST_Response|WP_Error
     */
    public function connect(WP_REST_Request $request) {
        // Refused before anything is switched on: there is no hub to send
        // the browser to, and the default is not one the host would accept.
        if (Mcp_Hub::pin_is_invalid()) {
            return new WP_Error(
                'thinkrank_hub_pin_invalid',
                __('The hub address set in wp-config.php (THINKRANK_HUB_URL) is not an https address this site can use, so it will not connect until that is corrected.', 'thinkrank'),
                ['status' => 409]
            );
        }

        // A staging copy refuses the hub's grant, so connecting one would
        // mint a grant that is paused the moment it exists.
        if (Mcp_Hub::is_staging()) {
            return new WP_Error(
                'thinkrank_hub_staging',
                __('This copy of the site is marked as a staging environment, which pauses the hub’s access. Connect the live site instead.', 'thinkrank'),
                ['status' => 409]
            );
        }

        $access = Mcp_Hub::ACCESS_READ === (string) $request->get_param('access') ? Mcp_Hub::ACCESS_READ : Mcp_Hub::ACCESS_WRITE;

        // On, through the setter, and never off from here.
        Mcp_Manager::enable();

        $ticket = Mcp_Hub::mint_ticket(get_current_user_id(), $access);

        return new WP_REST_Response([
            'redirect' => Mcp_Hub::connect_url($ticket, $access, (string) $request->get_param('from')),
            'expires'  => gmdate('c', time() + Mcp_Hub::TICKET_TTL),
            'hub'      => self::state(),
        ], 200);
    }

    /**
     * POST /hub/disconnect — end every hub grant, and nothing else.
     *
     * @return WP_REST_Response
     */
    public function disconnect(): WP_REST_Response {
        Mcp_Hub::disconnect();

        return new WP_REST_Response(self::state(), 200);
    }

    /**
     * Where the hub is and what it holds, as the card reads it.
     *
     * A grant is not access: what the site does with a hub call is decided
     * by its own switches, and the state says that (`reachable`,
     * `refused_by`) rather than what the grant was once approved for.
     *
     * @return array<string,mixed>
     */
    public static function state(): array {
        $apps = [];

        foreach (Mcp_Hub::apps() as $app) {
            $user   = $app['user_id'] > 0 ? get_userdata($app['user_id']) : false;
            $apps[] = [
                'id'          => (string) $app['client_id'],
                'name'        => (string) $app['name'],
                // The hub this grant was approved for, which is not the
                // configured one when the address moved underneath it.
                'host'        => '' !== (string) $app['hub'] ? (string) $app['hub'] : Mcp_Hub::host(),
                'scope'       => (string) $app['scope'],
                'read_only'   => (bool) $app['read_only'],
                'approved_by' => $user ? (string) $user->display_name : __('Unknown user', 'thinkrank'),
                'granted_at'  => $app['connected_at'] > 0 ? gmdate('c', (int) $app['connected_at']) : null,
                'last_used'   => $app['last_used'] > 0 ? gmdate('c', (int) $app['last_used']) : null,
                'expires_at'  => $app['expires_at'] > 0 ? gmdate('c', (int) $app['expires_at']) : null,
            ];
        }

        $refused_by = Mcp_Hub::refused_by();
        $last_read  = Mcp_Hub::last_read();

        return [
            'url'         => Mcp_Hub::url(),
            'default_url' => Mcp_Hub::DEFAULT_URL,
            'host'        => Mcp_Hub::host(),
            'locked'      => Mcp_Hub::is_locked(),
            'pin_invalid' => Mcp_Hub::pin_is_invalid(),
            'enabled'     => Mcp_Manager::is_enabled(),
            'staging'     => Mcp_Hub::is_staging(),
            'connected'   => [] !== $apps,
            'reachable'   => [] === $refused_by,
            'refused_by'  => $refused_by,
            'last_read'   => $last_read > 0 ? gmdate('c', $last_read) : null,
            'mcp_url'     => Mcp_Hub::mcp_screen_url(),
            'apps'        => $apps,
        ];
    }
}
