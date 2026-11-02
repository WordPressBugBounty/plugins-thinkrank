<?php
/**
 * Permalinks REST endpoint: the URL base switches.
 *
 * `GET|POST /thinkrank/v1/permalinks/settings`. See
 * {@see \ThinkRank\SEO\Permalinks} for what each switch does (#1476).
 *
 * @package ThinkRank
 * @subpackage API
 * @since 2.21.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\SEO\Permalinks;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Permalinks settings endpoint.
 *
 * @since 2.21.0
 */
class Permalinks_Endpoint extends WP_REST_Controller {

    /**
     * Namespace.
     *
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'permalinks';

    /**
     * Which section's capability may change each switch: the screen it sits on.
     *
     * @since 2.21.0
     *
     * @var array<string, string>
     */
    private const SWITCH_CAPABILITIES = [
        'remove_category_base'            => 'thinkrank_crawling',
        'remove_product_base'             => 'thinkrank_woocommerce',
        'remove_product_category_base'    => 'thinkrank_woocommerce',
        'remove_product_category_parents' => 'thinkrank_woocommerce',
    ];

    /**
     * Register the settings route.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_settings'],
                    'permission_callback' => [$this, 'check_permissions'],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'update_settings'],
                    'permission_callback' => [$this, 'check_update_permissions'],
                    'args'                => $this->get_settings_args(),
                ],
            ]
        );
    }

    /**
     * The category base sits on the Crawling screen and the product ones on
     * the WooCommerce screen, so either area's capability may read the route.
     * What each may change is decided per switch by check_update_permissions().
     *
     * @return bool
     */
    public function check_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_crawling')
            || \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_woocommerce');
    }

    /**
     * A save must hold only switches the user's own section owns (#1506).
     *
     * One switch outside it refuses the whole request, even when its value is
     * what is stored already, so nothing is half saved. A switch with no owner
     * listed is left to administrators.
     *
     * @since 2.21.0
     *
     * @param WP_REST_Request $request Request.
     * @return bool|WP_Error
     */
    public function check_update_permissions(WP_REST_Request $request) {
        if (!$this->check_permissions()) {
            return false;
        }

        foreach (array_keys(self::submitted_switches($request)) as $key) {
            $capability = self::SWITCH_CAPABILITIES[$key] ?? 'manage_options';

            if (!\ThinkRank\Core\Capability_Manager::current_user_can($capability)) {
                return new WP_Error(
                    'rest_forbidden',
                    __('You do not have permission to change one of these URL settings.', 'thinkrank'),
                    ['status' => 403]
                );
            }
        }

        return true;
    }

    /**
     * The switches a request holds, and nothing else it may carry.
     *
     * @since 2.21.0
     *
     * @param WP_REST_Request $request Request.
     * @return array<string, mixed>
     */
    private static function submitted_switches(WP_REST_Request $request): array {
        return array_intersect_key($request->get_params(), Permalinks::DEFAULTS);
    }

    /**
     * Current settings, plus what the screen needs to explain them.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response(Permalinks::settings() + ['context' => self::context()], 200);
    }

    /**
     * Save the switches sent and return the stored settings.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function update_settings(WP_REST_Request $request): WP_REST_Response {
        $settings = Permalinks::save(self::submitted_switches($request));

        return new WP_REST_Response([
            'success'        => true,
            'message'        => __('Permalink settings saved. Old URLs redirect to the new ones.', 'thinkrank'),
            'settings'       => $settings,
            'context'        => self::context(),
            // Page caches still hold the old pages under the old URLs.
            'cache_warnings' => Permalinks::purge_changed_urls(),
        ], 200);
    }

    /**
     * The bases in use, so the screen can show real examples, and whether
     * the site has WooCommerce and pretty permalinks at all.
     *
     * @return array<string, mixed>
     */
    public static function context(): array {
        $product_base = Permalinks::product_base();

        return [
            'woocommerce_active'              => function_exists('wc_get_permalink_structure'),
            'pretty_permalinks'               => '' !== (string) get_option('permalink_structure', ''),
            'product_base'                    => null === $product_base ? '' : trim($product_base, '/'),
            'product_category_base'           => (string) Permalinks::product_category_base(),
            'category_base'                   => Permalinks::category_base(),
            'category_blog_prefix'            => Permalinks::blog_prefix(),
            'home_url'                        => home_url('/'),
            'categories_keeping_base'         => Permalinks::categories_keeping_base(),
            'product_categories_keeping_base' => Permalinks::product_categories_keeping_base(),
            'products_keeping_base'           => Permalinks::products_keeping_base(),
            'products_keep_base_under_structure' => Permalinks::products_keep_base_under_structure(),
        ];
    }

    /**
     * Route arguments.
     *
     * @return array<string, array<string, mixed>>
     */
    private function get_settings_args(): array {
        $args = [];
        foreach (array_keys(Permalinks::DEFAULTS) as $key) {
            $args[$key] = [
                'type'              => 'boolean',
                'required'          => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ];
        }

        return $args;
    }
}
