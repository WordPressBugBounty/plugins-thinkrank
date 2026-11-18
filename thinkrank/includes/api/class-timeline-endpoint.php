<?php
/**
 * SEO Timeline REST endpoint.
 *
 * @package ThinkRank
 * @subpackage API
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\API;

use ThinkRank\Core\Capability_Manager;
use ThinkRank\SEO\Timeline\Event_Recorder;
use ThinkRank\SEO\Timeline\Event_Repository;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads the Timeline.
 *
 * Under `analytics/` so the Role Manager's existing route map gates it with the
 * Analytics capability, which is the section the screen lives in. Mapping a new
 * prefix would be a second thing to keep in step with the navigation.
 *
 * @since 2.22.0
 */
class Timeline_Endpoint {

    /**
     * API namespace.
     *
     * @since 2.22.0
     */
    private const NAMESPACE = 'thinkrank/v1';

    /**
     * Route base.
     *
     * @since 2.22.0
     */
    private const REST_BASE = 'analytics/timeline';

    /**
     * Register routes.
     *
     * @since 2.22.0
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE,
            '/' . self::REST_BASE,
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_events'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => $this->args(),
            ]
        );
    }

    /**
     * Route arguments.
     *
     * @since 2.22.0
     *
     * @return array<string,array<string,mixed>>
     */
    private function args(): array {
        return [
            'from'     => [
                'type'              => 'string',
                'description'       => __('Earliest event time: Y-m-d or Y-m-d H:i:s in UTC, or an ISO 8601 time.', 'thinkrank'),
                'required'          => false,
                'validate_callback' => static fn($value) => self::date_param_error('from', $value) ?? true,
            ],
            'to'       => [
                'type'              => 'string',
                'description'       => __('Latest event time: Y-m-d or Y-m-d H:i:s in UTC, or an ISO 8601 time.', 'thinkrank'),
                'required'          => false,
                'validate_callback' => static fn($value) => self::date_param_error('to', $value) ?? true,
            ],
            'type'     => [
                'type'        => 'array',
                'items'       => ['type' => 'string', 'enum' => Event_Recorder::types()],
                'description' => __('Event types to include. Omit for all.', 'thinkrank'),
                'required'    => false,
            ],
            'severity' => [
                'type'        => 'array',
                'items'       => ['type' => 'string', 'enum' => Event_Repository::SEVERITIES],
                'description' => __('Severities to include. Omit for all.', 'thinkrank'),
                'required'    => false,
            ],
            'limit'    => [
                'type'    => 'integer',
                'default' => 50,
                'minimum' => 1,
                'maximum' => Event_Repository::MAX_LIMIT,
            ],
            'offset'   => [
                'type'    => 'integer',
                'default' => 0,
                'minimum' => 0,
            ],
        ];
    }

    /**
     * Read events.
     *
     * @since 2.22.0
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_events(WP_REST_Request $request): WP_REST_Response {
        $args = [
            // validate_callback has refused anything normalize_date() rejects.
            'from'     => self::normalize_date((string) $request->get_param('from'), '00:00:00') ?? '',
            'to'       => self::normalize_date((string) $request->get_param('to'), '23:59:59') ?? '',
            'type'     => (array) ($request->get_param('type') ?? []),
            'severity' => (array) ($request->get_param('severity') ?? []),
            'limit'    => (int) $request->get_param('limit'),
            'offset'   => (int) $request->get_param('offset'),
        ];

        $events = array_map([self::class, 'with_edit_url'], Event_Repository::query($args));

        // How many of each severity the current type filter holds, so the
        // screen can say "2 critical" before anyone scrolls for them.
        $counts = [];
        foreach (Event_Repository::SEVERITIES as $level) {
            $counts[$level] = Event_Repository::count(['severity' => [$level]] + $args);
        }

        return new WP_REST_Response(
            [
                'success' => true,
                'data'    => [
                    'events'         => $events,
                    'total'          => Event_Repository::count($args),
                    'counts'         => $counts,
                    'retention_days' => Event_Repository::retention_days(),
                    'types'          => Event_Recorder::types(),
                    'severities'     => Event_Repository::SEVERITIES,
                ],
            ],
            200
        );
    }

    /**
     * Add the edit link of the post an event is about, when the reader may
     * edit it.
     *
     * @since 2.22.0
     *
     * @param array<string,mixed> $event Event row.
     * @return array<string,mixed>
     */
    public static function with_edit_url(array $event): array {
        $event['edit_url'] = '';

        $post_id = (int) ($event['object_id'] ?? 0);

        if ($post_id > 0 && '' !== (string) ($event['object_type'] ?? '')
            && get_post($post_id) && current_user_can('edit_post', $post_id)) {
            $event['edit_url'] = (string) get_edit_post_link($post_id, 'raw');
        }

        return $event;
    }

