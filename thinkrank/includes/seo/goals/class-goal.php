<?php
/**
 * SEO Goals: one item on the list.
 *
 * @package ThinkRank\SEO\Goals
 * @since 2.22.0
 */

declare(strict_types=1);

namespace ThinkRank\SEO\Goals;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Goal
 *
 * One thing to do (#728): what, why it matters, how to do it, and where. A
 * provider reports every goal it knows about with `open` saying whether it is
 * still to do — the feed shows the open ones, and a section checklist shows
 * all of them with a tick on the rest. One source for both, so a checklist
 * can never tick something the feed still asks for.
 *
 * Immutable; build one with {@see self::from_array()}.
 *
 * @since 2.22.0
 */
final class Goal {

    /**
     * WordPress core screens a goal may link to.
     *
     * @var string[]
     */
    public const CORE_SCREENS = ['options-general.php', 'options-reading.php'];


    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    public const EFFORTS = ['S', 'M', 'L'];

    /**
     * @var array<string, mixed>
     */
    private array $data;

    /**
     * @param array<string, mixed> $data Normalised fields.
     */
    private function __construct(array $data) {
        $this->data = $data;
    }

    /**
     * Build a goal, filling defaults and refusing what cannot be shown.
     *
     * @param array<string, mixed> $data {
     *     @type string   $id       Stable id, `source:slug`. Required.
     *     @type string   $title    Short imperative. Required.
     *     @type string   $why      Why it matters, one or two sentences.
     *     @type string[] $how      Steps, plain language.
     *     @type array    $link     Where it is fixed: ['section' => nav section, 'item' => nav item], or ['page' => SPA page id, 'tab' => optional tab].
     *     @type string   $severity One of SEVERITIES.
     *     @type string   $effort   One of EFFORTS.
     *     @type int      $count    Items affected (0 when not countable).
     *     @type string   $section  Nav section whose checklist it belongs to ('' for none).
     *     @type bool     $open     Whether it is still to do.
     *     @type array    $action   Optional one-click action, e.g. ['type' => 'analyzer_fix', 'check_id' => '…'].
     * }
     * @return self|null Null when the id or title is missing.
     */
    public static function from_array(array $data): ?self {
        $id    = trim((string) ($data['id'] ?? ''));
        $title = trim((string) ($data['title'] ?? ''));

        if ('' === $id || '' === $title) {
            return null;
        }

        $severity = (string) ($data['severity'] ?? 'medium');
        $effort   = (string) ($data['effort'] ?? 'M');

        return new self([
            'id'       => $id,
            'source'   => (string) strtok($id, ':'),
            'title'    => $title,
            'why'      => (string) ($data['why'] ?? ''),
            'how'      => array_values(array_filter(array_map('strval', (array) ($data['how'] ?? [])), static fn(string $s): bool => '' !== $s)),
            'link'     => self::link((array) ($data['link'] ?? [])),
            'severity' => in_array($severity, self::SEVERITIES, true) ? $severity : 'medium',
            'effort'   => in_array($effort, self::EFFORTS, true) ? $effort : 'M',
            'count'    => max(0, (int) ($data['count'] ?? 0)),
            'section'  => (string) ($data['section'] ?? ''),
            'open'     => (bool) ($data['open'] ?? true),
            'action'   => is_array($data['action'] ?? null) ? $data['action'] : null,
        ]);
    }

    /**
     * Goal id.
     *
     * @return string
     */
    public function id(): string {
        return $this->data['id'];
    }

    /**
     * Whether it is still to do.
     *
     * @return bool
     */
    public function is_open(): bool {
        return $this->data['open'];
    }

    /**
     * Items affected.
     *
     * @return int
     */
    public function count(): int {
        return $this->data['count'];
    }

    /**
     * How strongly the list should put this first. Severity dominates, a
     * larger count lifts a goal within its severity, and a quick one beats a
     * long one of the same weight — "fix 3 descriptions" before "rewrite the
     * site's schema".
     *
     * @return float
     */
    public function priority(): float {
        $severity = ['critical' => 1000.0, 'high' => 300.0, 'medium' => 100.0, 'low' => 30.0][$this->data['severity']];
        $effort   = ['S' => 1.0, 'M' => 0.7, 'L' => 0.45][$this->data['effort']];
        $count    = 1.0 + log(1 + $this->data['count'], 2) / 4;

        return round($severity * $effort * $count, 3);
    }

    /**
     * As data.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return $this->data + ['priority' => $this->priority()];
    }

    /**
     * Keep only the link keys the SPA understands.
     *
     * @param array $link Raw link.
     * @return array<string, string>
     */
    private static function link(array $link): array {
        $out = [];
        foreach (['page', 'tab', 'section', 'item'] as $key) {
            if (isset($link[$key]) && '' !== (string) $link[$key]) {
                $out[$key] = sanitize_key((string) $link[$key]);
            }
        }

        // A WordPress settings screen, for what core owns (site title,
        // tagline). Only the screens listed here, never a free-form URL.
        if (isset($link['screen']) && in_array((string) $link['screen'], self::CORE_SCREENS, true)) {
            $out['screen'] = (string) $link['screen'];
        }

        return $out;
    }
}
