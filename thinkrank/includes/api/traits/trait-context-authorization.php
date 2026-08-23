<?php
/**
 * Context Authorization Trait
 *
 * Every ThinkRank REST route that accepts a `context_type` / `context_id` pair
 * addresses a specific object, and a section capability — `thinkrank_schema`,
 * `thinkrank_social_media`, `thinkrank_crawling` and friends — only authorises
 * entry to that section. It is not permission to read or write SEO data for
 * every post on the site. Without an object-level check a delegated user can
 * walk `context_id` and reach other authors' drafts (#364, #366, #385).
 *
 * The check lived as a private copy in two endpoint classes, so handlers that
 * never called it were missed twice over. It lives here now, and any endpoint
 * accepting a context uses this trait.
 *
 * @package ThinkRank
 * @subpackage API\Traits
 * @since 2.0.1
 */

declare(strict_types=1);

namespace ThinkRank\API\Traits;

use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Context Authorization Trait
 *
 * @since 2.0.1
 */
trait Context_Authorization {

    /**
     * Context types the ThinkRank REST API addresses objects with.
     *
     * @since 2.0.1
     * @var string[]
     */
    private static $context_types = ['site', 'post', 'page', 'product'];

    /**
     * Validate a context type and ID, and the caller's access to that object.
     *
     * @since 2.0.1
     *
     * @param string   $context_type Context type.
     * @param int|null $context_id   Context ID.
     * @return true|WP_Error True when the caller may use this context, WP_Error
     *                       otherwise (400 for a shape error, 403 for authorization).
     */
    protected function validate_context(string $context_type, ?int $context_id) {
        $invalid = new WP_Error(
            'invalid_context',
            'Invalid context type or ID provided',
            ['status' => 400]
        );

        if (!in_array($context_type, self::$context_types, true)) {
            return $invalid;
        }

        if ($context_type !== 'site' && (!$context_id || $context_id <= 0)) {
            return $invalid;
        }

        if ($context_id && !get_post($context_id)) {
            return $invalid;
        }

        // SECURITY: everything above establishes that the context *exists*, not
        // that this caller may see it. `edit_post` is a meta capability, so
        // map_meta_cap() resolves authorship, published state and
        // edit_others_posts for this specific post — the same check
        // Schema_Input_Validator::validate_context_ownership() and
        // Settings_Management_Endpoint::authorize_settings_context() make on
        // their write paths.
        //
        // Site context is deliberately left to the route's capability gate.
        // The write paths demand manage_options for it, but applying that here
        // would stop a delegated Schema Manager reading site-level schema at
        // all, which is the point of delegating the section.
        if ($context_type !== 'site' && !current_user_can('edit_post', $context_id)) {
            return new WP_Error(
                'rest_forbidden',
                'You are not allowed to access this content.',
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Read the context pair off a request and authorise it in one step.
     *
     * Handlers that only need "is this allowed, and what are the resolved
     * values" use this rather than repeating the read/cast/validate dance.
     *
     * @since 2.0.1
     *
     * @param \WP_REST_Request $request Request object.
     * @return array{0: string, 1: int|null}|WP_Error The resolved
     *         [$context_type, $context_id], or the validation error.
     */
    protected function resolve_request_context(\WP_REST_Request $request) {
        $context_type = $request->get_param('context_type') ?? 'site';
        $context_id   = $request->get_param('context_id');
        $context_id   = (null === $context_id || '' === $context_id) ? null : (int) $context_id;

        $validation = $this->validate_context((string) $context_type, $context_id);

        if (is_wp_error($validation)) {
            return $validation;
        }

        return [(string) $context_type, $context_id];
    }

    /**
     * REST args declaring the context pair, so the values arrive typed.
     *
     * Named apart from Social_Media_Endpoint's own private get_context_args():
     * a class method silently wins over a trait method of the same name, and
     * that one marks both parameters required — correct for the
     * /social-media/meta/{context} routes it was written for, and a 400 on
     * every site-context read if it captured these registrations.
     *
     * @since 2.0.1
     *
     * @return array<string, array<string, mixed>> Argument definitions.
     */
    protected function get_context_route_args(): array {
        return [
            'context_type' => [
                'required'    => false,
                'type'        => 'string',
                'enum'        => self::$context_types,
                'default'     => 'site',
                'description' => 'Context type',
            ],
            'context_id' => [
                'required'    => false,
                'type'        => 'integer',
                'minimum'     => 1,
                'description' => 'Context ID (required for non-site contexts)',
            ],
        ];
    }
}
