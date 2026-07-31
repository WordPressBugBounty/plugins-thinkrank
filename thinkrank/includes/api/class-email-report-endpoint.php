<?php
/**
 * Email Report REST Endpoint
 *
 * Three routes:
 *   GET  /thinkrank/v1/email-report/config       — returns config + capability map + section catalog
 *   POST /thinkrank/v1/email-report/config       — saves config (sanitized + capability-clamped)
 *   POST /thinkrank/v1/email-report/test-send    — triggers an immediate one-off send
 *
 * Permissions: admin (`manage_options`) + valid REST nonce. Pro-only
 * fields submitted on a free plan are silently dropped by
 * Email_Report_Config::sanitize() — we don't 403 on those, since the
 * client may not know its current capabilities yet (e.g. mid-downgrade).
 *
 * @package ThinkRank
 * @subpackage API
 * @since 1.9.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\API\Traits\CSRF_Protection;
use ThinkRank\Core\Plan_Config;
use ThinkRank\SEO\Email_Report_Manager;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

require_once THINKRANK_PLUGIN_DIR . 'includes/api/traits/trait-csrf-protection.php';

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email_Report_Endpoint
 *
 * @since 1.9.0
 */
final class Email_Report_Endpoint extends WP_REST_Controller {
    use CSRF_Protection;

    protected $namespace = 'thinkrank/v1';
    protected $rest_base = 'email-report';

    private ?Email_Report_Manager $manager = null;

    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/config',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_config'],
                    'permission_callback' => [$this, 'check_admin_read_permissions'],
                ],
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'save_config'],
                    'permission_callback' => [$this, 'check_admin_csrf_permissions'],
                    'args' => $this->save_args(),
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/test-send',
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'test_send'],
                    'permission_callback' => [$this, 'check_admin_csrf_permissions'],
                ],
            ]
        );
    }

    /**
     * GET /email-report/config
     *
     * Returns the current per-site config along with the plan's capability
     * map and the section catalog so the React panel can render the right
     * fields without a second round-trip.
     */
    public function get_config(WP_REST_Request $request): WP_REST_Response {
        $manager = $this->resolve_manager();
        if ($manager === null) {
            return new WP_REST_Response([
                'error' => __('Email Report Manager unavailable.', 'thinkrank'),
            ], 500);
        }

        return new WP_REST_Response([
            'config'         => $manager->config()->get(),
            'capabilities'   => Plan_Config::email_report(),
            'sections'       => $manager->registry()->describe_for_ui(),
            'next_run'       => $manager->scheduler()->next_run_iso(),
            'tokens'         => $this->supported_tokens(),
        ]);
    }

    /**
     * POST /email-report/config
     */
    public function save_config(WP_REST_Request $request): WP_REST_Response {
        $manager = $this->resolve_manager();
        if ($manager === null) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Email Report Manager unavailable.', 'thinkrank'),
            ], 500);
        }

        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = $request->get_params();
        }

        $saved = $manager->config()->save(is_array($input) ? $input : []);

        return new WP_REST_Response([
            'success'      => true,
            'config'       => $saved,
            'capabilities' => Plan_Config::email_report(),
            'next_run'     => $manager->scheduler()->next_run_iso(),
        ]);
    }

    /**
     * POST /email-report/test-send
     */
    public function test_send(WP_REST_Request $request): WP_REST_Response {
        $manager = $this->resolve_manager();
        if ($manager === null) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Email Report Manager unavailable.', 'thinkrank'),
            ], 500);
        }

        $result = $manager->generator()->generate_test();

        $status = !empty($result['success']) ? 200 : 400;
        return new WP_REST_Response([
            'success' => (bool) ($result['success'] ?? false),
            'result'  => $result,
        ], $status);
    }

    /**
     * Permission for read endpoints. Same admin gate, but no CSRF
     * (GET requests don't require it).
     */
    public function check_admin_read_permissions(WP_REST_Request $request) {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_forbidden', __('Not logged in.', 'thinkrank'), ['status' => 401]);
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', __('Insufficient permissions.', 'thinkrank'), ['status' => 403]);
        }
        return true;
    }

    /**
     * Permission for the state-changing POST endpoints (save config / test-send).
     *
     * These write the site-global report config and can trigger a send of private
     * analytics, so they require admin (manage_options) plus CSRF verification —
     * NOT the shared edit_posts-level check_csrf_permissions() trait, which would
     * let a Contributor overwrite the config and exfiltrate the report. Matches the
     * manage_options gate on the GET route.
     */
    public function check_admin_csrf_permissions(WP_REST_Request $request) {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_forbidden', __('Not logged in.', 'thinkrank'), ['status' => 401]);
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', __('Insufficient permissions.', 'thinkrank'), ['status' => 403]);
        }
        if (!$this->verify_request_nonce($request)) {
            return new WP_Error('rest_forbidden', __('Invalid security token. Please refresh the page and try again.', 'thinkrank'), ['status' => 403]);
        }
        return true;
    }

    /**
     * Reach into the plugin DI container for the Email_Report_Manager
     * instance built at boot. Falls back to creating one on demand if
     * the function doesn't exist yet (defensive — shouldn't happen).
     */
    private function resolve_manager(): ?Email_Report_Manager {
        if ($this->manager !== null) {
            return $this->manager;
        }
        if (function_exists('thinkrank')) {
            $component = thinkrank()->get_component('email_report');
            if ($component instanceof Email_Report_Manager) {
                $this->manager = $component;
                return $this->manager;
            }
        }
        return null;
    }

    /**
     * REST args: permissive on type so we accept the full config object
     * the panel sends back (including nulls for paid fields the user
     * isn't allowed to set). Heavy sanitization happens in
     * Email_Report_Config::sanitize() so the cron path benefits too.
     *
     * Don't add `sanitize_callback` here for nullable fields — REST
     * runs sanitize before validate, and `esc_url_raw(null)` would
     * coerce to '', defeating the point of preserving "unset".
     */
    private function save_args(): array {
        $nullable_string = ['type' => ['string', 'null']];
        return [
            'enabled' => [
                'type' => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'frequency_days' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'recipients' => [
                'type' => ['array', 'string', 'null'],
            ],
            'subject_template' => $nullable_string,
            'logo_url' => $nullable_string,
            'logo_link' => $nullable_string,
            'header_background' => $nullable_string,
            'link_to_full_report' => [
                'type' => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'intro_text' => $nullable_string,
            'sections_enabled' => [
                'type' => ['array', 'null'],
            ],
            'footer_text' => $nullable_string,
            'additional_css' => $nullable_string,
        ];
    }

    private function supported_tokens(): array {
        if (!function_exists('thinkrank_get_email_report_tokens')) {
            require_once THINKRANK_PLUGIN_DIR . 'includes/config/email-report-settings-config.php';
        }
        return thinkrank_get_email_report_tokens();
    }
}
