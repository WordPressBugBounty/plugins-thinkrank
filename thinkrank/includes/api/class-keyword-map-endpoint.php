<?php
/**
 * Keyword map and cannibalization REST endpoints.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 2.21.3
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\Core\Capability_Manager;
use ThinkRank\SEO\Keyword_Map;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keyword governance endpoints.
 *
 * Under `global-seo/` for the same reason Bulk Snippets, Thin Content and
 * Orphan Pages are: the Role Manager maps that route prefix to the Bulk SEO
 * Optimization capability, so a role granted the section gets the screen and
 * one denied it does not, with no second mapping to keep in step.
 *
 * @since 2.21.3
 */
class Keyword_Map_Endpoint {

    /**
     * API namespace.
     */
    private const NAMESPACE = 'thinkrank/v1';

    /**
     * Route base.
     */
    private const REST_BASE = 'global-seo/keyword-map';

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE, [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_report'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => [
                'refresh' => [
                    'type'    => 'boolean',
                    'default' => false,
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE . '/search-console', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_search_console'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
    }

    /**
     * Section permission.
     *
     * @return bool
     */
    public function check_permissions(): bool {
        return Capability_Manager::current_user_can('thinkrank_global_seo');
    }

    /**
     * GET — the map and the assignment conflicts.
     *
     * Bounded: each call indexes one batch of unindexed posts and reports how
     * many are left, so the screen keeps asking until `pending` reaches zero
     * rather than any one request walking the site.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_report(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response(
            Keyword_Map::report((bool) $request->get_param('refresh'))
        );
    }

    /**
     * GET — queries where several of our URLs compete.
     *
     * Its own route rather than a section of the report above, because it is a
     * live round trip to Google: folding it in would make every poll of a
     * filling index wait on an external API, to answer a question an install
     * without Search Console cannot ask at all.
     *
     * Always 200, never an error status. "Search Console is not connected" and
     * "Google timed out" are both ordinary states of a screen whose other half
     * is still correct and still worth rendering; a 4xx here would blank a
     * report that has real findings in it.
     *
     * @return WP_REST_Response
     */
    public function get_search_console(): WP_REST_Response {
        return new WP_REST_Response(Keyword_Map::search_console());
    }
}
