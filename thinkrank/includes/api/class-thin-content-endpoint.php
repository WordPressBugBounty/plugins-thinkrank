<?php
/**
 * Thin content report REST endpoints.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 2.10.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\Core\Capability_Manager;
use ThinkRank\SEO\Thin_Content;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin content endpoints.
 *
 * Under `global-seo/` for the same reason Bulk Snippets is: the Role Manager
 * maps that route prefix to the Bulk SEO Optimization capability, so a role
 * granted the section gets the screen and one denied it does not, with no
 * second mapping to keep in step.
 *
 * @since 2.10.0
 */
class Thin_Content_Endpoint {

    /**
     * API namespace.
     */
    private const NAMESPACE = 'thinkrank/v1';

    /**
     * Route base.
     */
    private const REST_BASE = 'global-seo/thin-content';

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

        register_rest_route(self::NAMESPACE, '/' . self::REST_BASE . '/settings', [
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
                    'default' => [
                        'type'    => 'integer',
                        'minimum' => Thin_Content::MIN_THRESHOLD,
                        'maximum' => Thin_Content::MAX_THRESHOLD,
                    ],
                    'overrides' => [
                        'type'                 => 'object',
                        'description'          => 'Post type => threshold. null clears an override.',
                        // Spelled out per post type rather than left as a bare
                        // object. Without this the route accepted anything and
                        // `(int)` turned it into a number: `"abc"` and `0` both
                        // became 1, the strictest threshold there is, and came
                        // back as a 200. A threshold the caller never asked for
                        // is worse than a rejection, and `default` beside it was
                        // already rejecting the same values with a 400.
                        'additionalProperties' => false,
                        'properties'           => self::override_schema(),
                    ],
                ],
            ],
        ]);
    }

    /**
     * One schema per post type in scope, for the overrides object.
     *
     * The same shape `update-thin-content-settings` declares, so the route the
     * screen calls and the ability an agent calls accept and refuse the same
     * values. `null` is a member of the type rather than a special case: it is
     * how an override is cleared.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function override_schema(): array {
        $properties = [];

        foreach (Thin_Content::post_types() as $post_type) {
            $properties[$post_type] = [
                'type'    => ['integer', 'null'],
                'minimum' => Thin_Content::MIN_THRESHOLD,
                'maximum' => Thin_Content::MAX_THRESHOLD,
            ];
        }

        return $properties;
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
     * Bounded: each call counts one batch of uncounted posts and reports how
     * many are left, so the screen keeps asking until `pending` reaches zero
     * rather than any one request walking the site. Resolving builder content
     * is what makes that necessary.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_report(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response(
            Thin_Content::report((bool) $request->get_param('refresh'))
        );
    }

    /**
     * GET — the thresholds alone.
     *
     * @return WP_REST_Response
     */
    public function get_settings(): WP_REST_Response {
        return new WP_REST_Response(self::settings_payload());
    }

    /**
     * POST — change the thresholds.
     *
     * Returns what is now **stored** rather than what was submitted, so a value
     * the clamp reduced is reported as the value it became.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function update_settings(WP_REST_Request $request): WP_REST_Response {
        $input = [];

        if (null !== $request->get_param('default')) {
            $input['default'] = (int) $request->get_param('default');
        }

        $overrides = $request->get_param('overrides');
        if (is_array($overrides)) {
            $input['overrides'] = $overrides;
        }

        Thin_Content::save_settings($input);

        return new WP_REST_Response(self::settings_payload());
    }

    /**
     * The thresholds, plus what a caller needs to render them.
     *
     * @return array<string,mixed>
     */
    private static function settings_payload(): array {
        $settings = Thin_Content::settings();

        return [
            'default'   => $settings['default'],
            'overrides' => (object) $settings['overrides'],
            // The effective threshold for every post type in scope, so a
            // caller never has to reimplement "override, else default".
            'effective' => (object) Thin_Content::thresholds(),
            'unit'      => \ThinkRank\SEO\Word_Count_Index::unit(),
            'limits'    => [
                'min_threshold' => Thin_Content::MIN_THRESHOLD,
                'max_threshold' => Thin_Content::MAX_THRESHOLD,
            ],
        ];
    }
}
