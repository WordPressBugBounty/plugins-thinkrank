<?php
/**
 * Topical coverage REST endpoint.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 2.21.3
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\SEO\Topical_Relevance;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Topical coverage endpoint.
 *
 * A per-post check, so the permission is the post's own rather than a section
 * capability: whoever may edit the post may ask how its coverage compares. It
 * also fetches URLs the caller supplies, which is a capability question in
 * itself — `edit_post` is the right floor for "this server will make an
 * outbound request on your say-so", and everything about that request goes
 * through {@see \ThinkRank\Core\Url_Safety}.
 *
 * @since 2.21.3
 */
class Topical_Relevance_Endpoint {

    /**
     * API namespace.
     */
    private const NAMESPACE = 'thinkrank/v1';

    /**
     * Route base.
     */
    private const REST_BASE = 'topical-relevance';

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE, [
            'methods'             => 'POST',
            'callback'            => [$this, 'analyze'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => [
                'post_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'urls'    => [
                    'required'    => true,
                    'type'        => 'array',
                    'minItems'    => 1,
                    'maxItems'    => Topical_Relevance::MAX_URLS,
                    'items'       => [
                        'type'   => 'string',
                        'format' => 'uri',
                    ],
                    'description' => 'Competitor pages to compare against.',
                ],
            ],
        ]);
    }

    /**
     * Whoever may edit the post may ask about it.
     *
     * @param WP_REST_Request $request Request.
     * @return bool
     */
    public function check_permissions(WP_REST_Request $request): bool {
        return current_user_can('edit_post', (int) $request->get_param('post_id'));
    }

    /**
     * POST — compare the post against the named pages.
     *
     * A POST rather than a GET although it changes nothing: the URL list is
     * the input, and a handful of full URLs in a query string is what query
     * string length limits and access logs are both bad at.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|\WP_Error
     */
    public function analyze(WP_REST_Request $request) {
        $result = Topical_Relevance::analyze(
            (int) $request->get_param('post_id'),
            (array) $request->get_param('urls')
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result);
    }
}
