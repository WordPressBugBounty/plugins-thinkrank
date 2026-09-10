<?php
/**
 * Content Type Matrix REST endpoints.
 *
 * Reads and writes the per-content-type feature matrix (#660): every entity —
 * post types, taxonomies, author/date archives, search and 404 — against the
 * features that can be switched for it.
 *
 * Two storage layers sit behind one screen. The tri-state feature flags and the
 * per-entity robots directives live in `thinkrank_global_seo_settings` next to
 * the existing per-post-type templates. Sitemap inclusion does NOT: it keeps
 * writing the sitemap settings' own `include_*` / `exclude_*` flags, so the
 * presets UI and the generator's regex matching keep working against a single
 * storage format.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 2.5.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\SEO\Content_Type_Settings;
use ThinkRank\SEO\Sitemap_Generator;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Content Type Matrix endpoints.
 *
 * @since 2.5.0
 */
class Content_Type_Matrix_Endpoint extends WP_REST_Controller {

    /**
     * API namespace.
     *
     * @var string
     */
    protected $namespace = 'thinkrank/v1';

    /**
     * API resource base.
     *
     * @var string
     */
    protected $rest_base = 'global-seo/matrix';

    /**
     * Sitemap generator, lazily created (hooks are not needed here).
     *
     * @var Sitemap_Generator|null
     */
    private ?Sitemap_Generator $sitemap = null;

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_matrix'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'save_entity'],
                    'permission_callback' => [$this, 'check_manage_permissions'],
                    'args'                => [
                        'entity'   => [
                            'required'          => true,
                            'type'              => 'string',
                            'description'       => 'Entity key (post type slug, taxonomy:<slug>, archive:<kind>, special:<kind>)',
                            'sanitize_callback' => [$this, 'sanitize_entity_key'],
                        ],
                        'settings' => [
                            'required'    => true,
                            'type'        => 'object',
                            'description' => 'Feature states, robots directives, and sitemap inclusion for this entity',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Entity keys carry a `:` separator, which sanitize_key() strips — keep it.
     *
     * @param mixed $value Raw parameter.
     * @return string
     */
    public function sanitize_entity_key($value): string {
        return (string) preg_replace('/[^a-z0-9_:\-]/', '', strtolower((string) $value));
    }

    /**
     * The whole matrix: entities, their stored values, and the global defaults
     * an 'inherit' cell resolves to.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_matrix(WP_REST_Request $request): WP_REST_Response {
        $sitemap_settings = $this->get_sitemap_settings();
        $entities         = [];

        foreach (Content_Type_Settings::get_entities() as $entity) {
            $stored = Content_Type_Settings::get_entity_settings($entity['key']);

            $values = [];
            foreach ($entity['features'] as $feature) {
                $values[$feature] = Content_Type_Settings::feature_state($entity['key'], $feature);
            }

            $entity['values']              = $values;
            $entity['robots_meta_enabled'] = !empty($stored['robots_meta_enabled']);
            $entity['robots_meta']         = Content_Type_Settings::resolve_robots_meta($entity['key']);
            $entity['sitemap_include']     = $entity['supports_sitemap']
                ? Content_Type_Settings::is_included_in_sitemap($entity['group'], $entity['object'], $sitemap_settings)
                : null;

            $entities[] = $entity;
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'entities' => $entities,
                'globals'  => $this->get_global_defaults(),
            ],
        ], 200);
    }

    /**
     * Save one entity's row.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function save_entity(WP_REST_Request $request) {
        $entity_key = (string) $request->get_param('entity');
        $settings   = $request->get_param('settings');

        if (!Content_Type_Settings::is_valid_entity($entity_key)) {
            return new WP_Error(
                'invalid_entity',
                sprintf('Entity "%s" is not configurable on this site', $entity_key),
                ['status' => 400]
            );
        }

        if (!is_array($settings)) {
            return new WP_Error('invalid_settings', 'Settings must be provided as an object', ['status' => 400]);
        }

        $descriptor = $this->find_entity($entity_key);
        $patch      = Content_Type_Settings::sanitize_feature_states($settings);

        // Drop any state sent for a feature this entity does not expose (e.g.
        // schema on search results) rather than storing a value nothing reads.
        $patch = array_intersect_key($patch, array_flip($descriptor['features']));

        if (isset($settings['robots_meta_enabled'])) {
            $patch['robots_meta_enabled'] = (bool) $settings['robots_meta_enabled'];
        }

        if (isset($settings['robots_meta']) && is_array($settings['robots_meta'])) {
            $robots = [];
            foreach (['index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet'] as $key) {
                if (isset($settings['robots_meta'][$key])) {
                    $robots[$key] = (bool) $settings['robots_meta'][$key];
                }
            }
            $patch['robots_meta'] = array_merge(
                Content_Type_Settings::default_robots_meta($entity_key),
                $robots
            );
        }

        if (!empty($patch)) {
            Content_Type_Settings::update_entity_settings($entity_key, $patch);
        }

        $sitemap_saved = true;
        if ($descriptor['supports_sitemap'] && isset($settings['sitemap_include'])) {
            $sitemap_saved = $this->save_sitemap_inclusion(
                $descriptor,
                (bool) rest_sanitize_boolean($settings['sitemap_include'])
            );
        }

        if (!$sitemap_saved) {
            return new WP_Error('sitemap_save_failed', 'Failed to save sitemap inclusion', ['status' => 500]);
        }

        $sitemap_settings = $this->get_sitemap_settings();

        return new WP_REST_Response([
            'success' => true,
            'entity'  => $entity_key,
            'data'    => [
                'values'              => array_intersect_key(
                    Content_Type_Settings::get_entity_settings($entity_key),
                    array_flip(Content_Type_Settings::FEATURES)
                ),
                'robots_meta_enabled' => !empty(Content_Type_Settings::get_entity_settings($entity_key)['robots_meta_enabled']),
                'robots_meta'         => Content_Type_Settings::resolve_robots_meta($entity_key),
                'sitemap_include'     => $descriptor['supports_sitemap']
                    ? Content_Type_Settings::is_included_in_sitemap($descriptor['group'], $descriptor['object'], $sitemap_settings)
                    : null,
            ],
        ], 200);
    }

    /**
     * Write one object's sitemap inclusion through the legacy flags.
     *
     * Both flags are written for non-legacy objects: `include_<slug>` is what
     * the presets UI reads, and `exclude_<slug>` is what a preset writes to opt
     * a type out — leaving a stale `exclude_` behind would silently outrank the
     * value just saved.
     *
     * @param array $descriptor Entity descriptor.
     * @param bool  $included   Whether the object belongs in the sitemap.
     * @return bool
     */
    private function save_sitemap_inclusion(array $descriptor, bool $included): bool {
        $flag    = Content_Type_Settings::sitemap_flag_key($descriptor['group'], $descriptor['object']);
        $payload = [$flag => $included];

        if ($flag === 'include_' . $descriptor['object']) {
            $payload['exclude_' . $descriptor['object']] = !$included;
        }

        return $this->sitemap()->save_settings('site', null, $payload);
    }

    /**
     * Look up an entity descriptor by key.
     *
     * @param string $entity_key Entity key.
     * @return array
     */
    private function find_entity(string $entity_key): array {
        foreach (Content_Type_Settings::get_entities() as $entity) {
            if ($entity['key'] === $entity_key) {
                return $entity;
            }
        }

        return [
            'key'              => $entity_key,
            'group'            => '',
            'object'           => '',
            'features'         => Content_Type_Settings::FEATURES,
            'supports_sitemap' => false,
        ];
    }

    /**
     * Current site-wide value each 'inherit' cell resolves to.
     *
     * @return array<string, bool>
     */
    private function get_global_defaults(): array {
        $social = [];
        if (class_exists('\ThinkRank\SEO\Social_Meta_Manager')) {
            $manager = new \ThinkRank\SEO\Social_Meta_Manager();
            $social  = $manager->get_settings('site');
        }

        $ga_measurement_id = (string) \ThinkRank\Core\Settings::instance()->get('ga4_measurement_id', '');
        $ga_auto_inject    = (bool) \ThinkRank\Core\Settings::instance()->get('ga4_auto_inject', false);

        return [
            Content_Type_Settings::FEATURE_META       => true,
            Content_Type_Settings::FEATURE_SCHEMA     => true,
            Content_Type_Settings::FEATURE_OPEN_GRAPH => !empty($social['enable_open_graph'] ?? $social['og_enabled'] ?? false),
            Content_Type_Settings::FEATURE_TWITTER    => !empty($social['enable_twitter_cards'] ?? $social['twitter_enabled'] ?? false),
            Content_Type_Settings::FEATURE_ANALYTICS  => $ga_auto_inject && $ga_measurement_id !== '',
        ];
    }

    /**
     * Sitemap settings for the site context.
     *
     * @return array
     */
    private function get_sitemap_settings(): array {
        return $this->sitemap()->get_settings('site');
    }

    /**
     * Sitemap generator instance (no auto-generation hooks: this is a request
     * that reads and writes settings, not one that rebuilds anything).
     *
     * @return Sitemap_Generator
     */
    private function sitemap(): Sitemap_Generator {
        if ($this->sitemap === null) {
            $this->sitemap = new Sitemap_Generator(false);
        }

        return $this->sitemap;
    }

    /**
     * Read permission.
     *
     * The same capability the write route asks for, and the same one the
     * plugin-wide REST guard enforces on this namespace. `edit_posts` read as
     * an access level the endpoint never actually grants: an editor holding it
     * was still refused by the guard, which invited a later "fix" in the wrong
     * direction (#669 review).
     *
     * @return bool
     */
    public function check_read_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_global_seo');
    }

    /**
     * Write permission.
     *
     * @return bool
     */
    public function check_manage_permissions(): bool {
        return \ThinkRank\Core\Capability_Manager::current_user_can('thinkrank_global_seo');
    }
}