    /**
     * Accept a date or a datetime, and reject anything else.
     *
     * A bare `Y-m-d` is widened to the whole day in whichever direction the
     * caller meant, so `from=2026-10-01&to=2026-10-01` returns that day rather
     * than the single instant of midnight. An ISO 8601 time (`2026-10-01T08:00:00Z`,
     * with or without seconds or an offset) is converted to UTC: it is what
     * AI clients send, and reading it as "no bound" answered a "since Monday"
     * question with the whole history (#1687).
     *
     * @since 2.22.0
     *
     * @param string $value Raw value.
     * @param string $time  Time to append to a bare date.
     * @return string|null Normalised UTC datetime, '' when the value is empty,
     *                     or null when it is not a date this accepts.
     */
    public static function normalize_date(string $value, string $time): ?string {
        $value = trim($value);

        if ('' === $value) {
            return '';
        }

        if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= ' ' . $time;
        }

        $utc = new \DateTimeZone('UTC');

        if (1 === preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc);

            // createFromFormat() rolls 2026-02-30 or 25:99 over into another
            // time; only a value that reads back unchanged is a real one.
            return $date && $date->format('Y-m-d H:i:s') === $value ? $value : null;
        }

        if (1 !== preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/i', $value, $parts)) {
            return null;
        }

        $local = $parts[1] . ' ' . $parts[2] . ('' !== ($parts[3] ?? '') ? $parts[3] : ':00');
        $zone  = strtoupper($parts[5] ?? '');

        try {
            $tz = '' === $zone || 'Z' === $zone ? $utc : new \DateTimeZone($zone);
        } catch (\Exception $e) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $local, $tz);
        if (!$date || $date->format('Y-m-d H:i:s') !== $local) {
            return null;
        }

        return $date->setTimezone($utc)->format('Y-m-d H:i:s');
    }

    /**
     * The error for a `from` or `to` value normalize_date() does not accept.
     *
     * One message for the REST route and the get-seo-timeline ability, so a
     * malformed bound is refused the same way on both instead of being dropped.
     *
     * @since 2.22.0
     *
     * @param string $param Parameter name.
     * @param mixed  $value Submitted value.
     * @return \WP_Error|null Null when the value is usable or absent.
     */
    public static function date_param_error(string $param, $value): ?\WP_Error {
        if (null === $value || (is_string($value) && null !== self::normalize_date($value, '00:00:00'))) {
            return null;
        }

        return new \WP_Error(
            'rest_invalid_param',
            sprintf(
                /* translators: %s: parameter name, "from" or "to". */
                __('%s must be a date as YYYY-MM-DD, YYYY-MM-DD HH:MM:SS in UTC, or an ISO 8601 time such as 2026-10-01T08:00:00Z.', 'thinkrank'),
                $param
            ),
            ['status' => 400, 'param' => $param]
        );
    }

    /**
     * Who may read the Timeline.
     *
     * @since 2.22.0
     *
     * @return bool
     */
    public function check_permissions(): bool {
        if (!is_user_logged_in()) {
            return false;
        }

        return Capability_Manager::current_user_can('thinkrank_analytics');
    }
}
