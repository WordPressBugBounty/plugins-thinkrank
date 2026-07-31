<?php
/**
 * Site SEO Analyzer REST API Endpoint
 *
 * Exposes the site-wide SEO Analyzer:
 * - GET  /thinkrank/v1/seo-analyzer      → last cached analysis (computes if empty)
 * - POST /thinkrank/v1/seo-analyzer/run  → force a fresh analysis (busts the cache)
 *
 * @package ThinkRank\API
 * @since 1.18.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\SEO\SEO_Analyzer;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO Analyzer Endpoint Class
 *
 * @since 1.18.0
 */
class SEO_Analyzer_Endpoint {

    /**
     * API namespace.
     */
    private const NAMESPACE = 'thinkrank/v1';

    /**
     * Analyzer engine.
     *
     * @var SEO_Analyzer
     */
    private SEO_Analyzer $analyzer;

    /**
     * Constructor.
     *
     * @param SEO_Analyzer|null $analyzer Optional analyzer (injected in tests).
     */
    public function __construct(?SEO_Analyzer $analyzer = null) {
        $this->analyzer = $analyzer ?? new SEO_Analyzer();
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/seo-analyzer', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_analysis'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        register_rest_route(self::NAMESPACE, '/seo-analyzer/run', [
            'methods'             => 'POST',
            'callback'            => [$this, 'run_analysis'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
    }

    /**
     * Return the last cached analysis (computing it if none is cached).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_analysis(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'data'    => $this->analyzer->run(false),
        ], 200);
    }

    /**
     * Force a fresh analysis, busting the cache.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function run_analysis(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'data'    => $this->analyzer->run(true),
        ], 200);
    }

    /**
     * Only administrators may run the site-wide analyzer.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool|\WP_Error
     */
    public function check_permissions(WP_REST_Request $request) {
        if (!is_user_logged_in()) {
            return new \WP_Error(
                'rest_forbidden',
                __('You must be logged in to access this endpoint.', 'thinkrank'),
                ['status' => 401]
            );
        }

        if (!current_user_can('manage_options')) {
            return new \WP_Error(
                'rest_forbidden',
                __('You do not have permission to run the site SEO analyzer.', 'thinkrank'),
                ['status' => 403]
            );
        }

        return true;
    }
}
