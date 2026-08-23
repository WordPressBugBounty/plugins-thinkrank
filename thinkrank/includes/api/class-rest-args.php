<?php
/**
 * REST argument helpers.
 *
 * WordPress core only falls back to `rest_parse_request_arg` when an argument
 * declares **no** `sanitize_callback`, and `WP_REST_Request::has_valid_params()`
 * never installs `rest_validate_request_arg` on its own — it calls a validator
 * only when one is explicitly set. So any argument that declares a
 * `sanitize_callback` and no `validate_callback` gets zero schema validation:
 * `enum`, `minimum`, `maximum` and `format` are all inert, and the schema is a
 * lie the API answers to (#394).
 *
 * The rule is core's, verified against this install:
 * - `wp-includes/rest-api/class-wp-rest-request.php` `sanitize_params()`
 *   falls back only when there is no `sanitize_callback`
 * - `has_valid_params()` calls a validator only when `! empty( $arg['validate_callback'] )`
 *
 * @package ThinkRank
 * @subpackage API
 * @since 2.0.1
 */

declare(strict_types=1);

namespace ThinkRank\API;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST argument helpers.
 *
 * @since 2.0.1
 */
class Rest_Args {

    /**
     * Schema keywords that need a validator to have any effect.
     *
     * @since 2.0.1
     * @var string[]
     */
    private const CONSTRAINTS = ['enum', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'format', 'pattern'];

    /**
     * The plugin's REST namespace.
     *
     * @since 2.0.1
     * @var string
     */
    private const NAMESPACE = 'thinkrank/v1';

    /**
     * Attach core's validator to every argument that declares a constraint.
     *
     * Deliberately only those. Applying `rest_validate_request_arg` to *every*
     * argument would also start enforcing `type`, turning today's lenient
     * boolean and numeric coercion into a hard 400 across routes that have
     * always accepted `"1"` for a boolean — a much larger behaviour change than
     * making a declared enum mean something. The same reasoning is recorded at
     * `class-image-seo-endpoint.php`, which is where this pattern started.
     *
     * An argument that already sets its own `validate_callback` is left alone,
     * and so is one that declares a constraint but no `type`: core's
     * `rest_validate_value_from_schema()` reads `$args['type']` unguarded, so
     * attaching the validator there buys a burst of `Undefined array key
     * "type"` warnings and a `_doing_it_wrong` on *every* request, valid ones
     * included. Such an argument is reported by `typeless()`, which the test
     * suite asserts is empty — the fix is to declare the type, not to widen
     * this guard.
     *
     * @since 2.0.1
     *
     * @param array<string, array<string, mixed>> $args Argument definitions.
     * @return array<string, array<string, mixed>> Definitions with validators attached.
     */
    public static function enforce(array $args): array {
        foreach ($args as $name => $definition) {
            if (!is_array($definition)
                || !empty($definition['validate_callback'])
                || !isset($definition['type'])
            ) {
                continue;
            }

            foreach (self::CONSTRAINTS as $keyword) {
                if (array_key_exists($keyword, $definition)) {
                    $args[$name]['validate_callback'] = 'rest_validate_request_arg';
                    break;
                }
            }
        }

        return $args;
    }

    /**
     * Attach validators across every route in the plugin's namespace.
     *
     * Hooked to `rest_endpoints`, which hands over the whole route map after
     * registration — so a constraint declared anywhere is enforced, including
     * on routes added by an add-on, and nobody has to remember to wrap an
     * argument array.
     *
     * @since 2.0.1
     *
     * @param array<string, array> $endpoints Registered routes.
     * @return array<string, array> Routes with validators attached.
     */
    public static function enforce_namespace(array $endpoints): array {
        foreach ($endpoints as $route => $handlers) {
            if (0 !== strpos((string) $route, '/' . self::NAMESPACE . '/')) {
                continue;
            }

            foreach ($handlers as $index => $handler) {
                if (!is_array($handler) || empty($handler['args']) || !is_array($handler['args'])) {
                    continue;
                }

                $endpoints[ $route ][ $index ]['args'] = self::enforce($handler['args']);
            }
        }

        return $endpoints;
    }

    /**
     * Argument names whose declared constraints would not be enforced.
     *
     * Used by the test that walks the namespace so this class of defect cannot
     * come back one route at a time.
     *
     * @since 2.0.1
     *
     * @param array<string, array<string, mixed>> $args Argument definitions.
     * @return string[] Names of arguments declaring a constraint with no validator.
     */
    public static function unenforced(array $args): array {
        $unenforced = [];

        foreach ($args as $name => $definition) {
            if (!is_array($definition) || !empty($definition['validate_callback'])) {
                continue;
            }

            foreach (self::CONSTRAINTS as $keyword) {
                if (array_key_exists($keyword, $definition)) {
                    $unenforced[] = (string) $name;
                    break;
                }
            }
        }

        return $unenforced;
    }

    /**
     * Argument names declaring a constraint with no `type` to validate against.
     *
     * Core cannot validate such an argument without emitting warnings, so
     * `enforce()` skips it and the constraint stays inert. Every one of these
     * is a bug at the point of registration.
     *
     * @since 2.0.1
     *
     * @param array<string, array<string, mixed>> $args Argument definitions.
     * @return string[] Names of constrained arguments missing a `type`.
     */
    public static function typeless(array $args): array {
        $typeless = [];

        foreach ($args as $name => $definition) {
            if (!is_array($definition) || isset($definition['type'])) {
                continue;
            }

            foreach (self::CONSTRAINTS as $keyword) {
                if (array_key_exists($keyword, $definition)) {
                    $typeless[] = (string) $name;
                    break;
                }
            }
        }

        return $typeless;
    }

    /**
     * Constrained arguments missing a `type`, across a whole route map.
     *
     * @since 2.0.1
     *
     * @param array<string, array> $endpoints Registered routes.
     * @return string[] "route: arg" for each offender in this namespace.
     */
    public static function typeless_in_namespace(array $endpoints): array {
        $offenders = [];

        foreach ($endpoints as $route => $handlers) {
            if (0 !== strpos((string) $route, '/' . self::NAMESPACE . '/')) {
                continue;
            }

            foreach ($handlers as $handler) {
                if (!is_array($handler) || empty($handler['args']) || !is_array($handler['args'])) {
                    continue;
                }

                foreach (self::typeless($handler['args']) as $name) {
                    $offenders[] = $route . ': ' . $name;
                }
            }
        }

        return $offenders;
    }
}
