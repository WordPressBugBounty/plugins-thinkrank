<?php
/**
 * Orphan page report REST endpoints.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\Core\Capability_Manager;
use ThinkRank\SEO\Link_Index;
use ThinkRank\SEO\Orphan_Pages;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Orphan page endpoints.
 *
 * Under `global-seo/` for the same reason Bulk Snippets and Thin Content are:
 * the Role Manager maps that route prefix to the Bulk SEO Optimization
 * capability, so a role granted the section gets the screen and one denied it
 * does not, with no second mapping to keep in step.
 *
 * @since 2.22.0
 */
class Orphan_Pages_Endpoint {

    /**
     * API namespace.
     */
    private const NAMESPACE = 'thinkrank/v1';

    /**
     * Route base.
     */
    private const REST_BASE = 'global-seo/orphan-pages';

    /**
     * Most edges one `/links` call will return.
     */
    private const MAX_EDGES = 5000;

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

        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE . '/links', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_links'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => [
                'limit' => [
                    'type'    => 'integer',
                    'default' => 1000,
                    'minimum' => 1,
                    'maximum' => self::MAX_EDGES,
                ],
            ],
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
     * GET — the report.
     *
     * Bounded: each call indexes one batch of unindexed posts and reports how
     * many are left, so the screen keeps asking until `pending` reaches zero
     * rather than any one request walking the site. Resolving builder content
     * is what makes that necessary.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_report(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response(
            Orphan_Pages::report((bool) $request->get_param('refresh'))
        );
    }

    /**
     * GET — the edge list itself.
     *
     * The orphan report is one reading of the graph; a structure view is
     * another, and it needs the edges rather than the conclusion. Served from
     * the same index so the two can never disagree, and bounded because a large
     * site's graph is not something to hand over in one response by accident.
     *
     * `complete` is false when the cap truncated the list, so a caller can say
     * so rather than drawing a graph that is quietly missing edges.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_links(WP_REST_Request $request): WP_REST_Response {
        $limit = (int) $request->get_param('limit');
        $edges = Link_Index::edges($limit + 1);

        $complete = count($edges) <= $limit;

        return new WP_REST_Response([
            'edges'    => array_slice($edges, 0, $limit),
            'complete' => $complete,
            'pending'  => Link_Index::pending(Orphan_Pages::post_types(), ['publish']),
        ]);
    }
}
