<?php
/**
 * WooCommerce settings REST endpoint.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 2.21.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\Integrations\WooCommerce_Settings;
use ThinkRank\Integrations\Woocommerce;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * `GET|POST /thinkrank/v1/woocommerce/settings` (#1478).
 *
 * Backs the free card at the top of the WooCommerce tab. ThinkRank Pro's own
 * WooCommerce settings live under `thinkrank-pro/v1/woocommerce/settings`, a
 * different namespace, so the two never answer for each other. The Role
 * Manager's route map already gates the `woocommerce` prefix on the
 * WooCommerce section's capability.
 *
 * @since 2.21.0
 */
class WooCommerce_Endpoint extends WP_REST_Controller {

    /**
     * API namespace
     *
     * @since 2.21.0
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * API resource base
     *
     * @since 2.21.0
     * @var string
     */
    protected $rest_base = 'woocommerce';

    /**
     * Register API routes
     *
     * @since 2.21.0
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
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => [
                        'brand_taxonomy'      => [
                            'type'     => 'string',
                            'required' => false,
                        ],
                        'shop_archive_schema' => [
                            'type'              => 'boolean',
                            'required'          => false,
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ],
                        'remove_generator'    => [
                            'type'              => 'boolean',
                            'required'          => false,
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Whether the current user may manage the WooCommerce section.
     *
     * @since 2.21.0
     * @return bool
     */
    public function check_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_woocommerce');
    }

    /**
     * The settings, the brand taxonomies to choose from, and whether
     * WooCommerce is active.
     *
     * The card renders only when `woocommerce_active` is true: the tab is in
     * the menu on every site, and these switches mean nothing without a shop.
     *
     * @since 2.21.0
     * @param WP_REST_Request $request API request object
     * @return WP_REST_Response
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response(self::payload(), 200);
    }

    /**
     * Save the keys sent; the rest keep their stored value.
     *
     * Answers with what is stored, so the panel shows the server's value.
     *
     * @since 2.21.0
     * @param WP_REST_Request $request API request object
     * @return WP_REST_Response|\WP_Error
     */
    public function update_settings(WP_REST_Request $request) {
        $input = array_intersect_key(
            (array) $request->get_params(),
            WooCommerce_Settings::defaults()
        );

        $error = WooCommerce_Settings::validate($input);
        if (null !== $error) {
            return $error;
        }

        WooCommerce_Settings::save($input);

        return new WP_REST_Response(
            array_merge(['success' => true], self::payload()),
            200
        );
    }

    /**
     * The response both routes share.
     *
     * @since 2.21.0
     * @return array<string,mixed>
     */
    private static function payload(): array {
        $choices = [];
        foreach (WooCommerce_Settings::brand_taxonomy_choices() as $name => $label) {
            $choices[] = ['value' => $name, 'label' => $label];
        }

        return [
            'settings'           => WooCommerce_Settings::get(),
            'brand_taxonomies'   => $choices,
            'woocommerce_active' => Woocommerce::is_active(),
        ];
    }
}
